<script setup lang="ts">
import { computed, onMounted, provide, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeftRight, ArrowRight, Boxes, CircleCheck, ClipboardCheck, ClipboardList, FileText, Info, PackagePlus, Plus, Trash2, TriangleAlert, Truck, Warehouse as WarehouseIcon } from 'lucide-vue-next';
import ProductsHead from '../products/ui/ProductsHead.vue';
import HeadLink from '../products/ui/HeadLink.vue';
import HeadButton from '../products/ui/HeadButton.vue';
import LineTabs from '../products/ui/LineTabs.vue';
import NumberPager from '../products/ui/NumberPager.vue';
import StatusChip from '../products/ui/StatusChip.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import ReportChip from '../reports/ReportChip.vue';
import SideCard from '../reports/SideCard.vue';
import { card, rowButton, rowPrimary, th, thead, type ChipTone } from '../products/ui/ui';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { adjustmentReasons, documentKinds, documentStatus, type DocumentKindKey } from './labels';
import type { InventoryDocument } from '@/shared/types';

/**
 * Lists of stock documents (nhập kho, điều chỉnh tồn, chuyển hàng) in the Sản phẩm style (mẫu 28/11). Transfers get
 * their figures (on the road, drafts, received this month, received short), from → to blocks and the next step per row.
 */
interface TransferSummary {
    counts: Record<'all' | 'draft' | 'in_transit' | 'received' | 'cancelled', number>;
    in_transit: { count: number; quantity: number };
    month: { count: number; quantity: number };
    short: { count: number; pending: number; quantity: number };
}

provide('soft-cards', true);
const route = useRoute();
const tenant = useTenantStore();
const canCost = computed(() => tenant.can('products.cost.view'));
const kindKey = computed(() => route.meta.kind as DocumentKindKey);
const kind = computed(() => documentKinds[kindKey.value]);
const transfers = computed(() => kindKey.value === 'transfers');

const docs = ref<InventoryDocument[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0, per_page: 20 });
const loading = ref(true);
const loadError = ref<string | null>(null);
const status = ref('all');
const page = ref(1);
const summary = ref<TransferSummary | null>(null);

const statusTabs = computed(() => {
    if (kindKey.value === 'adjustments') return ['all', 'pending', 'approved', 'rejected', 'cancelled'];
    if (transfers.value) return ['all', 'draft', 'in_transit', 'received', 'cancelled'];
    return [];
});
const tabLabel: Record<string, string> = { draft: 'Nháp', in_transit: 'Đang chuyển', received: 'Đã nhận' };
const tabs = computed(() =>
    statusTabs.value.map((s) => ({
        value: s,
        label: s === 'all' ? 'Tất cả' : (transfers.value && tabLabel[s]) || documentStatus[s]!.label,
        count: transfers.value ? (summary.value?.counts as Record<string, number> | undefined)?.[s] : undefined,
    })),
);

const titles = { receipts: 'Nhập kho', adjustments: 'Điều chỉnh tồn', transfers: 'Chuyển hàng' } as const;
const descriptions: Record<DocumentKindKey, string> = {
    receipts: 'Nhập hàng vào kho. Giá vốn bình quân được cập nhật tự động theo từng phiếu.',
    adjustments: 'Điều chỉnh theo kiểm kê, hàng hỏng, thất lạc. Chỉ thay đổi tồn sau khi được duyệt.',
    transfers: 'Chuyển hàng giữa các kho, chi nhánh. Kho đi trừ tồn khi xuất, kho đến cộng tồn khi xác nhận nhận hàng.',
};
const tones: Record<string, ChipTone> = { success: 'green', danger: 'red', warning: 'amber', muted: 'slate', default: 'blue' };
function chipOf(d: InventoryDocument): { label: string; tone: ChipTone } {
    if (d.kind === 'transfer') {
        if (d.status === 'received' && d.shortage) return { label: 'Nhận thiếu', tone: 'red' };
        if (d.status === 'in_transit') return { label: 'Đang chuyển', tone: 'blue' };
    }
    const s = documentStatus[d.status ?? ''];
    return { label: s?.label ?? d.status ?? '', tone: tones[s?.tone ?? 'muted']! };
}
function progress(d: InventoryDocument): string {
    if (d.status === 'draft') return 'Chưa xuất kho';
    if (d.status === 'in_transit') return `Xuất ${formatDateTime(d.dispatched_at)}`;
    if (d.status === 'received') {
        return d.shortage && d.received_qty != null && d.total_qty != null
            ? `Nhận ${formatNumber(d.received_qty)}/${formatNumber(d.total_qty)} · ${formatDateTime(d.received_at)}`
            : `Nhận đủ ${formatDateTime(d.received_at)}`;
    }
    return '—';
}
const linkOf = (d: InventoryDocument) => `/inventory/${kindKey.value}/${d.id}`;
const canAct = computed(() => tenant.can(kind.value.permission));

