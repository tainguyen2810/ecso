<script setup lang="ts">
import { computed, onMounted, provide, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Bookmark, Boxes, ChevronDown, ChevronRight, Columns3, Download, FileSpreadsheet, ImageIcon, ImageOff, Images, Package, Plus, Printer, RefreshCw, RotateCcw, SlidersHorizontal, Sparkles, Tags, Upload, X } from 'lucide-vue-next';
import StockOverview from '../../components/StockOverview.vue';
import ImportDialog from './ImportDialog.vue';
import ProductsHead from './ui/ProductsHead.vue';
import HeadLink from './ui/HeadLink.vue';
import HeadButton from './ui/HeadButton.vue';
import LineTabs from './ui/LineTabs.vue';
import SearchField from './ui/SearchField.vue';
import NumberPager from './ui/NumberPager.vue';
import StatusChip from './ui/StatusChip.vue';
import { card, th, thead } from './ui/ui';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Button from '@/shared/ui/Button.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { cn, formatDate, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import type { Product, Ref, StockSummary } from '@/shared/types';
import DateRangeInput from '@/shared/ui/DateRangeInput.vue';

const tenant = useTenantStore();
const toast = useToast();
const route = useRoute();
const router = useRouter();
const canManage = computed(() => tenant.can('products.manage'));

const products = ref<Product[]>([]);
const categories = ref<Ref[]>([]);
const brands = ref<Ref[]>([]);
const tags = ref<string[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0, per_page: 20 });
/** Products per status tab (from the list response). */
const counts = ref<Record<string, number>>({});
provide('soft-cards', true);
const loading = ref(true);
const loadError = ref<string | null>(null);
const q = (key: string) => (typeof route.query[key] === 'string' ? (route.query[key] as string) : '');
// Filters live in the URL so a filtered list can be bookmarked or shared (links from categories, tags).
const filters = reactive({
    q: q('q'),
    status: q('status') || 'active',
    category: q('category'),
    brand: q('brand'),
    tag: q('tag'),
    type: q('type'),
    created_from: q('created_from'),
    created_to: q('created_to'),
    stock: q('stock'),
    image: q('image'),
    sort: q('sort') || 'newest',
    page: 1,
});
const moreFilters = ref(!!(filters.type || filters.tag || filters.stock || filters.image || filters.created_from || filters.created_to));
const activeExtra = computed(() => [filters.type, filters.tag, filters.stock, filters.image, filters.created_from, filters.created_to].filter(Boolean).length);
/** "Đã xóa" tab: deleted products, which can be restored. */
const trashed = computed(() => filters.status === 'trashed');
function clearExtra() {
    Object.assign(filters, { type: '', tag: '', stock: '', image: '', created_from: '', created_to: '' });
}
const expanded = ref<Set<string>>(new Set());
const selected = ref<Set<string>>(new Set());
const importOpen = ref(false);
const exporting = ref(false);

async function exportXlsx() {
    exporting.value = true;
    try {
        await downloadFile('/products/export?format=xlsx', 'san-pham.xlsx');
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        exporting.value = false;
    }
}

const statusLabels = { active: 'Đang bán', inactive: 'Ngừng bán', archived: 'Lưu trữ' } as const;
const statusTone = { active: 'green', inactive: 'slate', archived: 'slate' } as const;

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { page, ...rest } = filters;
        const query = Object.fromEntries(Object.entries(rest).filter(([k, v]) => v && !(k === 'status' && v === 'active') && !(k === 'sort' && v === 'newest')));
        // Only while still on the list: a slow load must not pull the user back from a page they opened.
        const key = (o: Record<string, unknown>) => JSON.stringify(Object.entries(o).filter(([, v]) => v).sort(([a], [b]) => a.localeCompare(b)));
        const same = key(query) === key(route.query);
        if (!same && route.name === 'products') router.replace({ query });
        const { data } = await api.get('/products', {
            params: { ...query, status: filters.status === 'all' || trashed.value ? undefined : filters.status, trashed: trashed.value ? 1 : undefined, sort: filters.sort, page },
        });
        products.value = data.data;
        meta.value = data.meta;
        counts.value = data.counts ?? {};
        selected.value = new Set();
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}

