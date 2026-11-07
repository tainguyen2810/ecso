/** Carrier connections (kết nối hãng vận chuyển bằng tài khoản của shop): types and helpers. */
import { api } from '@/shared/lib/api';

export interface CarrierCatalogItem {
    carrier: string;
    label: string;
    fields: Record<string, string>;
    available: boolean;
    note: string | null;
}

export interface CarrierLocation {
    id: string;
    name: string;
    address?: string | null;
    province_code?: string | null;
}

export interface CarrierAccountData {
    id: string;
    carrier: string;
    carrier_label: string;
    name: string;
    external_account: string | null;
    environment: 'sandbox' | 'production';
    status: 'connected' | 'error' | 'disconnected';
    locations: CarrierLocation[];
    pickups: { warehouse: { id: string; code: string; name: string }; pickup: CarrierLocation | null }[];
    webhook_url: string | null;
    last_verified_at: string | null;
    last_event_at: string | null;
    last_error: string | null;
}

export interface ServiceQuoteData {
    code: string;
    name: string;
    fee: string;
    expected_at: string | null;
}

export interface QuoteOption extends ServiceQuoteData {
    account_id: string;
    carrier_label: string;
}

export interface QuoteResult {
    weight_g: number;
    cod_amount: string;
    accounts: { account: { id: string; carrier: string; carrier_label: string; name: string }; services: ServiceQuoteData[]; error: string | null }[];
    cheapest: QuoteOption | null;
    fastest: QuoteOption | null;
}

/** What the shop picked: one service of one connected account. */
export interface CarrierChoice {
    account_id: string;
    service: string;
    service_name: string;
    fee: string;
    carrier_label: string;
}

export interface QueueRow {
    id: string;
    external_order_id: string;
    marketplace: string;
    status: string;
    recipient: string | null;
    phone: string | null;
    address: string | null;
    area: string | null;
    has_address: boolean;
    total: string;
    cod_amount: string;
    weight_g: number;
    warehouse: { id: string; code: string; name: string } | null;
    placed_at: string | null;
    failed_shipment: { id: string; carrier_name: string | null; error: string | null } | null;
}

/** Parcel options the shop can change before pushing (the API's names). */
export interface ParcelOptions {
    weight_g?: number | null;
    length_cm?: number | null;
    width_cm?: number | null;
    height_cm?: number | null;
    cod_amount?: number | string | null;
    payer?: 'shop' | 'buyer';
    required_note?: 'view_no_try' | 'try' | 'no_view';
    note?: string | null;
}

export const requiredNotes = [
    { value: 'view_no_try', label: 'Cho xem hàng, không cho thử' },
    { value: 'try', label: 'Cho thử hàng' },
    { value: 'no_view', label: 'Không cho xem hàng' },
];

export const sizePresets = [
    { label: 'Nhỏ 20×15×10', l: 20, w: 15, h: 10 },
    { label: 'Vừa 30×20×15', l: 30, w: 20, h: 15 },
    { label: 'Lớn 40×30×20', l: 40, w: 30, h: 20 },
];

/** Short mark and brand color of each carrier, for the small logos in price chips. */
export const carrierMarks: Record<string, { short: string; bg: string }> = {
    ghn: { short: 'GHN', bg: 'bg-orange-500' },
    viettelpost: { short: 'VTP', bg: 'bg-red-600' },
    jt: { short: 'J&T', bg: 'bg-red-500' },
    spx: { short: 'SPX', bg: 'bg-orange-600' },
    fake: { short: 'TEST', bg: 'bg-slate-500' },
};

export function choiceOf(q: QuoteOption): CarrierChoice {
    return { account_id: q.account_id, service: q.code, service_name: q.name, fee: q.fee, carrier_label: q.carrier_label };
}

/** "T3 11/11": weekday and day of the expected delivery. */
export function shortDate(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    const day = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'][d.getDay()];
    return `${day} ${d.getDate()}/${d.getMonth() + 1}`;
}

export async function loadCarrierAccounts(): Promise<CarrierAccountData[]> {
    return (await api.get('/carrier-accounts')).data.data;
}

/** Opens the carrier's printable labels (A5 by default) in a new tab. */
export async function openLabels(ids: string[], size = 'a5'): Promise<void> {
    const tab = window.open('', '_blank');
    try {
        const { data } = await api.post('/shipments/labels', { ids, size });
        // A blocked pop-up never takes the shop away from the app: try once more as a plain new tab.
        if (tab) tab.location.href = data.data.url;
        else window.open(data.data.url, '_blank', 'noopener');
    } catch (e) {
        tab?.close();
        throw e;
    }
}
