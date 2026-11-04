<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, ImagePlus, Plus, ScanBarcode, Sparkles, Tags, Trash2, X } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Alert from '@/shared/ui/Alert.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatMoney } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import { useIdempotencyKey } from '@/shared/composables/useIdempotencyKey';
import SkuPicker from '../../components/SkuPicker.vue';
import { useSettingsStore } from '@/shared/stores/settings';
import type { Product, Ref, Variant, Warehouse } from '@/shared/types';

interface VariantRow {
    id?: string;
    sku: string;
    name: string;
    barcode: string;
    options: Record<string, string>;
    ref_price: string;
    wholesale_price: string;
    weight_gram: string;
    /** Usual supplier (ULID) and its code for this SKU, used by reorder suggestions and purchase orders. */
    supplier_id: string;
    supplier_sku: string;
    /** Package size in mm, for shipping fees. */
    length_mm: string;
    width_mm: string;
    height_mm: string;
    cost_price?: string;
    opening_qty: string;
    opening_cost: string;
    /** Pack size (đơn vị quy đổi): this SKU = quantity × base SKU. */
    conversion?: { base_sku: string; quantity: number } | null;
    skuTaken?: string | null;
}

const UNITS = ['Cái', 'Chiếc', 'Bộ', 'Đôi', 'Hộp', 'Gói', 'Chai', 'Thùng', 'Kg', 'Mét'];
const VAT_RATES = [
    { value: '', label: 'Không áp dụng' },
    { value: '0', label: '0%' },
    { value: '5', label: '5%' },
    { value: '8', label: '8%' },
    { value: '10', label: '10%' },
];

const route = useRoute();
const router = useRouter();
const toast = useToast();
const idempotency = useIdempotencyKey();
const tenant = useTenantStore();
const canManage = computed(() => tenant.can('products.manage'));
const canCost = computed(() => tenant.can('products.cost.view'));
const canReceive = computed(() => tenant.can('inventory.receive'));
const canSuppliers = computed(() => tenant.can('purchasing.view'));
const suppliers = ref<Ref[]>([]);
const productId = computed(() => (route.params.id as string | undefined) ?? null);
const copyFrom = computed(() => (route.query.copy as string | undefined) ?? null);
const isNew = computed(() => !productId.value);

const loading = ref(!isNew.value || !!copyFrom.value);
const loadError = ref<string | null>(null);
const categories = ref<Ref[]>([]);
const brands = ref<Ref[]>([]);
const knownTags = ref<string[]>([]);
const warehouses = ref<Warehouse[]>([]);
const images = ref<{ id: number; url: string }[]>([]);
const form = reactive({ name: '', description: '', status: 'active', category_id: '', brand_id: '', unit: '', vat_rate: '', opening_warehouse_id: '' });
const tags = ref<string[]>([]);
const tagInput = ref('');
const variants = ref<VariantRow[]>([]);
const saving = ref(false);
const error = ref<ApiError | null>(null);
// Combo: one SKU, no stock of its own; components are other SKUs with a quantity per combo.
const productType = ref<'standard' | 'combo'>(route.query.type === 'combo' ? 'combo' : 'standard');
const isCombo = computed(() => productType.value === 'combo');
const components = ref<{ variant: { id: string; sku: string; name: string; cost_price?: string; ref_price?: string | number | null }; quantity: number }[]>([]);
const componentCost = computed(() => components.value.reduce((s, c) => s + Number(c.variant.cost_price ?? 0) * c.quantity, 0));
const componentPrice = computed(() => components.value.reduce((s, c) => s + Number(c.variant.ref_price ?? 0) * c.quantity, 0));
function addComponent(v: Variant) {
    const existing = components.value.find((c) => c.variant.id === v.id);
    if (existing) existing.quantity++;
    else components.value.push({ variant: { id: v.id!, sku: v.sku, name: [v.product?.name, v.name].filter(Boolean).join(' - '), cost_price: v.cost_price, ref_price: v.ref_price }, quantity: 1 });
}
function setType(type: 'standard' | 'combo') {
    productType.value = type;
    if (type === 'combo') variants.value = [variants.value[0] ?? emptyRow()];
}

// Pack sizes are variants with a conversion, shown in their own card (indexes kept for server error keys).
const packRows = computed(() => variants.value.map((v, i) => ({ v, i })).filter((r) => r.v.conversion));
const baseOptions = computed(() => variants.value.filter((v) => !v.conversion && v.sku.trim()).map((v) => v.sku.trim()));
function addPack() {
    variants.value.push({ ...emptyRow(), conversion: { base_sku: baseOptions.value[0] ?? '', quantity: 2 } });
}

const showOpening = computed(() => isNew.value && canReceive.value && !isCombo.value);
const hasOpening = computed(() => !isCombo.value && variants.value.some((v) => Number(v.opening_qty) > 0));
// Opening stock without a cost starts the average cost at 0 ₫: gross profit would look higher than it is.
const noCost = (v: { opening_qty: string; opening_cost: string; conversion?: unknown }) => !v.conversion && Number(v.opening_qty) > 0 && !(Number(v.opening_cost) > 0);
const zeroCostOpening = computed(() => (isCombo.value ? [] : variants.value.filter(noCost)));

