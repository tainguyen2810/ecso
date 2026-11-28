<script setup lang="ts">
import { orderCode } from '@/shared/orderCode';
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowRight, Banknote, BookOpen, ClockAlert, Download, FileUp, Landmark, Receipt, Scale, Smartphone, Truck, Upload, Wallet } from 'lucide-vue-next';
import ReportChip from '../reports/ReportChip.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import { downloadCsv, toCsv } from '../reports/range';
import CarrierBox from '../shipping/CarrierBox.vue';
import ShipHead from '../shipping/ShipHead.vue';
import { btn, card, pill, th } from '../shipping/ui';
import CarrierLogo from '@/shared/ui/CarrierLogo.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import CodImportDialog from './CodImportDialog.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDate, formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import { codStatuses, type CodReconciliation } from './cod';

/** Vận chuyển › Đối soát COD và phí: statements imported from carriers' files, each booking COD collections and the carrier's fees. */
const tenant = useTenantStore();
const rows = ref<CodReconciliation[]>([]);
const loading = ref(true);
const loadError = ref<string | null>(null);
const importing = ref(false);
const detail = ref<CodReconciliation | null>(null);
const detailOpen = computed({ get: () => detail.value !== null, set: (v) => !v && (detail.value = null) });

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        rows.value = (await api.get('/cod-reconciliations')).data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(() => {
    load();
    loadHeld();
    loadFunds();
    loadGap();
});

/** "Chờ hãng trả": delivered COD parcels whose money the carrier still holds (needs orders.view). */
interface HeldParcel {
    id: string;
    carrier: string | null;
    carrier_name: string | null;
    tracking_number: string | null;
    cod_amount: string;
    fee_actual: string | null;
    fee_estimated: string | null;
    delivered_at: string | null;
    order?: { id: string; external_order_id: string; code?: string | null; channel: string | null; marketplace: string };
}
const route = useRoute();
const tab = ref<'waiting' | 'sessions' | 'fee'>(route.query.tab === 'fee' ? 'fee' : 'waiting');
const held = ref<HeldParcel[]>([]);
const heldTotal = ref(0);
const heldByCarrier = ref<{ carrier: string; carrier_name: string; amount: string; count: number; slow_amount: string; slow_count: number }[]>([]);
const slowDays = ref(7);
const carrier = ref('');
const canSeeParcels = computed(() => tenant.can('orders.view'));
async function loadHeld() {
    if (!canSeeParcels.value) return void (tab.value = 'sessions');
    const keep = tab.value === 'fee';
    try {
        const [list, sum] = await Promise.all([api.get('/shipments', { params: { cod: 'collected', carrier: carrier.value || undefined, per_page: 100 } }), api.get('/shipments/summary')]);
        held.value = list.data.data;
        heldTotal.value = list.data.meta?.total ?? held.value.length;
        heldByCarrier.value = sum.data.data.cod_held ?? [];
        slowDays.value = sum.data.data.slow_days ?? 7;
        if (!heldByCarrier.value.length && !carrier.value && !keep) tab.value = 'sessions';
    } catch {
        if (!keep) tab.value = 'sessions';
    }
}