/** On the road, per pair of warehouses, from the transfers on this page. */
const routes = computed(() => {
    const map = new Map<string, { from: string; to: string; count: number; quantity: number }>();
    for (const d of docs.value.filter((x) => x.status === 'in_transit')) {
        const key = `${d.from_warehouse?.id}>${d.to_warehouse?.id}`;
        const r = map.get(key) ?? { from: d.from_warehouse?.name ?? '', to: d.to_warehouse?.name ?? '', count: 0, quantity: 0 };
        r.count++;
        r.quantity += d.total_qty ?? 0;
        map.set(key, r);
    }
    return [...map.values()];
});

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get(`/inventory/${kindKey.value}`, { params: { status: status.value === 'all' ? undefined : status.value, page: page.value } });
        docs.value = data.data;
        meta.value = { per_page: 20, ...data.meta };
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
function loadSummary() {
    summary.value = null;
    if (transfers.value) api.get('/inventory/transfers/summary').then(({ data }) => (summary.value = data.data)).catch(() => {});
}
onMounted(() => (load(), loadSummary()));
watch(status, () => ((page.value = 1), load()));
watch(kindKey, () => ((status.value = 'all'), (page.value = 1), load(), loadSummary()));
</script>

<template>
    <ProductsHead :title="titles[kindKey]" :description="descriptions[kindKey]">
        <template v-if="!transfers" #links>
            <HeadLink :icon="Boxes" to="/inventory">Quản lý kho</HeadLink>
            <HeadLink v-if="kindKey !== 'receipts'" :icon="PackagePlus" to="/inventory/receipts">Nhập kho</HeadLink>
            <HeadLink v-if="kindKey !== 'adjustments'" :icon="ClipboardList" to="/inventory/adjustments">Điều chỉnh tồn</HeadLink>
            <HeadLink :icon="ArrowLeftRight" to="/inventory/transfers">Chuyển hàng</HeadLink>
        </template>
        <template v-if="kindKey === 'adjustments' && canAct">
            <HeadButton :icon="ClipboardCheck" to="/inventory/stocktake" data-testid="open-stocktake">Kiểm hàng</HeadButton>
            <HeadButton :icon="Trash2" :to="{ path: '/inventory/adjustments/new', query: { reason: 'damaged' } }" data-testid="new-write-off">Xuất hủy</HeadButton>
        </template>
        <HeadButton :icon="WarehouseIcon" to="/inventory/warehouses">Danh sách kho</HeadButton>
        <HeadButton v-if="canAct" :icon="Plus" primary :to="`/inventory/${kindKey}/new`" data-testid="new-document">{{ kind.create }}</HeadButton>
    </ProductsHead>

    <div v-if="transfers" class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="transfer-kpis">
        <ReportKpi label="Đang trên đường" tip="Phiếu đã xuất khỏi kho đi, kho đến chưa xác nhận nhận hàng." :icon="Truck" primary :value="summary?.in_transit.count ?? null" unit="phiếu">
            <template #chips><ReportChip tone="onblue">{{ formatNumber(summary?.in_transit.quantity ?? 0) }} sp</ReportChip></template>
            Chờ kho đến xác nhận nhận hàng
        </ReportKpi>
        <ReportKpi label="Phiếu nháp" :icon="FileText" tone="slate" plain :value="summary?.counts.draft ?? null" unit="phiếu">
            <template #chips><ReportChip>Chưa xuất kho</ReportChip></template>
            Tồn chưa thay đổi
        </ReportKpi>
        <ReportKpi label="Đã nhận tháng này" :icon="CircleCheck" tone="green" plain :value="summary?.month.count ?? null" unit="phiếu">
            <template #chips><ReportChip tone="up">{{ formatNumber(summary?.month.quantity ?? 0) }} sp</ReportChip></template>
            Đã cộng vào tồn kho đến
        </ReportKpi>
        <ReportKpi label="Nhận thiếu" tip="Kho đến nhận ít hơn số đã xuất. Phần thiếu nằm ở một phiếu thất lạc chờ duyệt." :icon="TriangleAlert" tone="red" :value="summary?.short.count ?? null" unit="phiếu">
            <template #chips><ReportChip tone="red">Thiếu {{ formatNumber(summary?.short.quantity ?? 0) }} sp</ReportChip></template>
            {{ summary?.short.pending ? `${formatNumber(summary.short.pending)} phiếu thất lạc chờ duyệt` : 'Kho đến nhận ít hơn số đã xuất' }}
        </ReportKpi>
    </div>

    <div :class="cn('grid gap-5', transfers && '2xl:grid-cols-[minmax(0,1fr)_380px]')">
        <section :class="cn(card, 'min-w-0 overflow-hidden')">
            <LineTabs v-if="tabs.length" v-model="status" :tabs="tabs" />
            <div v-if="loading && !docs.length" class="space-y-3 p-5"><Skeleton v-for="i in 5" :key="i" class="h-12" /></div>
            <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
            <EmptyState v-else-if="!docs.length" :icon="FileText" :title="`Chưa có ${kind.title.toLowerCase()}`" :description="descriptions[kindKey]" />
            <div v-else class="overflow-x-auto" :class="loading && 'opacity-60'">
                <table class="w-full min-w-[860px] text-[14px]" data-testid="document-table">
                    <thead :class="thead">
                        <tr>
                            <th :class="th">Số phiếu</th>
                            <th :class="th">{{ transfers ? 'Từ kho → Đến kho' : 'Kho, ghi chú' }}</th>
                            <th v-if="kindKey === 'adjustments'" :class="th">Lý do</th>
                            <th :class="cn(th, 'text-right')">Số dòng</th>
                            <th v-if="kindKey !== 'adjustments'" :class="cn(th, 'text-right')">Số lượng</th>
                            <th v-if="kindKey === 'receipts' && canCost" :class="cn(th, 'text-right')">Giá trị</th>
                            <th :class="th">Người tạo</th>
                            <th v-if="kindKey !== 'receipts'" :class="th">Trạng thái</th>
                            <th v-if="transfers" :class="th">Tiến độ</th>
                            <th :class="th"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in docs" :key="d.id" class="cursor-pointer border-t border-slate-100 hover:bg-slate-50/70" @click="$router.push(linkOf(d))">
                            <td class="px-4 py-3.5">
                                <span :class="cn('font-mono text-[13px] font-semibold text-blue-700', d.status === 'cancelled' && 'text-slate-400 line-through')">{{ d.number }}</span>
                                <p class="text-[12px] text-slate-500">{{ formatDateTime(d.created_at) }}</p>
                            </td>
                            <td class="px-4 py-3.5">
                                <div v-if="d.kind === 'transfer'" class="flex items-center gap-2">
                                    <span class="max-w-36 rounded-[8px] bg-slate-100 px-2.5 py-1.5 leading-tight text-slate-700">{{ d.from_warehouse?.name }}</span>
                                    <ArrowRight class="size-3.5 shrink-0 text-slate-400" />
                                    <span class="max-w-36 rounded-[8px] bg-blue-50 px-2.5 py-1.5 leading-tight text-blue-700">{{ d.to_warehouse?.name }}</span>
                                </div>
                                <template v-else>
                                    <p class="font-medium text-slate-800">{{ d.warehouse?.name }}</p>
                                    <p class="max-w-64 truncate text-[12px] text-slate-500">{{ d.note || '—' }}</p>
                                </template>
                            </td>
                            <td v-if="kindKey === 'adjustments'" class="px-4 py-3.5">{{ adjustmentReasons[d.reason ?? ''] ?? d.reason }}</td>
                            <td class="px-4 py-3.5 text-right tabular-nums text-slate-600">{{ formatNumber(d.lines_count ?? 0) }} SKU</td>
                            <td v-if="kindKey !== 'adjustments'" class="px-4 py-3.5 text-right font-semibold tabular-nums text-slate-900">{{ formatNumber((transfers ? d.total_qty : d.total_quantity) ?? 0) }}</td>
                            <td v-if="kindKey === 'receipts' && canCost" class="px-4 py-3.5 text-right tabular-nums">{{ formatMoney(d.total_value) }}</td>
                            <td class="px-4 py-3.5">
                                <p class="text-slate-800">{{ d.created_by ?? '—' }}</p>
                                <p class="text-[12px] text-slate-500">Người tạo</p>
                            </td>
                            <td v-if="kindKey !== 'receipts'" class="px-4 py-3.5"><StatusChip :tone="chipOf(d).tone">{{ chipOf(d).label }}</StatusChip></td>
                            <td v-if="transfers" class="whitespace-nowrap px-4 py-3.5 text-slate-600">{{ progress(d) }}</td>
                            <td class="px-4 py-3.5 text-right">
                                <template v-if="transfers && canAct">
                                    <RouterLink v-if="d.status === 'in_transit'" :to="linkOf(d)" :class="rowPrimary" @click.stop>Xác nhận nhận hàng</RouterLink>
                                    <RouterLink v-else-if="d.status === 'draft'" :to="linkOf(d)" :class="rowButton" @click.stop>Xuất kho</RouterLink>
                                </template>
                                <ArrowRight v-else class="ml-auto size-4 text-slate-300" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <NumberPager :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" :per-page="meta.per_page" :count="docs.length" :unit="kind.title.toLowerCase()" @go="(p) => ((page = p), load())" />
        </section>

        <aside v-if="transfers" class="grid content-start gap-5 md:grid-cols-2 2xl:grid-cols-1">
            <SideCard title="Hàng đang trên đường" :description="`${formatNumber(summary?.in_transit.count ?? 0)} phiếu chưa nhận`">
                <template #actions><span class="grid size-10 place-items-center rounded-[10px] bg-blue-50 text-blue-600"><Truck class="size-5" /></span></template>
                <ul v-if="routes.length" class="space-y-3 p-5">
                    <li v-for="r in routes" :key="r.from + r.to" class="flex items-center gap-2 text-[13px]">
                        <span class="min-w-0 flex-1 truncate rounded-[10px] border border-slate-200 px-3 py-2 text-center font-medium text-slate-700">{{ r.from }}</span>
                        <span class="shrink-0 text-center text-[12px] font-semibold text-blue-600">{{ formatNumber(r.quantity) }} sp<ArrowRight class="mx-auto size-3.5" /></span>
                        <span class="min-w-0 flex-1 truncate rounded-[10px] border border-blue-200 bg-blue-50 px-3 py-2 text-center font-medium text-blue-700">{{ r.to }}</span>
                    </li>
                </ul>
                <p v-else class="px-5 py-4 text-[14px] text-slate-500">Không có hàng đang chuyển giữa các kho.</p>
            </SideCard>
            <SideCard title="Cách chuyển hàng">
                <template #actions><span class="grid size-10 place-items-center rounded-[10px] bg-slate-100 text-slate-500"><Info class="size-5" /></span></template>
                <ol class="list-decimal space-y-2.5 py-4 pl-9 pr-5 text-[14px] text-slate-600">
                    <li>Tạo phiếu, chọn kho đi, kho đến và hàng cần chuyển.</li>
                    <li>Bấm <b class="text-slate-800">Xuất kho</b>: kho đi trừ tồn, hàng chuyển sang trạng thái đang chuyển.</li>
                    <li>Kho đến đếm lại, bấm <b class="text-slate-800">Xác nhận nhận hàng</b>. Nhận thiếu thì ghi số thực nhận, phần thiếu thành phiếu thất lạc chờ duyệt.</li>
                </ol>
            </SideCard>
        </aside>
    </div>
</template>
