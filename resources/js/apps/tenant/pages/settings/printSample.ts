import type { OrderDetail } from '@/shared/types';

/** A made-up sale for the Mẫu in preview and "In thử" (never saved). */
export function sampleReceipt(): OrderDetail {
    const items = [
        { name: 'Chuột không dây M331', quantity: 1, unit_price: 390000 },
        { name: 'Bàn phím cơ 87 phím', quantity: 1, unit_price: 760000 },
        { name: 'Lót chuột lớn 80x30', quantity: 2, unit_price: 50000 },
    ];
    const total = items.reduce((s, i) => s + i.quantity * i.unit_price, 0);
    return {
        code: 'SON50892',
        external_order_id: 'SON50892',
        placed_at: new Date().toISOString(),
        channel: null,
        buyer_name: 'Chị Lan',
        buyer_phone: '0912 *** 678',
        shipping_address: null,
        buyer_note: null,
        items: items.map((i) => ({ name: i.name, variant_name: null, seller_sku: null, variant: null, quantity: i.quantity, unit_price: String(i.unit_price), discount: '0' })),
        subtotal: String(total),
        shipping_fee: '0',
        discount: '0',
        tax_amount: '0',
        tax_rate: '0',
        prices_include_tax: true,
        total: String(total),
        paid_amount: '0',
        payments: [],
    } as unknown as OrderDetail;
}
