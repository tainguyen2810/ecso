import { describe, expect, it } from 'vitest';
import { deliverySlipHtml, invoiceHtml, invoicesHtml } from './invoice';
import { actionLabels, manualActionOrder, paymentMethods, paymentStatus } from './labels';
import type { OrderDetail } from '@/shared/types';

const order = (over: Partial<OrderDetail> = {}): OrderDetail =>
    ({
        id: '01B',
        marketplace: 'manual',
        source: 'manual',
        external_order_id: 'SO-000012',
        channel: { id: 'c', name: 'Bán tại cửa hàng', type: 'pos' },
        status: 'COMPLETED',
        subtotal: '300000.00',
        shipping_fee: '0.00',
        discount: '20000.00',
        total: '280000.00',
        paid_amount: '200000.00',
        refunded_amount: '0.00',
        buyer_name: 'Chị <Lan>',
        buyer_phone: '0912345678',
        shipping_address: null,
        buyer_note: null,
        placed_at: '2026-11-03T08:00:00+07:00',
        items: [{ id: 1, name: 'Áo thun', variant_name: 'Size M', seller_sku: 'AO-M', external_sku_id: null, quantity: 2, unit_price: '150000.00', discount: '0.00', mapping_status: 'mapped', listing_id: null, variant: null, reservation: null }],
        payments: [
            { id: 'p1', kind: 'payment', method: 'cash', amount: '200000.00', reference: null, note: null, paid_at: '2026-11-03T08:00:00+07:00', voided_at: null, void_reason: null },
            { id: 'p2', kind: 'payment', method: 'card', amount: '50000.00', reference: null, note: null, paid_at: '2026-11-03T08:00:00+07:00', voided_at: '2026-11-03T09:00:00+07:00', void_reason: 'nhầm' },
        ],
        ...over,
    }) as unknown as OrderDetail;

describe('sales invoice', () => {
    it('lists lines, totals, valid payments and the amount still due', () => {
        const html = invoiceHtml(order(), 'Shop Hoa', 'k80');
        expect(html).toContain('SO-000012');
        expect(html).toContain('Shop Hoa');
        expect(html).toContain('Áo thun · Size M');
        expect(html).toMatch(/280\.000/);
        expect(html).toContain('Tiền mặt');
        expect(html).not.toContain('Thẻ'); // voided payment is not printed
        expect(html).toMatch(/Còn phải trả[\s\S]*80\.000/);
        expect(html).toContain('80mm');
    });

    it('escapes user text and supports A5', () => {
        const html = invoiceHtml(order(), 'A&B', 'a5');
        expect(html).toContain('Chị &lt;Lan&gt;');
        expect(html).toContain('A&amp;B');
        expect(html).toContain('size: A5');
    });

    it('prints A5 landscape and A4 as a table with signatures, and K57 as a narrow roll', () => {
        const a5 = invoiceHtml(order(), 'Shop', 'a5');
        expect(a5).toContain('size: A5 landscape');
        expect(a5).toContain('<th>Sản phẩm</th>');
        expect(a5).toContain('Người mua hàng');
        expect(invoiceHtml(order(), 'Shop', 'a4')).toContain('size: A4;');
        expect(invoiceHtml(order(), 'Shop', 'k57')).toContain('57mm');
    });

    it('prints several receipts in one document, one per page', () => {
        const html = invoicesHtml([order(), order({ external_order_id: 'SO-000013' })], 'Shop');
        expect(html.match(/<html/g)).toHaveLength(1);
        expect(html.match(/page-break-after/g)).toHaveLength(2);
        expect(html).toContain('SO-000012');
        expect(html).toContain('SO-000013');
    });

    it('labels every manual action, payment method and payment status', () => {
        for (const a of manualActionOrder) expect(actionLabels[a]).toBeTruthy();
        for (const m of ['cash', 'bank_transfer', 'card', 'ewallet', 'cod', 'other']) expect(paymentMethods[m]).toBeTruthy();
        for (const s of ['unpaid', 'partial', 'paid']) expect(paymentStatus[s]).toBeTruthy();
    });
});

describe('deliverySlipHtml', () => {
    it('prints what to collect, no prices, and the signatures', () => {
        const html = deliverySlipHtml(order({ shipping_address: '12 Lê Lợi', carrier: 'GHN', tracking_number: 'GHN123' }), 'Shop A', 'a5');
        expect(html).toContain('PHIẾU GIAO HÀNG');
        expect(html).toContain('THU HỘ (COD)');
        expect(html).toContain('data-cod="80000"');
        expect(html).toContain('GHN123');
        expect(html).toContain('Chị &lt;Lan&gt;');
        expect(html).not.toContain('150.000');
        expect(html).toContain('Người nhận hàng');
    });
    it('says nothing is to be collected on a paid order', () => {
        expect(deliverySlipHtml(order({ paid_amount: '280000.00' }), 'Shop A')).toContain('KHÔNG THU TIỀN');
    });
});

