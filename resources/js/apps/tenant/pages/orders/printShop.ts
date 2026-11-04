import type { TenantSettingsData } from '@/shared/stores/settings';
import type { PrintShop } from './invoice';

/** What Cấu hình says to print on a receipt: shop details, footer, VietQR account, signatures, copies. */
export function printShopFrom(settings: TenantSettingsData | null, fallbackName: string): PrintShop {
    if (!settings) return { name: fallbackName };
    const p = settings.print;
    const s = settings.store;
    const bank = settings.payments.bank_accounts.find((a) => a.is_default) ?? settings.payments.bank_accounts[0] ?? null;
    return {
        name: settings.name || fallbackName,
        logo: p.show_logo ? settings.logo_url : null,
        address: p.show_logo ? [s.address, s.ward, s.province].filter(Boolean).join(', ') || null : null,
        phone: p.show_logo ? s.phone : null,
        taxCode: p.show_logo ? s.tax_code : null,
        footer: p.footer,
        bank: p.show_qr && bank && settings.payments.methods.bank_transfer ? bank : null,
        signatures: p.show_signatures,
        copies: settings.sales.invoice_copies,
    };
}
