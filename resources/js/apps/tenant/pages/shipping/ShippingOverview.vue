<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { ArrowRight, Banknote, ChevronRight, CircleAlert, CircleCheckBig, ClockAlert, PackageCheck, PackageOpen, Receipt, Send, Truck, Undo2 } from 'lucide-vue-next';
import ReportChip from '../reports/ReportChip.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import ChannelMark from '../reports/ChannelMark.vue';
import { channelLabel } from '../reports/channels';
import { presetRange } from '../reports/range';
import CarrierBox from './CarrierBox.vue';
import ShipHead from './ShipHead.vue';
import ShipRange from './ShipRange.vue';
import { card, iconTones, pct, th } from './ui';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import StackedBarChart from '@/shared/ui/charts/StackedBarChart.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';

/** Vận chuyển › Tổng quan (mẫu 28/11): where parcels are now, what needs the shop today, and how each carrier did. */
interface Money { count: number; amount: string }
interface Summary { count: number; finished: number; delivered: number; returned: number; failed: number; delivered_rate: number | null; returned_rate: number | null; fee_total: string; fee_count: number; buyer_paid: number }
interface Overview {
    now: {
        to_push: number; awaiting: number; overdue_pickup: number; delivering: number; late: number; late_days: number;
        failed: number; returning: number; returning_slow: number; cod_held: Money; cod_held_slow: Money; fee_gap: Money; slow_days: number;
    };
    range: { from: string; to: string };
    total: number;
    days: { date: string; delivered: number; problem: number; open: number }[];
    carriers: { carrier: string; carrier_name: string | null; marketplace: boolean; count: number; delivered_rate: number | null; returned_rate: number | null; road_days: number | null; avg_fee: string | null; fee_total: string; cod_held: string }[];
    summary: Summary;
    previous: Summary;
    channels: { label: string; kind: string | null; count: number }[];
}

const tenant = useTenantStore();
const range = ref(presetRange('30d'));
const data = ref<Overview | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
let seq = 0;

async function load() {
    const mine = ++seq;
    loading.value = true;
    error.value = null;
    try {
        const res = (await api.get('/shipping/overview', { params: range.value })).data.data as Overview;
        if (mine === seq) data.value = res;
    } catch (e) {
        if (mine === seq) error.value = (e as ApiError).message;
    } finally {
        if (mine === seq) loading.value = false;
    }
}
onMounted(load);
watch(range, load, { deep: true });

const canFinance = computed(() => tenant.can('finance.view'));
const n = (v: number) => formatNumber(v);
const money = (v: number | string) => formatNumber(Math.round(Number(v)));
const carrierName = (c: Overview['carriers'][number]) => c.carrier_name ?? (c.carrier === 'other' ? 'Tự giao hoặc hãng khác' : c.carrier);

/** Change in percentage points against the period before ("+2,1%"), null when either side has no result yet. */
function points(now: number | null, before: number | null) {
    if (now === null || before === null) return null;
    const d = Math.round((now - before) * 10) / 10;
    return { d, text: `${d > 0 ? '+' : d < 0 ? '−' : '±'}${String(Math.abs(d)).replace('.', ',')}%` };
}
const deliveredChange = computed(() => (data.value ? points(data.value.summary.delivered_rate, data.value.previous.delivered_rate) : null));
const returnedChange = computed(() => (data.value ? points(data.value.summary.returned_rate, data.value.previous.returned_rate) : null));
const feeChange = computed(() => {
    const now = Number(data.value?.summary.fee_total ?? 0);
    const before = Number(data.value?.previous.fee_total ?? 0);
    if (!before) return null;
    const d = Math.round(((now - before) / before) * 1000) / 10;
    return { d, text: `${d > 0 ? '+' : d < 0 ? '−' : '±'}${String(Math.abs(d)).replace('.', ',')}%` };
});

