<script setup lang="ts">
import { computed, ref } from 'vue';
import { Plus, X } from 'lucide-vue-next';
import SettingsPage from './SettingsPage.vue';
import SettingsSection from './SettingsSection.vue';
import { useSettingsForm } from './useSettingsForm';
import Button from '@/shared/ui/Button.vue';
import FormField from '@/shared/ui/FormField.vue';
import Select from '@/shared/ui/Select.vue';
import Switch from '@/shared/ui/Switch.vue';
import { useSettingsStore } from '@/shared/stores/settings';
import { useToast } from '@/shared/composables/useToast';
import { ApiError } from '@/shared/lib/api';

/** Cấu hình bán hàng: sale rules, the counter (POS) and the cancel / return reasons, saved together. */
const sales = useSettingsForm('sales', (s) => ({ ...s.sales }));
const reasons = useSettingsForm('reasons', (s) => ({ reasons: s.reasons.map((r) => ({ ...r })) }));
const store = useSettingsStore();
const toast = useToast();
const saving = ref(false);
const error = ref<ApiError | null>(null);
const dirty = computed(() => sales.dirty.value || reasons.dirty.value);

async function save() {
    saving.value = true;
    error.value = null;
    try {
        if (sales.dirty.value) await store.save('sales', { ...sales.form.value, invoice_copies: Number(sales.form.value!.invoice_copies) });
        if (reasons.dirty.value) await store.save('reasons', { reasons: reasons.form.value!.reasons.filter((r) => r.label.trim()) });
        sales.refresh();
        reasons.refresh();
        toast.success('Đã lưu cấu hình.');
    } catch (e) {
        error.value = e as ApiError;
        toast.error(error.value.message);
    } finally {
        saving.value = false;
    }
}
function cancel() {
    sales.cancel();
    reasons.cancel();
}
</script>

<template>
    <SettingsPage title="Cấu hình bán hàng" :saving="saving" :dirty="dirty" :can-save="sales.canSave.value" @save="save" @cancel="cancel">
        <template v-if="sales.form.value && reasons.form.value">
            <SettingsSection title="Quản lý bán hàng" description="Áp dụng cho đơn của kênh bán riêng (tạo đơn và bán tại quầy).">
                <div class="divide-y">
                    <Switch v-model="sales.form.value.allow_change_sale_time" label="Cho phép sửa thời gian bán" hint="Hiện ô ngày giờ bán khi tạo đơn, dùng khi nhập lại đơn đã bán hôm trước. Không chọn được thời điểm trong tương lai." data-testid="sales-change-time" />
                </div>
                <FormField label="Số liên khi in hóa đơn" class="mt-3 sm:w-1/2">
                    <Select :model-value="String(sales.form.value.invoice_copies)" :options="[1, 2, 3].map((n) => ({ value: String(n), label: `${n} liên` }))" data-testid="sales-copies" @update:model-value="(v) => (sales.form.value!.invoice_copies = Number(v))" />
                </FormField>
            </SettingsSection>
            <SettingsSection title="Bán tại quầy" description="Áp dụng riêng cho màn hình bán tại quầy.">
                <div class="divide-y">
                    <Switch v-model="sales.form.value.pos_require_cash" label="Bắt buộc nhập tiền khách đưa khi trả tiền mặt" hint="Để tính tiền thừa và đối chiếu két cuối ca." data-testid="pos-require-cash" />
                    <Switch v-model="sales.form.value.pos_auto_print" label="Tự in hóa đơn sau khi thanh toán" hint="In theo khổ mặc định ở Mẫu in." data-testid="pos-auto-print" />
                </div>
            </SettingsSection>
            <SettingsSection id="reasons" title="Lý do hủy, trả hàng" description="Hiện trong hộp chọn khi hủy đơn hoặc nhận hàng khách trả. Vẫn gõ được lý do khác.">
                <table class="w-full text-sm" data-testid="reasons-table">
                    <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <tr><th class="px-3 py-2 font-medium">Lý do</th><th class="px-3 py-2 text-center font-medium">Hủy đơn</th><th class="px-3 py-2 text-center font-medium">Trả hàng</th><th class="w-10" /></tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(r, i) in reasons.form.value.reasons" :key="i">
                            <td class="px-3 py-2"><input v-model="r.label" class="h-8 w-full rounded-md border border-input bg-card px-2 text-sm" :data-testid="`reason-${i}`" /></td>
                            <td class="px-3 text-center"><input v-model="r.cancel" type="checkbox" class="size-4" :aria-label="`${r.label}: hủy đơn`" /></td>
                            <td class="px-3 text-center"><input v-model="r.return" type="checkbox" class="size-4" :aria-label="`${r.label}: trả hàng`" /></td>
                            <td class="px-2"><button type="button" class="rounded p-1 text-muted-foreground hover:text-destructive" aria-label="Xóa lý do" @click="reasons.form.value.reasons.splice(i, 1)"><X class="size-4" /></button></td>
                        </tr>
                    </tbody>
                </table>
                <Button variant="ghost" size="sm" class="mt-2 text-primary" data-testid="add-reason" @click="reasons.form.value.reasons.push({ label: '', cancel: true, return: true })"><Plus /> Thêm lý do</Button>
            </SettingsSection>
        </template>
    </SettingsPage>
</template>