/** Stock at a glance (needs inventory.view); a card filters the list by stock state. */
const summary = ref<StockSummary | null>(null);
onMounted(async () => {
    if (tenant.can('inventory.view')) summary.value = (await api.get('/inventory/summary').catch(() => null))?.data.data ?? null;
});
function pickStock(state: string) {
    filters.stock = state === 'all' ? '' : state;
    if (filters.stock) moreFilters.value = true;
}
/** Category and brand columns only when something on this page has one (many shops leave them empty). */
const hasTaxonomy = computed(() => products.value.some((p) => p.category || p.brand));

onMounted(async () => {
    const [c, b, t] = await Promise.all([api.get('/categories'), api.get('/brands'), api.get('/products/tags')]);
    categories.value = c.data.data;
    brands.value = b.data.data;
    tags.value = t.data.data;
    await load();
});

let timer: ReturnType<typeof setTimeout> | undefined;
watch(() => filters.q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => ((filters.page = 1), load()), 250);
});
watch(
    () => [filters.status, filters.category, filters.type, filters.brand, filters.tag, filters.created_from, filters.created_to, filters.stock, filters.image, filters.sort],
    () => ((filters.page = 1), load()),
);

function toggle(set: Set<string>, id: string) {
    set.has(id) ? set.delete(id) : set.add(id);
}

function priceRange(p: Product): string {
    const prices = (p.variants ?? []).map((v) => Number(v.ref_price)).filter((n) => n > 0);
    if (!prices.length) return '—';
    const [min, max] = [Math.min(...prices), Math.max(...prices)];
    return min === max ? formatMoney(min) : `${formatMoney(min)} – ${formatMoney(max)}`;
}

const restoring = ref<string | null>(null);
async function restore(p: Product) {
    restoring.value = p.id;
    try {
        await api.post(`/products/${p.id}/restore`);
        toast.success(`Đã khôi phục ${p.name}.`);
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        restoring.value = null;
    }
}

