import { describe, expect, it } from 'vitest';
import { purchaseOrderHtml } from './printOrder';
import type { PurchaseOrderData } from '@/shared/types';

describe('purchaseOrderHtml', () => {
    it('prints lines with VAT, supplier details and freight owed to the supplier', () => {
        const po = {
            id: 'p', number: 'PO-000007', status: 'ordered', expected_at: '2026-11-10', ordered_at: '2026-11-03T09:00:00+07:00', received_at: null, closed_at: null,
            supplier: { id: 's', code: 'NCC-000001', name: 'Xưởng <May>', tax_code: '0312345678', phone: '0283', address: 'KCN Tân Bình', contact_name: 'Anh Long' },
            warehouse: { id: 'w', code: 'MAIN', name: 'Kho chính' },
            subtotal: '2000000.00', tax_amount: '180000.00', extra_cost: '50000.00', extra_cost_note: 'Phí giao', extra_cost_to_supplier: true, vat_in_cost: false,
            total: '2230000.00', received_value: '0', received_tax: '0', received_extra_cost: '0', returned_value: '0', paid_amount: '0', balance_due: '0',
            note: null, editable: true, created_by: 'Chủ', created_at: null, total_quantity: 30, received_quantity: 0,
            lines: [
                { variant: { id: 'v1', sku: 'AO-01', name: 'Áo' }, quantity: 10, received_qty: 0, returned_qty: 0, remaining: 10, unit_cost: '100000.0000', tax_rate: '10.00', landed_unit_cost: '0' },
                { variant: { id: 'v2', sku: 'QUAN-01', name: 'Quần' }, quantity: 20, received_qty: 0, returned_qty: 0, remaining: 20, unit_cost: '50000.0000', tax_rate: '8.00', landed_unit_cost: '0' },
            ],
        } as PurchaseOrderData;
        const html = purchaseOrderHtml(po, { name: 'Cửa hàng Mẫu' });
        expect(html).toContain('ĐƠN ĐẶT HÀNG');
        expect(html).toContain('Xưởng &lt;May&gt;');
        expect(html).toContain('MST: 0312345678');
        expect(html).toContain('8%');
        expect(html).toContain('Phí giao');
        expect(html).toMatch(/2\.230\.000/);
    });
});
