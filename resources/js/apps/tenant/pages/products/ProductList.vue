<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Bookmark, ChevronDown, ChevronRight, Columns3, Download, FileSpreadsheet, Package, Plus, Search, SlidersHorizontal, Upload, X } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Pagination from '../../components/Pagination.vue';
import Tabs from '../../components/Tabs.vue';
import ImportDialog from './ImportDialog.vue';
import ProductsNav from './ProductsNav.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { cn, formatDate, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import type { Product, Ref } from '@/shared/types';

const tenant = useTenantStore();
const toast = useToast();
const route = useRoute();
const router = useRouter();
const canManage = computed(() => tenant.can('products.manage'));

const products = ref<Product[]>([]);
const categories = ref<Ref[]>([]);
const brands = ref<Ref[]>([]);
const tags = ref<string[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
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
    sort: q('sort') || 'newest',
    page: 1,
});
const moreFilters = ref(!!(filters.type || filters.brand || filters.tag || filters.created_from || filters.created_to));
const activeExtra = computed(() => [filters.type, filters.brand, filters.tag, filters.created_from, filters.created_to].filter(Boolean).length);
function clearExtra() {
    Object.assign(filters, { type: '', brand: '', tag: '', created_from: '', created_to: '' });
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
const statusTone = { active: 'success', inactive: 'muted', archived: 'muted' } as const;

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
            params: { ...query, status: filters.status === 'all' ? undefined : filters.status, sort: filters.sort, page },
        });
        products.value = data.data;
        meta.value = data.meta;
        selected.value = new Set();
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}

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
    () => [filters.status, filters.category, filters.type, filters.brand, filters.tag, filters.created_from, filters.created_to, filters.sort],
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

