<script setup lang="ts">
import { computed, onMounted, provide, ref, type Component } from 'vue';
import { RouterLink } from 'vue-router';
import { BadgePercent, Check, HandHelping, Info, Package, Warehouse, Zap } from 'lucide-vue-next';
import { useSettingsForm } from '../settings/useSettingsForm';
import CarrierBox from './CarrierBox.vue';
import ShipHead from './ShipHead.vue';
import { btn, card, iconTones } from './ui';
import CarrierLogo from '@/shared/ui/CarrierLogo.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import Switch from '@/shared/ui/Switch.vue';
import { api } from '@/shared/lib/api';
import { cn } from '@/shared/lib/utils';
import { useSettingsStore, type ShippingSettings } from '@/shared/stores/settings';
import { loadCarrierAccounts, requiredNotes, sizePresets, type CarrierAccountData } from '../shipments/carriers';

/**
 * Vận chuyển › Cấu hình giao hàng (mẫu 28/11): what a new parcel starts with (weight, size, delivery note, who pays),
 * which carrier each warehouse uses first, and the two automatic actions, with a preview of the delivery slip.
 * Saved as the "shipping" settings section.
 */
provide('soft-cards', true);
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

interface WarehouseRow { id: string; name: string; purpose?: string; address?: string | null; province?: string | null }
const warehouses = ref<WarehouseRow[] | null>(null);
const accounts = ref<CarrierAccountData[]>([]);
onMounted(async () => {
    const [w, a] = await Promise.allSettled([api.get('/warehouses'), loadCarrierAccounts()]);
    warehouses.value = w.status === 'fulfilled' ? (w.value.data.data as WarehouseRow[]).filter((x) => x.purpose !== 'defective') : [];
    accounts.value = a.status === 'fulfilled' ? a.value.filter((x) => x.status !== 'disconnected') : [];
});
const accountOptions = computed(() => [{ value: '', label: 'Rẻ nhất trong các hãng' }, ...accounts.value.map((a) => ({ value: a.id, label: `${a.carrier_label} · ${a.name}` }))]);
const accountOf = (id: string | undefined) => accounts.value.find((a) => a.id === id) ?? null;
const fieldError = (k: string) => error.value?.field(k) ?? undefined;

/** Box sizes the shop often uses: one tap fills length, width and height. */
const presetOn = (p: (typeof sizePresets)[number]) => form.value?.length_cm === String(p.l) && form.value?.width_cm === String(p.w) && form.value?.height_cm === String(p.h);
function usePreset(p: (typeof sizePresets)[number]) {
    if (!form.value) return;
    form.value.length_cm = String(p.l);
    form.value.width_cm = String(p.w);
    form.value.height_cm = String(p.h);
}
const noteHints: Record<string, string> = { view_no_try: 'Phổ biến nhất, hạn chế hoàn', try: 'Quần áo, giày dép', no_view: 'Hàng niêm phong' };
const payers = [
    { value: 'shop', label: 'Shop trả', hint: 'Khách chỉ trả tiền hàng' },
    { value: 'buyer', label: 'Khách trả', hint: 'Hãng thu phí cùng tiền COD' },
] as const;

const sections: { id: string; icon: Component; tone: string; title: string }[] = [
    { id: 'ship-package', icon: Package, tone: 'blue', title: 'Gói hàng mặc định' },
    { id: 'ship-deliver', icon: HandHelping, tone: 'violet', title: 'Khi giao cho khách' },
    { id: 'ship-defaults', icon: Warehouse, tone: 'green', title: 'Hãng mặc định theo kho' },
    { id: 'ship-auto', icon: Zap, tone: 'amber', title: 'Tự động' },
];
const goTo = (id: string) => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });

/** Preview of the slip: the shop, a sample buyer, and what this form would print. */
const settings = useSettingsStore();
const shop = computed(() => settings.data);
const previewAccount = computed(() => accountOf(Object.values(form.value?.picks ?? {}).find(Boolean)) ?? accounts.value[0] ?? null);
const size = computed(() => (form.value?.length_cm && form.value.width_cm && form.value.height_cm ? `${form.value.length_cm}×${form.value.width_cm}×${form.value.height_cm}` : '—'));
const input = 'h-11 w-full rounded-[10px] border border-slate-200 bg-white px-3.5 text-[14px] text-slate-900 outline-none placeholder:text-slate-400 focus:border-blue-400 disabled:bg-slate-50';
</script>

