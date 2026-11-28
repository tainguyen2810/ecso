<script setup lang="ts">
import { orderCode } from '@/shared/orderCode';
import CameraScanButton from '../../components/CameraScanButton.vue';
import { computed, onMounted, ref, watch } from 'vue';
import { AlertTriangle, BarChart3, PackageCheck, PackageOpen, Plus, RefreshCw, ScanBarcode, Search, Truck, Undo2 } from 'lucide-vue-next';
import { useRouter } from 'vue-router';
import { useToast } from '@/shared/composables/useToast';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import ProductsHead from '../products/ui/ProductsHead.vue';
import HeadButton from '../products/ui/HeadButton.vue';
import LineTabs from '../products/ui/LineTabs.vue';
import NumberPager from '../products/ui/NumberPager.vue';
import StatusChip from '../products/ui/StatusChip.vue';
import { card, chip, chipBase, row, rowButton, rowPrimary, th, thead, type ChipTone } from '../products/ui/ui';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { marketplaceNames, returnStatus, returnTypes } from '../orders/labels';
import type { OrderReturnData, OrderSummary } from '@/shared/types';
import { useTenantStore } from '@/shared/stores/tenant';

const tenant = useTenantStore();
const router = useRouter();
const toast = useToast();
const source = ref('');

const rows = ref<OrderReturnData[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const loadError = ref<string | null>(null);
const status = ref('all');
const type = ref('');
const q = ref('');
const page = ref(1);

interface Summary {
    counts: Record<string, number>;
    on_the_way: number; on_the_way_late: number; to_inspect: number; to_inspect_items: number; refunded: number; refunded_count: number;
    resellable_pct: number | null; damaged: number; reasons_total: number;
    reasons: { label: string; count: number }[];
    products: { name: string; variant: string | null; quantity: number }[];
}
const summary = ref<Summary | null>(null);
const tabs = computed(() => [
    { value: 'all', label: 'Tất cả', count: summary.value?.counts.all ?? null },
    { value: 'pending_receipt', label: 'Đang hoàn về', count: summary.value?.counts.pending_receipt ?? null },
    ...['received', 'requested', 'inspected', 'closed', 'rejected'].map((s) => ({ value: s, label: returnStatus[s]!.label, count: summary.value?.counts[s] ?? 0 })),
]);
const statusTone: Record<string, ChipTone> = { requested: 'amber', approved: 'blue', in_transit: 'blue', received: 'amber', inspected: 'green', closed: 'slate', rejected: 'slate' };
const reasonMax = computed(() => Math.max(1, ...(summary.value?.reasons ?? []).map((r) => r.count)));

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get('/returns', { params: { status: status.value === 'all' ? undefined : status.value, type: type.value || undefined, source: source.value || undefined, q: q.value || undefined, page: page.value } });
        rows.value = data.data;
        meta.value = data.meta;
        summary.value = data.meta.summary ?? null;
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
watch([status, type, source], () => ((page.value = 1), load()));

// Đợt 6 "Quét hàng hoàn": a scanner types the parcel's tracking number and Enter; the return opens, marked received.
const scanCode = ref('');
const scanning = ref(false);
async function scan() {
    const code = scanCode.value.trim();
    if (!code) return;
    scanning.value = true;
    try {
        const { data } = await api.post('/returns/scan', { code });
        scanCode.value = '';
        toast.success(`Đã nhận hàng hoàn ${data.data.number}. Kiểm hàng để nhập lại kho.`);
        router.push(`/returns/${data.data.id}`);
    } catch (e) {
        toast.error((e as ApiError).message);
        scanCode.value = '';
    } finally {
        scanning.value = false;
    }
}

// "Lấy yêu cầu từ sàn": reads Shopee / TikTok return requests now (they also come in by themselves every 30 minutes).
const syncing = ref(false);
async function syncSan() {
    syncing.value = true;
    try {
        const { data } = await api.post('/returns/sync');
        const d = data.data as { read: number; created: number; failed: string[] };
        if (d.failed.length) toast.error(d.failed.join(' · '));
        else toast.success(d.created ? `Có ${d.created} yêu cầu trả hàng mới từ sàn.` : 'Không có yêu cầu trả hàng mới trên sàn.');
        load();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        syncing.value = false;
    }
}

// "Tạo đơn trả hàng": pick a delivered or completed order, then the return form opens on that order.
const pickOpen = ref(false);
const pickQ = ref('');
const pickRows = ref<OrderSummary[]>([]);
const pickLoading = ref(false);
async function loadPick() {
    pickLoading.value = true;
    try {
        const { data } = await api.get('/orders', { params: { tab: 'done', q: pickQ.value || undefined, per_page: 20 } });
        pickRows.value = data.data;
    } finally {
        pickLoading.value = false;
    }
}
let pickTimer: ReturnType<typeof setTimeout> | undefined;
watch(pickQ, () => {
    clearTimeout(pickTimer);
    pickTimer = setTimeout(loadPick, 300);
});
watch(pickOpen, (v) => v && loadPick());
</script>

<template>
    <ProductsHead section="Đơn hàng" title="Khách trả hàng" description="Hàng hoàn từ khách, đơn hủy sau khi gửi, giao thất bại. Kho chỉ được cộng lại sau khi kiểm hàng, theo số lượng còn bán được.">
        <HeadButton :icon="RefreshCw" :disabled="syncing" data-testid="returns-sync" @click="syncSan">Lấy yêu cầu từ sàn</HeadButton>
        <HeadButton v-if="tenant.can('returns.inspect')" primary :icon="Plus" data-testid="new-return" @click="pickOpen = true">Tạo đơn trả hàng</HeadButton>
    </ProductsHead>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="return-kpis">
        <button type="button" class="rounded-[14px] bg-gradient-to-br from-blue-600 to-indigo-600 p-5 text-left text-white shadow-sm" @click="status = 'pending_receipt'">
            <div class="flex items-start justify-between"><span class="text-[14px] text-blue-100">Đang hoàn về</span><span class="grid size-10 place-items-center rounded-[11px] bg-white/15"><Truck class="size-5" /></span></div>
            <p class="mt-1 text-[30px] font-bold leading-none tabular-nums">{{ summary?.on_the_way ?? '…' }} <span class="text-[15px] font-medium text-blue-100">đơn</span></p>
            <span class="mt-3 inline-block rounded-md bg-white/15 px-2 py-0.5 text-[12px]">{{ summary?.on_the_way_late ? `${summary.on_the_way_late} quá 7 ngày` : 'Không có đơn trễ' }}</span>
            <p class="mt-2 text-[13px] text-blue-100">Hàng đang trả về shop</p>
        </button>
        <button type="button" :class="cn(card, 'p-5 text-left', status === 'received' && 'ring-2 ring-amber-200')" @click="status = 'received'">
            <div class="flex items-start justify-between"><span class="text-[14px] text-slate-500">Đã nhận, chờ kiểm</span><span class="grid size-10 place-items-center rounded-[11px] bg-amber-50 text-amber-600"><PackageOpen class="size-5" /></span></div>
            <p class="mt-1 text-[30px] font-bold leading-none text-amber-600 tabular-nums">{{ summary?.to_inspect ?? '…' }} <span class="text-[15px] font-medium text-slate-400">đơn</span></p>
            <span :class="cn(chipBase, chip.amber, 'mt-3')">{{ formatNumber(summary?.to_inspect_items ?? 0) }} sản phẩm</span>
            <p class="mt-2 text-[13px] text-slate-500">Kiểm xong mới cộng lại kho</p>
        </button>
        <div :class="cn(card, 'p-5')">
            <div class="flex items-start justify-between"><span class="text-[14px] text-slate-500">Đã hoàn tiền tháng này</span><span class="grid size-10 place-items-center rounded-[11px] bg-red-50 text-red-600"><Undo2 class="size-5" /></span></div>
            <p class="mt-1 text-[30px] font-bold leading-none text-red-600 tabular-nums">{{ summary ? formatMoney(summary.refunded) : '…' }}</p>
            <span :class="cn(chipBase, chip.red, 'mt-3')">{{ summary?.refunded_count ?? 0 }} đơn</span>
            <p class="mt-2 text-[13px] text-slate-500">Gồm hoàn tiền sàn và kênh riêng</p>
        </div>
        <div :class="cn(card, 'p-5')">
            <div class="flex items-start justify-between"><span class="text-[14px] text-slate-500">Còn bán được</span><span class="grid size-10 place-items-center rounded-[11px] bg-emerald-50 text-emerald-600"><PackageCheck class="size-5" /></span></div>
            <p class="mt-1 text-[30px] font-bold leading-none text-emerald-600 tabular-nums">{{ summary?.resellable_pct ?? '—' }}<span class="text-[15px] font-medium text-slate-400"> %</span></p>
            <span :class="cn(chipBase, chip.green, 'mt-3')">sản phẩm trả về 90 ngày</span>
            <p class="mt-2 text-[13px] text-slate-500">{{ summary?.damaged ? `${formatNumber(summary.damaged)} sản phẩm hỏng vào kho hàng lỗi` : 'Chưa có sản phẩm hỏng' }}</p>
        </div>
    </div>

    <div v-if="tenant.can('returns.inspect')" :class="cn(card, 'mb-6 flex flex-wrap items-center gap-4 px-5 py-4')" data-testid="return-scan-card">
        <span class="grid size-11 shrink-0 place-items-center rounded-[12px] bg-violet-50 text-violet-600"><PackageOpen class="size-5" /></span>
        <div class="min-w-48 max-w-sm">
            <p class="font-semibold text-slate-900">Nhận hàng hoàn</p>
            <p class="text-[13px] text-slate-500">Quét mã vận đơn trên gói hàng hoàn, phiếu trả mở ra để kiểm.</p>
        </div>
        <form class="relative min-w-64 flex-1" @submit.prevent="scan">
            <ScanBarcode class="pointer-events-none absolute left-3.5 top-3.5 size-5 text-violet-500" />
            <input v-model="scanCode" class="h-12 w-full rounded-[12px] border-2 border-violet-200 bg-white pl-11 pr-12 text-[15px] outline-none focus:border-violet-400 disabled:opacity-60" placeholder="Quét mã vận đơn hoặc mã đơn hàng hoàn…" :disabled="scanning" data-testid="return-scan" aria-label="Mã vận đơn hàng hoàn" />
            <CameraScanButton label="Quét mã hàng hoàn bằng camera" class="absolute right-2 top-1/2 grid size-8 -translate-y-1/2 place-items-center rounded-md text-slate-500 hover:bg-slate-100" @scan="(c) => ((scanCode = c), scan())" />
        </form>
    </div>

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
        <div :class="cn(card, 'min-w-0')">
            <LineTabs v-model="status" :tabs="tabs" />
            <div class="flex flex-wrap gap-3 px-5 py-4">
                <div class="relative min-w-60 flex-1">
                    <Search class="pointer-events-none absolute left-3 top-3 size-4 text-slate-400" />
                    <input v-model="q" class="h-10 w-full rounded-[10px] border border-slate-200 pl-9 pr-3 text-sm outline-none focus:border-blue-400" placeholder="Tìm mã phiếu trả, mã đơn, mã vận đơn…" />
                </div>
                <select v-model="source" class="h-10 w-40 rounded-[10px] border border-slate-200 bg-white px-3 text-sm" aria-label="Nguồn" data-testid="return-source">
                    <option value="">Mọi nguồn</option><option value="marketplace">Yêu cầu từ sàn</option>
                </select>
                <select v-model="type" class="h-10 w-48 rounded-[10px] border border-slate-200 bg-white px-3 text-sm" aria-label="Loại">
                    <option value="">Mọi loại</option>
                    <option v-for="(label, value) in returnTypes" :key="value" :value="value">{{ label }}</option>
                </select>
            </div>
            <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 5" :key="i" class="h-10" /></div>
            <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
            <EmptyState v-else-if="!rows.length" :icon="Undo2" title="Chưa có phiếu trả hàng" description="Phiếu được tạo tự động khi đơn bị hủy sau khi gửi hoặc giao thất bại, hoặc tạo thủ công từ trang chi tiết đơn." />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="return-table">
                    <thead :class="thead">
                        <tr>
                            <th :class="th">Phiếu trả</th>
                            <th :class="th">Đơn gốc, kênh, khách</th>
                            <th :class="th">Trạng thái</th>
                            <th :class="cn(th, 'text-right')">Hoàn tiền</th>
                            <th :class="th">Ngày nhận</th>
                            <th :class="th">Lý do</th>
                            <th :class="th" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.id" :class="cn(row, 'cursor-pointer')" @click="$router.push(`/returns/${r.id}`)">
                            <td class="px-4 py-3">
                                <span class="font-mono text-[13px] font-semibold text-blue-700">{{ r.number }}</span>
                                <div class="text-[12px] text-slate-500">{{ returnTypes[r.type] }} · {{ r.lines_count }} sản phẩm<span v-if="r.external_return_id" class="ml-1 rounded bg-orange-50 px-1 font-medium text-orange-700">từ sàn</span></div>
                            </td>
                            <td class="px-4 py-3" @click.stop>
                                <RouterLink v-if="r.order" :to="`/orders/${r.order.id}`" class="flex items-center gap-2.5">
                                    <MarketplaceLogo :marketplace="r.order.marketplace" :name="marketplaceNames[r.order.marketplace]" class="!size-8" />
                                    <span class="min-w-0">
                                        <span class="block font-mono text-[13px] font-semibold text-slate-800 hover:text-primary">{{ orderCode(r.order) }}</span>
                                        <span class="block max-w-56 truncate text-[12px] text-slate-500">{{ [marketplaceNames[r.order.marketplace] ?? 'Kênh riêng', r.order.customer ?? 'Khách lẻ'].join(' · ') }}</span>
                                    </span>
                                </RouterLink>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <StatusChip :tone="statusTone[r.status] ?? 'slate'">{{ returnStatus[r.status]?.label }}</StatusChip>
                                <div v-if="r.respond_by && r.status === 'requested'" class="mt-1 text-[11px] font-medium text-amber-700">Trả lời trên sàn trước {{ formatDateTime(r.respond_by) }}</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <span class="font-semibold tabular-nums">{{ Number(r.refund_amount ?? 0) > 0 ? formatMoney(r.refund_amount ?? 0) : '—' }}</span>
                                <span v-if="r.exchange_order" class="block text-[12px] text-slate-500" @click.stop><RouterLink :to="`/orders/${r.exchange_order.id}`" class="hover:text-primary">Đổi sang {{ orderCode(r.exchange_order) }}</RouterLink></span>
                                <span v-else class="block text-[12px] text-slate-500">{{ Number(r.refund_amount ?? 0) > 0 ? 'Có hoàn tiền' : 'Không' }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-600">{{ r.received_at ? formatDateTime(r.received_at) : '—' }}</td>
                            <td class="max-w-56 truncate px-4 py-3 text-slate-600" :title="r.reason ?? ''">{{ r.reason ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <RouterLink :to="`/returns/${r.id}`" :class="r.status === 'received' && tenant.can('returns.inspect') ? rowPrimary : rowButton" @click.stop>{{ r.status === 'received' && tenant.can('returns.inspect') ? 'Kiểm hàng' : 'Chi tiết' }}</RouterLink>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <NumberPager v-if="rows.length" :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" :per-page="30" :count="rows.length" unit="phiếu trả" @go="(p) => ((page = p), load())" />
        </div>

        <aside class="space-y-6">
            <div :class="card" data-testid="return-reasons">
                <div class="flex items-start justify-between border-b border-slate-100 px-5 py-4">
                    <div><h3 class="font-semibold text-slate-900">Lý do trả hàng</h3><p class="mt-0.5 text-[13px] text-slate-500">30 ngày qua · {{ summary?.reasons_total ?? 0 }} phiếu</p></div>
                    <span class="grid size-10 place-items-center rounded-[11px] bg-violet-50 text-violet-600"><BarChart3 class="size-5" /></span>
                </div>
                <p v-if="!summary?.reasons.length" class="px-5 py-8 text-center text-sm text-slate-400">Chưa có phiếu trả nào trong 30 ngày.</p>
                <div v-else class="space-y-4 px-5 py-4">
                    <div v-for="r in summary.reasons" :key="r.label">
                        <div class="flex items-baseline justify-between gap-3 text-[13px]">
                            <span class="truncate text-slate-800">{{ r.label }}</span>
                            <span class="whitespace-nowrap text-slate-500"><b class="text-slate-900">{{ r.count }}</b> · {{ Math.round((r.count / Math.max(1, summary.reasons_total)) * 100) }}%</span>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-violet-500" :style="{ width: `${Math.max(4, (r.count / reasonMax) * 100)}%` }" /></div>
                    </div>
                </div>
            </div>
            <div :class="card" data-testid="return-products">
                <div class="flex items-start justify-between border-b border-slate-100 px-5 py-4">
                    <div><h3 class="font-semibold text-slate-900">Sản phẩm bị trả nhiều</h3><p class="mt-0.5 text-[13px] text-slate-500">Xem lại mô tả, bảng size</p></div>
                    <span class="grid size-10 place-items-center rounded-[11px] bg-amber-50 text-amber-600"><AlertTriangle class="size-5" /></span>
                </div>
                <p v-if="!summary?.products.length" class="px-5 py-8 text-center text-sm text-slate-400">Chưa có sản phẩm nào bị trả.</p>
                <div v-for="p in summary?.products ?? []" :key="`${p.name}-${p.variant}`" class="flex items-center gap-3 border-t border-slate-100 px-5 py-3 first-of-type:border-t-0">
                    <span class="min-w-0 flex-1 truncate text-[14px] text-slate-800">{{ p.name }}<template v-if="p.variant"> · <span class="text-slate-500">{{ p.variant }}</span></template></span>
                    <span class="font-semibold tabular-nums">{{ p.quantity }}</span>
                </div>
            </div>
        </aside>
    </div>

    <Dialog v-model:open="pickOpen" title="Chọn đơn hàng để trả" description="Đơn đã giao hoặc hoàn tất." size="xl">
        <div class="relative mb-3">
            <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
            <Input v-model="pickQ" class="pl-8" placeholder="Tìm kiếm theo mã đơn hàng, tên, SĐT khách hàng" data-testid="return-order-search" />
        </div>
        <div class="overflow-x-auto rounded-md border">
            <table class="w-full text-sm" data-testid="return-order-pick">
                <thead class="whitespace-nowrap border-b bg-slate-50 text-left text-xs font-semibold text-foreground/80">
                    <tr><th class="px-3 py-2.5">Mã đơn hàng</th><th class="px-3 py-2.5">Ngày tạo</th><th class="px-3 py-2.5">Nhân viên</th><th class="px-3 py-2.5">Khách hàng</th><th class="px-3 py-2.5 text-right">Tổng tiền</th><th class="px-3 py-2.5 text-center">Thao tác</th></tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="o in pickRows" :key="o.id" class="hover:bg-blue-50/40">
                        <td class="px-3 py-2.5"><span class="inline-flex items-center gap-1.5"><MarketplaceLogo :marketplace="o.marketplace" :name="marketplaceNames[o.marketplace]" size="sm" />{{ orderCode(o) }}</span></td>
                        <td class="whitespace-nowrap px-3 py-2.5 tabular-nums">{{ formatDateTime(o.placed_at) }}</td>
                        <td class="px-3 py-2.5 text-muted-foreground">{{ o.assignee?.name ?? '—' }}</td>
                        <td class="max-w-48 truncate px-3 py-2.5">{{ o.customer?.name ?? o.buyer_name ?? 'Khách lẻ' }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums">{{ formatMoney(o.total) }}</td>
                        <td class="px-3 py-2.5 text-center"><RouterLink :to="{ path: `/orders/${o.id}`, query: { return: '1' } }" class="font-medium text-primary hover:underline">Đổi trả</RouterLink></td>
                    </tr>
                    <tr v-if="!pickRows.length"><td colspan="6" class="px-3 py-8 text-center text-muted-foreground">{{ pickLoading ? 'Đang tải…' : 'Không có đơn đã giao hoặc hoàn tất khớp tìm kiếm.' }}</td></tr>
                </tbody>
            </table>
        </div>
    </Dialog>
</template>