async function bulkStatus(status: string) {
    try {
        const { data } = await api.post('/products/bulk-status', { ids: [...selected.value], status });
        toast.success(`Đã cập nhật ${data.updated} sản phẩm`);
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
const columnLabels = { category: 'Danh mục / Thương hiệu', price: 'Giá bán lẻ', on_hand: 'Tồn thực tế', available: 'Khả dụng', status: 'Trạng thái', created: 'Ngày tạo' } as const;
type ColumnKey = keyof typeof columnLabels;
const columns = ref<Record<ColumnKey, boolean>>({ category: true, price: true, on_hand: true, available: true, status: true, created: true, ...readStore('columns', {}) });
watch(columns, (v) => writeStore('columns', v), { deep: true });
const columnsOpen = ref(false);
const visibleCount = computed(() => Object.values(columns.value).filter(Boolean).length + 1 + (canManage.value ? 1 : 0));

const allSelected = computed(() => products.value.length > 0 && products.value.every((p) => selected.value.has(p.id)));
function toggleAll() {
    selected.value = allSelected.value ? new Set() : new Set(products.value.map((p) => p.id));
}
</script>

<template>
    <PageHeader title="Sản phẩm" description="Sản phẩm, biến thể và SKU nội bộ. SKU là duy nhất trong doanh nghiệp.">
        <Button variant="outline" :loading="exporting" @click="exportXlsx"><Download /> Xuất Excel</Button>
        <Button v-if="canManage" variant="outline" data-testid="import-button" @click="importOpen = true"><Upload /> Import</Button>
        <RouterLink v-if="canManage" :to="{ path: '/products/new', query: { type: 'combo' } }"><Button variant="outline" data-testid="new-combo"><Plus /> Thêm combo</Button></RouterLink>
        <RouterLink v-if="canManage" to="/products/new"><Button data-testid="new-product"><Plus /> Thêm sản phẩm</Button></RouterLink>
    </PageHeader>
    <ProductsNav />

    <Card>
        <div class="flex flex-wrap items-center gap-3 border-b px-4 py-3">
            <div class="relative w-full sm:w-72">
                <Search class="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="filters.q" data-testid="product-search" placeholder="Tên sản phẩm, SKU hoặc mã vạch" class="pl-8" />
            </div>
            <Select v-model="filters.category" :options="[{ value: '', label: 'Mọi danh mục' }, ...categories.map((c) => ({ value: c.id, label: c.name }))]" class="w-full sm:w-48" aria-label="Danh mục" />
            <Button variant="outline" size="sm" data-testid="more-filters" :class="cn(moreFilters && 'bg-muted')" @click="moreFilters = !moreFilters">
                <SlidersHorizontal /> Bộ lọc khác<span v-if="activeExtra" class="rounded-full bg-primary px-1.5 text-xs text-primary-foreground">{{ activeExtra }}</span>
            </Button>
            <Select
                v-model="filters.sort"
                class="w-full sm:w-44"
                aria-label="Sắp xếp"
                :options="[
                    { value: 'newest', label: 'Mới tạo trước' },
                    { value: 'oldest', label: 'Cũ trước' },
                    { value: 'name', label: 'Tên A → Z' },
                    { value: 'available', label: 'Tồn khả dụng tăng dần' },
                    { value: '-available', label: 'Tồn khả dụng giảm dần' },
                ]"
            />
            <div class="relative">
                <Button variant="outline" size="sm" data-testid="columns-button" @click="columnsOpen = !columnsOpen"><Columns3 /> Cột</Button>
                <div v-if="columnsOpen" class="absolute left-0 top-10 z-20 w-56 rounded-md border bg-card p-2 text-sm shadow-lg" data-testid="columns-menu">
                    <label v-for="(label, key) in columnLabels" :key="key" class="flex items-center gap-2 rounded px-2 py-1.5 hover:bg-muted">
                        <input v-model="columns[key]" type="checkbox" /> {{ label }}
                    </label>
                </div>
            </div>
            <Button v-if="activeExtra || filters.q || filters.category || filters.status !== 'active'" variant="outline" size="sm" data-testid="save-view" @click="saveView"><Bookmark /> Lưu bộ lọc</Button>
            <Tabs
                v-model="filters.status"
                class="ml-auto"
                :tabs="[
                    { value: 'active', label: 'Đang bán' },
                    { value: 'inactive', label: 'Ngừng bán' },
                    { value: 'archived', label: 'Lưu trữ' },
                    { value: 'all', label: 'Tất cả' },
                ]"
            />
        </div>
        <div v-if="savedViews.length" class="flex flex-wrap items-center gap-2 border-b px-4 py-2 text-xs" data-testid="saved-views">
            <span class="text-muted-foreground">Bộ lọc đã lưu:</span>
            <span v-for="v in savedViews" :key="v.name" class="inline-flex items-center gap-1 rounded-full border bg-card pl-2.5 pr-1">
                <button type="button" class="py-0.5 hover:text-primary" @click="applyView(v)">{{ v.name }}</button>
                <button type="button" class="rounded-full p-0.5 text-muted-foreground hover:bg-muted" :aria-label="`Xóa bộ lọc ${v.name}`" @click="removeView(v.name)"><X class="size-3" /></button>
            </span>
        </div>
        <div v-if="moreFilters" class="flex flex-wrap items-end gap-3 border-b bg-muted/30 px-4 py-3 text-sm" data-testid="extra-filters">
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">Loại</span>
                <Select v-model="filters.type" class="w-40" aria-label="Loại sản phẩm" :options="[{ value: '', label: 'Mọi loại' }, { value: 'standard', label: 'Sản phẩm thường' }, { value: 'combo', label: 'Combo' }]" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">Thương hiệu</span>
                <Select v-model="filters.brand" class="w-44" aria-label="Thương hiệu" :options="[{ value: '', label: 'Mọi thương hiệu' }, ...brands.map((b) => ({ value: b.id, label: b.name }))]" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">Tag</span>
                <Select v-model="filters.tag" class="w-40" aria-label="Tag" :options="[{ value: '', label: 'Mọi tag' }, ...tags.map((t) => ({ value: t, label: t }))]" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">Tạo từ ngày</span>
                <Input v-model="filters.created_from" type="date" class="w-40" aria-label="Tạo từ ngày" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">Đến ngày</span>
                <Input v-model="filters.created_to" type="date" class="w-40" aria-label="Tạo đến ngày" />
            </label>
            <Button v-if="activeExtra" variant="ghost" size="sm" @click="clearExtra"><X /> Xóa bộ lọc</Button>
        </div>

        <div v-if="selected.size" class="flex flex-wrap items-center gap-3 border-b bg-secondary/60 px-4 py-2 text-sm">
            <span class="font-medium">Đã chọn {{ selected.size }}</span>
            <Button size="sm" variant="outline" @click="bulkStatus('active')">Đang bán</Button>
            <Button size="sm" variant="outline" @click="bulkStatus('inactive')">Ngừng bán</Button>
            <Button size="sm" variant="outline" @click="bulkStatus('archived')">Lưu trữ</Button>
            <Button size="sm" variant="outline" data-testid="bulk-print" @click="printSelected">In tem mã vạch</Button>
            <Button size="sm" variant="outline" class="text-destructive" data-testid="bulk-delete" @click="bulkDelete">Xóa</Button>
            <button class="ml-auto text-xs text-muted-foreground hover:text-foreground" @click="selected = new Set()">Bỏ chọn</button>
        </div>

        <div v-if="loading && !products.length" class="space-y-3 p-4"><Skeleton v-for="i in 6" :key="i" class="h-12" /></div>
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
        <div v-else class="overflow-x-auto" :class="loading && 'opacity-60'">
            <table class="w-full text-sm" data-testid="product-table">
                <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th v-if="canManage" class="w-10 px-4 py-2.5"><input type="checkbox" :checked="allSelected" aria-label="Chọn tất cả" @change="toggleAll" /></th>
                        <th class="px-4 py-2.5 font-medium">Sản phẩm</th>
                        <th v-if="columns.category" class="px-4 py-2.5 font-medium">Danh mục / Thương hiệu</th>
                        <th v-if="columns.price" class="px-4 py-2.5 text-right font-medium">Giá bán lẻ</th>
                        <th v-if="columns.on_hand" class="px-4 py-2.5 text-right font-medium">Tồn thực tế</th>
                        <th v-if="columns.available" class="px-4 py-2.5 text-right font-medium">Khả dụng</th>
                        <th v-if="columns.status" class="px-4 py-2.5 font-medium">Trạng thái</th>
                        <th v-if="columns.created" class="px-4 py-2.5 font-medium">Ngày tạo</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <template v-for="p in products" :key="p.id">
                        <tr class="hover:bg-muted/30">
                            <td v-if="canManage" class="px-4 py-3"><input type="checkbox" :checked="selected.has(p.id)" :aria-label="`Chọn ${p.name}`" @change="toggle(selected, p.id)" /></td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <button class="rounded p-0.5 text-muted-foreground hover:bg-muted" :aria-label="expanded.has(p.id) ? 'Thu gọn' : 'Xem biến thể'" @click="toggle(expanded, p.id)">
                                        <ChevronDown v-if="expanded.has(p.id)" class="size-4" />
                                        <ChevronRight v-else class="size-4" />
                                    </button>
                                    <img v-if="p.images?.length" :src="p.images[0]!.url" alt="" class="size-10 rounded object-cover" />
                                    <span v-else class="flex size-10 items-center justify-center rounded bg-muted text-muted-foreground"><Package class="size-4" /></span>
                                    <div class="min-w-0">
                                        <RouterLink :to="`/products/${p.id}`" class="font-medium hover:text-primary hover:underline">{{ p.name }}</RouterLink>
                                        <Badge v-if="p.type === 'combo'" class="ml-1.5">Combo</Badge>
                                        <p class="truncate text-xs text-muted-foreground">
                                            {{ p.variants_count }} biến thể ·
                                            <span class="font-mono">{{ p.variants?.slice(0, 2).map((v) => v.sku).join(', ') }}{{ (p.variants?.length ?? 0) > 2 ? '…' : '' }}</span>
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td v-if="columns.category" class="px-4 py-3 text-muted-foreground">{{ p.category?.name ?? '—' }}<span v-if="p.brand"> · {{ p.brand.name }}</span></td>
                            <td v-if="columns.price" class="px-4 py-3 text-right tabular-nums">{{ priceRange(p) }}</td>
                            <td v-if="columns.on_hand" class="px-4 py-3 text-right tabular-nums">{{ formatNumber(p.stock?.on_hand ?? 0) }}</td>
                            <td v-if="columns.available" :class="cn('px-4 py-3 text-right font-medium tabular-nums', (p.stock?.available ?? 0) <= 0 && 'text-destructive')">{{ formatNumber(p.stock?.available ?? 0) }}</td>
                            <td v-if="columns.status" class="px-4 py-3"><Badge :tone="statusTone[p.status]">{{ statusLabels[p.status] }}</Badge></td>
                            <td v-if="columns.created" class="whitespace-nowrap px-4 py-3 text-muted-foreground">{{ formatDate(p.created_at) }}</td>
                        </tr>
                        <tr v-if="expanded.has(p.id)" class="bg-muted/20">
                            <td :colspan="visibleCount" class="px-4 py-2">
                                <table class="ml-12 w-[calc(100%-3rem)] text-xs">
                                    <tr v-for="v in p.variants" :key="v.id" class="border-b border-dashed last:border-0">
                                        <td class="py-1.5 pr-4 font-mono font-medium">{{ v.sku }}</td>
                                        <td class="py-1.5 pr-4">{{ v.name ?? '—' }}</td>
                                        <td class="py-1.5 pr-4 font-mono text-muted-foreground">{{ v.barcode ?? '' }}</td>
                                        <td class="py-1.5 pr-4 text-right tabular-nums">{{ formatMoney(v.ref_price) }}</td>
                                        <td class="py-1.5 pr-4 text-right tabular-nums text-muted-foreground">{{ Number(v.wholesale_price) ? `Buôn ${formatMoney(v.wholesale_price)}` : '' }}</td>
                                        <td class="py-1.5 text-right"><RouterLink :to="`/inventory/sku/${v.id}`" class="text-primary hover:underline">Thẻ kho</RouterLink></td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((filters.page = p), load())" />
    </Card>

    <ImportDialog v-model:open="importOpen" @imported="load" />
</template>
