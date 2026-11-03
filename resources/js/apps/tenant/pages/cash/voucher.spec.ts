import { describe, expect, it } from 'vitest';
import { amountInWords, documentLink, voucherCode, voucherHtml, type VoucherRow } from './voucher';

const row = (over: Partial<VoucherRow> = {}): VoucherRow => ({
    source: 'voucher',
    id: '01V',
    number: 'PC-000007',
    direction: 'payment',
    type: { id: 't1', name: 'Chi phí quảng cáo' },
    counts_in_pnl: true,
    party: { kind: 'supplier', id: 's1', name: 'Google <Ads>' },
    method: 'bank_transfer',
    amount: 1250000,
    paid_at: '2026-11-03T08:00:00+07:00',
    document: null,
    reference: 'GOOGLE_ADS_T10',
    note: null,
    created_by: 'Tài',
    created_at: '2026-11-03T08:00:00+07:00',
    voided_at: null,
    void_reason: null,
    ...over,
});

describe('amountInWords', () => {
    it.each([
        [0, 'Không đồng'],
        [15, 'Mười lăm đồng'],
        [21, 'Hai mươi mốt đồng'],
        [24, 'Hai mươi tư đồng'],
        [105000, 'Một trăm lẻ năm nghìn đồng'],
        [1005000, 'Một triệu không trăm lẻ năm nghìn đồng'],
        [1250000, 'Một triệu hai trăm năm mươi nghìn đồng'],
        [2000000000, 'Hai tỷ đồng'],
        [2500000000, 'Hai tỷ năm trăm triệu đồng'],
    ])('reads %d', (n, words) => expect(amountInWords(n)).toBe(words));
});

describe('voucher print', () => {
    it('prints a payment voucher with amount in words and escapes names', () => {
        const html = voucherHtml(row(), 'Shop A');
        expect(html).toContain('PHIẾU CHI');
        expect(html).toContain('Người nhận');
        expect(html).toContain('Một triệu hai trăm năm mươi nghìn đồng');
        expect(html).toContain('Google &lt;Ads&gt;');
        expect(html).not.toContain('ĐÃ HỦY');
    });

    it('marks a voided receipt and names automatic rows by their source document', () => {
        const auto = row({ source: 'order_payment', number: null, direction: 'receipt', document: { kind: 'order', id: 'o1', number: 'SO-000012' }, voided_at: '2026-11-03T09:00:00+07:00', void_reason: 'Nhầm' });
        expect(voucherCode(auto)).toBe('Tự động · SO-000012');
        expect(documentLink(auto)).toBe('/orders/o1');
        const html = voucherHtml(auto, 'Shop A');
        expect(html).toContain('PHIẾU THU');
        expect(html).toContain('ĐÃ HỦY: Nhầm');
    });
});