/** "Phí ship lệch": parcels the carrier charged more for than it quoted when the order was pushed. */
const gap = ref<HeldParcel[]>([]);
const gapTotal = ref(0);
async function loadGap() {
    if (!canSeeParcels.value) return;
    try {
        const { data } = await api.get('/shipments', { params: { fee: 'gap', per_page: 100 } });
        gap.value = data.data;
        gapTotal.value = data.meta?.total ?? gap.value.length;
    } catch {
        gap.value = [];
    }
}
const gapOf = (p: HeldParcel) => Number(p.fee_actual ?? 0) - Number(p.fee_estimated ?? 0);
const gapSum = computed(() => gap.value.reduce((s, p) => s + gapOf(p), 0));
function pickCarrier(c: string) {
    carrier.value = carrier.value === c ? '' : c;
    loadHeld();
}
const heldAmount = computed(() => heldByCarrier.value.reduce((s, c) => s + Number(c.amount), 0));
const heldCount = computed(() => heldByCarrier.value.reduce((s, c) => s + c.count, 0));
const feeOf = (p: HeldParcel) => Number(p.fee_actual ?? p.fee_estimated ?? 0);
const slow = computed(() => {
    const amount = heldByCarrier.value.reduce((s, c) => s + Number(c.slow_amount ?? 0), 0);
    const count = heldByCarrier.value.reduce((s, c) => s + (c.slow_count ?? 0), 0);
    const top = [...heldByCarrier.value].sort((a, b) => Number(b.slow_amount) - Number(a.slow_amount))[0];
    return { amount, count, top: top && Number(top.slow_amount) > 0 ? top : null };
});
const heldMax = computed(() => Math.max(1, ...heldByCarrier.value.map((c) => Number(c.amount))));
const shown = computed(() => ({
    cod: held.value.reduce((s, p) => s + Number(p.cod_amount), 0),
    fee: held.value.reduce((s, p) => s + feeOf(p), 0),
}));
const money = (v: number | string) => formatNumber(Math.round(Number(v)));
const ageTone = (d: number | null) => (d === null ? 'bg-slate-100 text-slate-600' : d > slowDays.value ? 'bg-red-50 text-red-600' : d >= 4 ? 'bg-amber-50 text-amber-700' : d === 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600');
function exportHeld() {
    const csv = toCsv(['Đơn', 'Hãng', 'Mã vận đơn', 'Giao xong', 'Thu hộ', 'Phí ship', 'Sẽ nhận', 'Số ngày hãng giữ'],
        held.value.map((p) => [orderCode(p.order), p.carrier_name, p.tracking_number, p.delivered_at ? formatDate(p.delivered_at) : '', Math.round(Number(p.cod_amount)), Math.round(feeOf(p)), Math.round(Number(p.cod_amount) - feeOf(p)), daysSince(p.delivered_at)]));
    downloadCsv('cod-cho-hang-tra.csv', csv);
}
const daysSince = (iso: string | null) => (iso ? Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000)) : null);

/** This month's statements: money that reached the account, and the carriers' fees. */
const month = computed(() => {
    const start = new Date();
    start.setDate(1);
    start.setHours(0, 0, 0, 0);
    const mine = rows.value.filter((r) => !r.voided_at && new Date(r.remitted_at) >= start);
    const cod = mine.reduce((s, r) => s + Number(r.total_cod), 0);
    const fee = mine.reduce((s, r) => s + Number(r.total_fee), 0);
    const prevStart = new Date(start.getFullYear(), start.getMonth() - 1, 1);
    const prevEnd = new Date(prevStart.getFullYear(), prevStart.getMonth(), new Date().getDate(), 23, 59, 59);
    const before = rows.value.filter((r) => !r.voided_at && new Date(r.remitted_at) >= prevStart && new Date(r.remitted_at) <= prevEnd).reduce((s, r) => s + Number(r.transferred), 0);
    const transferred = mine.reduce((s, r) => s + Number(r.transferred), 0);
    const change = before > 0 ? Math.round(((transferred - before) / before) * 1000) / 10 : null;
    return { transferred, times: mine.length, fee, feeRate: cod > 0 ? (fee / cod) * 100 : null, change, account: mine[0]?.account?.name ?? null, orders: mine.reduce((s, r) => s + r.matched_count, 0) };
});

/** Quỹ hiện có: the funds and what is in them (when the member may see balances). */
interface Fund { id: string; kind: string; name: string; account_number?: string | null; balance: number | null; is_active: boolean }
const funds = ref<Fund[]>([]);
async function loadFunds() {
    try {
        funds.value = ((await api.get('/cash-accounts')).data.data as Fund[]).filter((f) => f.is_active);
    } catch {
        funds.value = [];
    }
}
const fundIcon = (kind: string) => (kind === 'cash' ? Banknote : kind === 'bank' ? Landmark : kind === 'ewallet' ? Smartphone : Wallet);
const fundsTotal = computed(() => (funds.value.some((f) => f.balance === null) ? null : funds.value.reduce((s, f) => s + (f.balance ?? 0), 0)));