function addTag(raw = tagInput.value) {
    for (const t of raw.split(',').map((s) => s.trim()).filter(Boolean)) {
        if (!tags.value.some((x) => x.toLowerCase() === t.toLowerCase()) && tags.value.length < 20) tags.value.push(t.slice(0, 40));
    }
    tagInput.value = '';
}

// Variant generator: option groups like Màu = Trắng, Đen and Size = M, L.
const groups = ref<{ name: string; values: string }[]>([
    { name: 'Màu', values: '' },
    { name: 'Size', values: '' },
]);
const skuPrefix = ref('');

/** Fill an empty barcode with an unused internal EAN-13 (prefix 2x, the range for in-store codes). */
async function suggestBarcode(row: VariantRow) {
    try {
        const { data } = await api.get('/products/barcode-suggestion');
        row.barcode = data.data.barcode;
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}

function emptyRow(): VariantRow {
    return { sku: '', name: '', barcode: '', options: {}, ref_price: '', wholesale_price: '', weight_gram: '', supplier_id: '', supplier_sku: '', length_mm: '', width_mm: '', height_mm: '', opening_qty: '', opening_cost: '' };
}

const money = (value: string | number | null | undefined) => (Number(value) ? String(Math.round(Number(value))) : '');

onMounted(async () => {
    // Set the first row before awaiting: rows generated while the lookups load must not be overwritten.
    if (isNew.value && !copyFrom.value) variants.value = [emptyRow()];
    const [c, b, t] = await Promise.all([api.get('/categories'), api.get('/brands'), api.get('/products/tags')]);
    categories.value = c.data.data;
    brands.value = b.data.data;
    knownTags.value = t.data.data;
    if (canSuppliers.value) suppliers.value = (await api.get('/suppliers', { params: { active: 1, per_page: 200 } }).catch(() => null))?.data.data ?? [];
    if (isNew.value && canReceive.value) {
        warehouses.value = ((await api.get('/warehouses')).data.data as Warehouse[]).filter((w) => w.status === 'active');
        form.opening_warehouse_id = (warehouses.value.find((w) => w.is_default) ?? warehouses.value[0])?.id ?? '';
    }
    if (isNew.value && !copyFrom.value) {
        // New product: the sales tax set in Cấu hình → Thuế (0 = not applied).
        const rate = (await useSettingsStore().load().catch(() => null))?.tax.sale_rate;
        if (rate) form.vat_rate = String(rate);
        return;
    }
    try {
        const p: Product = (await api.get(`/products/${productId.value ?? copyFrom.value}`)).data.data;
        const copying = isNew.value;
        Object.assign(form, {
            name: copying ? `${p.name} (bản sao)` : p.name,
            description: p.description ?? '',
            status: p.status,
            category_id: p.category?.id ?? '',
            brand_id: p.brand?.id ?? '',
            unit: p.unit ?? '',
            vat_rate: p.vat_rate === null || p.vat_rate === undefined ? '' : String(Number(p.vat_rate)),
        });
        tags.value = [...(p.tags ?? [])];
        images.value = copying ? [] : (p.images ?? []);
        productType.value = p.type ?? 'standard';
        components.value = (p.variants?.[0]?.components ?? []).map((c) => ({ variant: { ...c.variant }, quantity: c.quantity }));
        // A copy keeps names, options and prices; SKU and barcode must be new (unique per business).
        variants.value = (p.variants ?? []).map((v) => ({
            ...(copying ? {} : { id: v.id, cost_price: v.cost_price }),
            sku: copying ? '' : v.sku,
            name: v.name ?? '',
            barcode: copying ? '' : (v.barcode ?? ''),
            options: Array.isArray(v.options) ? {} : v.options,
            ref_price: money(v.ref_price),
            wholesale_price: money(v.wholesale_price),
            weight_gram: v.weight_gram ? String(v.weight_gram) : '',
            supplier_id: v.supplier?.id ?? '',
            supplier_sku: v.supplier_sku ?? '',
            length_mm: v.length_mm ? String(v.length_mm) : '',
            width_mm: v.width_mm ? String(v.width_mm) : '',
            height_mm: v.height_mm ? String(v.height_mm) : '',
            opening_qty: '',
            opening_cost: '',
            conversion:
                p.type !== 'combo' && v.components?.length === 1
                    ? { base_sku: copying ? '' : v.components[0]!.variant.sku, quantity: v.components[0]!.quantity }
                    : null,
        }));
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
});

function slug(value: string): string {
    return value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/đ/gi, 'D')
        .replace(/[^A-Za-z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
        .toUpperCase();
}

function generate() {
    const active = groups.value.map((g) => ({ name: g.name.trim(), values: g.values.split(',').map((v) => v.trim()).filter(Boolean) })).filter((g) => g.name && g.values.length);
    if (!active.length) return;
    let combos: Record<string, string>[] = [{}];
    for (const g of active) combos = combos.flatMap((c) => g.values.map((v) => ({ ...c, [g.name]: v })));

    const prefix = slug(skuPrefix.value || form.name).slice(0, 20) || 'SKU';
    const price = variants.value.find((v) => v.ref_price)?.ref_price ?? '';
    const existing = new Set(variants.value.map((v) => v.sku.toUpperCase()));
    const rows = combos
        .map((options) => ({ ...emptyRow(), options, name: Object.values(options).join(' / '), sku: `${prefix}-${Object.values(options).map(slug).join('-')}`, ref_price: price }))
        .filter((r) => !existing.has(r.sku));
    variants.value = [...variants.value.filter((v) => v.id || v.sku), ...rows];
}

let skuTimer: ReturnType<typeof setTimeout> | undefined;
function checkSku(row: VariantRow) {
    clearTimeout(skuTimer);
    row.skuTaken = null;
    if (!row.sku.trim()) return;
    skuTimer = setTimeout(async () => {
        const { data } = await api.get('/products/sku-check', { params: { sku: row.sku, ignore: row.id } });
        row.skuTaken = data.available ? null : `Đã dùng cho "${data.product?.name ?? 'sản phẩm khác'}"`;
    }, 300);
}

const duplicateSkus = computed(() => {
    const seen = new Map<string, number>();
    variants.value.forEach((v) => v.sku && seen.set(v.sku.trim().toLowerCase(), (seen.get(v.sku.trim().toLowerCase()) ?? 0) + 1));
    return new Set([...seen].filter(([, n]) => n > 1).map(([k]) => k));
});

function fieldError(i: number, field: string): string | undefined {
    return error.value?.field(`variants.${i}.${field}`);
}

const num = (value: string) => (value === '' || value === null || value === undefined ? null : Number(value));

async function save(then: 'detail' | 'labels' = 'detail') {
    addTag();
    saving.value = true;
    error.value = null;
    try {
        const payload = {
            name: form.name,
            description: form.description || null,
            status: form.status,
            category_id: form.category_id || null,
            brand_id: form.brand_id || null,
            unit: form.unit.trim() || null,
            tags: tags.value,
            vat_rate: form.vat_rate === '' ? null : Number(form.vat_rate),
            ...(isNew.value && hasOpening.value ? { opening_warehouse_id: form.opening_warehouse_id || null } : {}),
            ...(isNew.value ? { type: productType.value } : {}),
            ...(isCombo.value ? { components: components.value.map((c) => ({ variant_id: c.variant.id, quantity: c.quantity })) } : {}),
            variants: variants.value.map((v) => ({
                ...(v.id ? { id: v.id } : {}),
                sku: v.sku.trim(),
                name: v.name || null,
                barcode: v.barcode || null,
                options: Object.keys(v.options).length ? v.options : null,
                ref_price: num(v.ref_price),
                wholesale_price: num(v.wholesale_price),
                weight_gram: num(v.weight_gram),
                // Only sent when the supplier list was loaded, so a user without purchasing access keeps what is saved.
                ...(canSuppliers.value ? { supplier_id: v.supplier_id || null, supplier_sku: v.supplier_sku.trim() || null } : {}),
                length_mm: num(v.length_mm),
                width_mm: num(v.width_mm),
                height_mm: num(v.height_mm),
                ...(isNew.value && !v.conversion && Number(v.opening_qty) > 0 ? { opening_qty: Number(v.opening_qty), opening_cost: num(v.opening_cost) ?? 0 } : {}),
                ...(!isCombo.value && v.conversion ? { conversion: { base_sku: v.conversion.base_sku, quantity: Number(v.conversion.quantity) } } : {}),
            })),
        };
        const { data } = isNew.value ? await api.post('/products', payload, idempotency.config()) : await api.put(`/products/${productId.value}`, payload);
        idempotency.renew();
        toast.success(isNew.value ? 'Đã tạo sản phẩm' : 'Đã lưu thay đổi');
        const id = data.data.id as string;
        if (then === 'labels') await router.push({ path: '/products/labels', query: { products: id } });
        else await router.replace(`/products/${id}`);
    } catch (e) {
        error.value = e as ApiError;
        toast.error(error.value.body.errors ? 'Vui lòng kiểm tra các trường được đánh dấu.' : error.value.message);
    } finally {
        saving.value = false;
    }
}

async function remove() {
    if (!window.confirm(`Xóa sản phẩm "${form.name}"? SKU còn tồn kho sẽ không xóa được.`)) return;
    try {
        await api.delete(`/products/${productId.value}`);
        toast.success('Đã xóa sản phẩm');
        await router.push('/products');
    } catch (e) {
        toast.error((e as ApiError).field('variants') ?? (e as ApiError).message);
    }
}

async function upload(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file || !productId.value) return;
    const body = new FormData();
    body.append('image', file);
    try {
        const { data } = await api.post(`/products/${productId.value}/images`, body);
        images.value.push(data);
    } catch (err) {
        toast.error((err as ApiError).field('image') ?? (err as ApiError).message);
    }
}

async function removeImage(id: number) {
    await api.delete(`/products/${productId.value}/images/${id}`);
    images.value = images.value.filter((i) => i.id !== id);
}
</script>

<template>
    <RouterLink :to="productId ? `/products/${productId}` : '/products'" class="mb-3 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft class="size-4" /> {{ productId ? 'Chi tiết sản phẩm' : 'Sản phẩm' }}
    </RouterLink>
    <ErrorState v-if="loadError" :message="loadError" />
    <div v-else-if="loading" class="space-y-3"><Skeleton class="h-10 w-80" /><Skeleton class="h-72" /></div>
    <form v-else @submit.prevent="save()">
        <PageHeader
            :title="isNew ? (copyFrom ? 'Sao chép sản phẩm' : 'Thêm sản phẩm') : form.name || 'Sản phẩm'"
            :description="copyFrom ? 'Đã chép thông tin, biến thể và giá. Nhập SKU mới cho từng biến thể.' : isNew ? 'Mỗi biến thể là một SKU bán được, có tồn kho riêng.' : undefined"
        >
            <Button v-if="!isNew && canManage" variant="ghost" class="text-destructive" @click="remove"><Trash2 /> Xóa</Button>
            <Button v-if="canManage" variant="outline" :disabled="saving" data-testid="save-and-print" @click="save('labels')"><Tags /> Lưu và in tem</Button>
            <Button v-if="canManage" type="submit" :loading="saving" data-testid="save-product">{{ isNew ? 'Tạo sản phẩm' : 'Lưu thay đổi' }}</Button>
        </PageHeader>

        <div class="grid gap-5 lg:grid-cols-3">
            <div class="space-y-5 lg:col-span-2">
                <div v-if="isNew && !copyFrom" class="grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="Loại sản phẩm">
                    <button
                        v-for="t in [
                            { value: 'standard', title: 'Sản phẩm thường', text: 'Có tồn kho riêng, một hoặc nhiều biến thể.' },
                            { value: 'combo', title: 'Combo', text: 'Gồm nhiều SKU khác. Bán combo thì trừ tồn các SKU thành phần.' },
                        ] as const"
                        :key="t.value"
                        type="button"
                        role="radio"
                        :aria-checked="productType === t.value"
                        :data-testid="`type-${t.value}`"
                        :class="cn('rounded-lg border bg-card p-4 text-left text-sm shadow-xs transition-colors hover:border-primary/60', productType === t.value && 'border-primary ring-2 ring-primary/20')"
                        @click="setType(t.value)"
                    >
                        <p class="font-medium">{{ t.title }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">{{ t.text }}</p>
                    </button>
                </div>

                <Card title="Thông tin chung">
                    <div class="grid gap-4 p-5 sm:grid-cols-2">
                        <FormField label="Tên sản phẩm" for="p-name" :error="error?.field('name')" class="sm:col-span-2">
                            <Input id="p-name" v-model="form.name" required maxlength="255" placeholder="Ví dụ: Áo thun cotton cổ tròn" data-testid="product-name" />
                        </FormField>
                        <FormField label="Danh mục" for="p-cat">
                            <Select id="p-cat" v-model="form.category_id" :options="[{ value: '', label: '— Không chọn —' }, ...categories.map((c) => ({ value: c.id, label: c.name }))]" />
                        </FormField>
                        <FormField label="Thương hiệu" for="p-brand">
                            <Select id="p-brand" v-model="form.brand_id" :options="[{ value: '', label: '— Không chọn —' }, ...brands.map((b) => ({ value: b.id, label: b.name }))]" />
                        </FormField>
                        <FormField label="Đơn vị tính" for="p-unit" :error="error?.field('unit')">
                            <Input id="p-unit" v-model="form.unit" maxlength="30" list="unit-suggestions" placeholder="Cái, Hộp, Kg…" data-testid="product-unit" />
                            <datalist id="unit-suggestions"><option v-for="u in UNITS" :key="u" :value="u" /></datalist>
                        </FormField>
                        <FormField label="Thuế GTGT" for="p-vat" :error="error?.field('vat_rate')">
                            <Select id="p-vat" v-model="form.vat_rate" :options="VAT_RATES" />
                        </FormField>
                        <FormField label="Tags" for="p-tags" class="sm:col-span-2" :error="error?.field('tags')">
                            <div class="flex min-h-9 flex-wrap items-center gap-1.5 rounded-md border border-input bg-card px-2 py-1 shadow-xs focus-within:ring-2 focus-within:ring-ring">
                                <span v-for="(t, i) in tags" :key="t" class="inline-flex items-center gap-1 rounded-full bg-secondary px-2 py-0.5 text-xs">
                                    {{ t }}
                                    <button type="button" class="text-muted-foreground hover:text-foreground" :aria-label="`Bỏ tag ${t}`" @click="tags.splice(i, 1)"><X class="size-3" /></button>
                                </span>
                                <input
                                    id="p-tags"
                                    v-model="tagInput"
                                    list="tag-suggestions"
                                    class="h-7 min-w-32 flex-1 bg-transparent text-sm outline-none"
                                    placeholder="Gõ tag rồi nhấn Enter"
                                    data-testid="product-tags"
                                    @keydown.enter.prevent="addTag()"
                                    @keydown.,.prevent="addTag()"
                                    @blur="addTag()"
                                />
                                <datalist id="tag-suggestions"><option v-for="t in knownTags.filter((k) => !tags.includes(k))" :key="t" :value="t" /></datalist>
                            </div>
                        </FormField>
                        <FormField label="Mô tả" for="p-desc" class="sm:col-span-2">
                            <textarea id="p-desc" v-model="form.description" rows="3" class="w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" />
                        </FormField>
                    </div>
                </Card>

                <Card v-if="isCombo" title="Thành phần combo" description="Mỗi combo bán ra sẽ giữ và xuất kho đúng số lượng các SKU dưới đây." data-testid="combo-components">
                    <div v-if="canManage" class="border-b px-5 py-3">
                        <SkuPicker stocked placeholder="Tìm hoặc quét SKU thành phần" :exclude="components.map((c) => c.variant.id)" @pick="addComponent" />
                    </div>
                    <p v-if="error?.field('components')" class="px-5 pt-3 text-sm text-destructive">{{ error.field('components') }}</p>
                    <p v-if="!components.length" class="px-5 py-6 text-center text-sm text-muted-foreground">Chưa có SKU thành phần.</p>
                    <table v-else class="w-full text-sm">
                        <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-5 py-2 font-medium">SKU thành phần</th>
                                <th class="px-3 py-2 text-right font-medium">Số lượng / combo</th>
                                <th v-if="canCost" class="px-3 py-2 text-right font-medium">Giá vốn BQ</th>
                                <th class="w-10" />
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="(c, i) in components" :key="c.variant.id">
                                <td class="px-5 py-2">
                                    <p class="font-mono text-xs font-medium">{{ c.variant.sku }}</p>
                                    <p class="text-xs text-muted-foreground">{{ c.variant.name }}</p>
                                    <p v-if="error?.field(`components.${i}.variant_id`)" class="text-xs text-destructive">{{ error.field(`components.${i}.variant_id`) }}</p>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <input v-model.number="c.quantity" type="number" min="1" max="10000" :disabled="!canManage" :data-testid="`component-qty-${i}`" aria-label="Số lượng mỗi combo" class="h-8 w-20 rounded-md border border-input bg-card px-2 text-right tabular-nums" />
                                </td>
                                <td v-if="canCost" class="px-3 py-2 text-right tabular-nums text-muted-foreground">{{ c.variant.cost_price ? formatMoney(Number(c.variant.cost_price) * c.quantity) : '—' }}</td>
                                <td class="px-2 text-right">
                                    <Button v-if="canManage" variant="ghost" size="icon" class="size-8" title="Bỏ thành phần" @click="components.splice(i, 1)"><Trash2 class="text-muted-foreground" /></Button>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t text-xs text-muted-foreground">
                            <tr>
                                <td class="px-5 py-2" colspan="2">Tổng giá bán lẻ nếu mua lẻ: <span class="font-medium text-foreground">{{ formatMoney(componentPrice) }}</span></td>
                                <td v-if="canCost" class="px-3 py-2 text-right">Giá vốn combo: <span class="font-medium text-foreground">{{ formatMoney(componentCost) }}</span></td>
                                <td />
                            </tr>
                        </tfoot>
                    </table>
                </Card>

                <Card :title="isCombo ? 'SKU và giá combo' : 'Biến thể và SKU'" description="SKU duy nhất trong doanh nghiệp (không phân biệt hoa thường). Giá vốn tự tính từ phiếu nhập.">
                    <template v-if="canManage && !isCombo" #actions>
                        <Button variant="outline" size="sm" @click="variants.push(emptyRow())"><Plus /> Thêm dòng</Button>
                    </template>

                    <details v-if="canManage && !isCombo" class="border-b bg-muted/30 px-5 py-3 text-sm" :open="isNew">
                        <summary class="cursor-pointer font-medium"><Sparkles class="mr-1 inline size-4 text-primary" /> Tạo nhanh biến thể theo thuộc tính</summary>
                        <div class="mt-3 grid gap-3 sm:grid-cols-[8rem_1fr]">
                            <template v-for="(g, i) in groups" :key="i">
                                <Input v-model="g.name" placeholder="Thuộc tính" />
                                <Input v-model="g.values" placeholder="Giá trị, cách nhau bằng dấu phẩy (Trắng, Đen)" :data-testid="`option-values-${i}`" />
                            </template>
                            <Input v-model="skuPrefix" placeholder="Tiền tố SKU (mặc định theo tên)" />
                            <div class="flex gap-2">
                                <Button variant="secondary" size="sm" data-testid="generate-variants" @click="generate">Tạo biến thể</Button>
                                <Button variant="ghost" size="sm" @click="groups.push({ name: '', values: '' })">+ Thuộc tính</Button>
                            </div>
                        </div>
                    </details>

                    <p v-if="error?.field('variants')" class="px-5 pt-3 text-sm text-destructive">{{ error.field('variants') }}</p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" data-testid="variant-table">
                            <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th class="px-3 py-2 font-medium">SKU *</th>
                                    <th class="px-3 py-2 font-medium">Tên biến thể</th>
                                    <th class="px-3 py-2 font-medium">Mã vạch</th>
                                    <th class="px-3 py-2 text-right font-medium">Giá bán lẻ</th>
                                    <th class="px-3 py-2 text-right font-medium">Giá bán buôn</th>
                                    <th class="px-3 py-2 text-right font-medium">KL (g)</th>
                                    <th class="px-3 py-2 font-medium" title="Dài × Rộng × Cao, tính bằng mm">Kích thước (mm)</th>
                                    <th v-if="canSuppliers" class="px-3 py-2 font-medium">NCC / Mã NCC</th>
                                    <th v-if="!isNew && canCost" class="px-4 py-2 text-right font-medium">Giá vốn BQ</th>
                                    <th class="px-2 py-2" />
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="(v, i) in variants" v-show="!v.conversion" :key="v.id ?? `new-${i}`" class="align-top">
                                    <td class="px-3 py-2">
                                        <input
                                            v-model="v.sku"
                                            required
                                            maxlength="64"
                                            :disabled="!canManage"
                                            :data-testid="`sku-${i}`"
                                            :class="cn('h-8 w-36 rounded-md border border-input bg-card px-2 font-mono text-xs uppercase', (fieldError(i, 'sku') || v.skuTaken || duplicateSkus.has(v.sku.trim().toLowerCase())) && 'border-destructive')"
                                            @input="checkSku(v)"
                                        />
                                        <p v-if="fieldError(i, 'sku') || v.skuTaken" class="mt-1 max-w-36 text-xs text-destructive">{{ fieldError(i, 'sku') ?? v.skuTaken }}</p>
                                        <p v-else-if="duplicateSkus.has(v.sku.trim().toLowerCase())" class="mt-1 text-xs text-destructive">Trùng SKU</p>
                                    </td>
                                    <td class="px-3 py-2"><input v-model="v.name" :disabled="!canManage" class="h-8 w-32 rounded-md border border-input bg-card px-2" /></td>
                                    <td class="px-3 py-2">
                                        <div class="flex items-center gap-1">
                                            <input v-model="v.barcode" :disabled="!canManage" maxlength="64" :data-testid="`barcode-${i}`" :class="cn('h-8 w-32 rounded-md border border-input bg-card px-2 font-mono text-xs', fieldError(i, 'barcode') && 'border-destructive')" />
                                            <Button v-if="canManage && !v.barcode" variant="ghost" size="icon" class="size-8" title="Tạo mã vạch EAN-13 nội bộ" :data-testid="`barcode-gen-${i}`" @click="suggestBarcode(v)"><ScanBarcode class="text-muted-foreground" /></Button>
                                        </div>
                                        <p v-if="fieldError(i, 'barcode')" class="mt-1 max-w-36 text-xs text-destructive">{{ fieldError(i, 'barcode') }}</p>
                                    </td>
                                    <td class="px-3 py-2 text-right"><input v-model="v.ref_price" type="number" min="0" :disabled="!canManage" aria-label="Giá bán lẻ" class="h-8 w-24 rounded-md border border-input bg-card px-2 text-right tabular-nums" /></td>
                                    <td class="px-3 py-2 text-right"><input v-model="v.wholesale_price" type="number" min="0" :disabled="!canManage" aria-label="Giá bán buôn" :data-testid="`wholesale-${i}`" class="h-8 w-24 rounded-md border border-input bg-card px-2 text-right tabular-nums" /></td>
                                    <td class="px-3 py-2 text-right"><input v-model="v.weight_gram" type="number" min="0" :disabled="!canManage" class="h-8 w-16 rounded-md border border-input bg-card px-2 text-right tabular-nums" /></td>
                                    <td class="px-3 py-2">
                                        <div class="flex items-center gap-0.5 text-xs text-muted-foreground" :data-testid="`size-${i}`">
                                            <input v-model="v.length_mm" type="number" min="0" :disabled="!canManage" aria-label="Dài (mm)" placeholder="D" class="h-8 w-14 rounded-md border border-input bg-card px-1.5 text-right tabular-nums" />×
                                            <input v-model="v.width_mm" type="number" min="0" :disabled="!canManage" aria-label="Rộng (mm)" placeholder="R" class="h-8 w-14 rounded-md border border-input bg-card px-1.5 text-right tabular-nums" />×
                                            <input v-model="v.height_mm" type="number" min="0" :disabled="!canManage" aria-label="Cao (mm)" placeholder="C" class="h-8 w-14 rounded-md border border-input bg-card px-1.5 text-right tabular-nums" />
                                        </div>
                                    </td>
                                    <td v-if="canSuppliers" class="px-3 py-2">
                                        <select v-model="v.supplier_id" :disabled="!canManage" aria-label="Nhà cung cấp" :data-testid="`supplier-${i}`" class="h-8 w-36 rounded-md border border-input bg-card px-1 text-xs">
                                            <option value="">— Chưa chọn —</option>
                                            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                                        </select>
                                        <input v-if="v.supplier_id" v-model="v.supplier_sku" maxlength="64" :disabled="!canManage" placeholder="Mã hàng của NCC" aria-label="Mã hàng của NCC" class="mt-1 h-8 w-36 rounded-md border border-input bg-card px-2 font-mono text-xs" />
                                    </td>
                                    <td v-if="!isNew && canCost" class="px-4 py-2 text-right tabular-nums text-muted-foreground">
                                        {{ v.cost_price ? formatMoney(v.cost_price) : '—' }}
                                        <RouterLink v-if="v.id" :to="`/inventory/sku/${v.id}`" class="block text-xs text-primary hover:underline">Thẻ kho</RouterLink>
                                    </td>
                                    <td class="px-2 py-2 text-right">
                                        <Button v-if="canManage && variants.length > 1" variant="ghost" size="icon" class="size-8" title="Xóa biến thể" @click="variants.splice(i, 1)"><Trash2 class="text-muted-foreground" /></Button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <Card v-if="!isCombo" title="Đơn vị quy đổi" description="Bán theo thùng, hộp, lốc… Tồn vẫn tính theo đơn vị gốc: bán 1 thùng 24 chai sẽ trừ 24 chai." data-testid="pack-sizes">
                    <template v-if="canManage" #actions>
                        <Button variant="outline" size="sm" data-testid="add-pack" :disabled="!baseOptions.length" @click="addPack"><Plus /> Thêm đơn vị quy đổi</Button>
                    </template>
                    <p v-if="!packRows.length" class="px-5 py-5 text-sm text-muted-foreground">Chưa có đơn vị quy đổi.</p>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th class="px-3 py-2 font-medium">Tên đơn vị</th>
                                    <th class="px-3 py-2 font-medium">Quy đổi</th>
                                    <th class="px-3 py-2 font-medium">SKU *</th>
                                    <th class="px-3 py-2 font-medium">Mã vạch</th>
                                    <th class="px-3 py-2 text-right font-medium">Giá bán lẻ</th>
                                    <th class="px-3 py-2 text-right font-medium">Giá bán buôn</th>
                                    <th class="px-2 py-2" />
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="{ v, i } in packRows" :key="v.id ?? `pack-${i}`" class="align-top">
                                    <td class="px-3 py-2"><input v-model="v.name" :disabled="!canManage" placeholder="Thùng 24 chai" :data-testid="`pack-name-${i}`" class="h-8 w-32 rounded-md border border-input bg-card px-2" /></td>
                                    <td class="px-3 py-2">
                                        <div class="flex items-center gap-1 text-xs">
                                            <input v-model.number="v.conversion!.quantity" type="number" min="2" :disabled="!canManage" aria-label="Số đơn vị gốc" :data-testid="`pack-qty-${i}`" class="h-8 w-16 rounded-md border border-input bg-card px-2 text-right tabular-nums" />
                                            <span>×</span>
                                            <select v-model="v.conversion!.base_sku" :disabled="!canManage" aria-label="SKU đơn vị gốc" class="h-8 rounded-md border border-input bg-card px-1 font-mono text-xs">
                                                <option v-for="b in baseOptions" :key="b" :value="b">{{ b }}</option>
                                            </select>
                                        </div>
                                        <p v-if="fieldError(i, 'conversion.base_sku') || fieldError(i, 'conversion.quantity')" class="mt-1 max-w-48 text-xs text-destructive">{{ fieldError(i, 'conversion.base_sku') ?? fieldError(i, 'conversion.quantity') }}</p>
                                    </td>
                                    <td class="px-3 py-2">
                                        <input v-model="v.sku" required maxlength="64" :disabled="!canManage" :data-testid="`pack-sku-${i}`" :class="cn('h-8 w-36 rounded-md border border-input bg-card px-2 font-mono text-xs uppercase', (fieldError(i, 'sku') || v.skuTaken) && 'border-destructive')" @input="checkSku(v)" />
                                        <p v-if="fieldError(i, 'sku') || v.skuTaken" class="mt-1 max-w-36 text-xs text-destructive">{{ fieldError(i, 'sku') ?? v.skuTaken }}</p>
                                    </td>
                                    <td class="px-3 py-2"><input v-model="v.barcode" maxlength="64" :disabled="!canManage" class="h-8 w-32 rounded-md border border-input bg-card px-2 font-mono text-xs" /></td>
                                    <td class="px-3 py-2 text-right"><input v-model="v.ref_price" type="number" min="0" :disabled="!canManage" aria-label="Giá bán lẻ đơn vị quy đổi" class="h-8 w-24 rounded-md border border-input bg-card px-2 text-right tabular-nums" /></td>
                                    <td class="px-3 py-2 text-right"><input v-model="v.wholesale_price" type="number" min="0" :disabled="!canManage" aria-label="Giá bán buôn đơn vị quy đổi" class="h-8 w-24 rounded-md border border-input bg-card px-2 text-right tabular-nums" /></td>
                                    <td class="px-2 py-2 text-right">
                                        <Button v-if="canManage" variant="ghost" size="icon" class="size-8" title="Xóa đơn vị quy đổi" @click="variants.splice(i, 1)"><Trash2 class="text-muted-foreground" /></Button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <Card v-if="showOpening" title="Tồn kho ban đầu" description="Không bắt buộc. Ghi số lượng đang có và giá vốn để tạo một phiếu nhập tồn đầu kỳ cùng lúc với sản phẩm.">
                    <div class="flex flex-wrap items-end gap-3 border-b px-5 py-3">
                        <FormField label="Nhập vào kho" for="p-opening-wh" :error="error?.field('opening_warehouse_id')" class="w-56">
                            <Select id="p-opening-wh" v-model="form.opening_warehouse_id" :options="warehouses.map((w) => ({ value: w.id, label: w.name }))" />
                        </FormField>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" data-testid="opening-table">
                            <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th class="px-4 py-2 font-medium">SKU</th>
                                    <th class="px-4 py-2 text-right font-medium">Số lượng</th>
                                    <th class="px-4 py-2 text-right font-medium">Giá vốn</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="(v, i) in variants" v-show="!v.conversion" :key="`opening-${i}`">
                                    <td class="px-4 py-2"><span class="font-mono text-xs">{{ v.sku || '(chưa có SKU)' }}</span><span v-if="v.name" class="text-muted-foreground"> · {{ v.name }}</span></td>
                                    <td class="px-4 py-2 text-right">
                                        <input v-model="v.opening_qty" type="number" min="0" :data-testid="`opening-qty-${i}`" aria-label="Số lượng tồn ban đầu" class="h-8 w-24 rounded-md border border-input bg-card px-2 text-right tabular-nums" />
                                        <p v-if="fieldError(i, 'opening_qty')" class="mt-1 text-xs text-destructive">{{ fieldError(i, 'opening_qty') }}</p>
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        <input v-model="v.opening_cost" type="number" min="0" :data-testid="`opening-cost-${i}`" aria-label="Giá vốn ban đầu" :class="cn('h-8 w-28 rounded-md border border-input bg-card px-2 text-right tabular-nums', noCost(v) && 'border-amber-300 bg-amber-50/60')" placeholder="0 ₫" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <Alert v-if="zeroCostOpening.length" tone="amber" class="m-4" data-testid="opening-no-cost">
                        {{ zeroCostOpening.length }} SKU có tồn ban đầu nhưng chưa có giá vốn. Giá vốn sẽ bắt đầu từ 0 ₫, lãi gộp khi bán sẽ cao hơn thực tế. Hãy nhập giá vốn, hoặc để trống nếu đây là hàng được tặng.
                    </Alert>
                </Card>
            </div>

            <div class="space-y-5">
                <Card title="Trạng thái">
                    <div class="p-5">
                        <Select
                            v-model="form.status"
                            :disabled="!canManage"
                            :options="[
                                { value: 'active', label: 'Đang bán' },
                                { value: 'inactive', label: 'Ngừng bán' },
                                { value: 'archived', label: 'Lưu trữ' },
                            ]"
                        />
                        <p class="mt-2 text-xs text-muted-foreground">Sản phẩm lưu trữ bị ẩn khỏi danh sách tồn kho và ô chọn SKU.</p>
                    </div>
                </Card>
                <Card title="Hình ảnh" :description="isNew ? 'Lưu sản phẩm trước rồi thêm ảnh.' : 'JPG, PNG, WEBP tối đa 4 MB, 9 ảnh.'">
                    <div class="grid grid-cols-3 gap-2 p-5">
                        <div v-for="img in images" :key="img.id" class="group relative aspect-square overflow-hidden rounded-md border">
                            <img :src="img.url" alt="" class="size-full object-cover" />
                            <button v-if="canManage" type="button" class="absolute right-1 top-1 hidden rounded bg-black/60 p-0.5 text-white group-hover:block" aria-label="Xóa ảnh" @click="removeImage(img.id)"><X class="size-3.5" /></button>
                        </div>
                        <label v-if="!isNew && canManage && images.length < 9" class="flex aspect-square cursor-pointer flex-col items-center justify-center gap-1 rounded-md border border-dashed text-xs text-muted-foreground hover:bg-muted">
                            <ImagePlus class="size-5" /> Thêm ảnh
                            <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="upload" />
                        </label>
                    </div>
                </Card>
            </div>
        </div>
    </form>
</template>
