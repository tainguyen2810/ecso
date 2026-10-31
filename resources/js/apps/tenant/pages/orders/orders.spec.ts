import { describe, expect, it } from 'vitest';
import { deadlineLabel, orderFlags, orderStatus, orderTabs } from './labels';
import { packingSlipDocument, type PackingSlipData } from './packingSlip';

const slip = (over: Partial<PackingSlipData> = {}): PackingSlipData => ({
    id: '01A',
    external_order_id: 'FK-1001',
    shop: 'Shop A',
    marketplace: 'fake',
    placed_at: '2026-10-31T08:00:00+07:00',
    ship_by_at: '2026-11-01T08:00:00+07:00',
    buyer_name: 'Nguyễn Văn A',
    buyer_phone: '*******789',
    shipping_address: '1 Lê Lợi, Quận 1, TP.HCM',
    carrier: 'GHN',
    tracking_number: 'GHN123',
    is_cod: true,
    total: '250000.00',
    note: null,
    warehouse: 'Kho chính',
    items: [
        { sku: 'AO-001-M', name: 'Áo thun', variant_name: 'Size M', quantity: 2 },
        { sku: null, name: 'Quà tặng', variant_name: null, quantity: 1 },
    ],
    ...over,
});

describe('order labels', () => {
    it('labels every internal status and tab', () => {
        for (const s of ['UNPAID', 'PENDING_CONFIRMATION', 'READY_TO_PACK', 'PACKED', 'HANDED_OVER', 'IN_TRANSIT', 'DELIVERED', 'COMPLETED', 'CANCELLED', 'FAILED_DELIVERY', 'RETURN_REQUESTED', 'RETURNED', 'UNKNOWN_STATUS']) {
            expect(orderStatus[s]?.label).toBeTruthy();
        }
        expect(orderTabs.map((t) => t.value)).toEqual(['all', 'new', 'to_confirm', 'to_pack', 'to_handover', 'shipping', 'done', 'cancelled', 'returns', 'unknown']);
        expect(orderFlags.map((f) => f.value)).toContain('overdue');
    });

    it('formats ship-by deadlines relative to now', () => {
        const now = new Date('2026-10-31T10:00:00Z');
        expect(deadlineLabel('2026-10-31T13:00:00Z', now)).toBe('còn 3 giờ');
        expect(deadlineLabel('2026-10-31T09:30:00Z', now)).toBe('quá 30 phút');
        expect(deadlineLabel('2026-11-04T10:00:00Z', now)).toBe('còn 4 ngày');
        expect(deadlineLabel(null, now)).toBeNull();
    });
});

describe('packing slip document', () => {
    it('renders one page per order with item rows and total quantity', () => {
        const doc = packingSlipDocument([slip(), slip({ external_order_id: 'FK-1002', is_cod: false })]);
        const content = doc.content as Record<string, unknown>[];
        const json = JSON.stringify(content);

        expect(json).toContain('Mã đơn: FK-1001');
        expect(json).toContain('Mã đơn: FK-1002');
        expect(content.filter((c) => (c as { pageBreak?: string }).pageBreak === 'before')).toHaveLength(1);
        expect(json).toContain('Áo thun — Size M');
        expect(json).toContain('(chưa map)');
        expect(json).toContain('"text":"3"');
        expect(json).toMatch(/Thu hộ \(COD\): 250\.000/);
        expect(json).toContain('Đã thanh toán trên sàn');
        expect(doc.defaultStyle).toMatchObject({ font: 'Roboto' });
    });

    it('shows the masked phone only (no raw phone in the slip data)', () => {
        const json = JSON.stringify(packingSlipDocument([slip()]));
        expect(json).toContain('*******789');
    });
});
