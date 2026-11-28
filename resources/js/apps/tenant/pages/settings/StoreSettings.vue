<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { CircleAlert, CircleCheck, Globe, ImagePlus, MapPin, Store } from 'lucide-vue-next';
import SettingsPage from './SettingsPage.vue';
import SettingsSection from './SettingsSection.vue';
import { useSettingsForm } from './useSettingsForm';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import AddressPicker from '../../components/AddressPicker.vue';
import { describe, type AddressParts } from '@/shared/lib/geo';
import { cn } from '@/shared/lib/utils';
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
// Province and ward names for the invoice preview.
const place = ref<{ province: string | null; ward: string | null }>({ province: null, ward: null });
watch(
    () => [form.value?.province_code, form.value?.ward_code] as const,
    async ([province_code, ward_code]) => {
        try {
            place.value = await describe({ province_code: province_code ?? '', ward_code: ward_code ?? '' });
        } catch {
            place.value = { province: null, ward: null };
        }
    },
    { immediate: true },
);
const previewAddress = computed(() => {
    const f = form.value;
    if (!f) return '';
    return [f.address, place.value.ward ?? f.ward, place.value.province ?? f.province].filter(Boolean).join(', ');
});
const initials = computed(() =>
    (form.value?.name ?? '')
        .split(/\s+/)
        .filter((w) => w && !/^(cửa|hàng|shop|công|ty)$/i.test(w))
        .slice(0, 2)
        .map((w) => w[0]!.toUpperCase())
        .join('') || 'CH',
);
const today = new Date().toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' });
// What a complete profile has (same as the card on Cấu hình), grouped like the mockup.
const checklist = computed(() => {
    const f = form.value;
    if (!f) return [];
    return [
        { label: 'Tên và logo', ok: !!f.name && !!store.data?.logo_url },
        { label: 'Số điện thoại, email', ok: !!f.phone && !!f.email },
        { label: 'Địa chỉ lấy hàng', ok: !!(f.province_code || f.address) },
        { label: 'Mã số thuế', ok: !!f.tax_code },
        { label: 'Lĩnh vực, website', ok: !!f.business_type && !!f.website },
    ];
});
const profileDone = computed(() => {
    const f = form.value;
    if (!f) return 0;
    return [f.name, store.data?.logo_url, f.phone, f.email, f.province_code || f.address, f.tax_code, f.business_type, f.website].filter(Boolean).length;
});
async function removeLogo() {
    store.set((await api.delete('/tenant/logo')).data.data);
}
</script>

