<script setup lang="ts">
import { computed } from 'vue';
import { Plus, Trash2 } from 'lucide-vue-next';
import SettingsPage from './SettingsPage.vue';
import SettingsSection from './SettingsSection.vue';
import { useSettingsForm } from './useSettingsForm';
import { BANKS } from './banks';
import Button from '@/shared/ui/Button.vue';
import Input from '@/shared/ui/Input.vue';
import FormField from '@/shared/ui/FormField.vue';
import Select from '@/shared/ui/Select.vue';
import Switch from '@/shared/ui/Switch.vue';
import { PAYMENT_METHOD_LABELS } from '@/shared/lib/paymentMethods';
import type { PaymentMethodKey } from '@/shared/stores/settings';

/** Thanh toán: bank accounts (VietQR on invoices still owed), which methods are offered and the defaults. */
const { form, dirty, saving, error, canSave, save, cancel } = useSettingsForm('payments', (s) => JSON.parse(JSON.stringify(s.payments)) as typeof s.payments);
const methodKeys = Object.keys(PAYMENT_METHOD_LABELS) as PaymentMethodKey[];
const enabled = computed(() => methodKeys.filter((m) => form.value?.methods[m]).map((m) => ({ value: m, label: PAYMENT_METHOD_LABELS[m] })));
/** Approval of large payment vouchers: on with an amount, off at 0. */
const approvalOn = computed({
    get: () => (form.value?.approval_threshold ?? 0) > 0,
    set: (on: boolean) => form.value && (form.value.approval_threshold = on ? form.value.approval_threshold || 5000000 : 0),
});
const bankOptions = BANKS.map((b) => ({ value: b.code, label: `${b.code} · ${b.name}` }));

function addAccount() {
    form.value!.bank_accounts.push({ bank: 'MB', number: '', holder: '', is_default: form.value!.bank_accounts.length === 0 });
}
function setDefault(i: number) {
    form.value!.bank_accounts.forEach((a, j) => (a.is_default = i === j));
}
</script>

<template>
    <SettingsPage title="Thanh toán" :saving="saving" :dirty="dirty" :can-save="canSave" @save="save" @cancel="cancel">
        <template v-if="form">
            <SettingsSection title="Tài khoản ngân hàng" description="Dùng cho chuyển khoản. Hóa đơn còn phải trả sẽ in mã VietQR của tài khoản mặc định, có sẵn số tiền và mã đơn để khách quét.">
                <div v-if="form.bank_accounts.length" class="space-y-3" data-testid="bank-accounts">
                    <div v-for="(a, i) in form.bank_accounts" :key="i" class="grid items-end gap-3 rounded-md border p-3 sm:grid-cols-[12rem_1fr_1fr_auto]">
                        <FormField label="Ngân hàng"><Select v-model="a.bank" :options="bankOptions" /></FormField>
                        <FormField label="Số tài khoản" :error="error?.field(`bank_accounts.${i}.number`)">
                            <input v-model="a.number" inputmode="numeric" class="h-9 w-full rounded-md border border-input bg-card px-3 text-sm" :data-testid="`bank-number-${i}`" />
                        </FormField>
                        <FormField label="Chủ tài khoản" :error="error?.field(`bank_accounts.${i}.holder`)">
                            <input v-model="a.holder" class="h-9 w-full rounded-md border border-input bg-card px-3 text-sm uppercase" :data-testid="`bank-holder-${i}`" />
                        </FormField>
                        <div class="flex items-center gap-2 pb-1.5">
                            <label class="flex items-center gap-1.5 whitespace-nowrap text-xs"><input type="radio" :checked="a.is_default" @change="setDefault(i)" /> Mặc định</label>
                            <button type="button" class="rounded p-1 text-muted-foreground hover:text-destructive" aria-label="Xóa tài khoản" @click="form.bank_accounts.splice(i, 1)"><Trash2 class="size-4" /></button>
                        </div>
                    </div>
                </div>
                <p v-else class="text-sm text-muted-foreground">Chưa có tài khoản nào.</p>
                <Button v-if="form.bank_accounts.length < 5" variant="ghost" size="sm" class="mt-2 text-primary" data-testid="add-bank" @click="addAccount"><Plus /> Thêm tài khoản</Button>
                <p v-if="error?.field('bank_accounts')" class="text-xs text-destructive">{{ error.field('bank_accounts') }}</p>
            </SettingsSection>
            <SettingsSection title="Phương thức thanh toán" description="Phương thức đang bật mới hiện khi thu tiền đơn hàng và bán tại quầy.">
                <div class="divide-y">
                    <Switch v-for="m in methodKeys" :key="m" v-model="form.methods[m]" :label="PAYMENT_METHOD_LABELS[m]" :data-testid="`method-${m}`" />
                </div>
            </SettingsSection>
            <SettingsSection title="Phương thức mặc định" description="Chọn sẵn khi thu tiền.">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Bán tại quầy" :error="error?.field('default_pos')"><Select v-model="form.default_pos" :options="enabled" data-testid="default-pos" /></FormField>
                    <FormField label="Tạo đơn, bán online" :error="error?.field('default_online')"><Select v-model="form.default_online" :options="enabled" data-testid="default-online" /></FormField>
                </div>
            </SettingsSection>
            <SettingsSection title="Duyệt phiếu chi" description="Phiếu chi từ mức tiền này trở lên, do người không có quyền &quot;Duyệt phiếu chi lớn&quot; lập, phải được duyệt rồi mới trừ quỹ và tính vào báo cáo. Chủ cửa hàng và quản trị có quyền duyệt.">
                <Switch v-model="approvalOn" label="Bật duyệt phiếu chi lớn" data-testid="approval-on" />
                <FormField v-if="approvalOn" label="Từ số tiền" class="mt-3 max-w-xs" :error="error?.field('approval_threshold')">
                    <Input
                        :model-value="form.approval_threshold ? form.approval_threshold.toLocaleString('vi-VN') : ''"
                        inputmode="numeric"
                        class="text-right tabular-nums"
                        data-testid="approval-threshold"
                        @input="form.approval_threshold = Number(($event.target as HTMLInputElement).value.replace(/\D/g, '').slice(0, 11)) || 0"
                    />
                </FormField>
            </SettingsSection>
        </template>
    </SettingsPage>
</template>
