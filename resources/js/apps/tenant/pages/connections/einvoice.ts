/** Hóa đơn điện tử (C2): the shop's provider connection and the invoice of each order. */
import { api } from '@/shared/lib/api';

export interface EInvoiceProviderItem {
    provider: string;
    label: string;
    fields: Record<string, string>;
    available: boolean;
    note: string | null;
}

export interface EInvoiceTemplate {
    template_code: string;
    invoice_series: string;
    label?: string;
}

export type IssueMode = 'manual' | 'paid' | 'completed';

export interface EInvoiceAccountData {
    id: string;
    provider: string;
    provider_label: string;
    provider_name: string | null;
    environment: 'sandbox' | 'production';
    status: 'connected' | 'error' | 'disconnected';
    templates: EInvoiceTemplate[];
    template: string | null;
    template_label: string | null;
    issue_mode: IssueMode;
    send_email: boolean;
    last_error: string | null;
    last_verified_at: string | null;
    credentials_set: string[];
}

export interface EInvoiceSettings {
    providers: EInvoiceProviderItem[];
    account: EInvoiceAccountData | null;
}

export interface EInvoiceBuyer {
    name: string;
    legal_name: string | null;
    tax_code: string | null;
    address: string | null;
    email: string | null;
    phone: string | null;
}

export interface EInvoiceData {
    id: string;
    status: 'pending' | 'issued' | 'failed';
    reference_code: string;
    template_code: string | null;
    invoice_series: string | null;
    invoice_number: string | null;
    issued_at: string | null;
    pdf_url: string | null;
    buyer: EInvoiceBuyer | null;
    total: string;
    message: string | null;
    created_at: string | null;
}

export interface OrderEInvoice {
    ready: boolean;
    template: string | null;
    buyer: EInvoiceBuyer;
    invoices: EInvoiceData[];
}

export const providerMarks: Record<string, { short: string; bg: string }> = {
    sepay: { short: 'SP', bg: 'bg-sky-600' },
    misa: { short: 'MISA', bg: 'bg-blue-700' },
    viettel: { short: 'VT', bg: 'bg-red-600' },
    vnpt: { short: 'VNPT', bg: 'bg-blue-500' },
    fake: { short: 'TEST', bg: 'bg-slate-500' },
};

export const issueModes: { value: IssueMode; label: string; hint: string }[] = [
    { value: 'manual', label: 'Bấm tay trên từng đơn', hint: 'An toàn nhất khi mới dùng.' },
    { value: 'paid', label: 'Tự xuất khi đơn đã thu đủ tiền', hint: 'Chỉ đơn kênh riêng và POS. Khách không lấy hóa đơn thì xuất cho "Người mua không lấy hóa đơn".' },
    { value: 'completed', label: 'Tự xuất khi đơn hoàn tất (đã giao)', hint: 'Chỉ đơn kênh riêng và POS.' },
];

export const providerHelp: Record<string, string> = {
    sepay: 'Đăng nhập my.sepay.vn → Hóa đơn điện tử → API → tạo Client ID và Client Secret. Shop cần đăng ký và kích hoạt tài khoản hóa đơn với SePay trước.',
    fake: 'Nhà cung cấp giả lập để thử quy trình (chỉ có ở bản thử). Client Secret "wrong" bị từ chối.',
};

/** The invoice the order shows: the issued or pending one first, else the last failed try. */
export function currentInvoice(invoices: EInvoiceData[]): EInvoiceData | null {
    return invoices.find((i) => i.status !== 'failed') ?? invoices[0] ?? null;
}

/** Only one invoice per order: another can be issued only while none is issued or pending. */
export function canIssueAgain(invoices: EInvoiceData[]): boolean {
    return !invoices.some((i) => i.status !== 'failed');
}

export async function loadEInvoiceSettings(): Promise<EInvoiceSettings> {
    return (await api.get('/einvoice/settings')).data.data;
}
