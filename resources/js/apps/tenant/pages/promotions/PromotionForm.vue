<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Plus, X } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import SkuPicker from '../../components/SkuPicker.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import DateTimeInput from '@/shared/ui/DateTimeInput.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
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
    { value: 'percent', label: '%' },
    { value: 'amount', label: '₫' },
    ...(type.value === 'product' ? [{ value: 'fixed_price', label: 'Đồng giá' }] : []),
]);

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
    <PageHeader :title="id ? 'Sửa khuyến mãi' : 'Tạo khuyến mãi'" :back="{ to: '/promotions', label: 'Khuyến mãi' }">
        <Button variant="outline" @click="router.push('/promotions')">Hủy</Button>
        <Button v-if="!id" variant="outline" :loading="saving" @click="save('paused')">Lưu, chưa bật</Button>
        <Button :loading="saving" data-testid="promotion-save" @click="save(id ? form.status : 'active')">{{ id ? 'Lưu' : 'Lưu và bật' }}</Button>
    </PageHeader>

    <div v-if="!loading" class="space-y-4">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <button
                v-for="k in promotionKinds"
                :key="k.key"
                type="button"
                :data-testid="`kind-${k.key}`"
                :class="cn('flex items-start gap-3 rounded-lg border bg-card p-3 text-left hover:border-primary/60', kind === k.key && 'border-primary ring-1 ring-primary')"
                @click="pickKind(k.key)"
            >
                <span class="grid h-9 min-w-9 place-items-center rounded-md bg-primary/10 px-1.5 text-xs font-bold text-primary">{{ k.badge }}</span>
                <span><b class="block text-sm">{{ k.title }}</b><small class="text-xs text-muted-foreground">{{ k.hint }}</small></span>
            </button>
        </div>

        <div class="grid items-start gap-4 lg:grid-cols-[1fr_320px]">
            <div class="space-y-4">
                <Card caps title="Thông tin">
                    <div class="grid gap-3 p-5 sm:grid-cols-2">
                        <FormField label="Tên khuyến mãi" for="p-name" :error="error?.field('name')"><Input id="p-name" v-model="form.name" placeholder="VD: Giảm 10% đơn từ 500K" data-testid="p-name" /></FormField>
                        <FormField :label="kind === 'code' ? 'Mã giảm giá' : 'Mã (bỏ trống = tự áp dụng)'" for="p-code" :error="error?.field('code')">
                            <Input id="p-code" v-model="form.code" class="uppercase" placeholder="VD: GIAM10" data-testid="p-code" />
                        </FormField>
                    </div>
                </Card>

                <Card v-if="type !== 'gift'" caps title="Mức giảm">
                    <div class="grid gap-3 p-5 sm:grid-cols-3">
                        <FormField v-if="type === 'order'" label="Đơn tối thiểu (₫)" for="p-min" :error="error?.field('min_subtotal')"><Input id="p-min" v-model="form.min_subtotal" type="number" min="0" placeholder="0" data-testid="p-min" /></FormField>
                        <FormField :label="form.discount_kind === 'fixed_price' ? 'Giá bán còn (₫)' : 'Giảm'" for="p-value" :error="error?.field('discount_value') ?? error?.field('discount_kind')">
                            <div class="flex gap-2">
                                <Input id="p-value" v-model="form.discount_value" type="number" min="0" data-testid="p-value" />
                                <Select v-model="form.discount_kind" class="w-28" :options="kindOptions" aria-label="Kiểu giảm" data-testid="p-kind" />
                            </div>
                        </FormField>
                        <FormField v-if="form.discount_kind === 'percent'" label="Giảm tối đa (₫)" for="p-max" :error="error?.field('max_discount')"><Input id="p-max" v-model="form.max_discount" type="number" min="0" placeholder="Không giới hạn" /></FormField>
                    </div>
                    <div v-if="type === 'order'" class="space-y-2 border-t px-5 py-4">
                        <p class="text-xs text-muted-foreground">Thêm bậc (giảm nhiều hơn khi đơn lớn hơn)</p>
                        <div v-for="(t, i) in form.tiers" :key="i" class="flex items-center gap-2 text-sm">
                            Từ <Input v-model="t.min_subtotal" type="number" min="0" class="w-36" aria-label="Đơn từ" /> ₫ giảm
                            <Input v-model="t.value" type="number" min="0" class="w-28" aria-label="Mức giảm" /> {{ form.discount_kind === 'percent' ? '%' : '₫' }}
                            <Button variant="ghost" size="sm" aria-label="Bỏ bậc" @click="form.tiers.splice(i, 1)"><X /></Button>
                        </div>
                        <Button v-if="form.tiers.length < 5" variant="outline" size="sm" @click="form.tiers.push({ min_subtotal: '', value: '' })"><Plus /> Thêm bậc</Button>
                    </div>
                </Card>

                <Card v-else caps title="Điều kiện tặng">
                    <div class="grid gap-3 p-5 sm:grid-cols-3">
                        <FormField label="Mua đủ (sản phẩm áp dụng)" for="p-buy" :error="error?.field('buy_qty')"><Input id="p-buy" v-model="form.buy_qty" type="number" min="1" data-testid="p-buy" /></FormField>
                        <FormField label="Tặng số lượng" for="p-gqty" :error="error?.field('gift_qty')"><Input id="p-gqty" v-model="form.gift_qty" type="number" min="1" /></FormField>
                        <FormField label="Đơn tối thiểu (₫)" for="p-gmin"><Input id="p-gmin" v-model="form.min_subtotal" type="number" min="0" placeholder="0" /></FormField>
                        <FormField label="Sản phẩm tặng" class="sm:col-span-3" :error="error?.field('gift_variant_id')">
                            <div v-if="gift" class="flex items-center justify-between rounded-md border bg-muted/30 px-3 py-2 text-sm" data-testid="p-gift">
                                <span><span class="font-mono text-xs">{{ gift.sku }}</span> · {{ gift.name }}</span>
                                <Button variant="ghost" size="sm" aria-label="Đổi sản phẩm tặng" @click="gift = null"><X /></Button>
                            </div>
                            <SkuPicker v-else placeholder="Tìm sản phẩm tặng theo SKU, tên" @pick="(v) => (gift = asPicked(v))" />
                        </FormField>
                    </div>
                </Card>

                <Card caps title="Áp dụng cho">
                    <div class="space-y-4 p-5">
                        <div>
                            <p class="mb-1.5 text-xs text-muted-foreground">Kênh bán (bỏ trống = mọi kênh riêng và bán tại quầy; đơn sàn theo khuyến mãi của sàn)</p>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="c in channels"
                                    :key="c.id"
                                    type="button"
                                    :class="cn('rounded-full border px-3 py-1 text-sm', form.channel_ids.includes(c.id) && 'border-primary bg-primary/10 text-primary')"
                                    @click="toggle(form.channel_ids, c.id)"
                                >
                                    {{ c.name }}
                                </button>
                            </div>
                        </div>
                        <div v-if="type !== 'order'">
                            <p class="mb-1.5 text-xs text-muted-foreground">Sản phẩm áp dụng (bỏ trống cả sản phẩm và danh mục = mọi sản phẩm)</p>
                            <div v-if="variants.length" class="mb-2 flex flex-wrap gap-2">
                                <span v-for="(v, i) in variants" :key="v.id" class="inline-flex items-center gap-1 rounded-full border bg-muted/30 py-0.5 pl-3 pr-1 text-sm">
                                    {{ v.sku }} <button type="button" class="rounded-full p-0.5 hover:bg-muted" :aria-label="`Bỏ ${v.sku}`" @click="variants.splice(i, 1)"><X class="size-3.5" /></button>
                                </span>
                            </div>
                            <SkuPicker placeholder="Thêm sản phẩm theo SKU, tên" :exclude="variants.map((v) => v.id)" @pick="addVariant" />
                            <p v-if="error?.field('variant_ids')" class="mt-1 text-xs text-destructive">{{ error.field('variant_ids') }}</p>
                            <div v-if="categories.length" class="mt-3 flex flex-wrap gap-2">
                                <span class="self-center text-xs text-muted-foreground">Danh mục:</span>
                                <button
                                    v-for="c in categories"
                                    :key="c.id"
                                    type="button"
                                    :class="cn('rounded-full border px-3 py-1 text-sm', form.category_ids.includes(c.id) && 'border-primary bg-primary/10 text-primary')"
                                    @click="toggle(form.category_ids, c.id)"
                                >
                                    {{ c.name }}
                                </button>
                            </div>
                        </div>
                        <div v-if="groups.length">
                            <p class="mb-1.5 text-xs text-muted-foreground">Nhóm khách (bỏ trống = mọi khách; chọn nhóm để không áp cho khách sỉ chẳng hạn)</p>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="g in groups"
                                    :key="g.id"
                                    type="button"
                                    :class="cn('rounded-full border px-3 py-1 text-sm', form.customer_group_ids.includes(g.id) && 'border-primary bg-primary/10 text-primary')"
                                    @click="toggle(form.customer_group_ids, g.id)"
                                >
                                    {{ g.name }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Card>

                <Card caps title="Thời gian và giới hạn">
                    <div class="grid gap-3 p-5 sm:grid-cols-2 lg:grid-cols-4">
                        <FormField label="Bắt đầu" :error="error?.field('starts_at')"><DateTimeInput v-model="form.starts_at" /></FormField>
                        <FormField label="Kết thúc" :error="error?.field('ends_at')"><DateTimeInput v-model="form.ends_at" /></FormField>
                        <FormField label="Tổng lượt dùng tối đa" for="p-limit" :error="error?.field('usage_limit')"><Input id="p-limit" v-model="form.usage_limit" type="number" min="1" placeholder="Không giới hạn" /></FormField>
                        <FormField label="Mỗi khách dùng tối đa" for="p-per" :error="error?.field('per_customer_limit')" hint="Cần chọn khách trên đơn">
                            <Input id="p-per" v-model="form.per_customer_limit" type="number" min="1" placeholder="Không giới hạn" data-testid="p-per" />
                        </FormField>
                    </div>
                </Card>
                <p v-if="error && !error.body.errors" class="text-sm text-destructive">{{ error.message }}</p>
            </div>

            <Card caps title="Tóm tắt" class="lg:sticky lg:top-4">
                <div class="space-y-2 p-5 text-sm" data-testid="promotion-summary">
                    <p v-for="(line, i) in summary" :key="i">{{ line }}</p>
                    <p class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">
                        Nếu đơn đủ điều kiện nhiều khuyến mãi giảm tiền tự động, EzSell chọn cái giảm nhiều nhất. Quà tặng và mã giảm giá cộng thêm. Người có quyền sửa giá có thể bỏ khuyến mãi tự động trên một đơn.
                    </p>
                </div>
            </Card>
        </div>
    </div>
</template>