/** "Đơn đang ở đâu": each step opens the list already filtered. */
const steps = computed(() => {
    const x = data.value?.now;
    const s = data.value?.summary;
    if (!x || !s) return [];
    return [
        { key: 'to_push', icon: Send, tone: 'amber', label: 'Chờ đẩy', value: x.to_push, note: 'Chưa gửi sang hãng', to: '/shipments' },
        { key: 'awaiting', icon: PackageOpen, tone: 'violet', label: 'Chờ lấy hàng', value: x.awaiting, note: x.overdue_pickup ? `${n(x.overdue_pickup)} đơn quá hạn lấy` : 'Hãng sắp đến lấy', to: '/shipments?tab=awaiting' },
        { key: 'delivering', icon: Truck, tone: 'blue', label: 'Đang giao', value: x.delivering, note: 'Gồm đang vận chuyển, giao lại', to: '/shipments?tab=delivering' },
        { key: 'delivered', icon: CircleCheckBig, tone: 'green', label: 'Giao thành công', value: s.delivered, note: 'Trong khoảng đã chọn', to: '/shipments?tab=delivered' },
        { key: 'failed', icon: CircleAlert, tone: 'red', label: 'Giao thất bại', value: x.failed, note: x.failed ? 'Cần gọi khách' : 'Không có đơn nào', to: '/shipments?tab=failed' },
        { key: 'returning', icon: Undo2, tone: 'amber', label: 'Đang hoàn', value: x.returning, note: x.returning_slow ? `${n(x.returning_slow)} đơn hơn ${x.slow_days} ngày` : 'Đang về kho', to: '/shipments?tab=returning' },
    ];
});

const chart = computed(() => {
    const days = data.value?.days ?? [];
    return {
        labels: days.map((d) => d.date.slice(8, 10) + '/' + d.date.slice(5, 7)),
        series: [
            { key: 'delivered', label: 'Đã giao', color: '#34d399', values: days.map((d) => d.delivered) },
            { key: 'open', label: 'Đang trên đường', color: '#93c5fd', values: days.map((d) => d.open) },
            { key: 'problem', label: 'Thất bại hoặc hoàn', color: '#f87171', values: days.map((d) => d.problem) },
        ],
        tips: days.map((d) => `${d.date.split('-').reverse().join('/')}\nĐã giao: ${d.delivered}\nĐang trên đường: ${d.open}\nThất bại hoặc hoàn: ${d.problem}`),
    };
});

/** "Cần xử lý ngay": only lines with something in them, each opening the list already filtered. */
const todo = computed(() => {
    const x = data.value?.now;
    if (!x) return [];
    return [
        { show: x.failed > 0, tone: 'red', icon: CircleAlert, text: `${n(x.failed)} đơn giao thất bại`, note: 'Gọi khách hoặc báo hãng giao lại', to: '/shipments?tab=failed', action: 'Xử lý' },
        { show: x.overdue_pickup > 0, tone: 'red', icon: ClockAlert, text: `${n(x.overdue_pickup)} đơn quá hạn giao cho hãng`, note: 'Đã quá hạn bàn giao, hãng chưa lấy', to: '/shipments?tab=awaiting', action: 'Xem đơn' },
        { show: x.to_push > 0, tone: 'amber', icon: Send, text: `${n(x.to_push)} đơn chưa đẩy sang hãng`, note: 'Đã xác nhận, đang chờ chọn hãng', to: '/shipments', action: 'Đẩy đơn' },
        { show: x.late > 0, tone: 'amber', icon: Truck, text: `${n(x.late)} đơn giao chậm`, note: `Đã lấy hơn ${x.late_days} ngày mà chưa giao xong`, to: '/shipments?tab=delivering&late=1', action: 'Xem đơn' },
        { show: canFinance.value && x.cod_held_slow.count > 0, tone: 'amber', icon: Banknote, text: `${formatMoney(x.cod_held_slow.amount)} COD hãng giữ quá ${x.slow_days} ngày`, note: `${n(x.cod_held_slow.count)} đơn đã giao, hãng chưa trả tiền`, to: '/shipping/cod', action: 'Đối soát' },
        { show: x.returning_slow > 0, tone: 'amber', icon: Undo2, text: `${n(x.returning_slow)} đơn đang hoàn hơn ${x.slow_days} ngày`, note: 'Chưa về kho, nên hỏi hãng', to: '/shipments?tab=returning', action: 'Xem đơn' },
        { show: canFinance.value && x.fee_gap.count > 0, tone: 'amber', icon: Receipt, text: `${n(x.fee_gap.count)} đơn hãng tính phí cao hơn báo giá`, note: `Lệch tổng ${formatMoney(x.fee_gap.amount)}`, to: '/shipping/cod?tab=fee', action: 'Xem phí' },
    ].filter((t) => t.show);
});

