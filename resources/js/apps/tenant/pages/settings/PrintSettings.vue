<script setup lang="ts">
import SettingsPage from './SettingsPage.vue';
import SettingsSection from './SettingsSection.vue';
import { useSettingsForm } from './useSettingsForm';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Switch from '@/shared/ui/Switch.vue';
import { cn } from '@/shared/lib/utils';
import { invoiceSizes } from '../orders/invoice';

/** Mẫu in: the business-wide default template for sales invoices and what is printed on them. */
const { form, dirty, saving, canSave, save, cancel } = useSettingsForm('print', (s) => ({ ...(JSON.parse(JSON.stringify(s.print)) as typeof s.print), footer: s.print.footer ?? '' }));
</script>

<template>
    <SettingsPage title="Mẫu in" :saving="saving" :dirty="dirty" :can-save="canSave" @save="save" @cancel="cancel">
        <template v-if="form">
            <SettingsSection title="Khổ in hóa đơn bán hàng" description="Chọn sẵn khi in hóa đơn, cho mọi nhân viên và mọi máy. Khi in vẫn đổi được khổ khác.">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4" data-testid="print-default-sale">
                    <button
                        v-for="s in invoiceSizes"
                        :key="s.value"
                        type="button"
                        :class="cn('rounded-md border px-3 py-3 text-left text-sm hover:bg-muted/40', form.defaults.sale === s.value && 'border-blue-300 bg-blue-50/70')"
                        @click="form.defaults.sale = s.value"
                    >
                        <span class="block font-medium">{{ s.label }}</span>
                        <span class="block text-xs text-muted-foreground">{{ s.hint }}</span>
                    </button>
                </div>
            </SettingsSection>
            <SettingsSection title="Nội dung trên phiếu" description="Lấy từ Thông tin cửa hàng và Thanh toán.">
                <div class="divide-y">
                    <Switch v-model="form.show_logo" label="In logo, địa chỉ, số điện thoại, mã số thuế ở đầu phiếu" />
                    <Switch v-model="form.show_qr" label="In mã VietQR khi hóa đơn còn phải trả" hint="Dùng tài khoản ngân hàng mặc định; mã có sẵn số tiền còn phải trả và mã đơn." data-testid="print-qr" />
                    <Switch v-model="form.show_signatures" label="In ô chữ ký người mua, người bán (khổ A4, A5)" />
                </div>
                <FormField label="Lời chào cuối phiếu" for="pr-footer" class="mt-3"><Input id="pr-footer" v-model="form.footer" maxlength="255" placeholder="Cảm ơn quý khách!" data-testid="print-footer" /></FormField>
            </SettingsSection>
        </template>
    </SettingsPage>
</template>
