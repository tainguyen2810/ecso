/** "Hoá đơn mua vào": the list row and the words for each check. */
export interface PurchaseInvoiceRow {
    id: string;
    kind: 'vat' | 'sale';
    series: string;
    number: string;
    issued_on: string | null;
    authority_code: string | null;
    seller: { name: string; tax_code: string; address: string | null };
    buyer: { name: string | null; tax_code: string | null };
    subtotal: string;
    vat_amount: string;
    vat_rate: number | null;
    total: string;
    line_count: number;
    issues: string[];
    blocked: boolean;
    status: 'open' | 'linked' | 'ignored';
    note: string | null;
    supplier: { id: string; name: string } | null;
    purchase_order: { id: string; number: string } | null;
}

/** blocking: the VAT cannot be deducted as it is (red). */
export const ISSUE_LABELS: Record<string, { label: string; blocking: boolean }> = {
    buyer_mismatch: { label: 'Sai MST người mua', blocking: true },
    no_authority_code: { label: 'Thiếu mã CQT', blocking: true },
    no_buyer_tax_code: { label: 'Thiếu MST người mua', blocking: false },
    total_mismatch: { label: 'Cộng tiền lệch', blocking: false },
};

/** This month and the ones before it, "YYYY-MM" with "Tháng 11/2026". */
export function monthOptions(today: Date, count: number): { value: string; label: string }[] {
    const out: { value: string; label: string }[] = [];
    for (let i = 0; i < count; i++) {
        const d = new Date(today.getFullYear(), today.getMonth() - i, 1);
        const m = d.getMonth() + 1;
        out.push({ value: `${d.getFullYear()}-${String(m).padStart(2, '0')}`, label: `Tháng ${m}/${d.getFullYear()}` });
    }
    return out;
}
