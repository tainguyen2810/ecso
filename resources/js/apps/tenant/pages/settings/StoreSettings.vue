<script setup lang="ts">
import { computed, ref } from 'vue';
import { ImagePlus, Trash2 } from 'lucide-vue-next';
import SettingsPage from './SettingsPage.vue';
import SettingsSection from './SettingsSection.vue';
import { useSettingsForm } from './useSettingsForm';
import Button from '@/shared/ui/Button.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import AddressPicker from '../../components/AddressPicker.vue';
import type { AddressParts } from '@/shared/lib/geo';
import { api, ApiError } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';

/** Thông tin cửa hàng: printed at the top of invoices and on delivery notes. */
const { form, dirty, saving, error, canSave, save, cancel, store } = useSettingsForm('store', (s) => ({ name: s.name, timezone: s.timezone, ...(Object.fromEntries(Object.entries(s.store).map(([k, v]) => [k, v ?? ''])) as Record<keyof typeof s.store, string>) }));
const toast = useToast();
// Store address = pickup address for carriers: province → ward → street ("address" holds the street part).
const addr = computed<AddressParts>({
    get: () => ({ street: form.value?.address ?? '', province_code: form.value?.province_code ?? '', ward_code: form.value?.ward_code ?? '' }),
    set: (v) => form.value && Object.assign(form.value, { address: v.street, province_code: v.province_code, ward_code: v.ward_code }),
});
// Typed before standard addresses: shown with a suggestion until a province and ward are picked.
const legacy = computed(() => (form.value && !form.value.province_code && (form.value.ward || form.value.province) ? [form.value.address, form.value.ward, form.value.province].filter(Boolean).join(', ') : null));
const uploading = ref(false);
const timezones = ['Asia/Ho_Chi_Minh', 'Asia/Bangkok', 'Asia/Singapore', 'Asia/Jakarta', 'Asia/Manila', 'UTC'].map((z) => ({ value: z, label: z === 'Asia/Ho_Chi_Minh' ? '(GMT+7) Hà Nội, TP Hồ Chí Minh' : z }));

async function upload(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    uploading.value = true;
    try {
        const body = new FormData();
        body.append('logo', file);
        store.set((await api.post('/tenant/logo', body)).data.data);
        toast.success('Đã cập nhật logo.');
    } catch (err) {
        toast.error((err as ApiError).field('logo') ?? (err as ApiError).message);
    } finally {
        uploading.value = false;
        (e.target as HTMLInputElement).value = '';
    }
}
async function removeLogo() {
    store.set((await api.delete('/tenant/logo')).data.data);
}
</script>

<template>
    <SettingsPage title="Thông tin cửa hàng" :saving="saving" :dirty="dirty" :can-save="canSave" @save="save" @cancel="cancel">
        <template v-if="form">
            <SettingsSection title="Thông tin liên hệ" description="Dùng trên hóa đơn, phiếu in và để khách liên hệ với cửa hàng.">
                <div class="mb-5 flex items-center gap-4">
                    <img v-if="store.data?.logo_url" :src="store.data.logo_url" alt="Logo" class="h-16 max-w-40 rounded-lg border object-contain p-1" data-testid="store-logo" />
                    <span v-else class="grid size-16 place-items-center rounded-lg border-2 border-dashed text-muted-foreground"><ImagePlus class="size-6" /></span>
                    <div class="text-sm">
                        <label v-if="canSave" class="cursor-pointer font-medium text-primary hover:underline">
                            {{ uploading ? 'Đang tải lên…' : store.data?.logo_url ? 'Đổi logo' : 'Tải lên logo cửa hàng' }}
                            <input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" data-testid="logo-input" @change="upload" />
                        </label>
                        <p class="text-xs text-muted-foreground">PNG, JPG, WEBP tối đa 2MB. In ở đầu hóa đơn.</p>
                    </div>
                    <Button v-if="canSave && store.data?.logo_url" variant="ghost" size="sm" class="ml-auto text-destructive" @click="removeLogo"><Trash2 /> Xóa logo</Button>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Tên cửa hàng" for="st-name" :error="error?.field('name')"><Input id="st-name" v-model="form.name" data-testid="store-name" /></FormField>
                    <FormField label="Số điện thoại" for="st-phone" :error="error?.field('phone')"><Input id="st-phone" v-model="form.phone" data-testid="store-phone" /></FormField>
                    <FormField label="Email" for="st-email" :error="error?.field('email')"><Input id="st-email" v-model="form.email" type="email" /></FormField>
                    <FormField label="Lĩnh vực kinh doanh" for="st-type"><Input id="st-type" v-model="form.business_type" placeholder="Máy tính, linh kiện…" /></FormField>
                    <FormField label="Website" for="st-web"><Input id="st-web" v-model="form.website" /></FormField>
                    <FormField label="Mã số thuế" for="st-tax" :error="error?.field('tax_code')"><Input id="st-tax" v-model="form.tax_code" data-testid="store-tax-code" /></FormField>
                </div>
            </SettingsSection>
            <SettingsSection title="Địa chỉ cửa hàng" description="In trên hóa đơn và phiếu giao hàng.">
                <AddressPicker
                    v-model="addr"
                    id-prefix="st-addr"
                    :quick="false"
                    :legacy="legacy"
                    :errors="{ street: error?.field('address'), province_code: error?.field('province_code'), ward_code: error?.field('ward_code') }"
                />
            </SettingsSection>
            <SettingsSection title="Khu vực" description="Múi giờ dùng cho báo cáo theo ngày.">
                <FormField label="Múi giờ"><Select v-model="form.timezone" :options="timezones" /></FormField>
            </SettingsSection>
        </template>
    </SettingsPage>
</template>
