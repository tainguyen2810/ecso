export type CodLineStatus = 'matched' | 'mismatch' | 'not_found' | 'duplicate' | 'no_cod';

export interface CodLine {
    tracking: string;
    cod: number | string;
    fee: number | string;
    status: CodLineStatus;
    booked: number | string;
    note: string | null;
    order: { id: string; number: string; total?: string; due?: string; status?: string } | null;
    /** The carrier collected the COD of an own order still shown on its way: it can be marked delivered. */
    deliverable?: boolean;
}

export interface CodReconciliation {
    id: string;
    number: string;
    carrier: string;
    carrier_name: string;
    file_name: string | null;
    account: { id: string; name: string } | null;
    remitted_at: string;
    total_cod: number;
    total_fee: number;
    total_booked: number;
    transferred: number;
    lines_count: number;
    matched_count: number;
    reference: string | null;
    note: string | null;
    created_by: string | null;
    created_at: string | null;
    voided_at: string | null;
    voided_by: string | null;
    void_reason: string | null;
    fee_voucher?: { id: string; number: string } | null;
    lines?: CodLine[];
}

export const carriers: Record<string, string> = { ghn: 'GHN', viettelpost: 'Viettel Post', spx: 'SPX Express', jt: 'J&T Express', other: 'Hãng khác' };

export const codStatuses: Record<CodLineStatus, { label: string; tone: 'success' | 'warning' | 'danger' | 'muted' }> = {
    matched: { label: 'Khớp', tone: 'success' },
    mismatch: { label: 'Lệch tiền', tone: 'warning' },
    not_found: { label: 'Không thấy đơn', tone: 'danger' },
    duplicate: { label: 'Đã thu đủ', tone: 'muted' },
    no_cod: { label: 'Chỉ có phí', tone: 'muted' },
};
