<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { CalendarRange, Check, ChevronLeft, Lightbulb, Percent, Plus, Store, Tag, Trash2, X } from 'lucide-vue-next';
import SkuPicker from '../../components/SkuPicker.vue';
import Button from '@/shared/ui/Button.vue';
import DateTimeInput from '@/shared/ui/DateTimeInput.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import SegmentedControl from '@/shared/ui/SegmentedControl.vue';
import { btnOutline, btnPrimary, card } from '../customers/frame';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime, formatMoney } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import type { Variant } from '@/shared/types';
import { describe, promotionKinds, type DiscountKind, type PromotionData, type PromotionType } from './promotions';

/** Tạo / sửa khuyến mãi (C4): one of four kinds, its amounts, where it applies, its dates and limits; a summary in words on the right. */
const route = useRoute();
const router = useRouter();
const toast = useToast();
const id = computed(() => (route.params.id && route.params.id !== 'new' ? String(route.params.id) : null));

type Kind = (typeof promotionKinds)[number]['key'];
type Picked = { id: string; sku: string; name: string };
const kind = ref<Kind>('order');
const form = reactive({
    name: '',
    code: '',
    discount_kind: 'percent' as DiscountKind,
    discount_value: '' as string | number,
    max_discount: '' as string | number,
    min_subtotal: '' as string | number,
    tiers: [] as { min_subtotal: string | number; value: string | number }[],
    buy_qty: '' as string | number,
    gift_qty: 1 as string | number,
    channel_ids: [] as string[],
    category_ids: [] as string[],
    customer_group_ids: [] as string[],
    starts_at: '',
    ends_at: '',
    usage_limit: '' as string | number,
    per_customer_limit: '' as string | number,
    status: 'active' as 'active' | 'paused',
});
const variants = ref<Picked[]>([]);
const gift = ref<Picked | null>(null);
const channels = ref<{ id: string; name: string }[]>([]);
const categories = ref<{ id: string; name: string }[]>([]);
const groups = ref<{ id: string; name: string }[]>([]);
const loading = ref(true);
const saving = ref(false);
const error = ref<ApiError | null>(null);

