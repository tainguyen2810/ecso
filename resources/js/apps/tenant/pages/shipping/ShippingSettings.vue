<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import PageHeader from '../../PageHeader.vue';
import SettingsSection from '../settings/SettingsSection.vue';
import { useSettingsForm } from '../settings/useSettingsForm';
import Button from '@/shared/ui/Button.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import Switch from '@/shared/ui/Switch.vue';
import { api } from '@/shared/lib/api';
import type { ShippingSettings } from '@/shared/stores/settings';
import { loadCarrierAccounts, requiredNotes, type CarrierAccountData } from '../shipments/carriers';

/**
 * Vận chuyển › Cấu hình giao hàng: what a new parcel starts with (weight, size, delivery note, who pays), which carrier
 * each warehouse uses first, and the two automatic actions. Saved as the "shipping" settings section.
 */
type Form = Omit<ShippingSettings, 'weight_g' | 'length_cm' | 'width_cm' | 'height_cm'> & { weight_g: string; length_cm: string; width_cm: string; height_cm: string; picks: Record<string, string> };

const str = (v: number | null | undefined) => (v === null || v === undefined ? '' : String(v));
const num = (v: string) => (v.trim() === '' ? null : Number(v));

const { form, dirty, saving, error, canSave, save, cancel } = useSettingsForm<Form>(
    'shipping',
    (s) => {
        const x = s.shipping!;
        return {
            ...x,
            note: x.note ?? '',
            weight_g: str(x.weight_g),
            length_cm: str(x.length_cm),
            width_cm: str(x.width_cm),
            height_cm: str(x.height_cm),
            picks: Object.fromEntries((x.default_carriers ?? []).map((p) => [p.warehouse_id, p.account_id])),
        };
    },
    (f) => {
        const { picks, ...rest } = f;
        return {
            ...rest,
            note: rest.note?.trim() || null,
            weight_g: num(f.weight_g) ?? 500,
            length_cm: num(f.length_cm),
            width_cm: num(f.width_cm),
            height_cm: num(f.height_cm),
            default_carriers: Object.entries(picks).filter(([, a]) => a).map(([warehouse_id, account_id]) => ({ warehouse_id, account_id })),
        };
    },
);

const warehouses = ref<{ id: string; name: string; purpose?: string }[] | null>(null);
const accounts = ref<CarrierAccountData[]>([]);
onMounted(async () => {
    const [w, a] = await Promise.allSettled([api.get('/warehouses'), loadCarrierAccounts()]);
    warehouses.value = w.status === 'fulfilled' ? (w.value.data.data as { id: string; name: string; purpose?: string }[]).filter((x) => x.purpose !== 'defective') : [];
    accounts.value = a.status === 'fulfilled' ? a.value.filter((x) => x.status !== 'disconnected') : [];
});
const accountOptions = computed(() => [{ value: '', label: 'Rẻ nhất trong các hãng' }, ...accounts.value.map((a) => ({ value: a.id, label: `${a.carrier_label} · ${a.name}` }))]);
const fieldError = (k: string) => error.value?.field(k) ?? undefined;
</script>