/** Parcels per sales channel: the five biggest, the rest together. */
const kindColors: Record<string, string> = { shopee: '#f97316', tiktok: '#0f172a', lazada: '#1e3a8a', tiki: '#0284c7', woocommerce: '#8b5cf6', website: '#8b5cf6', zalo: '#0ea5e9', facebook: '#2563eb', pos: '#10b981', store: '#10b981', manual: '#10b981' };
const palette = ['#f59e0b', '#ec4899', '#14b8a6', '#6366f1', '#84cc16'];
const channels = computed(() => {
    const list = data.value?.channels ?? [];
    const used = new Set<string>();
    const top = list.slice(0, 5).map((c, i) => {
        let color = kindColors[c.kind ?? ''] ?? palette[i];
        if (used.has(color)) color = palette[i];
        used.add(color);
        return { key: `${c.label}-${c.kind}`, label: channelLabel(c.label, c.kind), kind: c.kind, count: c.count, color };
    });
    const rest = list.slice(5).reduce((s, c) => s + c.count, 0);
    if (rest) top.push({ key: 'rest', label: 'Kênh khác', kind: null, count: rest, color: '#cbd5e1' });
    return top;
});
const channelTotal = computed(() => channels.value.reduce((s, c) => s + c.count, 0));
const arcs = computed(() => {
    let start = 0;
    return channels.value.map((c) => {
        const len = channelTotal.value ? (c.count / channelTotal.value) * 100 : 0;
        const a = { key: c.key, color: c.color, dash: `${len} ${100 - len}`, offset: -start };
        start += len;
        return a;
    });
});

/** Fees the shop paid, split by carrier. */
const feeShares = computed(() => {
    const total = Number(data.value?.summary.fee_total ?? 0);
    if (!total) return [];
    const colors = ['bg-orange-500', 'bg-red-500', 'bg-rose-300', 'bg-amber-400', 'bg-slate-400'];
    return (data.value?.carriers ?? []).filter((c) => Number(c.fee_total) > 0).sort((a, b) => Number(b.fee_total) - Number(a.fee_total))
        .map((c, i) => ({ key: c.carrier, name: carrierName(c), share: Math.round((Number(c.fee_total) / total) * 100), color: colors[i % colors.length] }));
});
const feeAvg = computed(() => (data.value?.summary.fee_count ? Number(data.value.summary.fee_total) / data.value.summary.fee_count : null));
</script>