async function open(r: CodReconciliation) {
    detail.value = (await api.get(`/cod-reconciliations/${r.id}`)).data.data;
}
// Void the whole statement: its order payments and fee voucher are voided with it.
const toast = useToast();
const voiding = ref(false);
const voidReason = ref('');
const voidError = ref<ApiError | null>(null);
const busy = ref(false);
function startVoid() {
    voidReason.value = '';
    voidError.value = null;
    voiding.value = true;
}
async function confirmVoid() {
    busy.value = true;
    voidError.value = null;
    try {
        await api.post(`/cod-reconciliations/${detail.value!.id}/void`, { reason: voidReason.value });
        toast.success(`Đã hủy phiên đối soát ${detail.value!.number}.`);
        voiding.value = false;
        detail.value = (await api.get(`/cod-reconciliations/${detail.value!.id}`)).data.data;
        load();
    } catch (e) {
        voidError.value = e as ApiError;
    } finally {
        busy.value = false;
    }
}
function saved(r: CodReconciliation) {
    load();
    loadHeld();
    loadFunds();
    tab.value = 'sessions';
    detail.value = r;
}
</script>

<template>
    <ShipHead title="Đối soát COD và phí" description="Tiền thu hộ hãng vận chuyển đang giữ, tiền đã về tài khoản và phí ship hãng tính lệch.">
        <RouterLink v-if="tenant.can('finance.view')" to="/reports/cashbook" :class="btn.outline"><BookOpen class="size-4" /> Xem sổ quỹ</RouterLink>
        <button v-if="tenant.can('finance.vouchers')" type="button" :class="btn.primary" data-testid="cod-import" @click="importing = true"><FileUp class="size-4" /> Nhập file đối soát</button>
    </ShipHead>

    <div v-if="canSeeParcels && slow.count" class="mb-5 flex flex-wrap items-center gap-4 rounded-[14px] border border-amber-200 bg-amber-50 px-5 py-4" data-testid="cod-slow">
        <span class="grid size-10 shrink-0 place-items-center rounded-[11px] bg-amber-100 text-amber-600"><ClockAlert class="size-5" /></span>
        <p class="min-w-0 flex-1 text-[15px] text-amber-900">
            <b>{{ money(slow.amount) }} đ</b> COD hãng giữ quá {{ slowDays }} ngày ({{ formatNumber(slow.count) }} đơn<template v-if="slow.top">, nhiều nhất {{ slow.top.carrier_name }} {{ money(slow.top.slow_amount) }} đ</template>). Nên tải file đối soát mới nhất của hãng để khớp tiền.
        </p>
        <RouterLink to="/shipments?cod=collected" class="inline-flex h-10 items-center gap-2 rounded-[10px] bg-amber-500 px-4 text-[14px] font-medium text-white hover:bg-amber-600">Xem các đơn <ArrowRight class="size-4" /></RouterLink>
    </div>

    <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="cod-kpis">
        <ReportKpi label="Hãng đang giữ" tip="Tiền thu hộ của đơn đã giao mà hãng chưa chuyển về tài khoản của shop." :icon="Wallet" primary :value="canSeeParcels ? Math.round(heldAmount) : null">
            <template #chips>
                <ReportChip tone="onblue">{{ formatNumber(heldCount) }} đơn đã giao</ReportChip>
                <ReportChip v-if="slow.count" tone="onblue">{{ formatNumber(slow.count) }} quá {{ slowDays }} ngày</ReportChip>
            </template>
            {{ canSeeParcels ? 'Tiền thu hộ chưa chuyển về tài khoản' : 'Cần quyền xem đơn hàng' }}
        </ReportKpi>
        <ReportKpi label="Đã về tài khoản tháng này" tip="Tổng tiền hãng đã chuyển trong các phiên đối soát tháng này (thu hộ trừ phí)." :icon="Landmark" tone="green" :value="Math.round(month.transferred)">
            <template #chips>
                <ReportChip tone="up">{{ formatNumber(month.times) }} lần chuyển</ReportChip>
                <ReportChip v-if="month.change !== null" :tone="month.change >= 0 ? 'up' : 'down'">{{ month.change >= 0 ? '+' : '−' }}{{ String(Math.abs(month.change)).replace('.', ',') }}% so với cùng kỳ tháng trước</ReportChip>
            </template>
            {{ month.account ? `Ghi vào quỹ ${month.account}` : 'Chưa có phiên đối soát tháng này' }}
        </ReportKpi>
        <ReportKpi label="Phí hãng trừ tháng này" tip="Phí vận chuyển hãng trừ vào tiền thu hộ trong các phiên đối soát tháng này." :icon="Receipt" tone="slate" :value="Math.round(month.fee)">
            <template #chips>
                <ReportChip tone="plain">{{ formatNumber(month.orders) }} đơn</ReportChip>
                <ReportChip v-if="month.feeRate !== null" tone="plain">{{ month.feeRate.toFixed(1).replace('.', ',') }}% tiền thu hộ</ReportChip>
            </template>
            {{ month.orders ? `Trung bình ${money(month.fee / month.orders)} đ một đơn` : 'Chưa có đối soát' }}
        </ReportKpi>
        <ReportKpi label="Phí ship lệch" tip="Đơn hãng tính phí cao hơn lúc báo giá khi đẩy đơn. Gửi danh sách cho hãng để khiếu nại." :icon="Scale" tone="red" :value="canSeeParcels ? Math.round(gapSum) : null">
            <template #chips><ReportChip :tone="gapTotal ? 'red' : 'plain'">{{ formatNumber(gapTotal) }} đơn</ReportChip></template>
            Hãng tính cao hơn lúc báo giá
        </ReportKpi>
    </div>

    <div class="grid grid-cols-1 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_400px] 2xl:grid-cols-[minmax(0,1fr)_420px]">
        <ErrorState v-if="loadError" :message="loadError" @retry="load" />
        <section v-else :class="cn(card, 'min-w-0 overflow-hidden')">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3.5">
                <div class="flex rounded-[12px] bg-slate-100 p-1 text-[14px]" role="tablist" aria-label="Đối soát">
                    <button v-if="canSeeParcels" type="button" role="tab" :aria-selected="tab === 'waiting'" :class="pill(tab === 'waiting')" data-testid="cod-tab-waiting" @click="tab = 'waiting'">Chờ hãng trả ({{ formatNumber(heldCount) }})</button>
                    <button type="button" role="tab" :aria-selected="tab === 'sessions'" :class="pill(tab === 'sessions')" data-testid="cod-tab-sessions" @click="tab = 'sessions'">Phiên đối soát ({{ formatNumber(rows.length) }})</button>
                    <button v-if="canSeeParcels" type="button" role="tab" :aria-selected="tab === 'fee'" :class="pill(tab === 'fee')" data-testid="cod-tab-fee" @click="tab = 'fee'">Phí ship lệch ({{ formatNumber(gapTotal) }})</button>
                </div>
                <button v-if="tab === 'waiting' && held.length" type="button" :class="btn.small" @click="exportHeld"><Download class="size-3.5" /> Xuất CSV</button>
            </div>

            <template v-if="tab === 'waiting'">
                <div v-if="heldByCarrier.length > 1" class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-3">
                    <button type="button" :class="cn('flex h-10 items-center gap-2 rounded-[10px] border px-3 text-[14px]', !carrier ? 'border-blue-600 bg-blue-50 font-medium text-blue-700' : 'border-slate-200 bg-white hover:border-blue-300')" @click="carrier && pickCarrier('')">
                        Tất cả <span class="rounded-md bg-slate-100 px-1.5 text-[12px] font-semibold text-slate-600">{{ formatNumber(heldCount) }}</span>
                    </button>
                    <button
                        v-for="c in heldByCarrier"
                        :key="c.carrier"
                        type="button"
                        :class="cn('flex h-10 items-center gap-2 rounded-[10px] border px-2.5', carrier === c.carrier ? 'border-blue-600 bg-blue-50' : 'border-slate-200 bg-white hover:border-blue-300')"
                        :title="c.carrier_name"
                        @click="pickCarrier(c.carrier)"
                    >
                        <CarrierLogo :carrier="c.carrier" :name="c.carrier_name" :width="64" :height="20" />
                        <span class="rounded-md bg-slate-100 px-1.5 text-[12px] font-semibold text-slate-600">{{ formatNumber(c.count) }}</span>
                    </button>
                </div>
                <div v-if="!held.length" class="flex flex-col items-center gap-2 px-6 py-12 text-center text-[14px] text-slate-500">
                    <Truck class="size-8 text-slate-300" />
                    <p>Hãng không còn giữ tiền thu hộ nào của shop.</p>
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[860px]" data-testid="cod-held">
                        <thead class="bg-slate-50/70 text-left">
                            <tr>
                                <th :class="cn(th, 'pl-5')">Đơn · vận đơn</th>
                                <th :class="th">Hãng</th>
                                <th :class="th">Giao xong</th>
                                <th :class="cn(th, 'text-right')">Thu hộ</th>
                                <th :class="cn(th, 'text-right')">Phí ship</th>
                                <th :class="cn(th, 'text-right')">Sẽ nhận</th>
                                <th :class="cn(th, 'pr-5 text-right')">Hãng giữ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="p in held" :key="p.id" class="text-[14px] hover:bg-slate-50">
                                <td class="px-5 py-3">
                                    <RouterLink v-if="p.order" :to="`/orders/${p.order.id}`" class="inline-flex items-center gap-1.5 font-medium text-blue-600 hover:underline">
                                        <MarketplaceLogo :marketplace="p.order.marketplace" size="sm" />{{ orderCode(p.order) }}
                                    </RouterLink>
                                    <p v-if="p.tracking_number" class="font-mono text-[12px] text-slate-400">{{ p.tracking_number }}</p>
                                </td>
                                <td class="px-4 py-3"><CarrierBox :carrier="p.carrier" :name="p.carrier_name" size="sm" /></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ p.delivered_at ? formatDate(p.delivered_at) : '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(p.cod_amount) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-slate-500">
                                    <template v-if="feeOf(p)">−{{ formatMoney(feeOf(p)) }}</template>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums">{{ formatMoney(Number(p.cod_amount) - feeOf(p)) }}</td>
                                <td class="px-5 py-3 text-right">
                                    <span :class="cn('inline-flex whitespace-nowrap rounded-md px-2 py-0.5 text-[12px] font-medium', ageTone(daysSince(p.delivered_at)))">{{ daysSince(p.delivered_at) === 0 ? 'Hôm nay' : daysSince(p.delivered_at) === null ? '—' : `${daysSince(p.delivered_at)} ngày` }}</span>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-slate-200 bg-slate-50/70 text-[14px]">
                                <td class="px-5 py-3 font-semibold" colspan="3">Tổng {{ formatNumber(held.length) }} đơn<template v-if="heldTotal > held.length"> (trên {{ formatNumber(heldTotal) }})</template></td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ formatMoney(shown.cod) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-500">−{{ formatMoney(shown.fee) }}</td>
                                <td class="px-4 py-3 text-right font-bold tabular-nums text-blue-600">{{ formatMoney(shown.cod - shown.fee) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                    <p v-if="heldTotal > held.length" class="border-t border-slate-100 px-5 py-3 text-[13px] text-slate-500">Đang hiện {{ held.length }} trên {{ formatNumber(heldTotal) }} đơn. Xem đủ ở <RouterLink to="/shipments?cod=collected" class="text-blue-600 hover:underline">Quản lý vận đơn</RouterLink>.</p>
                </div>
            </template>

            <template v-else-if="tab === 'fee'">
                <div v-if="!gap.length" class="flex flex-col items-center gap-2 px-6 py-12 text-center text-[14px] text-slate-500">
                    <Truck class="size-8 text-slate-300" />
                    <p>Không có đơn nào hãng tính phí cao hơn lúc báo giá.</p>
                    <p class="max-w-md text-[13px]">Phí thật của hãng lấy từ hành trình hoặc file đối soát. Đơn đẩy qua hãng mới có phí báo giá để so.</p>
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[640px]" data-testid="cod-fee-gap">
                        <thead class="bg-slate-50/70 text-left">
                            <tr>
                                <th :class="cn(th, 'pl-5')">Đơn · vận đơn</th>
                                <th :class="th">Hãng</th>
                                <th :class="cn(th, 'text-right')">Phí lúc báo giá</th>
                                <th :class="cn(th, 'text-right')">Hãng tính</th>
                                <th :class="cn(th, 'pr-5 text-right')">Lệch</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="p in gap" :key="p.id" class="text-[14px] hover:bg-slate-50">
                                <td class="px-5 py-3">
                                    <RouterLink v-if="p.order" :to="`/orders/${p.order.id}`" class="font-medium text-blue-600 hover:underline">{{ orderCode(p.order) }}</RouterLink>
                                    <p v-if="p.tracking_number" class="font-mono text-[12px] text-slate-400">{{ p.tracking_number }}</p>
                                </td>
                                <td class="px-4 py-3"><CarrierBox :carrier="p.carrier" :name="p.carrier_name" size="sm" /></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-slate-500">{{ formatMoney(p.fee_estimated) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(p.fee_actual) }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-right font-semibold tabular-nums text-red-600">+{{ formatMoney(gapOf(p)) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-slate-200 bg-slate-50/70 text-[14px] font-semibold"><td class="px-5 py-3" colspan="4">Tổng lệch<template v-if="gapTotal > gap.length"> ({{ gap.length }} trên {{ formatNumber(gapTotal) }} đơn)</template></td><td class="px-5 py-3 text-right tabular-nums text-red-600">+{{ formatMoney(gapSum) }}</td></tr>
                        </tfoot>
                    </table>
                    <p class="border-t border-slate-100 px-5 py-3 text-[13px] text-slate-500">Gửi danh sách này cho hãng để khiếu nại nếu khối lượng hoặc kích thước hãng đo khác với gói hàng.</p>
                </div>
            </template>

            <template v-else>
                <div v-if="loading && !rows.length" class="space-y-3 p-5"><Skeleton v-for="i in 4" :key="i" class="h-10" /></div>
                <div v-else-if="!rows.length" class="flex flex-col items-center gap-2 px-6 py-12 text-center text-[14px] text-slate-500">
                    <Truck class="size-8 text-slate-300" />
                    <p>Chưa có phiên đối soát nào.</p>
                    <p class="max-w-md text-[13px]">Tải file đối soát của GHN, Viettel Post, SPX hoặc J&amp;T. Hệ thống tự tìm cột mã vận đơn, tiền thu hộ và phí, khớp với đơn COD theo mã vận đơn, cho xem trước rồi mới ghi.</p>
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[960px]" data-testid="cod-table">
                        <thead class="bg-slate-50/70 text-left">
                            <tr>
                                <th :class="cn(th, 'pl-5')">Phiên</th>
                                <th :class="th">Hãng</th>
                                <th :class="th">Ngày tiền về</th>
                                <th :class="th">Vào quỹ</th>
                                <th :class="cn(th, 'text-right')">Thu hộ</th>
                                <th :class="cn(th, 'text-right')">Phí hãng</th>
                                <th :class="cn(th, 'text-right')">Hãng chuyển</th>
                                <th :class="cn(th, 'pr-5 text-right')">Khớp</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="r in rows" :key="r.id" :class="cn('cursor-pointer text-[14px] hover:bg-slate-50', r.voided_at && 'opacity-60')" @click="open(r)">
                                <td class="px-5 py-3"><p class="font-mono text-[13px] font-medium">{{ r.number }} <Badge v-if="r.voided_at" tone="danger">Đã hủy</Badge></p><p class="max-w-48 truncate text-[12px] text-slate-400">{{ r.file_name }}</p></td>
                                <td class="px-4 py-3"><CarrierBox :carrier="r.carrier" :name="r.carrier_name" size="sm" /></td>
                                <td class="whitespace-nowrap px-4 py-3">{{ formatDateTime(r.remitted_at) }}<p class="text-[12px] text-slate-400">{{ r.created_by }}</p></td>
                                <td class="px-4 py-3">{{ r.account?.name ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(r.total_cod) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-red-600">−{{ formatMoney(r.total_fee) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums">{{ formatMoney(r.transferred) }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-right tabular-nums">
                                    <span :class="cn('rounded-md px-2 py-0.5 text-[12px] font-medium', r.matched_count === r.lines_count ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700')">{{ r.matched_count }}/{{ r.lines_count }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </section>

        <div class="space-y-5">
            <button v-if="tenant.can('finance.vouchers')" type="button" :class="cn(card, 'block w-full p-5 text-left hover:border-blue-300')" @click="importing = true">
                <span class="flex items-center gap-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-[11px] bg-blue-50 text-blue-600"><FileUp class="size-5" /></span>
                    <span><span class="block text-[16px] font-semibold text-slate-900">Nhập file đối soát của hãng</span><span class="block text-[13px] text-slate-500">Khớp theo mã vận đơn, xem trước rồi mới ghi sổ</span></span>
                </span>
                <span class="mt-4 grid place-items-center rounded-[12px] border-2 border-dashed border-slate-200 bg-slate-50/60 px-4 py-6 text-center">
                    <Upload class="size-7 text-slate-400" />
                    <span class="mt-2 text-[14px] font-medium text-slate-800">Bấm để chọn file Excel hoặc CSV</span>
                    <span class="mt-1 text-[12px] text-slate-500">Nhận file đối soát gốc tải từ trang của hãng</span>
                    <span class="mt-3 flex flex-wrap justify-center gap-2">
                        <CarrierBox v-for="c in ['ghn', 'viettelpost', 'jt', 'spx']" :key="c" :carrier="c" size="sm" />
                    </span>
                </span>
            </button>

            <section v-if="heldByCarrier.length" :class="card" data-testid="cod-by-carrier">
                <header class="border-b border-slate-100 px-5 py-4">
                    <h3 class="text-[16px] font-semibold text-slate-900">COD theo hãng</h3>
                    <p class="mt-0.5 text-[13px] text-slate-500">Hãng đang giữ, chưa chuyển về</p>
                </header>
                <div class="divide-y divide-slate-100">
                    <div v-for="c in heldByCarrier" :key="c.carrier" class="px-5 py-3">
                        <div class="flex items-center justify-between gap-3">
                            <CarrierLogo :carrier="c.carrier" :name="c.carrier_name" :width="96" :height="24" class="!justify-items-start" />
                            <span class="text-[14px] font-semibold tabular-nums">{{ formatMoney(c.amount) }}</span>
                        </div>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-blue-500" :style="{ width: `${(Number(c.amount) / heldMax) * 100}%` }" /></div>
                        <p class="mt-1.5 text-[12px] text-slate-500">{{ c.carrier_name }} · {{ formatNumber(c.count) }} đơn<span v-if="c.slow_count" class="text-red-600"> · {{ formatNumber(c.slow_count) }} đơn quá {{ slowDays }} ngày</span></p>
                    </div>
                </div>
            </section>

            <section v-if="rows.length" :class="card">
                <header class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                    <h3 class="text-[16px] font-semibold text-slate-900">Phiên đối soát gần đây</h3>
                    <button type="button" class="text-[13px] font-medium text-blue-600 hover:underline" @click="tab = 'sessions'">Xem tất cả</button>
                </header>
                <div class="divide-y divide-slate-100">
                    <button v-for="r in rows.slice(0, 3)" :key="r.id" type="button" class="flex w-full items-center gap-3 px-5 py-3 text-left hover:bg-slate-50" @click="open(r)">
                        <CarrierBox :carrier="r.carrier" :name="r.carrier_name" size="sm" />
                        <span class="min-w-0 flex-1"><span class="block truncate text-[14px] font-medium">{{ r.number }}</span><span class="block text-[12px] text-slate-500">{{ formatDate(r.remitted_at) }} · {{ formatNumber(r.lines_count) }} đơn</span></span>
                        <span class="text-right">
                            <span class="block text-[14px] font-semibold tabular-nums">{{ formatMoney(r.transferred) }}</span>
                            <span :class="cn('inline-block rounded-md px-2 py-0.5 text-[12px] font-medium', r.voided_at ? 'bg-slate-100 text-slate-500' : r.matched_count === r.lines_count ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700')">{{ r.voided_at ? 'Đã hủy' : r.matched_count === r.lines_count ? 'Đã ghi sổ' : `Lệch ${r.lines_count - r.matched_count} đơn` }}</span>
                        </span>
                    </button>
                </div>
            </section>

            <section v-if="funds.length" :class="card" data-testid="cod-funds">
                <header class="border-b border-slate-100 px-5 py-4"><h3 class="text-[16px] font-semibold text-slate-900">Quỹ hiện có</h3></header>
                <ul class="divide-y divide-slate-100 px-5 text-[14px]">
                    <li v-for="f in funds" :key="f.id" class="flex items-center gap-3 py-3">
                        <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-slate-100 text-slate-600"><component :is="fundIcon(f.kind)" class="size-4" /></span>
                        <span class="min-w-0 flex-1 truncate">{{ f.name }}<span v-if="f.account_number" class="block text-[12px] text-slate-400">{{ f.account_number }}</span></span>
                        <span class="font-medium tabular-nums">{{ f.balance === null ? '—' : formatMoney(f.balance) }}</span>
                    </li>
                </ul>
                <div v-if="fundsTotal !== null" class="flex justify-between border-t border-slate-100 px-5 py-3 font-semibold"><span>Tổng</span><span class="tabular-nums">{{ formatMoney(fundsTotal) }}</span></div>
            </section>
        </div>
    </div>

    <CodImportDialog v-model:open="importing" @saved="saved" />

    <Dialog v-model:open="detailOpen" :title="detail ? `Đối soát ${detail.number} · ${detail.carrier_name}` : ''" size="xl">
        <div v-if="detail" class="space-y-3 text-sm" data-testid="cod-detail">
            <p v-if="detail.voided_at" class="rounded-md bg-rose-50 px-3 py-2 text-rose-700" data-testid="cod-voided">
                Đã hủy {{ formatDateTime(detail.voided_at) }}<template v-if="detail.voided_by"> bởi {{ detail.voided_by }}</template>: {{ detail.void_reason }}. Các khoản thu trên đơn và phiếu phí đã được hủy theo.
            </p>
            <p class="text-muted-foreground">
                Tiền về {{ formatDateTime(detail.remitted_at) }}, vào quỹ {{ detail.account?.name ?? '—' }}. Đã ghi {{ formatMoney(detail.total_booked) }} vào đơn,
                phí {{ formatMoney(detail.total_fee) }}<template v-if="detail.fee_voucher"> (phiếu {{ detail.fee_voucher.number }})</template>.
            </p>
            <div class="max-h-[60vh] overflow-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <tr><th class="px-3 py-2">Mã vận đơn</th><th class="px-3 py-2">Đơn</th><th class="px-3 py-2 text-right">Thu hộ</th><th class="px-3 py-2 text-right">Phí</th><th class="px-3 py-2 text-right">Đã ghi</th><th class="px-3 py-2">Kết quả</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(l, i) in detail.lines" :key="i">
                            <td class="px-3 py-2 font-mono text-xs">{{ l.tracking }}</td>
                            <td class="px-3 py-2"><RouterLink v-if="l.order" :to="`/orders/${l.order.id}`" class="font-mono text-xs text-primary hover:underline">{{ l.order.number }}</RouterLink><span v-else>—</span></td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ formatMoney(l.cod) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ formatMoney(l.fee) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ formatMoney(l.booked) }}</td>
                            <td class="px-3 py-2"><Badge :tone="codStatuses[l.status].tone">{{ codStatuses[l.status].label }}</Badge><div v-if="l.note" class="text-xs text-muted-foreground">{{ l.note }}</div></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <template v-if="detail && !detail.voided_at && tenant.can('finance.vouchers.void')" #footer>
            <Button variant="outline" class="text-destructive" data-testid="cod-void" @click="startVoid">Hủy phiên đối soát</Button>
        </template>
    </Dialog>

    <Dialog v-model:open="voiding" title="Hủy phiên đối soát" description="Mọi khoản thu COD đã ghi vào đơn và phiếu phí của phiên này sẽ bị hủy theo. Quỹ và đơn trở về như trước khi đối soát.">
        <FormField label="Lý do" :error="voidError?.field('reason')"><Input v-model="voidReason" data-testid="cod-void-reason" placeholder="Ví dụ: nhập nhầm file" /></FormField>
        <p v-if="voidError && !voidError.field('reason')" class="mt-2 text-sm text-destructive">{{ voidError.message }}</p>
        <template #footer>
            <Button variant="outline" @click="voiding = false">Đóng</Button>
            <Button variant="destructive" :loading="busy" :disabled="!voidReason.trim()" data-testid="cod-void-confirm" @click="confirmVoid">Hủy phiên</Button>
        </template>
    </Dialog>
</template>
