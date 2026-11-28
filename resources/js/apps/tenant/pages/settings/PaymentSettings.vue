<script setup lang="ts">
import { computed, ref, type Component } from 'vue';
import { Banknote, CreditCard, Landmark, Plus, QrCode, ShieldCheck, Smartphone, Trash2, Truck, Wallet } from 'lucide-vue-next';
import SettingsPage from './SettingsPage.vue';
import SettingsSection from './SettingsSection.vue';
import { useSettingsForm } from './useSettingsForm';
import { BANKS } from './banks';
import { toneClasses, type SettingsTone } from './catalog';
import { vietQrUrl } from '../orders/invoice';
import Input from '@/shared/ui/Input.vue';
import FormField from '@/shared/ui/FormField.vue';
import Select from '@/shared/ui/Select.vue';
import Switch from '@/shared/ui/Switch.vue';
import { PAYMENT_METHOD_LABELS } from '@/shared/lib/paymentMethods';
import { cn, formatMoney } from '@/shared/lib/utils';
import type { PaymentMethodKey } from '@/shared/stores/settings';

/** Thanh toán (mẫu 28/11): bank accounts as cards with the bank's logo, methods as rows, and what customers see. */
const { form, dirty, saving, error, canSave, save, cancel } = useSettingsForm('payments', (s) => JSON.parse(JSON.stringify(s.payments)) as typeof s.payments);
const methodKeys = Object.keys(PAYMENT_METHOD_LABELS) as PaymentMethodKey[];
const enabled = computed(() => methodKeys.filter((m) => form.value?.methods[m]).map((m) => ({ value: m, label: PAYMENT_METHOD_LABELS[m] })));
const methodInfo: Record<PaymentMethodKey, { icon: Component; tone: SettingsTone; hint: string }> = {
    cash: { icon: Banknote, tone: 'green', hint: 'Tính tiền thừa, đối chiếu két cuối ca' },
    bank_transfer: { icon: QrCode, tone: 'blue', hint: 'Hiện mã VietQR trên màn hình và hóa đơn' },
    card: { icon: CreditCard, tone: 'violet', hint: 'Ghi nhận thanh toán qua máy POS ngân hàng' },
    cod: { icon: Truck, tone: 'amber', hint: 'Hãng vận chuyển thu tiền khi giao' },
    ewallet: { icon: Smartphone, tone: 'rose', hint: 'Ghi nhận thanh toán qua ví của khách' },
};
/** Approval of large payment vouchers: on with an amount, off at 0. */
const approvalOn = computed({
    get: () => (form.value?.approval_threshold ?? 0) > 0,
    set: (on: boolean) => form.value && (form.value.approval_threshold = on ? form.value.approval_threshold || 5000000 : 0),
});
const bankOptions = BANKS.map((b) => ({ value: b.code, label: `${b.code} · ${b.name}` }));
const bankName = (code: string) => BANKS.find((b) => b.code === code)?.name ?? code;
// Bank logos from VietQR (same codes as the QR); a broken one falls back to an icon.
const broken = ref(new Set<string>());
const bankLogo = (code: string) => `https://api.vietqr.io/img/${encodeURIComponent(code)}.png`;

const main = computed(() => form.value?.bank_accounts.find((a) => a.is_default) ?? form.value?.bank_accounts[0] ?? null);
const sampleAmount = 1250000;
const sampleCode = 'SON50892';
const qrBroken = ref(false);
const qr = computed(() => (main.value && main.value.number ? vietQrUrl(main.value, sampleAmount, sampleCode) : null));

function addAccount() {
    form.value!.bank_accounts.push({ bank: 'MB', number: '', holder: '', is_default: form.value!.bank_accounts.length === 0 });
}
function setDefault(i: number) {
    form.value!.bank_accounts.forEach((a, j) => (a.is_default = i === j));
}
function removeAccount(i: number) {
    const [gone] = form.value!.bank_accounts.splice(i, 1);
    if (gone?.is_default && form.value!.bank_accounts[0]) form.value!.bank_accounts[0].is_default = true;
}
const field = 'h-11 w-full rounded-[10px] border border-slate-200 bg-white px-3.5 text-[15px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-200';
</script>