<template>
    <ShipHead title="Tổng quan vận chuyển" description="Đơn đang ở đâu, việc cần làm hôm nay và hãng nào giao tốt.">
        <ShipRange v-model:from="range.from" v-model:to="range.to" />
    </ShipHead>
    <ErrorState v-if="error && !data" :message="error" @retry="load" />
    <div v-else-if="!data" class="space-y-4"><Skeleton class="h-36" /><Skeleton class="h-28" /><Skeleton class="h-72" /></div>
    <div v-else :class="cn('space-y-5', loading && 'opacity-60')" data-testid="shipping-overview">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <component :is="canFinance ? RouterLink : 'div'" to="/shipping/cod" class="block">
                <ReportKpi label="COD hãng đang giữ" tip="Tiền thu hộ của đơn đã giao mà hãng vận chuyển chưa chuyển về tài khoản của shop." :icon="Banknote" primary :value="canFinance ? Math.round(Number(data.now.cod_held.amount)) : null" class="h-full">
                    <template #chips>
                        <ReportChip tone="onblue">{{ n(data.now.cod_held.count) }} đơn đã giao</ReportChip>
                        <ReportChip v-if="canFinance && data.now.cod_held_slow.count" tone="onblue">{{ money(data.now.cod_held_slow.amount) }} quá {{ data.now.slow_days }} ngày</ReportChip>
                    </template>
                    Tiền thu hộ hãng chưa chuyển về tài khoản
                </ReportKpi>
            </component>
            <RouterLink to="/shipments?tab=delivering" class="block">
                <ReportKpi label="Đang giao" tip="Đơn hãng đã lấy và đang trên đường, gồm cả đơn đang giao lại." :icon="Truck" tone="blue" plain :value="data.now.delivering" unit="đơn" class="h-full">
                    <template #chips>
                        <ReportChip v-if="data.now.late" tone="amber">{{ n(data.now.late) }} đơn chậm</ReportChip>
                        <ReportChip tone="up">{{ n(Math.max(0, data.now.delivering - data.now.late)) }} đúng tiến độ</ReportChip>
                    </template>
                    Đã lấy hơn {{ data.now.late_days }} ngày mà chưa giao là chậm
                </ReportKpi>
            </RouterLink>
            <ReportKpi label="Giao thành công" tip="Trong các đơn tạo trong khoảng đã chọn và đã có kết quả (giao xong hoặc hoàn), bao nhiêu phần trăm giao được cho khách." :icon="PackageCheck" tone="green" :value="null" :text="pct(data.summary.delivered_rate)">
                <template #chips>
                    <ReportChip v-if="deliveredChange" :tone="deliveredChange.d >= 0 ? 'up' : 'down'">{{ deliveredChange.text }} so với kỳ trước</ReportChip>
                    <ReportChip v-else tone="plain">Chưa có kỳ trước để so</ReportChip>
                </template>
                {{ n(data.summary.delivered) }} đơn giao xong trong {{ n(data.summary.finished) }} đơn đã có kết quả
            </ReportKpi>
            <ReportKpi label="Thất bại và hoàn" tip="Đơn đang giao thất bại cần xử lý và đơn đang trên đường hoàn về kho." :icon="Undo2" tone="red" :value="data.now.failed + data.now.returning" unit="đơn">
                <template #chips>
                    <ReportChip tone="red">{{ n(data.now.failed) }} giao thất bại</ReportChip>
                    <ReportChip tone="amber">{{ n(data.now.returning) }} đang hoàn</ReportChip>
                </template>
                Tỉ lệ hoàn {{ pct(data.summary.returned_rate) }}<template v-if="returnedChange">, {{ returnedChange.d <= 0 ? 'giảm' : 'tăng' }} {{ returnedChange.text.slice(1) }} so với kỳ trước</template>
            </ReportKpi>
        </div>

        <section :class="card" data-testid="shipping-pipeline">
            <header class="border-b border-slate-100 px-5 py-4">
                <h3 class="text-[16px] font-semibold text-slate-900">Đơn đang ở đâu</h3>
                <p class="mt-0.5 text-[13px] text-slate-500">Bấm từng bước để mở danh sách vận đơn đã lọc sẵn</p>
            </header>
            <div class="grid gap-2 p-4 sm:grid-cols-2 lg:grid-cols-3 2xl:flex 2xl:items-center 2xl:gap-1">
                <template v-for="(s, i) in steps" :key="s.key">
                    <RouterLink :to="s.to" class="flex min-w-0 flex-1 items-center gap-3 rounded-[12px] border border-slate-100 bg-slate-50/60 px-4 py-3.5 hover:border-blue-200 hover:bg-blue-50/40">
                        <span :class="cn('grid size-10 shrink-0 place-items-center rounded-[11px]', iconTones[s.tone])"><component :is="s.icon" class="size-5" :stroke-width="1.9" /></span>
                        <span class="min-w-0">
                            <span class="block text-[13px] text-slate-500">{{ s.label }}</span>
                            <span class="block text-[22px] font-bold leading-tight tabular-nums text-slate-900">{{ n(s.value) }}</span>
                            <span class="block truncate text-[12px] text-slate-400">{{ s.note }}</span>
                        </span>
                    </RouterLink>
                    <ChevronRight v-if="i < steps.length - 1" class="hidden size-5 shrink-0 text-slate-300 2xl:block" />
                </template>
            </div>
        </section>

        <div class="grid grid-cols-1 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_400px] 2xl:grid-cols-[minmax(0,1fr)_420px]">
            <div class="min-w-0 space-y-5">
                <section :class="card">
                    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                        <div>
                            <h3 class="text-[16px] font-semibold text-slate-900">Đơn tạo theo ngày</h3>
                            <p class="mt-0.5 text-[13px] text-slate-500">{{ n(data.total) }} đơn trong khoảng đã chọn, tô màu theo kết quả giao hiện tại</p>
                        </div>
                    </header>
                    <div class="px-5 py-4">
                        <StackedBarChart v-if="data.total" :labels="chart.labels" :series="chart.series" :tips="chart.tips" :height="250" />
                        <EmptyState v-else :icon="PackageCheck" title="Chưa có đơn giao trong khoảng này" />
                    </div>
                </section>

                <section :class="cn(card, 'overflow-hidden')">
                    <header class="border-b border-slate-100 px-5 py-4">
                        <h3 class="text-[16px] font-semibold text-slate-900">Hiệu quả theo hãng vận chuyển</h3>
                        <p class="mt-0.5 text-[13px] text-slate-500">Tính trên đơn tạo trong khoảng đã chọn. Tỉ lệ giao và hoàn chỉ tính đơn đã có kết quả.</p>
                    </header>
                    <div v-if="data.carriers.length" class="overflow-x-auto">
                        <table class="w-full min-w-[820px]" data-testid="carrier-table">
                            <thead class="bg-slate-50/70 text-left">
                                <tr>
                                    <th :class="cn(th, 'pl-5')">Hãng</th>
                                    <th :class="cn(th, 'text-right')">Số đơn</th>
                                    <th :class="cn(th, 'text-right')">Giao thành công</th>
                                    <th :class="cn(th, 'text-right')">Hoàn</th>
                                    <th :class="cn(th, 'text-right')">Thời gian giao</th>
                                    <th :class="cn(th, 'text-right')">Phí ship TB</th>
                                    <th v-if="canFinance" :class="cn(th, 'pr-5 text-right')">COD đang giữ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="c in data.carriers" :key="c.carrier" class="text-[14px]">
                                    <td class="px-5 py-3">
                                        <div class="flex items-center gap-3">
                                            <CarrierBox :carrier="c.carrier" :name="carrierName(c)" />
                                            <div class="min-w-0">
                                                <p class="font-medium text-slate-900">{{ carrierName(c) }}</p>
                                                <p v-if="c.marketplace" class="text-[12px] text-slate-400">Vận chuyển của sàn</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ n(c.count) }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center justify-end gap-3">
                                            <div class="hidden h-1.5 w-28 overflow-hidden rounded-full bg-slate-100 2xl:block">
                                                <div :class="cn('h-full rounded-full', (c.delivered_rate ?? 0) >= 80 ? 'bg-emerald-500' : 'bg-amber-500')" :style="{ width: `${c.delivered_rate ?? 0}%` }" />
                                            </div>
                                            <span :class="cn('w-14 text-right font-medium tabular-nums', c.delivered_rate === null ? 'text-slate-400' : c.delivered_rate >= 80 ? 'text-emerald-600' : 'text-amber-600')">{{ pct(c.delivered_rate) }}</span>
                                        </div>
                                    </td>
                                    <td :class="cn('px-4 py-3 text-right tabular-nums', (c.returned_rate ?? 0) >= 15 ? 'text-red-600' : 'text-slate-700')">{{ pct(c.returned_rate) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ c.road_days === null ? '—' : `${String(c.road_days).replace('.', ',')} ngày` }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">
                                        <template v-if="c.avg_fee !== null">{{ money(c.avg_fee) }} đ</template>
                                        <span v-else class="text-slate-400">{{ c.marketplace ? 'Sàn trả' : '—' }}</span>
                                    </td>
                                    <td v-if="canFinance" class="px-5 py-3 text-right font-medium tabular-nums">{{ money(c.cod_held) }} đ</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <EmptyState v-else :icon="Truck" title="Chưa có đơn giao trong khoảng này" />
                </section>
            </div>

            <div class="space-y-5">
                <section :class="card">
                    <header class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                        <h3 class="text-[16px] font-semibold text-slate-900">Cần xử lý ngay</h3>
                        <span v-if="todo.length" class="rounded-md bg-red-50 px-2 py-0.5 text-[12px] font-semibold text-red-600">{{ todo.length }} việc</span>
                    </header>
                    <div v-if="todo.length" class="divide-y divide-slate-100" data-testid="shipping-todo">
                        <RouterLink v-for="t in todo" :key="t.text" :to="t.to" class="flex items-center gap-3 px-5 py-3 hover:bg-slate-50">
                            <span :class="cn('grid size-9 shrink-0 place-items-center rounded-[10px]', iconTones[t.tone])"><component :is="t.icon" class="size-[18px]" :stroke-width="1.9" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-[14px] font-medium text-slate-900">{{ t.text }}</span>
                                <span class="block text-[12px] text-slate-500">{{ t.note }}</span>
                            </span>
                            <span class="flex shrink-0 items-center gap-1 text-[13px] font-medium text-blue-600">{{ t.action }} <ArrowRight class="size-3.5" /></span>
                        </RouterLink>
                    </div>
                    <div v-else class="flex items-center gap-3 px-5 py-6 text-[14px] text-slate-500"><PackageCheck class="size-5 text-emerald-500" /> Không có việc gấp. Mọi đơn đang đúng tiến độ.</div>
                </section>

                <section v-if="channels.length" :class="card" data-testid="shipping-channels">
                    <header class="border-b border-slate-100 px-5 py-4">
                        <h3 class="text-[16px] font-semibold text-slate-900">Vận đơn theo kênh bán</h3>
                        <p class="mt-0.5 text-[13px] text-slate-500">{{ n(channelTotal) }} vận đơn trong khoảng đã chọn</p>
                    </header>
                    <div class="flex items-center gap-5 p-5">
                        <div class="relative size-[132px] shrink-0">
                            <svg viewBox="0 0 42 42" class="size-full -rotate-90" role="img" aria-label="Vận đơn theo kênh bán">
                                <circle cx="21" cy="21" r="15.9155" fill="none" stroke="#f1f5f9" stroke-width="5" />
                                <circle v-for="a in arcs" :key="a.key" cx="21" cy="21" r="15.9155" fill="none" :stroke="a.color" stroke-width="5" :stroke-dasharray="a.dash" :stroke-dashoffset="a.offset" />
                            </svg>
                            <div class="absolute inset-0 grid place-items-center text-center">
                                <div><p class="text-[20px] font-bold leading-none text-slate-900">{{ n(channelTotal) }}</p><p class="mt-1 text-[11px] text-slate-400">vận đơn</p></div>
                            </div>
                        </div>
                        <ul class="min-w-0 flex-1 space-y-2.5">
                            <li v-for="c in channels" :key="c.key" class="flex items-center gap-2 text-[13px]">
                                <span class="size-2.5 shrink-0 rounded-full" :style="{ background: c.color }" />
                                <ChannelMark v-if="c.kind" :kind="c.kind" :name="c.label" size="sm" class="!size-5" />
                                <span class="min-w-0 flex-1 truncate text-slate-600">{{ c.label }}</span>
                                <span class="font-medium tabular-nums">{{ n(c.count) }}</span>
                                <span class="w-10 text-right tabular-nums text-slate-400">{{ Math.round((c.count / channelTotal) * 100) }}%</span>
                            </li>
                        </ul>
                    </div>
                </section>

                <section v-if="canFinance" :class="cn(card, 'p-5')" data-testid="shipping-fees">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-[14px] text-slate-500">Phí ship shop trả trong kỳ</p>
                        <ReportChip v-if="feeChange" :tone="feeChange.d <= 0 ? 'up' : 'red'">{{ feeChange.text }}</ReportChip>
                    </div>
                    <p class="mt-1 text-[24px] font-bold tabular-nums tracking-tight text-slate-900">{{ money(data.summary.fee_total) }}<span class="ml-1 text-[15px] font-semibold text-slate-400">đ</span></p>
                    <p class="mt-1 text-[13px] text-slate-500">
                        <template v-if="feeAvg !== null">Trung bình {{ money(feeAvg) }} đ một đơn</template><template v-else>Chưa có đơn shop trả phí</template>
                        <template v-if="data.summary.buyer_paid"> · khách trả {{ n(data.summary.buyer_paid) }} đơn</template>
                    </p>
                    <template v-if="feeShares.length">
                        <div class="mt-4 flex h-2 overflow-hidden rounded-full bg-slate-100">
                            <div v-for="f in feeShares" :key="f.key" :class="f.color" :style="{ width: `${f.share}%` }" />
                        </div>
                        <div class="mt-2 flex flex-wrap justify-between gap-x-3 gap-y-1 text-[12px] text-slate-500">
                            <span v-for="f in feeShares" :key="f.key">{{ f.name }} {{ f.share }}%</span>
                        </div>
                    </template>
                </section>
            </div>
        </div>
    </div>
</template>
