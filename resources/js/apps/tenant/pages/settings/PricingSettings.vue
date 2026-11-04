<script setup lang="ts">
import SettingsPage from './SettingsPage.vue';
import SettingsSection from './SettingsSection.vue';
import MarketplaceFeeTable from './MarketplaceFeeTable.vue';
import { useSettingsForm } from './useSettingsForm';
import FormField from '@/shared/ui/FormField.vue';
import Select from '@/shared/ui/Select.vue';
import Switch from '@/shared/ui/Switch.vue';
import Badge from '@/shared/ui/Badge.vue';
import { useSettingsStore } from '@/shared/stores/settings';
import { useToast } from '@/shared/composables/useToast';
import { ApiError } from '@/shared/lib/api';
import { computed, ref } from 'vue';

/** Thuế và chính sách giá: two sections saved together (tax + pricing). */
const tax = useSettingsForm('tax', (s) => ({ ...s.tax }));
const pricing = useSettingsForm('pricing', (s) => ({ ...s.pricing }));
const store = useSettingsStore();
const toast = useToast();
const saving = ref(false);
const dirty = computed(() => tax.dirty.value || pricing.dirty.value);
const rates = [0, 5, 8, 10].map((r) => ({ value: String(r), label: r ? `${r}%` : 'Không áp dụng (0%)' }));

async function save() {
    saving.value = true;
    try {
        if (tax.dirty.value) await store.save('tax', { ...tax.form.value, sale_rate: Number(tax.form.value!.sale_rate), purchase_rate: Number(tax.form.value!.purchase_rate) });
        if (pricing.dirty.value) await store.save('pricing', pricing.form.value);
        tax.refresh();
        pricing.refresh();
        toast.success('Đã lưu cấu hình.');
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <SettingsPage title="Thuế và chính sách giá" :saving="saving" :dirty="dirty" :can-save="tax.canSave.value" @save="save" @cancel="(tax.cancel(), pricing.cancel())">
        <template v-if="tax.form.value && pricing.form.value">
            <SettingsSection title="Thuế mặc định" description="Gán sẵn cho sản phẩm mới và dòng đơn nhập mới. Hộ kinh doanh nộp thuế theo phương pháp trực tiếp nên chọn “Giá đã bao gồm thuế”.">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Thuế bán hàng mặc định">
                        <Select :model-value="String(tax.form.value.sale_rate)" :options="rates" data-testid="tax-sale" @update:model-value="(v) => (tax.form.value!.sale_rate = Number(v))" />
                    </FormField>
                    <FormField label="Thuế nhập hàng mặc định">
                        <Select :model-value="String(tax.form.value.purchase_rate)" :options="rates" data-testid="tax-purchase" @update:model-value="(v) => (tax.form.value!.purchase_rate = Number(v))" />
                    </FormField>
                </div>
                <div class="mt-4 flex flex-wrap gap-6 text-sm">
                    <label class="flex items-center gap-2"><input v-model="tax.form.value.prices_include_tax" type="radio" :value="false" /> Giá chưa bao gồm thuế</label>
                    <label class="flex items-center gap-2"><input v-model="tax.form.value.prices_include_tax" type="radio" :value="true" /> Giá đã bao gồm thuế</label>
                </div>
            </SettingsSection>
            <SettingsSection title="Thiết lập giá" description="Giá tự điền khi tạo đơn bán. Nhóm khách hàng có chính sách giá riêng thì dùng giá của nhóm.">
                <FormField label="Giá bán hàng mặc định" class="sm:w-1/2">
                    <Select v-model="pricing.form.value.sale_price" :options="[{ value: 'retail', label: 'Giá bán lẻ' }, { value: 'wholesale', label: 'Giá bán buôn' }]" data-testid="pricing-sale" />
                </FormField>
                <div class="mt-3">
                    <Switch v-model="pricing.form.value.staff_edit_price" label="Cho nhân viên sửa giá và chiết khấu khi bán" hint="Tắt thì chỉ người có quyền “Sửa giá, chiết khấu khi bán” (chủ, quản trị, quản lý) được đổi giá; nhân viên bán theo giá bán lẻ hoặc bán buôn." data-testid="pricing-staff-edit" />
                </div>
            </SettingsSection>
            <SettingsSection title="Chính sách giá" description="Ba mức giá có sẵn trên mỗi phiên bản sản phẩm.">
                <table class="w-full text-sm">
                    <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <tr><th class="px-3 py-2 font-medium">Tên</th><th class="px-3 py-2 font-medium">Mã</th><th class="px-3 py-2 font-medium">Loại giá</th><th class="px-3 py-2 font-medium">Mặc định</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr><td class="px-3 py-2.5">Giá bán lẻ</td><td class="px-3 font-mono text-xs">BANLE</td><td class="px-3">Bán hàng</td><td class="px-3"><Badge v-if="pricing.form.value.sale_price === 'retail'" tone="success">Bán hàng</Badge></td></tr>
                        <tr><td class="px-3 py-2.5">Giá bán buôn</td><td class="px-3 font-mono text-xs">BANBUON</td><td class="px-3">Bán hàng</td><td class="px-3"><Badge v-if="pricing.form.value.sale_price === 'wholesale'" tone="success">Bán hàng</Badge></td></tr>
                        <tr><td class="px-3 py-2.5">Giá vốn</td><td class="px-3 font-mono text-xs">GIAVON</td><td class="px-3">Nhập hàng</td><td class="px-3"><Badge tone="success">Nhập hàng</Badge></td></tr>
                    </tbody>
                </table>
            </SettingsSection>
        </template>
    </SettingsPage>
    <MarketplaceFeeTable />
</template>
