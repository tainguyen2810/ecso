/** Zalo ZNS (C6): settings types and loading. */
import { api } from '@/shared/lib/api';

export interface ZaloTemplate {
    id: string;
    name: string;
    status: string;
    params: { name: string; required: boolean; type: string | null; max: number | null }[];
}

export interface ZaloRule {
    /** '' = no template chosen. */
    template_id: string;
    enabled: boolean;
    params: Record<string, string>;
}

export interface ZaloSettings {
    account: { id: string; app_id: string; oa_name: string | null; status: string; last_error: string | null; templates: ZaloTemplate[]; rules: Record<string, ZaloRule> } | null;
    events: Record<string, string>;
    fields: Record<string, string>;
    messages: { id: number; event: string; template_name: string | null; phone: string; buyer_name: string | null; status: string; error: string | null; order: { id: string; number: string } | null; created_at: string | null }[];
}

export async function loadZalo(): Promise<ZaloSettings> {
    return (await api.get('/zalo')).data.data;
}

/** Zalo's template states in words. */
export function templateState(status: string): { label: string; tone: 'success' | 'warning' | 'danger' | 'muted' } {
    switch (status.toUpperCase()) {
        case 'ENABLE':
            return { label: 'Đã duyệt', tone: 'success' };
        case 'PENDING_REVIEW':
            return { label: 'Chờ Zalo duyệt', tone: 'warning' };
        case 'REJECT':
            return { label: 'Bị từ chối', tone: 'danger' };
        default:
            return { label: 'Đã tắt', tone: 'muted' };
    }
}