<template>
    <ShipHead title="Cấu hình giao hàng" description="Giá trị mặc định khi tạo vận đơn. Vẫn sửa được ở từng đơn." />
    <p v-if="!canSave" class="mb-4 rounded-[12px] bg-slate-100 px-4 py-3 text-[14px] text-slate-600">Bạn chỉ xem được cấu hình này. Chủ doanh nghiệp hoặc quản trị mới sửa được.</p>
    <div v-if="!form" class="space-y-4"><Skeleton class="h-40" /><Skeleton class="h-40" /></div>
    <div v-else class="grid grid-cols-1 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_380px] 2xl:grid-cols-[minmax(0,1fr)_400px]" data-testid="shipping-settings">
        <div class="min-w-0 space-y-5">
            <section id="ship-package" :class="cn(card, 'scroll-mt-24')">
                <header class="flex items-start gap-3 border-b border-slate-100 px-6 py-4">
                    <span :class="cn('grid size-10 shrink-0 place-items-center rounded-[11px]', iconTones.blue)"><Package class="size-5" /></span>
                    <div><h3 class="text-[16px] font-semibold text-slate-900">Gói hàng mặc định</h3><p class="text-[13px] text-slate-500">Khối lượng và kích thước gửi cho hãng để báo giá</p></div>
                </header>
                <div class="p-6">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <label v-for="f in ([['weight_g', 'Khối lượng', 'gram', 'ship-weight'], ['length_cm', 'Dài', 'cm', 'ship-length'], ['width_cm', 'Rộng', 'cm', 'ship-width'], ['height_cm', 'Cao', 'cm', 'ship-height']] as const)" :key="f[0]" class="block">
                            <span class="text-[13px] font-medium text-slate-700">{{ f[1] }}</span>
                            <span class="relative mt-1.5 block">
                                <input v-model="form[f[0]]" type="number" min="1" :class="cn(input, 'pr-14')" :placeholder="f[0] === 'weight_g' ? '500' : 'Không bắt buộc'" :disabled="!canSave" :data-testid="f[3]" />
                                <span class="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-[13px] text-slate-400">{{ f[2] }}</span>
                            </span>
                            <span v-if="fieldError(f[0])" class="mt-1 block text-[12px] text-red-600">{{ fieldError(f[0]) }}</span>
                        </label>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <span class="mr-1 text-[13px] text-slate-500">Cỡ hộp hay dùng</span>
                        <button v-for="p in sizePresets" :key="p.label" type="button" :disabled="!canSave" :class="cn('rounded-[9px] border px-3 py-1.5 text-[13px]', presetOn(p) ? 'border-blue-600 bg-blue-50 font-medium text-blue-700' : 'border-slate-200 text-slate-600 hover:border-blue-300')" @click="usePreset(p)">{{ p.label }}</button>
                    </div>
                    <p class="mt-4 flex items-center gap-2 rounded-[10px] bg-slate-50 px-3.5 py-2.5 text-[13px] text-slate-600"><Info class="size-4 shrink-0 text-slate-400" /> Dùng khi sản phẩm chưa có cân nặng. Có cân nặng thì phần mềm tự cộng theo sản phẩm trong đơn.</p>
                </div>
            </section>

            <section id="ship-deliver" :class="cn(card, 'scroll-mt-24')">
                <header class="flex items-start gap-3 border-b border-slate-100 px-6 py-4">
                    <span :class="cn('grid size-10 shrink-0 place-items-center rounded-[11px]', iconTones.violet)"><HandHelping class="size-5" /></span>
                    <div><h3 class="text-[16px] font-semibold text-slate-900">Khi giao cho khách</h3><p class="text-[13px] text-slate-500">Lời dặn in trên phiếu và gửi kèm cho hãng vận chuyển</p></div>
                </header>
                <div class="p-6">
                    <p class="text-[13px] font-medium text-slate-700">Yêu cầu khi giao</p>
                    <div class="mt-2 grid gap-3 md:grid-cols-3" role="radiogroup" aria-label="Yêu cầu khi giao" data-testid="ship-required-note">
                        <button v-for="o in requiredNotes" :key="o.value" type="button" role="radio" :aria-checked="form.required_note === o.value" :disabled="!canSave" :class="cn('flex gap-3 rounded-[12px] border p-4 text-left', form.required_note === o.value ? 'border-blue-600 bg-blue-50/50' : 'border-slate-200 hover:border-blue-300')" :data-testid="`ship-note-${o.value}`" @click="form.required_note = o.value as Form['required_note']">
                            <span :class="cn('mt-0.5 grid size-[18px] shrink-0 place-items-center rounded-full border-2', form.required_note === o.value ? 'border-blue-600' : 'border-slate-300')"><span v-if="form.required_note === o.value" class="size-2 rounded-full bg-blue-600" /></span>
                            <span><span class="block text-[14px] font-medium text-slate-900">{{ o.label }}</span><span class="block text-[12px] text-slate-500">{{ noteHints[o.value] }}</span></span>
                        </button>
                    </div>
                    <p class="mt-5 text-[13px] font-medium text-slate-700">Người trả phí ship</p>
                    <div class="mt-2 grid gap-3 md:grid-cols-2" role="radiogroup" aria-label="Người trả phí ship" data-testid="ship-payer">
                        <button v-for="o in payers" :key="o.value" type="button" role="radio" :aria-checked="form.payer === o.value" :disabled="!canSave" :class="cn('flex gap-3 rounded-[12px] border p-4 text-left', form.payer === o.value ? 'border-blue-600 bg-blue-50/50' : 'border-slate-200 hover:border-blue-300')" @click="form.payer = o.value">
                            <span :class="cn('mt-0.5 grid size-[18px] shrink-0 place-items-center rounded-full border-2', form.payer === o.value ? 'border-blue-600' : 'border-slate-300')"><span v-if="form.payer === o.value" class="size-2 rounded-full bg-blue-600" /></span>
                            <span><span class="block text-[14px] font-medium text-slate-900">{{ o.label }}</span><span class="block text-[12px] text-slate-500">{{ o.hint }}</span></span>
                        </button>
                    </div>
                    <label class="mt-5 block">
                        <span class="text-[13px] font-medium text-slate-700">Ghi chú cho hãng</span>
                        <input v-model="form.note" maxlength="200" :class="cn(input, 'mt-1.5')" placeholder="Ví dụ: Gọi trước khi giao, hàng dễ vỡ" :disabled="!canSave" data-testid="ship-note" />
                        <span class="mt-1 block text-[12px]" :class="fieldError('note') ? 'text-red-600' : 'text-slate-400'">{{ fieldError('note') ?? 'In trên phiếu và gửi kèm cho hãng. Tối đa 200 ký tự.' }}</span>
                    </label>
                </div>
            </section>

            <section id="ship-defaults" :class="cn(card, 'scroll-mt-24')">
                <header class="flex items-start gap-3 border-b border-slate-100 px-6 py-4">
                    <span :class="cn('grid size-10 shrink-0 place-items-center rounded-[11px]', iconTones.green)"><Warehouse class="size-5" /></span>
                    <div><h3 class="text-[16px] font-semibold text-slate-900">Hãng mặc định theo kho</h3><p class="text-[13px] text-slate-500">Hãng được chọn sẵn khi đẩy đơn từ mỗi kho. Để trống thì chọn hãng rẻ nhất.</p></div>
                </header>
                <div class="p-6">
                    <div v-if="!warehouses" class="space-y-2"><Skeleton class="h-11" /><Skeleton class="h-11" /></div>
                    <p v-else-if="!accounts.length" class="text-[14px] text-slate-500">
                        Chưa kết nối hãng vận chuyển nào.
                        <RouterLink to="/shipping/partners" class="font-medium text-blue-600 hover:underline">Kết nối đối tác</RouterLink>
                    </p>
                    <div v-else class="divide-y divide-slate-100">
                        <div v-for="w in warehouses" :key="w.id" class="flex flex-wrap items-center gap-4 py-3.5 first:pt-0 last:pb-0">
                            <span class="grid size-10 shrink-0 place-items-center rounded-[11px] bg-slate-100 text-slate-500"><Warehouse class="size-5" /></span>
                            <div class="w-56 min-w-0">
                                <p class="truncate text-[14px] font-medium text-slate-900">{{ w.name }}</p>
                                <p v-if="w.address || w.province" class="truncate text-[12px] text-slate-500">{{ [w.address, w.province].filter(Boolean).join(', ') }}</p>
                            </div>
                            <div class="flex min-w-64 flex-1 items-center gap-3">
                                <CarrierBox v-if="accountOf(form.picks[w.id])" :carrier="accountOf(form.picks[w.id])!.carrier" size="sm" />
                                <span v-else class="grid h-8 w-[72px] shrink-0 place-items-center rounded-[10px] bg-emerald-50 text-emerald-600" title="Rẻ nhất trong các hãng"><BadgePercent class="size-4" /></span>
                                <Select :model-value="form.picks[w.id] ?? ''" :options="accountOptions" :disabled="!canSave" class="flex-1" :data-testid="`ship-default-${w.id}`" @update:model-value="(v) => (form!.picks[w.id] = v ?? '')" />
                            </div>
                        </div>
                    </div>
                    <p v-if="fieldError('default_carriers.0.account_id') || fieldError('default_carriers.0.warehouse_id')" class="mt-2 text-[12px] text-red-600">{{ fieldError('default_carriers.0.account_id') ?? fieldError('default_carriers.0.warehouse_id') }}</p>
                </div>
            </section>

            <section id="ship-auto" :class="cn(card, 'scroll-mt-24')">
                <header class="flex items-start gap-3 border-b border-slate-100 px-6 py-4">
                    <span :class="cn('grid size-10 shrink-0 place-items-center rounded-[11px]', iconTones.amber)"><Zap class="size-5" /></span>
                    <div><h3 class="text-[16px] font-semibold text-slate-900">Tự động</h3><p class="text-[13px] text-slate-500">Phần mềm tự làm thay, có thể tắt bất cứ lúc nào</p></div>
                </header>
                <div class="divide-y divide-slate-100 px-6 py-2">
                    <Switch v-model="form.notify_problems" :disabled="!canSave" label="Báo ngay khi đơn giao thất bại hoặc bị hoàn" hint="Gửi thông báo đến điện thoại và máy tính của người xem được đơn hàng (cần bật thông báo trên trình duyệt)." data-testid="ship-notify" />
                    <Switch v-model="form.cancel_with_order" :disabled="!canSave" label="Hủy đơn thì tự hủy vận đơn bên hãng" hint="Chỉ khi hãng chưa lấy hàng. Đơn Shopee, TikTok do sàn tự hủy." data-testid="ship-cancel-with-order" />
                </div>
            </section>

            <div v-if="canSave" class="flex flex-wrap items-center justify-between gap-3 rounded-[14px] border border-slate-200 bg-white/95 px-5 py-3.5 shadow-[0_-4px_16px_-6px_rgba(15,23,42,0.12)] backdrop-blur" :class="dirty && 'border-blue-200'">
                <p class="flex items-center gap-2 text-[14px] text-slate-600">
                    <span :class="cn('size-2 rounded-full', dirty ? 'bg-amber-500' : 'bg-emerald-500')" />{{ dirty ? 'Có thay đổi chưa lưu' : 'Đã lưu mọi thay đổi' }}
                </p>
                <div class="flex gap-3">
                    <button type="button" :class="btn.outline" :disabled="!dirty || saving" @click="cancel">Hủy</button>
                    <button type="button" :class="btn.primary" :disabled="!dirty || saving" data-testid="settings-save" @click="save"><Check class="size-4" /> {{ saving ? 'Đang lưu…' : 'Lưu cấu hình' }}</button>
                </div>
            </div>
        </div>

        <aside class="space-y-5 xl:sticky xl:top-24">
            <nav :class="cn(card, 'p-3')" aria-label="Các phần cấu hình">
                <button v-for="s in sections" :key="s.id" type="button" class="flex w-full items-center gap-3 rounded-[10px] px-3 py-2.5 text-left text-[14px] text-slate-600 hover:bg-blue-50 hover:text-blue-700" @click="goTo(s.id)">
                    <component :is="s.icon" class="size-[18px]" /> {{ s.title }}
                </button>
            </nav>
            <section :class="cn(card, 'p-5')" data-testid="ship-preview">
                <p class="text-[15px] font-semibold text-slate-900">Xem trước trên phiếu giao</p>
                <p class="mb-4 text-[13px] text-slate-500">Cập nhật theo cấu hình bạn đang sửa (khách và mã vận đơn là ví dụ)</p>
                <div class="rounded-[12px] border border-slate-200 bg-white p-4 font-mono text-[11px] text-slate-700">
                    <div class="flex items-center justify-between border-b border-dashed border-slate-300 pb-2">
                        <CarrierLogo v-if="previewAccount" :carrier="previewAccount.carrier" :width="72" :height="18" class="!justify-items-start" />
                        <span v-else class="font-sans font-semibold">Phiếu giao hàng</span>
                        <span class="font-semibold">MÃ VẬN ĐƠN</span>
                    </div>
                    <div class="grid grid-cols-2 gap-2 border-b border-dashed border-slate-300 py-2">
                        <div class="min-w-0"><p class="text-slate-400">Từ</p><p class="truncate font-semibold">{{ shop?.name ?? 'Cửa hàng' }}</p><p class="truncate">{{ shop?.store.province ?? '' }}</p></div>
                        <div class="min-w-0"><p class="text-slate-400">Đến</p><p class="font-semibold">Khách hàng</p><p>Địa chỉ người nhận</p></div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 border-b border-dashed border-slate-300 py-2">
                        <div><p class="text-slate-400">Khối lượng</p><p class="font-semibold">{{ form.weight_g || '500' }} g</p></div>
                        <div><p class="text-slate-400">Kích thước</p><p class="font-semibold">{{ size }}</p></div>
                        <div><p class="text-slate-400">Phí ship</p><p class="font-semibold">{{ form.payer === 'buyer' ? 'Khách trả' : 'Shop trả' }}</p></div>
                    </div>
                    <div class="pt-2">
                        <p class="rounded bg-amber-50 px-2 py-1 font-semibold text-amber-800">{{ requiredNotes.find((r) => r.value === form!.required_note)?.label }}</p>
                        <p v-if="form.note" class="mt-1.5 break-words text-slate-500">Ghi chú: {{ form.note }}</p>
                    </div>
                </div>
            </section>
        </aside>
    </div>
</template>
