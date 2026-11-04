import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '@/shared/lib/api';

export type PaymentMethodKey = 'cash' | 'bank_transfer' | 'card' | 'cod' | 'ewallet';
export type PrintSize = 'a4' | 'a5' | 'k80' | 'k57';

export interface BankAccount {
    bank: string;
    number: string;
    holder: string;
    is_default?: boolean;
}

export interface CancelReason {
    label: string;
    cancel: boolean;
    return: boolean;
}

/** Business configuration ("Cấu hình"), same shape as TenantSettings on the server (defaults filled in). */
export interface TenantSettingsData {
    name: string;
    timezone: string;
    logo_url: string | null;
    store: { phone: string | null; email: string | null; website: string | null; tax_code: string | null; business_type: string | null; address: string | null; province: string | null; ward: string | null };
    tax: { sale_rate: number; purchase_rate: number; prices_include_tax: boolean };
    pricing: { sale_price: 'retail' | 'wholesale'; staff_edit_price: boolean };
    payments: { methods: Record<PaymentMethodKey, boolean>; default_pos: PaymentMethodKey; default_online: PaymentMethodKey; bank_accounts: BankAccount[] };
    sales: { allow_change_sale_time: boolean; invoice_copies: number; pos_require_cash: boolean; pos_auto_print: boolean; pos_require_shift: boolean };
    reasons: CancelReason[];
    print: { defaults: Record<'sale' | 'purchase' | 'receipt' | 'stocktake' | 'cash', PrintSize>; show_logo: boolean; show_qr: boolean; show_signatures: boolean; footer: string | null };
}

export type SettingsSection = 'store' | 'tax' | 'pricing' | 'payments' | 'sales' | 'reasons' | 'print';

/** Loaded once per business; screens read it to apply the configured defaults and rules. */
export const useSettingsStore = defineStore('settings', () => {
    const data = ref<TenantSettingsData | null>(null);
    let loading: Promise<TenantSettingsData> | null = null;

    async function load(force = false): Promise<TenantSettingsData> {
        if (data.value && !force) return data.value;
        loading ??= api
            .get('/tenant/settings')
            .then((r) => (data.value = r.data.data as TenantSettingsData))
            .finally(() => (loading = null));
        return loading;
    }

    async function save(section: SettingsSection, body: unknown): Promise<TenantSettingsData> {
        const { data: res } = await api.put(`/tenant/settings/${section}`, body);
        return (data.value = res.data);
    }

    function reset() {
        data.value = null;
    }

    return { data, load, save, reset, set: (v: TenantSettingsData) => (data.value = v) };
});
