/**
 * Marketplace price from fees, same formula as MarketplacePricing.php (used for live previews only; the server
 * computes the prices that are applied):
 *   price = (base + fixed fee) ÷ (1 − total % fees), rounded up, not below (cost × (1 + m) + fixed fee) ÷ (1 − total %).
 */
export interface FeeFields {
    commission_pct: number | string | null;
    payment_pct: number | string | null;
    service_pct: number | string | null;
    fixed_fee: number | string | null;
    rounding?: number | string;
    min_margin_pct?: number | string;
}

const n = (v: unknown) => Number(v) || 0;

export function totalPct(f: FeeFields): number {
    return Math.round((n(f.commission_pct) + n(f.payment_pct) + n(f.service_pct)) * 100) / 100;
}

export function roundUp(value: number, step: number): number {
    return Math.ceil(Math.round((value / step) * 1e6) / 1e6) * step;
}

export function quote(base: number, cost: number | null, f: FeeFields) {
    const total = totalPct(f);
    if (total >= 90 || base <= 0) return null;
    const r = total / 100;
    const fixed = n(f.fixed_fee);
    const step = Math.max(1, n(f.rounding) || 100);
    const raw = (base + fixed) / (1 - r);
    let price = roundUp(raw, step);
    let raised = false;
    if (cost && cost > 0) {
        const floor = roundUp((cost * (1 + n(f.min_margin_pct) / 100) + fixed) / (1 - r), step);
        if (floor > price) [price, raised] = [floor, true];
    }
    const fees = price * r + fixed;
    const net = price - fees;
    const profit = cost && cost > 0 ? net - cost : null;
    return { raw, price, raised, fees, net, profit, margin_pct: profit !== null && cost ? Math.round((profit / cost) * 1000) / 10 : null };
}

/** 4.91 → "4,91%" (sign=false drops the % sign for compact badges: "0,7"). */
export function fmtPct(value: number | string | null | undefined, sign = true): string {
    if (value === null || value === undefined || value === '') return '—';
    const s = (Math.round(Number(value) * 100) / 100).toLocaleString('vi-VN', { maximumFractionDigits: 2 });
    return sign ? `${s}%` : s;
}