<template>
    <SettingsPage title="Thanh toán" :saving="saving" :dirty="dirty" :can-save="canSave" @save="save" @cancel="cancel">
        <template v-if="form">
            <SettingsSection title="Tài khoản ngân hàng" description="Hóa đơn còn phải trả in mã VietQR của tài khoản mặc định, có sẵn số tiền và mã đơn." :icon="Landmark">
                <div class="space-y-3" data-testid="bank-accounts">
                    <div
                        v-for="(a, i) in form.bank_accounts"
                        :key="i"
                        :class="cn('rounded-[12px] border p-4', a.is_default ? 'border-blue-200 bg-blue-50/40' : 'border-slate-200')"
                    >
                        <div class="flex flex-wrap items-center gap-3.5">
                            <span class="grid h-11 w-16 shrink-0 place-items-center overflow-hidden rounded-[10px] border border-slate-200 bg-white">
                                <img v-if="!broken.has(a.bank)" :src="bankLogo(a.bank)" :alt="bankName(a.bank)" class="max-h-8 max-w-14 object-contain" @error="broken.add(a.bank)" />
                                <Landmark v-else class="size-5 text-slate-400" />
                            </span>
                            <div class="min-w-[10rem] flex-1">
                                <p class="text-[15px] font-semibold text-slate-900">{{ bankName(a.bank) }}</p>
                                <p class="truncate text-[14px] text-slate-500">{{ a.number || 'Số tài khoản' }} · {{ a.holder || 'Chủ tài khoản' }}</p>
                            </div>
                            <span v-if="a.is_default" class="shrink-0 rounded-md bg-blue-100 px-2 py-0.5 text-[13px] font-medium text-blue-700">Mặc định · In VietQR</span>
                            <button v-else-if="canSave" type="button" class="text-[14px] font-medium text-blue-600 hover:underline" @click="setDefault(i)">Đặt mặc định</button>
                            <button v-if="canSave" type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600" aria-label="Xóa tài khoản" @click="removeAccount(i)"><Trash2 class="size-4" /></button>
                        </div>
                        <div class="mt-4 grid gap-3 sm:grid-cols-[13rem_1fr_1fr]">
                            <FormField label="Ngân hàng"><Select v-model="a.bank" :options="bankOptions" /></FormField>
                            <FormField label="Số tài khoản" :error="error?.field(`bank_accounts.${i}.number`)">
                                <input v-model="a.number" inputmode="numeric" :class="field" :data-testid="`bank-number-${i}`" />
                            </FormField>
                            <FormField label="Chủ tài khoản" :error="error?.field(`bank_accounts.${i}.holder`)">
                                <input v-model="a.holder" :class="cn(field, 'uppercase')" :data-testid="`bank-holder-${i}`" />
                            </FormField>
                        </div>
                    </div>
                    <button
                        v-if="canSave && form.bank_accounts.length < 5"
                        type="button"
                        class="flex h-12 w-full items-center justify-center gap-2 rounded-[12px] border border-dashed border-slate-300 text-[15px] font-medium text-blue-600 hover:border-blue-300 hover:bg-blue-50/50"
                        data-testid="add-bank"
                        @click="addAccount"
                    >
                        <Plus class="size-4" /> Thêm tài khoản
                    </button>
                </div>
                <p v-if="error?.field('bank_accounts')" class="mt-2 text-[13px] text-red-600">{{ error.field('bank_accounts') }}</p>
            </SettingsSection>
            <SettingsSection title="Phương thức thanh toán" description="Cách đang bật mới hiện khi thu tiền đơn hàng và bán tại quầy." :icon="Wallet" tone="green">
                <div class="divide-y divide-slate-100">
                    <div v-for="m in methodKeys" :key="m" class="flex items-center gap-4">
                        <span :class="cn('grid size-10 shrink-0 place-items-center rounded-[11px]', toneClasses[methodInfo[m].tone])"><component :is="methodInfo[m].icon" class="size-5" :stroke-width="1.9" /></span>
                        <Switch v-model="form.methods[m]" :label="PAYMENT_METHOD_LABELS[m] + (form.default_pos === m ? ' · Chọn sẵn' : '')" :hint="methodInfo[m].hint" class="flex-1" :data-testid="`method-${m}`" />
                    </div>
                </div>
                <div class="mt-4 grid gap-5 border-t border-slate-100 pt-5 sm:grid-cols-2">
                    <FormField label="Chọn sẵn khi bán tại quầy" :error="error?.field('default_pos')"><Select v-model="form.default_pos" :options="enabled" data-testid="default-pos" /></FormField>
                    <FormField label="Chọn sẵn khi tạo đơn, bán online" :error="error?.field('default_online')"><Select v-model="form.default_online" :options="enabled" data-testid="default-online" /></FormField>
                </div>
            </SettingsSection>
            <SettingsSection title="Duyệt phiếu chi lớn" description="Phiếu chi từ mức này trở lên, do người không có quyền duyệt lập, phải được duyệt mới trừ quỹ và tính vào báo cáo." :icon="ShieldCheck" tone="amber">
                <div class="grid items-end gap-5 sm:grid-cols-2">
                    <FormField label="Duyệt từ" :error="error?.field('approval_threshold')">
                        <div class="relative">
                            <Input
                                :model-value="form.approval_threshold ? form.approval_threshold.toLocaleString('vi-VN') : ''"
                                inputmode="numeric"
                                class="pr-9 tabular-nums"
                                :disabled="!approvalOn"
                                placeholder="Đang tắt"
                                data-testid="approval-threshold"
                                @input="form.approval_threshold = Number(($event.target as HTMLInputElement).value.replace(/\D/g, '').slice(0, 11)) || 0"
                            />
                            <span class="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-[14px] text-slate-400">đ</span>
                        </div>
                    </FormField>
                    <Switch v-model="approvalOn" label="Bật duyệt phiếu chi" class="py-2.5" data-testid="approval-on" />
                </div>
                <p class="mt-3 text-[13px] text-slate-500">Người duyệt: Chủ cửa hàng, Quản trị viên.</p>
            </SettingsSection>
        </template>
        <template #aside>
            <div v-if="form" class="rounded-[14px] border border-slate-200/80 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.04)]" data-testid="qr-preview">
                <p class="text-[12px] font-semibold uppercase tracking-wider text-slate-400">Khách thấy trên hóa đơn</p>
                <div v-if="main && qr" class="mt-4 rounded-[12px] bg-slate-50 p-4 text-center">
                    <img v-if="!qrBroken" :src="qr" alt="Mã VietQR mẫu" class="mx-auto size-40 rounded-lg bg-white object-contain p-1.5" @error="qrBroken = true" />
                    <span v-else class="mx-auto grid size-40 place-items-center rounded-lg bg-white text-slate-300"><QrCode class="size-16" /></span>
                    <p class="mt-3 text-[13px] text-slate-500">Quét mã để chuyển khoản</p>
                    <p class="text-[22px] font-bold text-slate-900">{{ formatMoney(sampleAmount) }}</p>
                    <p class="text-[13px] text-slate-600">{{ bankName(main.bank) }} · {{ main.number }}</p>
                    <p class="text-[13px] uppercase text-slate-600">{{ main.holder || 'Chủ tài khoản' }} · ND: {{ sampleCode }}</p>
                </div>
                <div v-else class="mt-4 grid place-items-center rounded-[12px] border border-dashed border-slate-300 px-4 py-10 text-center text-[14px] text-slate-500">
                    <QrCode class="mb-2 size-8 text-slate-300" />
                    Thêm tài khoản ngân hàng để hóa đơn có mã VietQR.
                </div>
                <p class="mt-3 text-[13px] text-slate-500">Mã có sẵn số tiền và mã đơn, khách quét là đúng. Đây là ví dụ, số tiền thật lấy theo từng đơn.</p>
            </div>
        </template>
    </SettingsPage>
</template>