<template>
    <PageHeader title="Cấu hình giao hàng" description="Giá trị mặc định khi tạo vận đơn. Vẫn sửa được ở từng đơn.">
        <template v-if="canSave">
            <Button variant="outline" :disabled="!dirty || saving" @click="cancel">Hủy</Button>
            <Button :loading="saving" :disabled="!dirty" data-testid="settings-save" @click="save">Lưu</Button>
        </template>
    </PageHeader>
    <p v-if="!canSave" class="mb-2 text-sm text-muted-foreground">Bạn chỉ xem được cấu hình này. Chủ doanh nghiệp hoặc quản trị mới sửa được.</p>
    <div v-if="!form" class="space-y-4"><Skeleton class="h-40" /><Skeleton class="h-40" /></div>
    <div v-else class="divide-y" data-testid="shipping-settings">
        <SettingsSection title="Gói hàng mặc định" description="Dùng khi sản phẩm trong đơn chưa có cân nặng. Có cân nặng thì phần mềm tự cộng theo sản phẩm.">
            <div class="grid gap-4 sm:grid-cols-4">
                <FormField label="Khối lượng (gram)" :error="fieldError('weight_g')"><Input v-model="form.weight_g" type="number" min="1" data-testid="ship-weight" /></FormField>
                <FormField label="Dài (cm)" :error="fieldError('length_cm')"><Input v-model="form.length_cm" type="number" min="1" placeholder="Không bắt buộc" /></FormField>
                <FormField label="Rộng (cm)" :error="fieldError('width_cm')"><Input v-model="form.width_cm" type="number" min="1" placeholder="Không bắt buộc" /></FormField>
                <FormField label="Cao (cm)" :error="fieldError('height_cm')"><Input v-model="form.height_cm" type="number" min="1" placeholder="Không bắt buộc" /></FormField>
            </div>
        </SettingsSection>

        <SettingsSection title="Khi giao cho khách" description="Lời dặn in trên phiếu và gửi kèm cho hãng vận chuyển.">
            <div class="grid gap-4 sm:grid-cols-2">
                <FormField label="Yêu cầu khi giao">
                    <Select v-model="form.required_note" :options="requiredNotes" data-testid="ship-required-note" />
                </FormField>
                <FormField label="Người trả phí ship">
                    <Select v-model="form.payer" :options="[{ value: 'shop', label: 'Shop trả' }, { value: 'buyer', label: 'Khách trả (hãng thu cùng COD)' }]" data-testid="ship-payer" />
                </FormField>
                <FormField label="Ghi chú cho hãng" class="sm:col-span-2" :error="fieldError('note')">
                    <Input v-model="form.note" maxlength="200" placeholder="Ví dụ: Gọi trước khi giao, hàng dễ vỡ" data-testid="ship-note" />
                </FormField>
            </div>
        </SettingsSection>

        <SettingsSection title="Hãng mặc định" description="Hãng được chọn sẵn khi đẩy đơn từ mỗi kho. Để trống thì chọn hãng rẻ nhất.">
            <div v-if="!warehouses" class="space-y-2"><Skeleton class="h-9" /><Skeleton class="h-9" /></div>
            <p v-else-if="!accounts.length" class="text-sm text-muted-foreground">
                Chưa kết nối hãng vận chuyển nào.
                <RouterLink to="/shipping/partners" class="font-medium text-primary hover:underline">Kết nối đối tác</RouterLink>
            </p>
            <div v-else class="divide-y">
                <div v-for="w in warehouses" :key="w.id" class="flex flex-wrap items-center gap-3 py-2.5 first:pt-0 last:pb-0">
                    <span class="min-w-40 flex-1 text-sm font-medium">{{ w.name }}</span>
                    <Select :model-value="form.picks[w.id] ?? ''" :options="accountOptions" class="sm:w-80" :data-testid="`ship-default-${w.id}`" @update:model-value="(v) => (form!.picks[w.id] = v ?? '')" />
                </div>
            </div>
            <p v-if="fieldError('default_carriers.0.account_id') || fieldError('default_carriers.0.warehouse_id')" class="mt-2 text-xs text-destructive">{{ fieldError('default_carriers.0.account_id') ?? fieldError('default_carriers.0.warehouse_id') }}</p>
        </SettingsSection>

        <SettingsSection title="Tự động" description="Phần mềm tự làm thay, có thể tắt bất cứ lúc nào.">
            <div class="divide-y">
                <Switch v-model="form.notify_problems" label="Báo ngay khi đơn giao thất bại hoặc bị hoàn" hint="Gửi thông báo đến điện thoại và máy tính của người xem được đơn hàng (cần bật thông báo trên trình duyệt)." data-testid="ship-notify" />
                <Switch v-model="form.cancel_with_order" label="Hủy đơn thì tự hủy vận đơn bên hãng" hint="Chỉ khi hãng chưa lấy hàng. Đơn Shopee, TikTok do sàn tự hủy." data-testid="ship-cancel-with-order" />
            </div>
        </SettingsSection>
    </div>
</template>