const type = computed<PromotionType>(() => promotionKinds.find((k) => k.key === kind.value)!.type);
const kindOptions = computed(() => [
    { value: 'percent' as DiscountKind, label: '%' },
    { value: 'amount' as DiscountKind, label: 'Số tiền' },
    ...(type.value === 'product' ? [{ value: 'fixed_price' as DiscountKind, label: 'Đồng giá' }] : []),
]);
const kindTone: Record<string, string> = { order: 'bg-blue-50 text-blue-600', product: 'bg-amber-50 text-amber-600', gift: 'bg-emerald-50 text-emerald-600', code: 'bg-violet-50 text-violet-600' };
const chipClass = (on: boolean) =>
    cn('inline-flex h-9 items-center gap-1.5 rounded-[10px] border px-3 text-[13.5px] transition-colors', on ? 'border-blue-300 bg-blue-50 font-medium text-blue-700' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300');
/** Big figure of the summary card: "10%", "50.000 ₫", "Tặng 1". */
const headline = computed(() => {
    if (type.value === 'gift') return `Tặng ${form.gift_qty || 1}`;
    const v = Number(form.discount_value) || 0;
    if (form.discount_kind === 'percent') return `${v}%`;
    return form.discount_kind === 'fixed_price' ? `Còn ${formatMoney(v)}` : formatMoney(v);
});
/** A worked example for an order discount: an order a little over the minimum. */
const example = computed(() => {
    if (type.value !== 'order' || !Number(form.discount_value)) return null;
    const min = Number(form.min_subtotal) || 0;
    const goods = Math.max(500_000, Math.ceil((min * 1.2) / 10_000) * 10_000);
    let off = form.discount_kind === 'percent' ? Math.round((goods * Number(form.discount_value)) / 100) : Number(form.discount_value);
    if (form.discount_kind === 'percent' && Number(form.max_discount)) off = Math.min(off, Number(form.max_discount));
    off = Math.min(off, goods);
    return { goods, off, pay: goods - off };
});

/** ISO from the server → the yyyy-mm-ddTHH:mm the date-time input keeps, in the shop's local time. */
function local(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    const p = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}
const text = (v: string | number | null | undefined) => (v === null || v === undefined ? '' : String(Number(v)));

onMounted(async () => {
    try {
        const [c, cat, g, list] = await Promise.all([
            api.get('/sales-channels').catch(() => ({ data: { data: [] } })),
            api.get('/categories').catch(() => ({ data: { data: [] } })),
            api.get('/customer-groups').catch(() => ({ data: { data: [] } })),
            id.value ? api.get('/promotions') : Promise.resolve(null),
        ]);
        channels.value = c.data.data;
        categories.value = cat.data.data;
        groups.value = g.data.data;
        if (id.value) {
            const p = (list!.data.data as PromotionData[]).find((x) => x.id === id.value);
            if (!p) {
                toast.error('Không tìm thấy khuyến mãi.');
                await router.replace('/promotions');
                return;
            }
            kind.value = p.type === 'order' && p.code ? 'code' : p.type;
            Object.assign(form, {
                name: p.name, code: p.code ?? '', discount_kind: p.discount_kind, discount_value: text(p.discount_value), max_discount: text(p.max_discount),
                min_subtotal: Number(p.min_subtotal) ? text(p.min_subtotal) : '', tiers: p.tiers.map((t) => ({ min_subtotal: text(t.min_subtotal), value: text(t.value) })),
                buy_qty: p.buy_qty ?? '', gift_qty: p.gift_qty ?? 1, channel_ids: [...p.channel_ids], category_ids: [...p.category_ids], customer_group_ids: [...p.customer_group_ids],
                starts_at: local(p.starts_at), ends_at: local(p.ends_at), usage_limit: p.usage_limit ?? '', per_customer_limit: p.per_customer_limit ?? '', status: p.status,
            });
            variants.value = p.variants ?? [];
            gift.value = p.gift;
        } else if (promotionKinds.some((k) => k.key === route.query.kind)) {
            // "Tạo nhanh" on the list opens the form on the kind that was picked.
            pickKind(route.query.kind as Kind);
        }
    } finally {
        loading.value = false;
    }
});

function pickKind(k: Kind) {
    kind.value = k;
    if (k !== 'product' && form.discount_kind === 'fixed_price') form.discount_kind = 'amount';
    if (k === 'code' && !form.per_customer_limit) form.per_customer_limit = 1;
}
function toggle(list: string[], value: string) {
    const i = list.indexOf(value);
    if (i >= 0) list.splice(i, 1);
    else list.push(value);
}
const asPicked = (v: Variant): Picked => ({ id: v.id!, sku: v.sku, name: [v.product?.name, v.name].filter(Boolean).join(' - ') || v.sku });
function addVariant(v: Variant) {
    if (!variants.value.some((x) => x.id === v.id)) variants.value.push(asPicked(v));
}

const nul = (v: string | number) => (v === '' || v === null ? null : Number(v));
const summary = computed(() => {
    const first = describe({
        type: type.value, discount_kind: form.discount_kind, discount_value: String(form.discount_value || 0), max_discount: form.max_discount === '' ? null : String(form.max_discount),
        min_subtotal: String(form.min_subtotal || 0), buy_qty: nul(form.buy_qty), gift_qty: nul(form.gift_qty), gift: gift.value,
    });
    const scope = type.value === 'order' ? '' : variants.value.length || form.category_ids.length ? `Sản phẩm áp dụng: ${[...variants.value.map((v) => v.sku), ...form.category_ids.map((c) => categories.value.find((x) => x.id === c)?.name ?? '')].join(', ')}.` : 'Áp dụng mọi sản phẩm.';
    const tiers = form.tiers.filter((t) => t.min_subtotal !== '' && t.value !== '').map((t) => `từ ${formatMoney(Number(t.min_subtotal))} giảm ${form.discount_kind === 'percent' ? `${t.value}%` : formatMoney(Number(t.value))}`);
    const where = form.channel_ids.length ? channels.value.filter((c) => form.channel_ids.includes(c.id)).map((c) => c.name).join(', ') : 'mọi kênh riêng và bán tại quầy';
    const who = form.customer_group_ids.length ? `nhóm ${groups.value.filter((g) => form.customer_group_ids.includes(g.id)).map((g) => g.name).join(', ')}` : 'mọi khách';
    const how = form.code.trim() ? `Khách đọc mã ${form.code.trim().toUpperCase()}` : 'Tự áp dụng';
    const time = form.starts_at || form.ends_at ? `Từ ${form.starts_at ? formatDateTime(form.starts_at) : 'bây giờ'} đến ${form.ends_at ? formatDateTime(form.ends_at) : 'khi tắt'}.` : 'Không giới hạn thời gian.';
    return [`${first}.`, tiers.length ? `Thêm bậc: ${tiers.join('; ')}.` : '', scope, `${how} ở ${where} cho ${who}.`, time].filter(Boolean);
});

async function save(status: 'active' | 'paused') {
    saving.value = true;
    error.value = null;
    const isGift = type.value === 'gift';
    const body = {
        name: form.name.trim(),
        type: type.value,
        code: form.code.trim() || null,
        discount_kind: isGift ? null : form.discount_kind,
        discount_value: isGift ? null : nul(form.discount_value),
        max_discount: !isGift && form.discount_kind === 'percent' ? nul(form.max_discount) : null,
        min_subtotal: nul(form.min_subtotal),
        tiers: type.value === 'order' ? form.tiers.filter((t) => t.min_subtotal !== '' && t.value !== '').map((t) => ({ min_subtotal: Number(t.min_subtotal), value: Number(t.value) })) : [],
        buy_qty: isGift ? nul(form.buy_qty) : null,
        gift_variant_id: isGift ? (gift.value?.id ?? null) : null,
        gift_qty: isGift ? nul(form.gift_qty) : null,
        channel_ids: form.channel_ids,
        variant_ids: type.value === 'order' ? [] : variants.value.map((v) => v.id),
        category_ids: type.value === 'order' ? [] : form.category_ids,
        customer_group_ids: form.customer_group_ids,
        starts_at: form.starts_at || null,
        ends_at: form.ends_at || null,
        usage_limit: nul(form.usage_limit),
        per_customer_limit: nul(form.per_customer_limit),
        status,
    };
    if (kind.value === 'code' && !body.code) {
        error.value = new ApiError(422, { message: 'Nhập mã giảm giá.', errors: { code: ['Nhập mã giảm giá.'] } });
        saving.value = false;
        return;
    }
    try {
        if (id.value) await api.put(`/promotions/${id.value}`, body);
        else await api.post('/promotions', body);
        toast.success(id.value ? 'Đã lưu khuyến mãi.' : status === 'active' ? 'Đã tạo và bật khuyến mãi.' : 'Đã lưu nháp khuyến mãi (tạm dừng).');
        await router.push('/promotions');
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
        <div>
            <RouterLink to="/promotions" class="inline-flex items-center gap-1.5 text-[14px] text-slate-500 hover:text-blue-700"><ChevronLeft class="size-4" /> Khuyến mãi</RouterLink>
            <h1 class="mt-1 text-[30px] font-bold leading-tight tracking-tight text-slate-900">{{ id ? 'Sửa khuyến mãi' : 'Tạo khuyến mãi' }}</h1>
            <p class="mt-1 text-[15px] text-slate-500">Chọn loại, đặt mức giảm, chọn kênh và thời gian.</p>
        </div>
        <div class="flex items-center gap-2.5">
            <button type="button" class="h-11 rounded-[12px] px-4 text-[15px] font-medium text-slate-600 hover:bg-slate-100" @click="router.push('/promotions')">Hủy</button>
            <button v-if="!id" type="button" :class="btnOutline" :disabled="saving" @click="save('paused')">Lưu, chưa bật</button>
            <button type="button" :class="btnPrimary" :disabled="saving" data-testid="promotion-save" @click="save(id ? form.status : 'active')"><Check class="size-4" /> {{ id ? 'Lưu' : 'Lưu và bật' }}</button>
        </div>
    </div>

    <div v-if="!loading" class="space-y-5">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <button
                v-for="k in promotionKinds"
                :key="k.key"
                type="button"
                :data-testid="`kind-${k.key}`"
                :class="cn('flex items-center gap-3.5 rounded-[14px] border bg-white px-4 py-4 text-left transition-colors', kind === k.key ? 'border-blue-500 ring-4 ring-blue-50' : 'border-slate-200/80 hover:border-slate-300')"
                @click="pickKind(k.key)"
            >
                <span :class="cn('grid h-11 w-12 shrink-0 place-items-center rounded-[11px] text-[13px] font-bold', kindTone[k.key])">{{ k.badge }}</span>
                <span class="min-w-0 flex-1"><b class="block text-[15px] font-semibold text-slate-900">{{ k.title }}</b><small class="text-[12.5px] text-slate-500">{{ k.hint }}</small></span>
                <span v-if="kind === k.key" class="grid size-6 shrink-0 place-items-center rounded-full bg-blue-600 text-white"><Check class="size-3.5" /></span>
                <span v-else class="size-6 shrink-0 rounded-full border-2 border-slate-200" />
            </button>
        </div>

        <div class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_400px]">
            <div class="space-y-5">
                <section :class="card">
                    <header class="flex items-center gap-3.5 border-b border-slate-100 px-6 py-4">
                        <span class="grid size-10 place-items-center rounded-[11px] bg-blue-50 text-blue-600"><Tag class="size-5" /></span>
                        <div><p class="text-[12px] font-semibold text-slate-400">BƯỚC 1</p><h3 class="text-[16px] font-semibold text-slate-900">Thông tin</h3></div>
                    </header>
                    <div class="grid gap-4 px-6 py-5 sm:grid-cols-2">
                        <FormField label="Tên khuyến mãi" for="p-name" required :error="error?.field('name')"><Input id="p-name" v-model="form.name" placeholder="VD: Giảm 10% đơn từ 500K" data-testid="p-name" /></FormField>
                        <FormField label="Mã giảm giá" for="p-code" :required="kind === 'code'" :optional="kind !== 'code'" :error="error?.field('code')" :hint="kind === 'code' ? undefined : 'Để trống = tự áp dụng, không cần mã'">
                            <Input id="p-code" v-model="form.code" class="uppercase" placeholder="VD: GIAM10" data-testid="p-code" />
                        </FormField>
                    </div>
                </section>

                <section v-if="type !== 'gift'" :class="card">
                    <header class="flex items-center gap-3.5 border-b border-slate-100 px-6 py-4">
                        <span class="grid size-10 place-items-center rounded-[11px] bg-emerald-50 text-emerald-600"><Percent class="size-5" /></span>
                        <div><p class="text-[12px] font-semibold text-slate-400">BƯỚC 2</p><h3 class="text-[16px] font-semibold text-slate-900">Mức giảm</h3></div>
                    </header>
                    <div class="grid gap-4 px-6 py-5 sm:grid-cols-[1fr_1.4fr_1fr]">
                        <FormField v-if="type === 'order'" label="Đơn tối thiểu" for="p-min" :error="error?.field('min_subtotal')"><Input id="p-min" v-model="form.min_subtotal" type="number" min="0" placeholder="0" suffix="₫" data-testid="p-min" /></FormField>
                        <FormField :label="form.discount_kind === 'fixed_price' ? 'Giá bán còn' : 'Giảm'" for="p-value" required :error="error?.field('discount_value') ?? error?.field('discount_kind')" :class="type !== 'order' && 'sm:col-span-2'">
                            <div class="flex gap-2">
                                <Input id="p-value" v-model="form.discount_value" type="number" min="0" data-testid="p-value" />
                                <SegmentedControl v-model="form.discount_kind" :options="kindOptions" class="shrink-0" testid="p-kind" aria-label="Kiểu giảm" />
                            </div>
                        </FormField>
                        <FormField v-if="form.discount_kind === 'percent'" label="Giảm tối đa" for="p-max" :error="error?.field('max_discount')" hint="Để trống = không giới hạn"><Input id="p-max" v-model="form.max_discount" type="number" min="0" suffix="₫" /></FormField>
                    </div>
                    <div v-if="type === 'order'" class="px-6 pb-5">
                        <div class="rounded-[12px] border border-dashed border-slate-300 p-4">
                            <div class="mb-3 flex items-center justify-between"><p class="text-[14px] font-medium text-slate-800">Bậc giảm thêm</p><span class="text-[12.5px] text-slate-400">Đơn càng lớn giảm càng nhiều</span></div>
                            <div v-for="(t, i) in form.tiers" :key="i" class="mb-3 grid grid-cols-[1fr_1fr_44px] items-end gap-3">
                                <FormField label="Đơn từ"><Input v-model="t.min_subtotal" type="number" min="0" suffix="₫" aria-label="Đơn từ" /></FormField>
                                <FormField label="Giảm"><Input v-model="t.value" type="number" min="0" :suffix="form.discount_kind === 'percent' ? '%' : '₫'" aria-label="Mức giảm" /></FormField>
                                <button type="button" class="grid size-11 place-items-center rounded-[10px] text-slate-400 hover:bg-red-50 hover:text-red-600" aria-label="Bỏ bậc" @click="form.tiers.splice(i, 1)"><Trash2 class="size-4" /></button>
                            </div>
                            <button v-if="form.tiers.length < 5" type="button" class="inline-flex items-center gap-1.5 text-[14px] font-medium text-blue-600 hover:underline" @click="form.tiers.push({ min_subtotal: '', value: '' })"><Plus class="size-4" /> Thêm bậc</button>
                        </div>
                    </div>
                </section>

                <section v-else :class="card">
                    <header class="flex items-center gap-3.5 border-b border-slate-100 px-6 py-4">
                        <span class="grid size-10 place-items-center rounded-[11px] bg-emerald-50 text-emerald-600"><Percent class="size-5" /></span>
                        <div><p class="text-[12px] font-semibold text-slate-400">BƯỚC 2</p><h3 class="text-[16px] font-semibold text-slate-900">Điều kiện tặng</h3></div>
                    </header>
                    <div class="grid gap-4 px-6 py-5 sm:grid-cols-3">
                        <FormField label="Mua đủ" for="p-buy" required :error="error?.field('buy_qty')"><Input id="p-buy" v-model="form.buy_qty" type="number" min="1" suffix="sp" data-testid="p-buy" /></FormField>
                        <FormField label="Tặng số lượng" for="p-gqty" required :error="error?.field('gift_qty')"><Input id="p-gqty" v-model="form.gift_qty" type="number" min="1" suffix="sp" /></FormField>
                        <FormField label="Đơn tối thiểu" for="p-gmin"><Input id="p-gmin" v-model="form.min_subtotal" type="number" min="0" placeholder="0" suffix="₫" /></FormField>
                        <FormField label="Sản phẩm tặng" required class="sm:col-span-3" :error="error?.field('gift_variant_id')">
                            <div v-if="gift" class="flex h-11 items-center justify-between rounded-[10px] border border-slate-200 bg-slate-50 px-3.5 text-[14px]" data-testid="p-gift">
                                <span><span class="font-mono text-[13px] text-slate-500">{{ gift.sku }}</span> · {{ gift.name }}</span>
                                <button type="button" class="grid size-8 place-items-center rounded-[8px] text-slate-400 hover:bg-white" aria-label="Đổi sản phẩm tặng" @click="gift = null"><X class="size-4" /></button>
                            </div>
                            <SkuPicker v-else placeholder="Tìm sản phẩm tặng theo SKU, tên" @pick="(v) => (gift = asPicked(v))" />
                        </FormField>
                    </div>
                </section>

                <section :class="card">
                    <header class="flex items-center gap-3.5 border-b border-slate-100 px-6 py-4">
                        <span class="grid size-10 place-items-center rounded-[11px] bg-violet-50 text-violet-600"><Store class="size-5" /></span>
                        <div><p class="text-[12px] font-semibold text-slate-400">BƯỚC 3</p><h3 class="text-[16px] font-semibold text-slate-900">Áp dụng cho</h3><p class="text-[13px] text-slate-500">Bỏ trống = áp dụng cho tất cả. Đơn sàn theo khuyến mãi của sàn.</p></div>
                    </header>
                    <div class="space-y-5 px-6 py-5">
                        <div>
                            <p class="mb-2 text-[13.5px] font-medium text-slate-700">Kênh bán</p>
                            <div class="flex flex-wrap gap-2">
                                <button v-for="c in channels" :key="c.id" type="button" :class="chipClass(form.channel_ids.includes(c.id))" @click="toggle(form.channel_ids, c.id)">
                                    <Check v-if="form.channel_ids.includes(c.id)" class="size-3.5" /><Plus v-else class="size-3.5 text-slate-400" />{{ c.name }}
                                </button>
                            </div>
                        </div>
                        <div v-if="type !== 'order'">
                            <p class="mb-2 text-[13.5px] font-medium text-slate-700">Sản phẩm áp dụng <span class="font-normal text-slate-400">· bỏ trống cả sản phẩm và danh mục = mọi sản phẩm</span></p>
                            <div v-if="variants.length" class="mb-2 flex flex-wrap gap-2">
                                <span v-for="(v, i) in variants" :key="v.id" class="inline-flex h-9 items-center gap-1 rounded-[10px] border border-blue-300 bg-blue-50 pl-3 pr-1 text-[13.5px] font-medium text-blue-700">
                                    {{ v.sku }} <button type="button" class="grid size-7 place-items-center rounded-[8px] hover:bg-blue-100" :aria-label="`Bỏ ${v.sku}`" @click="variants.splice(i, 1)"><X class="size-3.5" /></button>
                                </span>
                            </div>
                            <SkuPicker placeholder="Thêm sản phẩm theo SKU, tên" :exclude="variants.map((v) => v.id)" @pick="addVariant" />
                            <p v-if="error?.field('variant_ids')" class="mt-1.5 text-[12.5px] text-red-600">{{ error.field('variant_ids') }}</p>
                            <div v-if="categories.length" class="mt-3 flex flex-wrap items-center gap-2">
                                <span class="text-[13px] text-slate-500">Danh mục:</span>
                                <button v-for="c in categories" :key="c.id" type="button" :class="chipClass(form.category_ids.includes(c.id))" @click="toggle(form.category_ids, c.id)">
                                    <Check v-if="form.category_ids.includes(c.id)" class="size-3.5" /><Plus v-else class="size-3.5 text-slate-400" />{{ c.name }}
                                </button>
                            </div>
                        </div>
                        <div v-if="groups.length">
                            <p class="mb-2 text-[13.5px] font-medium text-slate-700">Nhóm khách <span class="font-normal text-slate-400">· chọn nhóm để không áp cho khách sỉ chẳng hạn</span></p>
                            <div class="flex flex-wrap gap-2">
                                <button v-for="g in groups" :key="g.id" type="button" :class="chipClass(form.customer_group_ids.includes(g.id))" @click="toggle(form.customer_group_ids, g.id)">
                                    <Check v-if="form.customer_group_ids.includes(g.id)" class="size-3.5" /><Plus v-else class="size-3.5 text-slate-400" />{{ g.name }}
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <section :class="card">
                    <header class="flex items-center gap-3.5 border-b border-slate-100 px-6 py-4">
                        <span class="grid size-10 place-items-center rounded-[11px] bg-amber-50 text-amber-600"><CalendarRange class="size-5" /></span>
                        <div><p class="text-[12px] font-semibold text-slate-400">BƯỚC 4</p><h3 class="text-[16px] font-semibold text-slate-900">Thời gian và giới hạn</h3><p class="text-[13px] text-slate-500">Khuyến mãi tự bật và tự tắt theo giờ đã đặt.</p></div>
                    </header>
                    <div class="grid gap-4 px-6 py-5 sm:grid-cols-2">
                        <FormField label="Bắt đầu" :error="error?.field('starts_at')" hint="Để trống = bắt đầu ngay"><DateTimeInput v-model="form.starts_at" /></FormField>
                        <FormField label="Kết thúc" :error="error?.field('ends_at')" hint="Để trống = chạy đến khi tắt"><DateTimeInput v-model="form.ends_at" /></FormField>
                        <FormField label="Tổng lượt dùng tối đa" for="p-limit" :error="error?.field('usage_limit')" hint="Để trống = không giới hạn"><Input id="p-limit" v-model="form.usage_limit" type="number" min="1" suffix="lượt" /></FormField>
                        <FormField label="Mỗi khách dùng tối đa" for="p-per" :error="error?.field('per_customer_limit')" hint="Cần chọn khách trên đơn">
                            <Input id="p-per" v-model="form.per_customer_limit" type="number" min="1" suffix="lượt" data-testid="p-per" />
                        </FormField>
                    </div>
                </section>
                <p v-if="error && !error.body.errors" class="text-[14px] text-red-600">{{ error.message }}</p>
            </div>

            <div class="space-y-5 lg:sticky lg:top-4">
                <section :class="cn(card, 'p-6')">
                    <h3 class="mb-4 text-[16px] font-semibold text-slate-900">Tóm tắt</h3>
                    <div class="rounded-[14px] bg-gradient-to-br from-blue-600 to-indigo-600 p-5 text-white">
                        <p class="text-[13px] text-blue-100">{{ type === 'gift' ? 'Khách được tặng' : 'Khách được giảm' }}</p>
                        <p class="mt-1 text-[30px] font-bold leading-tight">{{ headline }}</p>
                    </div>
                    <div class="mt-4 space-y-2 text-[14px] text-slate-600" data-testid="promotion-summary">
                        <p v-for="(line, i) in summary" :key="i">{{ line }}</p>
                    </div>
                </section>
                <section v-if="example" :class="cn(card, 'p-6')">
                    <h3 class="mb-3 text-[16px] font-semibold text-slate-900">Ví dụ trên đơn</h3>
                    <div class="flex justify-between py-1.5 text-[14px]"><span class="text-slate-500">Tiền hàng</span><span class="tabular-nums text-slate-800">{{ formatMoney(example.goods) }}</span></div>
                    <div class="flex justify-between py-1.5 text-[14px]"><span class="text-slate-500">Giảm</span><span class="tabular-nums text-emerald-600">−{{ formatMoney(example.off) }}</span></div>
                    <div class="flex justify-between border-t border-slate-100 pt-2.5 text-[14px]"><span class="font-medium text-slate-700">Khách trả</span><span class="font-semibold tabular-nums text-slate-900">{{ formatMoney(example.pay) }}</span></div>
                </section>
                <p class="flex gap-2 rounded-[14px] border border-amber-200 bg-amber-50 p-4 text-[13px] text-amber-800">
                    <Lightbulb class="mt-0.5 size-4 shrink-0" />
                    <span>Đơn đủ điều kiện nhiều khuyến mãi giảm tiền tự động thì chọn cái giảm nhiều nhất. Quà tặng và mã giảm giá cộng thêm. Người có quyền sửa giá có thể bỏ khuyến mãi tự động trên một đơn.</span>
                </p>
            </div>
        </div>
    </div>
</template>
