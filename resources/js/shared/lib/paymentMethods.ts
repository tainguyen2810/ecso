import type { PaymentMethodKey, TenantSettingsData } from '@/shared/stores/settings';

/** Methods that Cấu hình → Thanh toán can switch on or off ("Khác" is always offered). */
export const PAYMENT_METHOD_LABELS: Record<PaymentMethodKey, string> = {
    cash: 'Tiền mặt',
    bank_transfer: 'Chuyển khoản',
    card: 'Quẹt thẻ',
    cod: 'Thu hộ (COD)',
    ewallet: 'Ví điện tử (MoMo, ZaloPay…)',
};

/** Keeps the methods switched on, in the given order; before settings load, everything is offered. */
export function enabledMethods<T extends { value: string }>(options: T[], settings: TenantSettingsData | null): T[] {
    if (!settings) return options;
    return options.filter((o) => !(o.value in settings.payments.methods) || settings.payments.methods[o.value as PaymentMethodKey]);
}
