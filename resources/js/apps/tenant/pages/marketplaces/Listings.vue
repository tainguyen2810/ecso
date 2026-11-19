<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Check, ImageOff, Link2, PackagePlus, RefreshCw, Search, Sparkles, Unlink, X } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Pagination from '../../components/Pagination.vue';
import SkuPicker from '../../components/SkuPicker.vue';
import Tabs from '../../components/Tabs.vue';
import CreateFromMarketplace from './CreateFromMarketplace.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import Switch from '@/shared/ui/Switch.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { manualStockHint, marketplaceNames, pushStatus } from '../orders/labels';
import type { ListingSummary, ListingVariantData, MarketplaceAccountData, Variant } from '@/shared/types';

/**
 * Liên kết sản phẩm: each SKU of a shop and the internal SKU it is linked to. SKUs equal to an internal SKU link by
 * themselves (switch on top); what is left is "Cần xử lý": create the product from the shop, pick an existing one,
 * or confirm a near SKU.
 */
const route = useRoute();
const tenant = useTenantStore();
const toast = useToast();
const accountId = computed(() => route.params.id as string);

const account = ref<MarketplaceAccountData | null>(null);
const rows = ref<ListingVariantData[]>([]);
const summary = ref<ListingSummary | null>(null);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const loadError = ref<string | null>(null);
const status = ref((route.query.status as string) ?? 'todo');
const q = ref('');
const page = ref(1);
const busy = ref<string | null>(null);
const picking = ref<ListingVariantData | null>(null);
const selected = ref<Set<string>>(new Set());
const creating = ref(false);
const createOnly = ref<string[]>([]);
const canMap = computed(() => tenant.can('marketplace.connect'));
const shopLabel = computed(() => (account.value ? `${marketplaceNames[account.value.marketplace] ?? account.value.marketplace} · ${account.value.shop_name}` : ''));

const tabs = computed(() => [
    { value: 'todo', label: 'Cần xử lý', count: summary.value ? summary.value.unmapped + summary.value.suggested : null },
    { value: 'mapped', label: 'Đã liên kết', count: summary.value?.mapped },
    { value: 'ignored', label: 'Bỏ qua', count: summary.value?.ignored },
    { value: 'push_error', label: 'Lỗi cập nhật tồn', count: summary.value?.push_errors },
    { value: 'all', label: 'Tất cả' },
]);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const [l, a] = await Promise.all([
            api.get(`/marketplace-accounts/${accountId.value}/listings`, { params: { status: status.value === 'all' ? undefined : status.value, q: q.value || undefined, page: page.value } }),
            api.get('/marketplace-accounts'),
        ]);
        rows.value = l.data.data;
        meta.value = l.data.meta;
        summary.value = l.data.summary;
        selected.value = new Set();
        account.value = (a.data.data as MarketplaceAccountData[]).find((x) => x.id === accountId.value) ?? null;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
let timer: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => ((page.value = 1), load()), 300);
});
watch(status, () => ((page.value = 1), load()));

