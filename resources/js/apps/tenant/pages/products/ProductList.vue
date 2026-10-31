<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ChevronDown, ChevronRight, Download, FileSpreadsheet, Package, Plus, Search, Upload } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Pagination from '../../components/Pagination.vue';
import Tabs from '../../components/Tabs.vue';
import ImportDialog from './ImportDialog.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import type { Product, Ref } from '@/shared/types';

const tenant = useTenantStore();
const toast = useToast();
const canManage = computed(() => tenant.can('products.manage'));

const products = ref<Product[]>([]);
const categories = ref<Ref[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const loadError = ref<string | null>(null);
const filters = reactive({ q: '', status: 'active', category: '', page: 1 });
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
        const { data } = await api.get('/products', {
            params: { q: filters.q || undefined, status: filters.status === 'all' ? undefined : filters.status, category: filters.category || undefined, page: filters.page },
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
    categories.value = (await api.get('/categories')).data.data;
    await load();
});

let timer: ReturnType<typeof setTimeout> | undefined;
watch(() => filters.q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => ((filters.page = 1), load()), 250);
});
watch(() => [filters.status, filters.category], () => ((filters.page = 1), load()));

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

const allSelected = computed(() => products.value.length > 0 && products.value.every((p) => selected.value.has(p.id)));
function toggleAll() {
    selected.value = allSelected.value ? new Set() : new Set(products.value.map((p) => p.id));
}
</script>

<template>
    <PageHeader title="Sản phẩm" description="Sản phẩm, biến thể và SKU nội bộ. SKU là duy nhất trong doanh nghiệp.">
        <Button variant="outline" :loading="exporting" @click="exportXlsx"><Download /> Xuất Excel</Button>
        <Button v-if="canManage" variant="outline" data-testid="import-button" @click="importOpen = true"><Upload /> Import</Button>
        <RouterLink v-if="canManage" to="/products/new"><Button data-testid="new-product"><Plus /> Thêm sản phẩm</Button></RouterLink>
    </PageHeader>

    <Card>
        <div class="flex flex-wrap items-center gap-3 border-b px-4 py-3">
            <div class="relative w-full sm:w-72">
                <Search class="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="filters.q" data-testid="product-search" placeholder="Tên sản phẩm, SKU hoặc mã vạch" class="pl-8" />
            </div>
            <Select v-model="filters.category" :options="[{ value: '', label: 'Mọi danh mục' }, ...categories.map((c) => ({ value: c.id, label: c.name }))]" class="w-full sm:w-48" />
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

        <div v-if="selected.size" class="flex items-center gap-3 border-b bg-secondary/60 px-4 py-2 text-sm">
            <span class="font-medium">Đã chọn {{ selected.size }}</span>
            <Button size="sm" variant="outline" @click="bulkStatus('active')">Đang bán</Button>
            <Button size="sm" variant="outline" @click="bulkStatus('inactive')">Ngừng bán</Button>
            <Button size="sm" variant="outline" @click="bulkStatus('archived')">Lưu trữ</Button>
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
                        <th class="px-4 py-2.5 font-medium">Danh mục / Thương hiệu</th>
                        <th class="px-4 py-2.5 text-right font-medium">Giá tham khảo</th>
                        <th class="px-4 py-2.5 text-right font-medium">Tồn thực tế</th>
                        <th class="px-4 py-2.5 text-right font-medium">Khả dụng</th>
                        <th class="px-4 py-2.5 font-medium">Trạng thái</th>
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
                                        <p class="truncate text-xs text-muted-foreground">
                                            {{ p.variants_count }} biến thể ·
                                            <span class="font-mono">{{ p.variants?.slice(0, 2).map((v) => v.sku).join(', ') }}{{ (p.variants?.length ?? 0) > 2 ? '…' : '' }}</span>
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">{{ p.category?.name ?? '—' }}<span v-if="p.brand"> · {{ p.brand.name }}</span></td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ priceRange(p) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatNumber(p.stock?.on_hand ?? 0) }}</td>
                            <td :class="cn('px-4 py-3 text-right font-medium tabular-nums', (p.stock?.available ?? 0) <= 0 && 'text-destructive')">{{ formatNumber(p.stock?.available ?? 0) }}</td>
                            <td class="px-4 py-3"><Badge :tone="statusTone[p.status]">{{ statusLabels[p.status] }}</Badge></td>
                        </tr>
                        <tr v-if="expanded.has(p.id)" class="bg-muted/20">
                            <td :colspan="canManage ? 7 : 6" class="px-4 py-2">
                                <table class="ml-12 w-[calc(100%-3rem)] text-xs">
                                    <tr v-for="v in p.variants" :key="v.id" class="border-b border-dashed last:border-0">
                                        <td class="py-1.5 pr-4 font-mono font-medium">{{ v.sku }}</td>
                                        <td class="py-1.5 pr-4">{{ v.name ?? '—' }}</td>
                                        <td class="py-1.5 pr-4 font-mono text-muted-foreground">{{ v.barcode ?? '' }}</td>
                                        <td class="py-1.5 pr-4 text-right tabular-nums">{{ formatMoney(v.ref_price) }}</td>
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
