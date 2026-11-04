import { describe, expect, it } from 'vitest';
import { invoiceHtml, invoicesHtml, vietQrUrl } from './invoice';
import { printShopFrom } from './printShop';
import type { TenantSettingsData } from '@/shared/stores/settings';
import type { OrderDetail } from '@/shared/types';

const settings = {
    name: 'Tin học ITSO',
    timezone: 'Asia/Ho_Chi_Minh',
    logo_url: '/storage/logo.png',
    store: { phone: '0909123456', email: null, website: null, tax_code: '0312345678', business_type: null, address: '106 Đình Nghi Xuân', province: 'TP HCM', ward: null },
    tax: { sale_rate: 0, purchase_rate: 0, prices_include_tax: true },
    pricing: { sale_price: 'retail', staff_edit_price: true },
    payments: { methods: { cash: true, bank_transfer: true, card: true, cod: true, ewallet: false }, default_pos: 'cash', default_online: 'bank_transfer', bank_accounts: [{ bank: 'MB', number: '0909123456', holder: 'ITSO', is_default: true }] },
    sales: { allow_change_sale_time: false, invoice_copies: 2, pos_require_cash: false, pos_auto_print: false, pos_require_shift: true },
    reasons: [],
    print: { defaults: { sale: 'a5', purchase: 'a4', receipt: 'a4', stocktake: 'a4', cash: 'a5' }, show_logo: true, show_qr: true, show_signatures: false, footer: 'Đổi trả 7 ngày' },
} as TenantSettingsData;

const order = (paid: string) =>
    ({ external_order_id: 'SO-000001', placed_at: '2026-11-04T10:00:00+07:00', total: '500000', subtotal: '500000', shipping_fee: '0', discount: '0', paid_amount: paid, buyer_name: 'An', items: [{ name: 'Áo', quantity: 1, unit_price: '500000', discount: '0' }], payments: [] }) as unknown as OrderDetail;

describe('printed receipt from Cấu hình', () => {
    it('prints logo, address, tax code, footer and a VietQR for what is still owed', () => {
        const html = invoiceHtml(order('100000'), printShopFrom(settings, 'x'), 'a5');
        expect(html).toContain('/storage/logo.png');
        expect(html).toContain('MST: 0312345678');
        expect(html).toContain('Đổi trả 7 ngày');
        expect(html).toContain(vietQrUrl(settings.payments.bank_accounts[0]!, 400000, 'SO-000001').replace(/&/g, '&amp;'));
        expect(html).not.toContain('Người mua hàng');
    });
    it('prints no QR when paid, and as many copies as configured', () => {
        expect(invoiceHtml(order('500000'), printShopFrom(settings, 'x'), 'k80')).not.toContain('img.vietqr.io');
        expect(invoicesHtml([order('0')], printShopFrom(settings, 'x'), 'k80').match(/page-break-after/g)).toHaveLength(2);
    });
});
