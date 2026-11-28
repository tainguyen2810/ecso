<script setup lang="ts">
import { computed, onMounted, provide, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { Check, CircleHelp, ImageOff, Lightbulb, Link2, Link2Off, PackagePlus, Plus, RefreshCw, Search, Sparkles, Store, Unlink } from 'lucide-vue-next';
import ChannelHead from './ChannelHead.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import { btn, card, pill, th } from './channel';
import Pagination from '../../components/Pagination.vue';
import SkuPicker from '../../components/SkuPicker.vue';
import CreateFromMarketplace from './CreateFromMarketplace.vue';
import Button from '@/shared/ui/Button.vue';
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
provide('soft-cards', true);
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

const chipTones = { success: 'bg-emerald-50 text-emerald-700', warning: 'bg-amber-50 text-amber-700', danger: 'bg-red-50 text-red-600', muted: 'bg-slate-100 text-slate-500', default: 'bg-violet-50 text-violet-700' };
const chip = (r: ListingVariantData) => `inline-flex whitespace-nowrap rounded-md px-2 py-0.5 text-[12px] font-medium ${chipTones[state(r).tone]}`;

/** "Product title · variant name · SKU sàn: X" without the empty parts (simple Woo products have no variant name). */
function pickingLabel(r: ListingVariantData): string {
    return [r.title, r.name, r.seller_sku ? `SKU trên sàn: ${r.seller_sku}` : 'Sản phẩm trên sàn chưa có SKU'].filter(Boolean).join(' · ');
}

function pickVariant(v: Variant) {
    if (picking.value) map(picking.value, v);
}
</script>

<template>
    <ChannelHead title="Liên kết sản phẩm" :description="shopLabel" :marketplace="account?.marketplace" :crumbs="account ? [{ label: marketplaceNames[account.marketplace] ?? account.marketplace, to: `/marketplaces?san=${account.marketplace}` }] : []">
        <button v-if="canMap" type="button" :class="btn.outline" :disabled="busy === 'pull'" data-testid="listings-pull" @click="pull"><RefreshCw :class="['size-4', busy === 'pull' && 'animate-spin']" /> Lấy lại hàng từ sàn</button>
        <button v-if="canMap && summary?.creatable_products" type="button" :class="btn.primary" data-testid="listing-create-all" @click="openCreate()"><PackagePlus class="size-4" /> Tạo {{ formatNumber(summary.creatable_products) }} sản phẩm từ sàn</button>
    </ChannelHead>

    <div v-if="summary" class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="listing-summary">
        <ReportKpi label="Mã trên sàn" :icon="Store" tone="blue" plain :value="summary.variants" unit="">{{ formatNumber(summary.products) }} sản phẩm · {{ formatNumber(summary.variants) }} phân loại</ReportKpi>
        <ReportKpi label="Đã liên kết" :icon="Link2" tone="green" plain :value="summary.mapped" unit="">
            <div class="mb-2 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-500" :style="{ width: `${summary.variants ? Math.round((summary.mapped / summary.variants) * 100) : 0}%` }" /></div>
            {{ formatNumber(summary.linked_by_sku) }} tự liên kết vì SKU trùng
        </ReportKpi>
        <ReportKpi label="Chưa liên kết" :icon="Link2Off" tone="amber" :value="summary.unmapped" unit="">Tạo sản phẩm hoặc chọn mã có sẵn</ReportKpi>
        <ReportKpi label="Cần xác nhận" :icon="CircleHelp" tone="violet" :value="summary.suggested" unit="">SKU gần giống, chưa chắc</ReportKpi>
    </div>

    <div v-if="canMap && summary?.creatable_products" :class="[card, 'mb-5 flex flex-wrap items-center gap-4 border-blue-100 bg-blue-50/50 p-4']" data-testid="listing-create-banner">
        <span class="grid size-10 shrink-0 place-items-center rounded-[11px] bg-white text-blue-600 shadow-sm"><Sparkles class="size-5" /></span>
        <div class="min-w-0 flex-1 text-[14px]">
            <p class="font-semibold text-slate-900">{{ formatNumber(summary.creatable_variants) }} mã trên sàn chưa có trong kho</p>
            <p class="text-slate-500">Tạo {{ formatNumber(summary.creatable_products) }} sản phẩm có sẵn tên, ảnh, phân loại và giá đang bán trên sàn, rồi tự liên kết.</p>
        </div>
        <button type="button" :class="btn.small" @click="openCreate()"><PackagePlus class="size-4" /> Xem và tạo</button>
    </div>
    <div v-else :class="[card, 'mb-5 flex items-center gap-4 border-blue-100 bg-blue-50/40 p-4 text-[14px] text-slate-600']">
        <span class="grid size-10 shrink-0 place-items-center rounded-[11px] bg-white text-blue-600 shadow-sm"><Lightbulb class="size-5" /></span>
        Mỗi mã trên sàn nối với một mã trong kho để giữ tồn khi có đơn và đẩy tồn lên sàn. Phần mềm không bao giờ ghép theo tên, chỉ gợi ý khi SKU gần giống để bạn xác nhận.
    </div>

    <div :class="card">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4">
            <div class="flex max-w-full flex-wrap gap-1 rounded-[12px] bg-slate-100 p-1 text-[14px]">
                <button v-for="t in tabs" :key="t.value" type="button" :class="pill(status === t.value)" @click="status = t.value">
                    {{ t.label }}
                    <span v-if="t.count !== null && t.count !== undefined" :class="['rounded-md px-1.5 text-[12px] tabular-nums', status === t.value ? 'bg-blue-50 text-blue-700' : 'bg-white/70 text-slate-500']">{{ formatNumber(t.count) }}</span>
                </button>
            </div>
            <div class="ml-auto flex flex-wrap items-center gap-4">
                <div class="relative w-full sm:w-80">
                    <Search class="pointer-events-none absolute left-3 top-3 size-4 text-slate-400" />
                    <Input v-model="q" class="h-10 pl-9" placeholder="Tên, SKU trên sàn, ID trên sàn…" />
                </div>
                <div v-if="canMap && account" data-testid="listing-auto-link"><Switch v-model="autoLink" label="Tự liên kết khi SKU trùng" :disabled="busy === 'auto'" /></div>
            </div>
        </div>
        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 6" :key="i" class="h-14" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState v-else-if="!rows.length" :icon="Link2" :title="status === 'todo' ? 'Không còn mã nào cần xử lý' : 'Không có mã nào'" :description="status === 'todo' ? 'Mọi mã trên sàn đã liên kết hoặc đã bỏ qua.' : 'Bấm “Lấy lại hàng từ sàn” để lấy danh sách sản phẩm.'" />
        <div v-else class="overflow-x-auto">
            <table class="w-full text-[14px]" data-testid="listing-table">
                <thead class="border-b border-slate-100 text-left">
                    <tr>
                        <th v-if="canMap" :class="[th, 'w-10']"><input type="checkbox" class="size-4 accent-blue-600" :checked="allPicked" aria-label="Chọn tất cả" @change="toggleAll" /></th>
                        <th :class="th">Hàng trên sàn</th>
                        <th :class="th">SKU trên sàn</th>
                        <th :class="[th, 'text-right']">Giá bán</th>
                        <th :class="[th, 'text-right']">Tồn sàn</th>
                        <th :class="th">Trạng thái</th>
                        <th :class="[th, 'min-w-[420px]']">Mã trong kho</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="r in rows" :key="r.id" :class="selected.has(r.id) && 'bg-blue-50/40'">
                        <td v-if="canMap" class="px-4 py-3.5"><input type="checkbox" class="size-4 accent-blue-600" :checked="selected.has(r.id)" :aria-label="r.title" @change="toggle(r.id)" /></td>
                        <td class="max-w-80 px-4 py-3.5">
                            <div class="flex items-center gap-3">
                                <img v-if="r.image_url" :src="r.image_url" alt="" class="size-11 shrink-0 rounded-[10px] border border-slate-200 object-cover" loading="lazy" />
                                <span v-else class="grid size-11 shrink-0 place-items-center rounded-[10px] bg-slate-50 text-slate-300"><ImageOff class="size-5" /></span>
                                <div class="min-w-0">
                                    <div class="truncate font-medium text-slate-900">{{ r.title }}</div>
                                    <div class="truncate text-[12px] text-slate-500">{{ r.name ? `Phân loại: ${r.name} · ` : '' }}ID {{ r.external_sku_id }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3.5 font-mono text-[13px] text-slate-700">
                            <span v-if="r.seller_sku">{{ r.seller_sku }}</span>
                            <span v-else class="font-sans text-red-600">Chưa có SKU</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3.5 text-right tabular-nums">{{ formatMoney(r.price) }}</td>
                        <td class="px-4 py-3.5 text-right tabular-nums">{{ r.marketplace_stock ?? '—' }}</td>
                        <td class="px-4 py-3.5">
                            <span :class="chip(r)">{{ state(r).label }}</span>
                            <div v-if="r.push_status && r.mapping_status === 'mapped'" class="mt-1 text-[12px]">
                                <span :class="pushStatus[r.push_status]?.tone === 'danger' ? 'text-red-600' : 'text-slate-500'">Tồn: {{ pushStatus[r.push_status]?.label ?? r.push_status }}</span>
                                <span v-if="r.last_pushed_qty !== null" class="text-slate-500"> · {{ formatNumber(r.last_pushed_qty) }} · {{ formatDateTime(r.last_pushed_at) }}</span>
                                <div v-if="r.push_status === 'unsupported'" class="max-w-56 text-slate-500">{{ manualStockHint }}</div>
                                <div v-else-if="r.push_error" class="max-w-56 text-red-600">{{ r.push_error }}</div>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div v-if="r.mapping_status === 'suggested' && r.variant" class="flex flex-wrap items-center gap-2 rounded-[12px] border border-slate-200 bg-slate-50/60 px-3 py-2">
                                <div class="min-w-0 flex-1">
                                    <div class="truncate font-medium text-slate-900">{{ r.variant.name }}</div>
                                    <div class="text-[12px] text-slate-500"><span class="font-mono">{{ r.variant.sku }}</span> · {{ r.near_match ? 'SKU gần giống' : 'gợi ý' }}</div>
                                </div>
                                <template v-if="canMap">
                                    <button type="button" :class="btn.smallPrimary" :disabled="busy === r.id" @click="map(r, r.variant)"><Check class="size-4" /> Đúng, liên kết</button>
                                    <button type="button" :class="btn.small" @click="picking = r">Chọn khác</button>
                                </template>
                            </div>
                            <div v-else-if="r.mapping_status === 'unmapped'" class="flex flex-wrap items-center gap-2 rounded-[12px] border border-dashed border-amber-300 bg-amber-50/40 px-3 py-2">
                                <span class="min-w-0 flex-1 text-[13px] text-amber-700">{{ r.creatable ? 'Chưa có mã nào trong kho khớp' : 'Chưa liên kết' }}</span>
                                <template v-if="canMap">
                                    <button type="button" :class="btn.small" @click="picking = r"><Search class="size-4" /> Chọn có sẵn</button>
                                    <button v-if="r.creatable" type="button" :class="[btn.small, 'border-blue-100 bg-blue-50 text-blue-700']" :data-testid="`listing-create-${r.id}`" @click="openCreate([r.listing_id!])"><Plus class="size-4" /> Tạo sản phẩm</button>
                                    <button type="button" class="px-2 text-[13px] text-slate-500 hover:text-slate-900 disabled:opacity-50" :disabled="busy === r.id" @click="unmap(r, true)">Bỏ qua</button>
                                </template>
                            </div>
                            <div v-else-if="r.mapping_status === 'mapped'" class="flex items-center gap-3 rounded-[12px] bg-slate-50 px-3 py-2">
                                <Link2 class="size-4 shrink-0 text-emerald-600" />
                                <div class="min-w-0 flex-1">
                                    <div class="truncate font-medium text-slate-900">{{ r.variant?.name ?? 'Đã liên kết' }}</div>
                                    <div class="font-mono text-[12px] text-slate-500">{{ r.variant?.sku ?? '—' }}</div>
                                </div>
                                <template v-if="canMap">
                                    <button type="button" class="px-2 text-[13px] text-slate-600 hover:text-blue-700" @click="picking = r">Đổi</button>
                                    <button type="button" class="grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-white hover:text-red-600 disabled:opacity-50" :disabled="busy === r.id" title="Bỏ liên kết" aria-label="Bỏ liên kết" @click="unmap(r)"><Unlink class="size-4" /></button>
                                </template>
                            </div>
                            <div v-else class="flex items-center gap-2 rounded-[12px] bg-slate-50 px-3 py-2 text-[13px] text-slate-500">
                                <span class="flex-1">Đã bỏ qua, không theo dõi tồn mã này</span>
                                <button v-if="canMap" type="button" :class="btn.small" @click="picking = r">Liên kết</button>
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
    </div>

    <CreateFromMarketplace v-if="account" v-model:open="creating" :account-id="accountId" :marketplace="account.marketplace" :preselected="createOnly" @done="load" />

    <Dialog :open="picking !== null" size="lg" title="Chọn mã trong kho" :description="picking ? pickingLabel(picking) : ''" @update:open="(v) => !v && (picking = null)">
        <SkuPicker v-if="picking" :key="picking.id" inline :initial-query="picking.seller_sku ?? ''" placeholder="Tìm SKU, mã vạch, tên sản phẩm…" @pick="pickVariant" />
        <p class="mt-3 text-xs text-muted-foreground">Sau khi liên kết, tồn có thể bán của mã trong kho sẽ được đẩy lên sàn và các đơn đang mở có sản phẩm này sẽ được giữ tồn.</p>
    </Dialog>
</template>