async function bulkStatus(status: string) {
    try {
        const { data } = await api.post('/products/bulk-status', { ids: [...selected.value], status });
        toast.success(`Đã cập nhật ${data.updated} sản phẩm`);
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}

async function bulkSourcing(sourcing: 'stock' | 'order') {
    try {
        const { data } = await api.post('/products/bulk-sourcing', { ids: [...selected.value], sourcing });
        toast.success(sourcing === 'order' ? `Đã chuyển ${data.updated} SKU sang hàng đặt` : `Đã chuyển ${data.updated} SKU sang hàng có sẵn`);
        selected.value = new Set();
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}

async function bulkDelete() {
    if (!window.confirm(`Xóa ${selected.value.size} sản phẩm đã chọn? Sản phẩm còn tồn kho sẽ được giữ lại.`)) return;
    try {
        const { data } = await api.post('/products/bulk-delete', { ids: [...selected.value] });
        if (data.deleted) toast.success(`Đã xóa ${data.deleted} sản phẩm`);
        if (data.skipped.length) toast.error(`${data.skipped.length} sản phẩm không xóa được: ${data.skipped[0].message}`);
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}

function printSelected() {
    router.push({ path: '/products/labels', query: { products: [...selected.value].join(',') } });
}

// Saved filters and visible columns, kept in this browser per business.
type ViewFilters = Omit<typeof filters, 'page'>;
const storageKey = (name: string) => `ecso.products.${name}.${tenant.current?.id ?? 'default'}`;
function readStore<T>(name: string, fallback: T): T {
    try {
        return JSON.parse(localStorage.getItem(storageKey(name)) ?? 'null') ?? fallback;
    } catch {
        return fallback;
    }
}
function writeStore(name: string, value: unknown) {
    try {
        localStorage.setItem(storageKey(name), JSON.stringify(value));
    } catch {
        // Private mode: keep for this visit only.
    }
}
const savedViews = ref<{ name: string; filters: ViewFilters }[]>(readStore('views', []));
function saveView() {
    const name = window.prompt('Tên bộ lọc', '')?.trim();
    if (!name) return;
    const { page: _page, ...current } = filters;
    savedViews.value = [...savedViews.value.filter((v) => v.name !== name), { name, filters: { ...current } }];
    writeStore('views', savedViews.value);
    toast.success(`Đã lưu bộ lọc "${name}"`);
}
function applyView(view: { filters: ViewFilters }) {
    Object.assign(filters, view.filters);
    moreFilters.value = activeExtra.value > 0;
}
function removeView(name: string) {
    savedViews.value = savedViews.value.filter((v) => v.name !== name);
    writeStore('views', savedViews.value);
}
const columnLabels = { category: 'Danh mục, thương hiệu', channels: 'Bán trên', price: 'Giá bán lẻ', available: 'Có thể bán', on_hand: 'Tồn kho', status: 'Trạng thái', created: 'Ngày tạo' } as const;
type ColumnKey = keyof typeof columnLabels;
const columns = ref<Record<ColumnKey, boolean>>({ category: true, channels: true, price: true, on_hand: true, available: true, status: true, created: true, ...readStore('columns', {}) });
watch(columns, (v) => writeStore('columns', v), { deep: true });
const columnsOpen = ref(false);
const showTaxonomy = computed(() => columns.value.category && hasTaxonomy.value);
/** "Bán trên" only when something on this page is linked to a marketplace shop. */
const showChannels = computed(() => columns.value.channels && products.value.some((p) => p.sold_on?.length));
const visibleCount = computed(
    () =>
        Object.values(columns.value).filter(Boolean).length -
        (columns.value.category ? 1 : 0) +
        (showTaxonomy.value ? 1 : 0) -
        (columns.value.channels ? 1 : 0) +
        (showChannels.value ? 1 : 0) +
        1 +
        (canManage.value && !trashed.value ? 1 : 0),
);

/** "Lấy ảnh từ sàn": products without a picture that a linked shop has pictures for. Hidden per browser with ✕. */
const missingImages = ref(0);
const imagesHidden = ref(readStore('images-banner-hidden', false));
const fetchingImages = ref(false);
onMounted(async () => {
    if (canManage.value && tenant.can('marketplace.view')) missingImages.value = (await api.get('/marketplace-images/missing').catch(() => null))?.data.data.count ?? 0;
});
function hideImagesBanner() {
    imagesHidden.value = true;
    writeStore('images-banner-hidden', true);
}
async function fetchImages() {
    fetchingImages.value = true;
    try {
        const { data } = await api.post('/marketplace-images/queue');
        toast.success(`Đang lấy ảnh cho ${formatNumber(data.data.queued)} sản phẩm từ sàn. Vài phút sẽ xong, tải lại trang để xem.`);
        missingImages.value = 0;
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        fetchingImages.value = false;
    }
}

const bulkBtn = 'rounded-[8px] border border-slate-200 bg-white px-3 py-1.5 font-medium text-slate-700 hover:border-blue-300 hover:text-blue-700';
/** Stock state of a row: out (nothing to sell), low (at or below the safety stock) or ok. Services and combos without stock rows count as ok. */
function stockState(p: Product): 'out' | 'low' | 'ok' {
    if (p.type === 'service' || p.type === 'subscription') return 'ok';
    const a = p.stock?.available ?? 0;
    if (a <= 0) return 'out';
    const safety = p.stock?.safety ?? 0;
    return safety && a <= safety ? 'low' : 'ok';
}

const allSelected = computed(() => products.value.length > 0 && products.value.every((p) => selected.value.has(p.id)));
function toggleAll() {
    selected.value = allSelected.value ? new Set() : new Set(products.value.map((p) => p.id));
}
</script>

<template>
    <ProductsHead title="Danh sách sản phẩm" description="Sản phẩm, biến thể, giá bán và nơi đang bán. Tồn kho lấy theo tất cả các kho.">
        <template #links>
            <HeadLink :icon="Download" :disabled="exporting" @click="exportXlsx">Xuất file</HeadLink>
            <HeadLink v-if="canManage" :icon="Upload" data-testid="import-button" @click="importOpen = true">Nhập file</HeadLink>
            <HeadLink :icon="Tags" to="/products/taxonomy">Danh mục và thương hiệu</HeadLink>
            <HeadLink v-if="canManage" :icon="Boxes" :to="{ path: '/products/new', query: { type: 'combo' } }" data-testid="new-combo">Thêm combo</HeadLink>
            <HeadLink v-if="canManage" :icon="RefreshCw" :to="{ path: '/products/new', query: { type: 'subscription' } }" data-testid="new-subscription">Thêm thuê bao</HeadLink>
            <HeadLink :icon="Printer" to="/products/labels">In tem mã vạch</HeadLink>
        </template>
        <HeadButton v-if="canManage" :icon="Sparkles" to="/products/scan" data-testid="product-from-photos">Tạo từ ảnh</HeadButton>
        <HeadButton v-if="canManage" :icon="Plus" to="/products/new" primary data-testid="new-product">Thêm sản phẩm</HeadButton>
    </ProductsHead>

    <StockOverview v-if="summary" :summary="summary" mode="products" :active-products="counts.active ?? null" :active="filters.stock || 'all'" @select="pickStock" />

    <div v-if="missingImages && !imagesHidden && !trashed" class="mb-5 flex flex-wrap items-center gap-4 rounded-[14px] border border-blue-200 bg-blue-50/70 px-5 py-3.5" data-testid="images-banner">
        <span class="grid size-10 shrink-0 place-items-center rounded-[10px] border border-dashed border-blue-300 bg-white text-blue-500"><ImageOff class="size-5" /></span>
        <div class="min-w-0 flex-1">
            <p class="text-[14px] font-semibold text-slate-800">{{ formatNumber(missingImages) }} sản phẩm chưa có ảnh nhưng đang bán trên sàn</p>
            <p class="text-[13px] text-slate-500">Lấy ảnh từ các shop đã liên kết. Sản phẩm đã có ảnh thì giữ nguyên.</p>
        </div>
        <Button :loading="fetchingImages" data-testid="images-fetch" @click="fetchImages"><Images /> Lấy ảnh từ sàn</Button>
        <button type="button" class="rounded p-1 text-slate-400 hover:bg-white hover:text-slate-700" aria-label="Ẩn" @click="hideImagesBanner"><X class="size-4" /></button>
    </div>

    <section :class="cn(card, 'overflow-hidden')">
        <LineTabs
            v-model="filters.status"
            :tabs="[
                { value: 'all', label: 'Tất cả sản phẩm', count: counts.all },
                { value: 'active', label: 'Đang bán', count: counts.active },
                { value: 'inactive', label: 'Ngừng bán', count: counts.inactive },
                { value: 'archived', label: 'Lưu trữ', count: counts.archived },
                { value: 'trashed', label: 'Đã xóa', count: counts.trashed || null },
            ]"
        />
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3">
            <SearchField v-model="filters.q" placeholder="Tìm theo mã sản phẩm, tên sản phẩm, barcode" data-testid="product-search" />
            <Select v-if="categories.length" v-model="filters.category" :options="[{ value: '', label: 'Danh mục' }, ...categories.map((c) => ({ value: c.id, label: c.name }))]" class="w-full sm:w-44" aria-label="Danh mục" />
            <Select v-if="brands.length" v-model="filters.brand" class="w-full sm:w-44" aria-label="Thương hiệu" :options="[{ value: '', label: 'Thương hiệu' }, ...brands.map((b) => ({ value: b.id, label: b.name }))]" />
            <Select
                v-model="filters.sort"
                class="w-full sm:w-52"
                aria-label="Sắp xếp"
                :options="[
                    { value: 'newest', label: 'Mới tạo trước' },
                    { value: 'oldest', label: 'Cũ trước' },
                    { value: 'name', label: 'Tên A → Z' },
                    { value: 'available', label: 'Tồn khả dụng tăng dần' },
                    { value: '-available', label: 'Tồn khả dụng giảm dần' },
                    { value: 'price', label: 'Giá bán tăng dần' },
                    { value: '-price', label: 'Giá bán giảm dần' },
                ]"
            />
            <button
                type="button"
                data-testid="more-filters"
                :class="cn('inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-[10px] border border-slate-200 px-3.5 text-[14px] text-slate-700 hover:border-blue-300', moreFilters && 'border-blue-300 bg-blue-50/60')"
                @click="moreFilters = !moreFilters"
            >
                <SlidersHorizontal class="size-4" /> Bộ lọc khác<span v-if="activeExtra" class="rounded-full bg-blue-600 px-1.5 text-[12px] text-white">{{ activeExtra }}</span>
            </button>
            <div class="relative">
                <button type="button" data-testid="columns-button" class="inline-flex h-11 items-center gap-2 rounded-[10px] border border-slate-200 px-3.5 text-[14px] text-slate-700 hover:border-blue-300" @click="columnsOpen = !columnsOpen"><Columns3 class="size-4" /> Cột</button>
                <div v-if="columnsOpen" class="absolute right-0 top-12 z-20 w-56 rounded-[12px] border border-slate-200 bg-white p-2 text-sm shadow-lg" data-testid="columns-menu">
                    <label v-for="(label, key) in columnLabels" :key="key" class="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-slate-50">
                        <input v-model="columns[key]" type="checkbox" /> {{ label }}
                    </label>
                </div>
            </div>
            <button
                v-if="activeExtra || filters.q || filters.category || filters.brand || filters.status !== 'active'"
                type="button"
                data-testid="save-view"
                class="inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-[10px] border border-slate-200 px-3.5 text-[14px] text-slate-700 hover:border-blue-300"
                @click="saveView"
            >
                <Bookmark class="size-4" /> Lưu bộ lọc
            </button>
        </div>
        <div v-if="savedViews.length" class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-2.5 text-[13px]" data-testid="saved-views">
            <span class="text-slate-500">Bộ lọc đã lưu:</span>
            <span v-for="v in savedViews" :key="v.name" class="inline-flex items-center gap-1 rounded-md bg-slate-100 pl-2 pr-1 text-slate-700">
                <button type="button" class="py-0.5 font-medium hover:text-blue-700" @click="applyView(v)">{{ v.name }}</button>
                <button type="button" class="rounded p-0.5 text-slate-400 hover:bg-white hover:text-slate-700" :aria-label="`Xóa bộ lọc ${v.name}`" @click="removeView(v.name)"><X class="size-3" /></button>
            </span>
        </div>
        <div v-if="moreFilters" class="flex flex-wrap items-end gap-3 border-b border-slate-100 bg-slate-50/60 px-5 py-3 text-sm" data-testid="extra-filters">
            <label class="grid gap-1">
                <span class="text-xs text-slate-500">Loại</span>
                <Select v-model="filters.type" class="w-40" aria-label="Loại sản phẩm" :options="[{ value: '', label: 'Mọi loại' }, { value: 'standard', label: 'Sản phẩm thường' }, { value: 'combo', label: 'Combo' }, { value: 'service', label: 'Dịch vụ' }, { value: 'subscription', label: 'Thuê bao' }]" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-slate-500">Tag</span>
                <Select v-model="filters.tag" class="w-40" aria-label="Tag" :options="[{ value: '', label: 'Mọi tag' }, ...tags.map((t) => ({ value: t, label: t }))]" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-slate-500">Tồn kho</span>
                <Select
                    v-model="filters.stock"
                    class="w-44"
                    aria-label="Tình trạng tồn"
                    data-testid="stock-filter"
                    :options="[
                        { value: '', label: 'Mọi tình trạng' },
                        { value: 'in', label: 'Còn hàng' },
                        { value: 'low', label: 'Sắp hết (≤ tồn an toàn)' },
                        { value: 'out', label: 'Hết hàng' },
                    ]"
                />
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-slate-500">Ảnh</span>
                <Select v-model="filters.image" class="w-40" aria-label="Ảnh" data-testid="image-filter" :options="[{ value: '', label: 'Mọi sản phẩm' }, { value: 'none', label: 'Chưa có ảnh' }]" />
            </label>
            <div class="grid gap-1">
                <span class="text-xs text-slate-500">Ngày tạo</span>
                <DateRangeInput v-model:from="filters.created_from" v-model:to="filters.created_to" clearable placeholder="Mọi ngày" aria-label="Ngày tạo" />
            </div>
            <Button v-if="activeExtra" variant="ghost" size="sm" @click="clearExtra"><X /> Xóa bộ lọc</Button>
        </div>

        <div v-if="selected.size" class="flex flex-wrap items-center gap-2 border-b border-slate-100 bg-blue-50/60 px-5 py-2.5 text-[13px]" data-testid="bulk-bar">
            <b class="mr-2 text-slate-800">Đã chọn {{ selected.size }}</b>
            <button type="button" :class="bulkBtn" @click="bulkStatus('active')">Đang bán</button>
            <button type="button" :class="bulkBtn" @click="bulkStatus('inactive')">Ngừng bán</button>
            <button type="button" :class="bulkBtn" @click="bulkStatus('archived')">Lưu trữ</button>
            <button type="button" :class="bulkBtn" data-testid="bulk-print" @click="printSelected">In tem mã vạch</button>
            <button type="button" :class="bulkBtn" data-testid="bulk-to-order" title="Giá theo web, không đẩy tồn lên web, khách đặt rồi mới nhập" @click="bulkSourcing('order')">Hàng đặt</button>
            <button type="button" :class="bulkBtn" @click="bulkSourcing('stock')">Hàng có sẵn</button>
            <button type="button" :class="cn(bulkBtn, 'border-red-200 text-red-600 hover:border-red-300 hover:text-red-700')" data-testid="bulk-delete" @click="bulkDelete">Xóa</button>
            <button type="button" class="ml-auto text-slate-500 hover:text-slate-900" @click="selected = new Set()">Bỏ chọn</button>
        </div>

        <div v-if="loading && !products.length" class="space-y-3 p-5"><Skeleton v-for="i in 6" :key="i" class="h-12" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState
            v-else-if="!products.length"
            :icon="Package"
            :title="filters.q ? 'Không tìm thấy sản phẩm' : 'Chưa có sản phẩm'"
            description="Tạo sản phẩm thủ công hoặc import hàng loạt từ file Excel/CSV theo mẫu."
        >
            <div v-if="canManage" class="flex gap-2">
                <Button variant="outline" @click="importOpen = true"><FileSpreadsheet /> Import từ Excel</Button>
                <RouterLink to="/products/new"><Button><Plus /> Thêm sản phẩm</Button></RouterLink>
            </div>
        </EmptyState>
        <div v-else class="overflow-x-auto 2xl:overflow-visible" :class="loading && 'opacity-60'">
            <table class="w-full min-w-[1000px]" data-testid="product-table">
                <thead :class="thead">
                    <tr>
                        <th v-if="canManage && !trashed" :class="cn(th, 'w-10')"><input type="checkbox" class="size-4 rounded" :checked="allSelected" aria-label="Chọn tất cả" @change="toggleAll" /></th>
                        <th :class="th">Sản phẩm</th>
                        <th v-if="showChannels" :class="th">Bán trên</th>
                        <th v-if="showTaxonomy" :class="th">Danh mục</th>
                        <th v-if="columns.price" :class="cn(th, 'text-right')">Giá bán lẻ</th>
                        <th v-if="columns.available" :class="cn(th, 'text-right')">Có thể bán</th>
                        <th v-if="columns.on_hand" :class="cn(th, 'text-right')">Tồn kho</th>
                        <th v-if="columns.status" :class="th">Trạng thái</th>
                        <th v-if="columns.created" :class="th">Ngày tạo</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="p in products" :key="p.id">
                        <tr :class="cn('border-t border-slate-100 hover:bg-slate-50/70', selected.has(p.id) && 'bg-blue-50/40')">
                            <td v-if="canManage && !trashed" class="w-10 px-4 py-3"><input type="checkbox" class="size-4 rounded" :checked="selected.has(p.id)" :aria-label="`Chọn ${p.name}`" @change="toggle(selected, p.id)" /></td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <button class="-ml-1 rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700" :aria-label="expanded.has(p.id) ? 'Thu gọn' : 'Xem biến thể'" @click="toggle(expanded, p.id)">
                                        <ChevronDown v-if="expanded.has(p.id)" class="size-4" />
                                        <ChevronRight v-else class="size-4" />
                                    </button>
                                    <img v-if="p.images?.length" :src="p.images[0]!.url" alt="" class="size-11 shrink-0 rounded-[10px] border border-slate-200 object-cover" />
                                    <span v-else class="grid size-11 shrink-0 place-items-center rounded-[10px] border border-dashed border-slate-300 bg-slate-50 text-slate-400"><ImageIcon class="size-4" /></span>
                                    <div class="min-w-0">
                                        <p class="flex flex-wrap items-center gap-2">
                                            <span v-if="trashed" class="text-[14px] font-medium text-slate-800">{{ p.name }}</span>
                                            <RouterLink v-else :to="`/products/${p.id}`" class="text-[14px] font-medium text-blue-700 hover:underline">{{ p.name }}</RouterLink>
                                            <StatusChip v-if="p.type === 'combo'" tone="violet">Combo</StatusChip>
                                            <StatusChip v-else-if="p.type === 'service'">Dịch vụ</StatusChip>
                                            <StatusChip v-else-if="p.type === 'subscription'" tone="blue">Thuê bao</StatusChip>
                                        </p>
                                        <p class="truncate text-[12px] text-slate-500">
                                            {{ p.variants_count }} biến thể ·
                                            <span class="font-mono">{{ p.variants?.slice(0, 2).map((v) => v.sku).join(', ') }}{{ (p.variants?.length ?? 0) > 2 ? '…' : '' }}</span>
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td v-if="showChannels" class="px-4 py-3">
                                <div class="flex -space-x-1"><MarketplaceLogo v-for="sh in p.sold_on" :key="sh.id" :marketplace="sh.marketplace" :name="sh.name" size="sm" :title="sh.name" class="ring-2 ring-white" /></div>
                            </td>
                            <td v-if="showTaxonomy" class="px-4 py-3 text-[14px]">
                                {{ p.category?.name ?? '—' }}<span v-if="p.brand" class="block text-[12px] text-slate-500">{{ p.brand.name }}</span>
                            </td>
                            <td v-if="columns.price" class="whitespace-nowrap px-4 py-3 text-right text-[14px] font-medium tabular-nums">{{ priceRange(p) }}</td>
                            <td v-if="columns.available" :class="cn('whitespace-nowrap px-4 py-3 text-right text-[14px] font-semibold tabular-nums', stockState(p) === 'out' ? 'text-red-600' : stockState(p) === 'low' ? 'text-amber-600' : 'text-slate-900')">
                                {{ p.type === 'service' ? '—' : formatNumber(p.stock?.available ?? 0) }}
                                <span v-if="stockState(p) === 'out'" class="block text-[11px] font-medium text-red-500">Hết hàng</span>
                                <span v-else-if="stockState(p) === 'low'" class="block text-[11px] font-medium text-amber-600">Sắp hết</span>
                            </td>
                            <td v-if="columns.on_hand" class="px-4 py-3 text-right text-[14px] tabular-nums text-slate-600">{{ p.type === 'service' ? '—' : formatNumber(p.stock?.on_hand ?? 0) }}</td>
                            <td v-if="columns.status" class="whitespace-nowrap px-4 py-3">
                                <div v-if="trashed" class="flex items-center gap-2">
                                    <StatusChip tone="red">Đã xóa</StatusChip>
                                    <Button v-if="canManage" size="sm" variant="outline" :disabled="restoring === p.id" :data-testid="`restore-${p.id}`" @click="restore(p)"><RotateCcw /> Khôi phục</Button>
                                </div>
                                <StatusChip v-else :tone="statusTone[p.status]">{{ statusLabels[p.status] }}</StatusChip>
                            </td>
                            <td v-if="columns.created" class="whitespace-nowrap px-4 py-3 text-[13px] text-slate-500">{{ formatDate(p.created_at) }}</td>
                        </tr>
                        <tr v-if="expanded.has(p.id)" class="bg-slate-50/60">
                            <td :colspan="visibleCount" class="px-4 py-2">
                                <table class="ml-20 w-[calc(100%-5rem)]">
                                    <tr v-for="v in p.variants" :key="v.id" class="border-b border-dashed border-slate-200 last:border-0">
                                        <td class="py-2 pr-6 font-mono text-[12px] font-semibold">{{ v.sku }}</td>
                                        <td class="py-2 pr-6 text-[13px]">{{ v.name ?? '—' }}</td>
                                        <td class="py-2 pr-6 font-mono text-[12px] text-slate-500">{{ v.barcode ?? '' }}</td>
                                        <td class="py-2 pr-6 text-right text-[13px] tabular-nums">{{ formatMoney(v.ref_price) }}</td>
                                        <td class="py-2 pr-6 text-right text-[13px] tabular-nums text-slate-500">{{ Number(v.wholesale_price) ? `Buôn ${formatMoney(v.wholesale_price)}` : '' }}</td>
                                        <td class="py-2 text-right text-[13px]"><RouterLink :to="`/inventory/sku/${v.id}`" class="font-medium text-blue-700 hover:underline">Thẻ kho</RouterLink></td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <NumberPager :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" :per-page="meta.per_page ?? 20" :count="products.length" unit="sản phẩm" @go="(p) => ((filters.page = p), load())" />
    </section>

    <ImportDialog v-model:open="importOpen" @imported="load" />
</template>
