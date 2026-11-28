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
    /** No public link (Viettel, VNPT, MISA): download it from einvoices/{id}/pdf. */
    pdf_download?: boolean;
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

/** "Lấy thông tin ở đâu?" beside the connect form: the steps for each provider. */
export const providerSteps: Record<string, { title: string; steps: string[] }> = {
    viettel: {
        title: 'Lấy thông tin Viettel ở đâu?',
        steps: [
            'Gọi tổng đài Viettel <b>18008000</b> hoặc nhân viên kinh doanh, nói “cấp tài khoản API để kết nối phần mềm bán hàng”.',
            'Viettel gửi tên đăng nhập và mật khẩu API qua email (khác tài khoản vào trang sinvoice.viettel.vn).',
            'Mẫu số và ký hiệu xem trong trang hóa đơn Viettel (vd. 1/001 và C25TMP). Dán vào đây, bấm <b>Kiểm tra và kết nối</b>.',
        ],
    },
    vnpt: {
        title: 'Lấy thông tin VNPT ở đâu?',
        steps: [
            'Báo nhân viên VNPT phụ trách hóa đơn của shop: “cấp tài khoản tích hợp (webservice) cho phần mềm bán hàng”.',
            'VNPT gửi địa chỉ trang hóa đơn, tài khoản dịch vụ và tài khoản quản trị.',
            'Mẫu số và ký hiệu xem trong trang quản trị hóa đơn VNPT. Dán đủ vào đây, bấm <b>Kiểm tra và kết nối</b>.',
        ],
    },
    misa: {
        title: 'Kết nối MISA meInvoice',
        steps: [
            'Dùng mã số thuế, tài khoản và mật khẩu đăng nhập meinvoice.vn của shop.',
            'Bấm <b>Kiểm tra và kết nối</b>: EzChat Bán hàng lấy danh sách mẫu, ký hiệu để shop chọn.',
            'Hóa đơn được tạo nháp trên meInvoice. Shop ký phát hành ở meInvoice (USB token hoặc ký số từ xa), số hóa đơn tự về đơn.',
        ],
    },
    sepay: {
        title: 'Lấy khóa SePay ở đâu?',
        steps: [
            'Đăng ký và kích hoạt hóa đơn điện tử tại my.sepay.vn.',
            'Vào Hóa đơn điện tử → API, tạo Client ID và Client Secret.',
            'Dán vào đây, bấm <b>Kiểm tra và kết nối</b>. Có thể thử ở môi trường Thử nghiệm trước.',
        ],
    },
};

/** Providers that issue on their own servers with a test account on the same address: no Thử nghiệm switch. */
export const noSandbox = ['viettel', 'vnpt'];

export interface EInvoicePackage {
    id: string;
    provider: string;
    name: string;
    invoices: number;
    price: string;
    setup_fee: string;
    signing_price: string | null;
    note: string | null;
    active: boolean;
    featured: boolean;
    sort: number;
}

export type EInvoiceOrderStatus = 'awaiting_payment' | 'paid' | 'account_created' | 'waiting_signature' | 'tax_approved' | 'connected' | 'cancelled';

export interface EInvoiceCompany {
    type: 'company' | 'household';
    tax_code: string;
    name: string;
    address: string | null;
    representative: string;
    email: string;
    phone: string;
}

export interface BankInfo {
    configured: boolean;
    bank_name: string | null;
    account_number: string | null;
    account_name: string | null;
    branch: string | null;
    amount: string;
    memo: string;
}

export interface EInvoiceOrder {
    id: string;
    number: string;
    provider: string;
    package_name: string;
    invoices: number;
    price: string;
    setup_fee: string;
    signing: boolean;
    signing_price: string;
    amount: string;
    transfer_code: string;
    status: EInvoiceOrderStatus;
    company: EInvoiceCompany;
    shop_note: string | null;
    sign_url: string | null;
    steps: Partial<Record<EInvoiceOrderStatus, string>>;
    paid_at: string | null;
    created_at: string | null;
    bank: BankInfo | null;
    payment_ref?: string | null;
    tenant?: { id: string; name: string } | null;
}

export interface EInvoiceShop {
    packages: EInvoicePackage[];
    order: EInvoiceOrder | null;
}

export const providerNames: Record<string, string> = { viettel: 'Viettel S-Invoice', vnpt: 'VNPT Invoice', misa: 'MISA meInvoice', sepay: 'SePay eInvoice' };

/** The registration steps the shop sees (Chờ thanh toán is the step before the first). */
export const orderSteps: { status: EInvoiceOrderStatus; label: string; hint: string }[] = [
    { status: 'paid', label: 'Đã thanh toán', hint: 'Bên mình nhận tiền' },
    { status: 'account_created', label: 'Tạo tài khoản', hint: 'Nhà cung cấp mở tài khoản' },
    { status: 'waiting_signature', label: 'Shop ký tờ khai 01', hint: 'Đăng ký với cơ quan thuế' },
    { status: 'tax_approved', label: 'Thuế chấp nhận', hint: 'Thường 1 ngày làm việc' },
    { status: 'connected', label: 'Tự kết nối', hint: 'Xuất được hóa đơn' },
];

export const orderStatusLabels: Record<EInvoiceOrderStatus, string> = {
    awaiting_payment: 'Chờ chuyển khoản',
    paid: 'Đã thanh toán',
    account_created: 'Đã tạo tài khoản',
    waiting_signature: 'Chờ shop ký',
    tax_approved: 'Thuế đã chấp nhận',
    connected: 'Đã kết nối',
    cancelled: 'Đã hủy',
};

export async function loadEInvoiceShop(): Promise<EInvoiceShop | null> {
    return (await api.get('/einvoice/packages')).data.data;
}

/** Opens an invoice's PDF: its public link, or downloaded through the API (the link needs the shop header). */
export async function openInvoicePdf(invoice: Pick<EInvoiceData, 'id' | 'pdf_url' | 'pdf_download'>): Promise<void> {
    if (invoice.pdf_url) {
        window.open(invoice.pdf_url, '_blank', 'noopener');
        return;
    }
    const tab = window.open('', '_blank');
    const res = await api.get(`/einvoices/${invoice.id}/pdf`, { responseType: 'blob' });
    const url = URL.createObjectURL(res.data as Blob);
    if (tab) tab.location.href = url;
    else window.location.href = url;
}

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
