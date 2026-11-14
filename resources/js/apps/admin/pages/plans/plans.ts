import { formatNumber } from '@/shared/lib/utils';

/** A1: Admin → Gói dịch vụ. */
export interface PlanFeature {
    name: string;
    type: 'limit' | 'boolean';
    enabled: boolean;
    limit: number | null;
}

export interface AdminPlan {
    key: string;
    name: string;
    description: string | null;
    badge: string | null;
    highlights: string[];
    price_monthly: number;
    price_yearly: number;
    trial_days: number;
    is_public: boolean;
    features: Record<string, PlanFeature>;
    tenants: number;
    deletable: boolean;
    is_default: boolean;
}

export interface FeatureDef {
    key: string;
    type: 'limit' | 'boolean';
    name: string;
}

/** One row of the edit form per feature: a number (or unlimited) for limits, a switch for the rest. */
export interface FeatureRow {
    key: string;
    name: string;
    type: 'limit' | 'boolean';
    enabled: boolean;
    unlimited: boolean;
    limit: number | null;
}

const SHORT: Record<string, string> = { 'limit.users': 'người', 'limit.shops': 'shop', 'limit.warehouses': 'kho', 'limit.orders_per_month': 'đơn/tháng' };

/** "2 người · 1 shop · 1 kho · 300 đơn/tháng", or "Không giới hạn" when all of them are unlimited. */
export function limitSummary(features: Record<string, PlanFeature>): string {
    const parts = Object.entries(SHORT)
        .filter(([key]) => features[key])
        .map(([key, unit]) => {
            const f = features[key];
            if (!f.enabled) return { unlimited: false, text: `0 ${unit}` };
            return f.limit === null ? { unlimited: true, text: `∞ ${unit}` } : { unlimited: false, text: `${formatNumber(f.limit)} ${unit}` };
        });
    if (parts.length > 0 && parts.every((p) => p.unlimited)) return 'Không giới hạn';
    return parts.map((p) => p.text).join(' · ');
}

export function featureRows(defs: FeatureDef[], features: Record<string, PlanFeature>): FeatureRow[] {
    return defs.map((d) => {
        const f = features[d.key];
        const enabled = f?.enabled ?? false;
        if (d.type === 'boolean') return { key: d.key, name: d.name, type: d.type, enabled, unlimited: false, limit: null };
        return { key: d.key, name: d.name, type: d.type, enabled: true, unlimited: enabled && f?.limit === null, limit: enabled ? (f?.limit ?? null) : 0 };
    });
}

/** What the API takes: a limit of 0 switches the feature off; "unlimited" is enabled with no limit. */
export function featurePayload(rows: FeatureRow[]): Record<string, { enabled: boolean; limit?: number | null }> {
    return Object.fromEntries(
        rows.map((r) => {
            if (r.type === 'boolean') return [r.key, { enabled: r.enabled }];
            if (r.unlimited) return [r.key, { enabled: true, limit: null }];
            const n = Math.max(0, Math.floor(Number(r.limit ?? 0)));
            return [r.key, n === 0 ? { enabled: false, limit: null } : { enabled: true, limit: n }];
        }),
    );
}

/** "= 415.833 ₫/tháng, tiết kiệm 17%" under the yearly price. */
export function yearlyHint(monthly: number, yearly: number): string {
    if (!yearly) return '';
    const perMonth = Math.round(yearly / 12);
    const saving = monthly > 0 ? Math.round((1 - yearly / (monthly * 12)) * 100) : 0;
    return `= ${formatNumber(perMonth)} ₫/tháng${saving > 0 ? `, tiết kiệm ${saving}%` : ''}`;
}

/** Plan keys after moving one up (-1) or down (+1). */
export function moved(keys: string[], key: string, by: -1 | 1): string[] {
    const i = keys.indexOf(key);
    const j = i + by;
    if (i < 0 || j < 0 || j >= keys.length) return keys;
    const next = [...keys];
    [next[i], next[j]] = [next[j], next[i]];
    return next;
}