<template>
    <SettingsPage title="Thông tin cửa hàng" :saving="saving" :dirty="dirty" :can-save="canSave" @save="save" @cancel="cancel">
        <template v-if="form">
            <SettingsSection title="Thông tin liên hệ" description="Dùng trên hóa đơn, phiếu in và để khách liên hệ cửa hàng." :icon="Store">
                <div class="mb-6 flex flex-wrap items-center gap-5 rounded-[12px] bg-slate-50 p-4 sm:p-5">
                    <img v-if="store.data?.logo_url" :src="store.data.logo_url" alt="Logo" class="size-20 rounded-[14px] border border-slate-200 bg-white object-contain p-1.5" data-testid="store-logo" />
                    <span v-else class="grid size-20 place-items-center rounded-[14px] border-2 border-dashed border-slate-300 bg-white text-slate-400"><ImagePlus class="size-7" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[15px] font-semibold text-slate-900">Logo cửa hàng</p>
                        <p class="mt-0.5 text-[13px] text-slate-500">PNG, JPG, WEBP tối đa 2MB. In ở đầu hóa đơn và phiếu giao.</p>
                        <div v-if="canSave" class="mt-3 flex items-center gap-4">
                            <label class="cursor-pointer rounded-[10px] border border-slate-200 bg-white px-3.5 py-1.5 text-[14px] font-medium text-slate-800 hover:bg-slate-50">
                                {{ uploading ? 'Đang tải lên…' : store.data?.logo_url ? 'Đổi logo' : 'Tải lên logo' }}
                                <input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" data-testid="logo-input" @change="upload" />
                            </label>
                            <button v-if="store.data?.logo_url" type="button" class="text-[14px] font-medium text-red-600 hover:underline" @click="removeLogo">Xóa</button>
                        </div>
                    </div>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField label="Tên cửa hàng" for="st-name" :error="error?.field('name')"><Input id="st-name" v-model="form.name" data-testid="store-name" /></FormField>
                    <FormField label="Số điện thoại" for="st-phone" :error="error?.field('phone')"><Input id="st-phone" v-model="form.phone" data-testid="store-phone" /></FormField>
                    <FormField label="Email" for="st-email" :error="error?.field('email')"><Input id="st-email" v-model="form.email" type="email" /></FormField>
                    <FormField label="Lĩnh vực kinh doanh" for="st-type"><Input id="st-type" v-model="form.business_type" placeholder="Máy tính, linh kiện…" /></FormField>
                    <FormField label="Website" for="st-web"><Input id="st-web" v-model="form.website" /></FormField>
                    <FormField label="Mã số thuế" for="st-tax" :error="error?.field('tax_code')">
                        <Input id="st-tax" v-model="form.tax_code" placeholder="Nhập 10 hoặc 13 số" :class="form.tax_code ? undefined : 'border-amber-300 bg-amber-50/40 ring-2 ring-amber-100'" data-testid="store-tax-code" />
                        <p v-if="!form.tax_code && !error?.field('tax_code')" class="mt-1.5 text-[13px] text-amber-600">Cần để xuất hóa đơn điện tử</p>
                    </FormField>
                </div>
            </SettingsSection>
            <SettingsSection title="Địa chỉ cửa hàng" description="In trên hóa đơn, là địa chỉ lấy hàng của hãng vận chuyển." :icon="MapPin" tone="green">
                <AddressPicker
                    v-model="addr"
                    id-prefix="st-addr"
                    :quick="false"
                    :legacy="legacy"
                    :columns="2"
                    :errors="{ street: error?.field('address'), province_code: error?.field('province_code'), ward_code: error?.field('ward_code') }"
                />
            </SettingsSection>
            <SettingsSection title="Khu vực" description="Múi giờ dùng cho báo cáo theo ngày." :icon="Globe" tone="violet">
                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField label="Múi giờ"><Select v-model="form.timezone" :options="timezones" /></FormField>
                </div>
            </SettingsSection>
        </template>
        <template #aside>
            <div v-if="form" class="rounded-[14px] border border-slate-200/80 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.04)]" data-testid="store-preview">
                <p class="text-[12px] font-semibold uppercase tracking-wider text-slate-400">Xem trước trên hóa đơn</p>
                <div class="mt-4 rounded-[12px] border border-dashed border-slate-300 p-4 font-mono text-[12px] leading-relaxed text-slate-600">
                    <div class="flex items-center gap-3">
                        <img v-if="store.data?.logo_url" :src="store.data.logo_url" alt="" class="size-11 shrink-0 rounded-lg object-contain" />
                        <span v-else class="grid size-11 shrink-0 place-items-center rounded-lg bg-gradient-to-br from-sky-400 to-blue-600 font-sans text-[15px] font-bold text-white">{{ initials }}</span>
                        <div class="min-w-0">
                            <p class="truncate font-sans text-[14px] font-bold uppercase text-slate-900">{{ form.name || 'Tên cửa hàng' }}</p>
                            <p v-if="form.phone">ĐT: {{ form.phone }}</p>
                        </div>
                    </div>
                    <p v-if="previewAddress" class="mt-2">{{ previewAddress }}</p>
                    <p :class="form.tax_code ? '' : 'text-amber-600'">MST: {{ form.tax_code || '(chưa có)' }}</p>
                    <div class="my-3 border-t border-dashed border-slate-300" />
                    <p class="text-center font-sans text-[13px] font-bold text-slate-900">HÓA ĐƠN BÁN HÀNG</p>
                    <p class="text-center">SON50892 · {{ today }}</p>
                </div>
                <p class="mt-3 text-[13px] text-slate-500">Đổi bên trái là phiếu cập nhật ngay. Khổ in đổi ở <RouterLink to="/settings/print" class="text-blue-600 hover:underline">Mẫu in</RouterLink>.</p>
            </div>
            <div v-if="form" class="rounded-[14px] border border-slate-200/80 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.04)]" data-testid="store-checklist">
                <p class="text-[15px] font-semibold text-slate-900">Hồ sơ đầy đủ {{ profileDone }}/8</p>
                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-blue-600" :style="{ width: `${(profileDone / 8) * 100}%` }" /></div>
                <ul class="mt-4 space-y-2.5">
                    <li v-for="c in checklist" :key="c.label" :class="cn('flex items-center gap-2.5 text-[14px]', c.ok ? 'text-slate-700' : 'text-amber-600')">
                        <CircleCheck v-if="c.ok" class="size-[18px] text-emerald-500" />
                        <CircleAlert v-else class="size-[18px]" />
                        {{ c.label }}
                    </li>
                </ul>
            </div>
        </template>
    </SettingsPage>
</template>