async function run(key: string, fn: () => Promise<unknown>) {
    busy.value = key;
    try {
        await fn();
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}

const map = (row: ListingVariantData, variant: { id?: string }) =>
    run(row.id, async () => {
        await api.put(`/listings/${row.id}/mapping`, { variant_id: variant.id });
        toast.success('Đã liên kết. Các đơn đang mở có sản phẩm này sẽ được giữ tồn.');
        picking.value = null;
    });
const unmap = (row: ListingVariantData, ignore = false) => run(row.id, () => api.delete(`/listings/${row.id}/mapping`, { params: { ignore: ignore ? 1 : undefined } }));
const ignoreSelected = () => run('ignore', async () => {
    for (const r of rows.value.filter((x) => selected.value.has(x.id) && x.mapping_status !== 'mapped')) await api.delete(`/listings/${r.id}/mapping`, { params: { ignore: 1 } });
});
const pull = () => run('pull', async () => {
    const { data } = await api.post(`/marketplace-accounts/${accountId.value}/sync-products`);
    toast.success(`Đã lấy ${formatNumber(data.data.variants)} mã từ sàn${data.data.linked ? `, tự liên kết ${formatNumber(data.data.linked)} mã trùng SKU` : ''}.`);
});
const autoLink = computed({
    get: () => account.value?.settings?.auto_link ?? true,
    set: (v: boolean) => run('auto', async () => {
        await api.patch(`/marketplace-accounts/${accountId.value}`, { settings: { auto_link: v } });
        toast.success(v ? 'Mã trùng SKU sẽ tự liên kết từ lần lấy hàng sau.' : 'Đã tắt. Mã trùng SKU sẽ chờ bạn xác nhận.');
    }),
});

function openCreate(listingIds: string[] = []) {
    createOnly.value = listingIds;
    creating.value = true;
}
const selectedCreatable = computed(() => [...new Set(rows.value.filter((r) => selected.value.has(r.id) && r.creatable && r.listing_id).map((r) => r.listing_id!))]);

function toggle(id: string) {
    const next = new Set(selected.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    selected.value = next;
}
const allPicked = computed(() => rows.value.length > 0 && rows.value.every((r) => selected.value.has(r.id)));
function toggleAll() {
    selected.value = allPicked.value ? new Set() : new Set(rows.value.map((r) => r.id));
}

function state(r: ListingVariantData): { label: string; tone: 'success' | 'warning' | 'danger' | 'muted' | 'default' } {
    if (r.mapping_status === 'mapped') return { label: r.linked_by_sku ? 'Tự liên kết · SKU trùng' : 'Đã liên kết', tone: 'success' };
    if (r.mapping_status === 'suggested') return { label: r.near_match ? 'SKU khác nhau' : 'Cần xác nhận', tone: 'default' };
    if (r.mapping_status === 'ignored') return { label: 'Bỏ qua', tone: 'muted' };
    return { label: r.creatable ? 'Chưa có trong kho' : 'Chưa liên kết', tone: 'warning' };
}

/** "Product title · variant name · SKU sàn: X" without the empty parts (simple Woo products have no variant name). */
function pickingLabel(r: ListingVariantData): string {
    return [r.title, r.name, r.seller_sku ? `SKU trên sàn: ${r.seller_sku}` : 'Sản phẩm trên sàn chưa có SKU'].filter(Boolean).join(' · ');
}

function pickVariant(v: Variant) {
    if (picking.value) map(picking.value, v);
}
</script>

<template>
    <RouterLink to="/marketplaces" class="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft class="size-4" /> {{ shopLabel || 'Kết nối sàn' }}</RouterLink>
    <PageHeader title="Liên kết sản phẩm" description="Mỗi mã trên sàn nối với một mã trong kho, để giữ tồn khi có đơn và đẩy tồn lên sàn. Không bao giờ ghép theo tên.">
        <Button v-if="canMap" variant="outline" :loading="busy === 'pull'" data-testid="listings-pull" @click="pull"><RefreshCw /> Lấy lại hàng từ sàn</Button>
    </PageHeader>

    <div v-if="summary" class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" data-testid="listing-summary">
        <Card class="p-4"><p class="text-sm text-muted-foreground">Mã trên sàn</p><p class="text-2xl font-semibold tabular-nums">{{ formatNumber(summary.variants) }}</p><p class="text-xs text-muted-foreground">{{ formatNumber(summary.products) }} sản phẩm</p></Card>
        <Card class="p-4"><p class="text-sm text-muted-foreground">Đã liên kết</p><p class="text-2xl font-semibold tabular-nums text-emerald-700">{{ formatNumber(summary.mapped) }}</p><p class="text-xs text-muted-foreground">{{ formatNumber(summary.linked_by_sku) }} tự liên kết vì SKU trùng</p></Card>
        <Card class="border-amber-200 bg-amber-50/40 p-4"><p class="text-sm text-muted-foreground">Chưa liên kết</p><p class="text-2xl font-semibold tabular-nums text-amber-700">{{ formatNumber(summary.unmapped) }}</p><p class="text-xs text-muted-foreground">Tạo sản phẩm hoặc chọn mã có sẵn</p></Card>
        <Card class="border-sky-200 bg-sky-50/40 p-4"><p class="text-sm text-muted-foreground">Cần xác nhận</p><p class="text-2xl font-semibold tabular-nums text-sky-700">{{ formatNumber(summary.suggested) }}</p><p class="text-xs text-muted-foreground">SKU gần giống, chưa chắc</p></Card>
    </div>

    <Card v-if="canMap && summary?.creatable_products" class="mb-4 flex flex-wrap items-center gap-4 border-primary/20 bg-primary/5 p-4" data-testid="listing-create-banner">
        <Sparkles class="size-5 text-primary" />
        <div class="min-w-0 flex-1">
            <p class="font-medium">{{ formatNumber(summary.creatable_variants) }} mã trên sàn chưa có trong kho</p>
            <p class="text-sm text-muted-foreground">Tạo {{ formatNumber(summary.creatable_products) }} sản phẩm có sẵn tên, ảnh, phân loại và giá đang bán trên sàn, rồi tự liên kết.</p>
        </div>
        <Button data-testid="listing-create-all" @click="openCreate()"><PackagePlus /> Tạo {{ formatNumber(summary.creatable_products) }} sản phẩm từ sàn</Button>
    </Card>

    <Card>
        <div class="flex flex-wrap items-center gap-3 border-b px-3">
            <Tabs v-model="status" :tabs="tabs" variant="underline" />
            <div v-if="canMap && account" class="ml-auto py-2" data-testid="listing-auto-link"><Switch v-model="autoLink" label="Tự liên kết khi SKU trùng" :disabled="busy === 'auto'" /></div>
        </div>
        <div class="border-b p-4">
            <div class="relative max-w-md">
                <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="q" class="pl-8" placeholder="Tên, SKU trên sàn, ID trên sàn…" />
            </div>
        </div>
        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 6" :key="i" class="h-12" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState v-else-if="!rows.length" :icon="Link2" :title="status === 'todo' ? 'Không còn mã nào cần xử lý' : 'Không có mã nào'" :description="status === 'todo' ? 'Mọi mã trên sàn đã liên kết hoặc đã bỏ qua.' : 'Bấm “Lấy lại hàng từ sàn” để lấy danh sách sản phẩm.'" />
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="listing-table">
                <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th v-if="canMap" class="w-10 px-4 py-2.5"><input type="checkbox" class="size-4" :checked="allPicked" aria-label="Chọn tất cả" @change="toggleAll" /></th>
                        <th class="px-4 py-2.5 font-medium">Hàng trên sàn</th>
                        <th class="px-4 py-2.5 font-medium">SKU trên sàn</th>
                        <th class="px-4 py-2.5 text-right font-medium">Giá bán</th>
                        <th class="px-4 py-2.5 text-right font-medium">Tồn sàn</th>
                        <th class="px-4 py-2.5 font-medium">Trạng thái</th>
                        <th class="px-4 py-2.5 font-medium">{{ canMap ? 'Việc cần làm' : '' }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="r in rows" :key="r.id" :class="selected.has(r.id) && 'bg-primary/5'">
                        <td v-if="canMap" class="px-4 py-3"><input type="checkbox" class="size-4" :checked="selected.has(r.id)" :aria-label="r.title" @change="toggle(r.id)" /></td>
                        <td class="max-w-80 px-4 py-3">
                            <div class="flex items-center gap-3">
                                <img v-if="r.image_url" :src="r.image_url" alt="" class="size-11 shrink-0 rounded-lg border object-cover" loading="lazy" />
                                <span v-else class="grid size-11 shrink-0 place-items-center rounded-lg border border-dashed bg-slate-50 text-slate-300"><ImageOff class="size-5" /></span>
                                <div class="min-w-0">
                                    <div class="truncate font-medium">{{ r.title }}</div>
                                    <div class="truncate text-xs text-muted-foreground">{{ r.name || `ID ${r.external_sku_id}` }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">
                            <span v-if="r.seller_sku">{{ r.seller_sku }}</span>
                            <span v-else class="font-sans text-rose-600">Chưa có SKU trên sàn</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(r.price) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ r.marketplace_stock ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <Badge :tone="state(r).tone" class="whitespace-nowrap">{{ state(r).label }}</Badge>
                            <div v-if="r.mapping_status === 'mapped' && r.variant" class="mt-1 text-xs"><span class="font-mono">{{ r.variant.sku }}</span> <span class="text-muted-foreground">· {{ r.variant.name }}</span></div>
                            <div v-if="r.push_status && r.mapping_status === 'mapped'" class="mt-1 text-xs">
                                <span :class="pushStatus[r.push_status]?.tone === 'danger' ? 'text-destructive' : 'text-muted-foreground'">Tồn: {{ pushStatus[r.push_status]?.label ?? r.push_status }}</span>
                                <span v-if="r.last_pushed_qty !== null" class="text-muted-foreground"> · {{ formatNumber(r.last_pushed_qty) }} · {{ formatDateTime(r.last_pushed_at) }}</span>
                                <div v-if="r.push_status === 'unsupported'" class="max-w-56 text-muted-foreground">{{ manualStockHint }}</div>
                                <div v-else-if="r.push_error" class="text-destructive">{{ r.push_error }}</div>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div v-if="canMap" class="flex items-center gap-1" :class="r.mapping_status === 'mapped' ? 'flex-nowrap' : 'flex-wrap'">
                                <template v-if="r.mapping_status === 'suggested' && r.variant">
                                    <div class="mb-1 w-full text-xs text-muted-foreground">Có thể là <b class="font-mono text-foreground">{{ r.variant.sku }}</b>?</div>
                                    <Button size="sm" variant="outline" :loading="busy === r.id" @click="map(r, r.variant)"><Check /> Đúng, liên kết</Button>
                                    <Button size="sm" variant="ghost" @click="picking = r">Chọn khác</Button>
                                </template>
                                <template v-else-if="r.mapping_status === 'unmapped'">
                                    <Button v-if="r.creatable" size="sm" variant="outline" :data-testid="`listing-create-${r.id}`" @click="openCreate([r.listing_id!])"><PackagePlus /> Tạo sản phẩm</Button>
                                    <Button size="sm" variant="ghost" @click="picking = r">Chọn có sẵn</Button>
                                    <Button size="sm" variant="ghost" :loading="busy === r.id" title="Bỏ qua mã này" aria-label="Bỏ qua mã này" @click="unmap(r, true)"><X /></Button>
                                </template>
                                <template v-else-if="r.mapping_status === 'mapped'">
                                    <Button size="sm" variant="ghost" @click="picking = r">Đổi</Button>
                                    <Button size="sm" variant="ghost" :loading="busy === r.id" title="Bỏ liên kết" aria-label="Bỏ liên kết" @click="unmap(r)"><Unlink /></Button>
                                </template>
                                <Button v-else size="sm" variant="ghost" @click="picking = r">Liên kết</Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-if="selected.size" class="flex flex-wrap items-center gap-3 border-t bg-slate-900 px-4 py-3 text-white" data-testid="listing-bulk">
            <span class="font-medium">Đã chọn {{ selected.size }}</span>
            <Button v-if="selectedCreatable.length" size="sm" variant="outline" class="text-foreground" @click="openCreate(selectedCreatable)"><PackagePlus /> Tạo sản phẩm từ sàn</Button>
            <Button size="sm" variant="ghost" class="text-white/80 hover:text-white" :loading="busy === 'ignore'" @click="ignoreSelected">Bỏ qua</Button>
            <button type="button" class="ml-auto text-sm text-white/70 hover:text-white" @click="selected = new Set()">Bỏ chọn</button>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((page = p), load())" />
    </Card>

    <CreateFromMarketplace v-if="account" v-model:open="creating" :account-id="accountId" :marketplace="account.marketplace" :preselected="createOnly" @done="load" />

    <Dialog :open="picking !== null" size="lg" title="Chọn mã trong kho" :description="picking ? pickingLabel(picking) : ''" @update:open="(v) => !v && (picking = null)">
        <SkuPicker v-if="picking" :key="picking.id" inline :initial-query="picking.seller_sku ?? ''" placeholder="Tìm SKU, mã vạch, tên sản phẩm…" @pick="pickVariant" />
        <p class="mt-3 text-xs text-muted-foreground">Sau khi liên kết, tồn có thể bán của mã trong kho sẽ được đẩy lên sàn và các đơn đang mở có sản phẩm này sẽ được giữ tồn.</p>
    </Dialog>
</template>
