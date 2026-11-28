<script setup lang="ts">
import { computed, onMounted, provide, ref, watch } from 'vue';
import { ArrowRight, Banknote, ChevronRight, Clock, CircleX, Download, Info, Landmark, Package, Percent, PiggyBank, Receipt, TrendingUp, Undo2, Wallet } from 'lucide-vue-next';
import ErrorState from '@/shared/ui/ErrorState.vue';
import ChannelMark from '../reports/ChannelMark.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatNumber } from '@/shared/lib/utils';
import { marketplaceNames } from '../orders/labels';
import { downloadCsv, presetLabels, presetRange, toCsv, type Preset } from '../reports/range';
import FinanceChart from './FinanceChart.vue';
import FinanceDetails from './FinanceDetails.vue';
import { change, formulas, type FinanceData, type Metric } from './finance';
import DateRangeInput from '@/shared/ui/DateRangeInput.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import ReportChip from '../reports/ReportChip.vue';
import SideCard from '../reports/SideCard.vue';

/**
 * Tài chính (mẫu Tailwind 28/11): revenue figures, the daily chart beside marketplace fees and payouts, estimated
 * profit, then every shop and channel. Each figure opens the orders behind it.
 */
provide('soft-cards', true);

const initial = presetRange('month');
const from = ref(initial.from);
const to = ref(initial.to);
const shop = ref('');
const data = ref<FinanceData | null>(null);
const error = ref<string | null>(null);
const loading = ref(false);
const drill = ref<Metric | null>(null);

const params = computed(() => ({ from: from.value, to: to.value, ...(shop.value ? { shop: shop.value } : {}) }));

async function load() {
    error.value = null;
    loading.value = true;
    try {
        data.value = (await api.get('/finance/summary', { params: params.value })).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
watch(params, load);

function preset(p: Preset) {
    const r = presetRange(p);
    from.value = r.from;
    to.value = r.to;
}
const activePreset = computed(() => (Object.keys(presetLabels) as Preset[]).find((p) => presetRange(p).from === from.value && presetRange(p).to === to.value) ?? null);

const shopOptions = computed(() => [
    { value: '', label: 'Tất cả shop và kênh' },
    ...(data.value?.shops ?? []).map((s) => ({ value: s.id, label: `${s.name} · ${marketplaceNames[s.marketplace] ?? s.marketplace}` })),
]);

const profit = computed(() => data.value?.can_view_profit ?? false);
const margin = (value: number | null) => (value === null || !data.value?.net_revenue ? null : Math.round((value / data.value.net_revenue) * 1000) / 10);
const dmy = (iso: string) => iso.split('-').reverse().join('/');

function changeChip(current: number | null, previous: number | null): { text: string; up: boolean } | null {
    const c = change(current, previous);
    return c === null ? null : { text: `${c > 0 ? '↑' : c < 0 ? '↓' : ''} ${Math.abs(c).toLocaleString('vi-VN')}%`, up: c >= 0 };
}
const money = (v: number | null | undefined) => (v === null || v === undefined ? '—' : formatNumber(Math.round(Number(v))));
const num = (v: number | null | undefined) => (v === null || v === undefined ? null : Math.round(Number(v)));
const QUICK: [Preset, string][] = (['today', '7d', 'month', 'last-month'] as Preset[]).filter((p) => p in presetLabels).map((p) => [p, { today: 'Hôm nay', '7d': '7 ngày', month: 'Tháng này', 'last-month': 'Tháng trước' }[p as string] ?? presetLabels[p]]);
const rangeEl = ref<HTMLElement | null>(null);
const card = 'rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]';

function exportCsv() {
    const d = data.value;
    if (!d) return;
    const p = d.can_view_profit;
    const head = ['Chỉ tiêu', `Kỳ này (${dmy(d.range.from)} - ${dmy(d.range.to)})`, `Kỳ trước (${dmy(d.previous_range.from)} - ${dmy(d.previous_range.to)})`];
    const lines: (string | number | null)[][] = [
        ['Doanh thu đặt hàng (trừ đơn hủy)', d.ordered.revenue, null],
        ['Doanh thu hoàn thành (tiền hàng sau giảm giá)', d.completed.goods, null],
        ['Hàng trả đã duyệt', d.refunds, null],
        ['Doanh thu thuần', d.net_revenue, d.previous.net_revenue],
        ['Phí ship khách trả (tính riêng)', d.completed.shipping, null],
        ['Chờ sàn hoàn thành (tạm tính)', d.awaiting_completion.goods, null],
        ['Phí sàn đã xác nhận', d.fees.confirmed, null],
        ['Phí sàn ước tính', d.fees.estimated, null],
        ['Số đơn chưa biết phí sàn', d.fees.unknown_orders, null],
        ['Tiền sàn đã chuyển về', d.payout.received, null],
        ...(p
            ? [
                  ['Giá vốn', d.cogs === null ? null : d.cogs - (d.cogs_returned ?? 0), null],
                  ['Lợi nhuận gộp', d.estimated_gross_profit, d.previous.estimated_gross_profit],
                  ['Lợi nhuận đóng góp', d.contribution_profit, d.previous.contribution_profit],
                  ['Lợi nhuận quản trị ước tính', d.estimated_profit, d.previous.estimated_profit],
              ]
            : []),
    ];
    const shops = d.by_shop.map((s) => [
        s.shop, marketplaceNames[s.marketplace] ?? s.marketplace, s.ordered_orders, s.cancelled_orders, s.orders, s.net_revenue,
        ...(p ? [s.cogs, s.gross_profit] : []), s.fees, s.fees_unknown_orders, s.payout, s.shipping, ...(p ? [s.profit, s.margin] : []), s.average_order, s.growth,
    ]);
    const shopHead = ['Shop / kênh', 'Sàn', 'Đơn đặt', 'Đơn hủy', 'Đơn ghi nhận', 'Doanh thu thuần', ...(p ? ['Giá vốn', 'Lãi gộp'] : []), 'Phí sàn', 'Đơn chưa biết phí', 'Thực nhận', 'Ship khách trả', ...(p ? ['Lợi nhuận đóng góp', 'Biên %'] : []), 'Giá trị TB/đơn', 'Tăng trưởng %'];
    const csv = toCsv(head, lines) + '\n\n' + toCsv(shopHead, shops).replace(/^﻿/, '');
    downloadCsv(`tai-chinh-${d.range.from}-${d.range.to}.csv`, csv);
}
</script>

<template>
    <div class="pt-2">
        <div class="mb-5">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="min-w-0">
                    <h1 class="text-[26px] font-bold leading-tight tracking-tight text-slate-900 sm:text-[30px]">Tài chính</h1>
                    <p class="mt-1 max-w-3xl text-[15px] text-slate-500">Doanh thu, phí sàn, tiền sàn chuyển về và lợi nhuận ước tính, theo shop và theo kỳ.</p>
                </div>
            <button
                    type="button"
                    class="inline-flex h-11 items-center gap-2 rounded-[12px] bg-blue-600 px-5 text-[15px] font-medium text-white shadow-sm hover:bg-blue-700 disabled:opacity-60"
                    :disabled="!data"
                    data-testid="finance-export"
                    @click="exportCsv"
                >
                    <Download class="size-4" /> Xuất báo cáo
                </button>
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <div class="flex rounded-[12px] bg-slate-100 p-1 text-[14px]" role="group" aria-label="Chọn nhanh kỳ">
                    <button
                        v-for="[p, label] in QUICK"
                        :key="p"
                        type="button"
                        :class="cn('rounded-[9px] px-3 py-1.5 text-slate-500 hover:text-slate-900 sm:px-3.5', activePreset === p && 'bg-white font-semibold text-slate-900 shadow-sm')"
                        @click="preset(p)"
                    >
                        {{ label }}
                    </button>
                    <button
                        type="button"
                        :class="cn('rounded-[9px] px-3 py-1.5 text-slate-500 hover:text-slate-900 sm:px-3.5', !QUICK.some(([p]) => p === activePreset) && 'bg-white font-semibold text-slate-900 shadow-sm')"
                        @click="rangeEl?.querySelector<HTMLElement>('button, input')?.click()"
                    >
                        Tùy chọn
                    </button>
                </div>
                <div ref="rangeEl"><DateRangeInput v-model:from="from" v-model:to="to" aria-label="Khoảng ngày" data-testid="finance-range" /></div>
                <Select v-model="shop" :options="shopOptions" class="w-60" aria-label="Shop hoặc kênh bán" data-testid="finance-shop" />

            </div>
        </div>

        <ErrorState v-if="error" :message="error" @retry="load" />
        <div v-else-if="!data" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><Skeleton v-for="i in 8" :key="i" class="h-[132px] rounded-[14px]" /></div>
        <div v-else :class="loading && 'opacity-60'">
            <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="finance-kpis">
                <button type="button" class="text-left" data-testid="kpi-revenue" @click="drill = 'revenue'">
                    <ReportKpi label="Doanh thu thuần" :tip="formulas.net_revenue" :icon="Banknote" primary :value="num(data.net_revenue)" class="h-full transition hover:brightness-105">
                        <template #chips>
                            <template v-if="changeChip(data.net_revenue, data.previous.net_revenue)">
                                <ReportChip tone="onblue">{{ changeChip(data.net_revenue, data.previous.net_revenue)!.text }}</ReportChip>
                                <span class="text-blue-100">so với kỳ trước</span>
                            </template>
                        </template>
                        {{ formatNumber(data.completed.orders) }} đơn ghi nhận · Doanh thu đặt hàng {{ money(data.ordered.revenue) }} đ
                    </ReportKpi>
                </button>
                <button type="button" class="text-left" data-testid="awaiting-completion" @click="drill = 'awaiting'">
                    <ReportKpi label="Chờ sàn hoàn thành" :icon="Clock" tone="amber" :value="num(data.awaiting_completion.goods)" class="h-full hover:border-blue-300">
                        <template #chips><ReportChip tone="amber">{{ formatNumber(data.awaiting_completion.orders) }} đơn đã giao</ReportChip></template>
                        Tạm tính, chưa vào doanh thu và lợi nhuận
                    </ReportKpi>
                </button>
                <button type="button" class="text-left" @click="drill = 'returns'">
                    <ReportKpi label="Hàng trả đã duyệt" :icon="Undo2" tone="violet" :value="num(data.refunds)" class="h-full hover:border-blue-300">Đã trừ khỏi doanh thu thuần</ReportKpi>
                </button>
                <button type="button" class="text-left" @click="drill = 'cancelled'">
                    <ReportKpi label="Đơn hủy" :icon="CircleX" :tone="data.cancelled.orders > 0 ? 'red' : 'slate'" :value="data.cancelled.orders" unit="đơn" class="h-full hover:border-blue-300">
                        <template #chips><ReportChip :tone="data.cancelled.orders ? 'red' : 'plain'">{{ money(data.cancelled.revenue) }} đ</ReportChip></template>
                        Không tính vào doanh thu
                    </ReportKpi>
                </button>
            </div>

            <div class="mb-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
                <section :class="card">
                    <header class="border-b border-slate-100 px-6 py-4">
                        <h3 class="text-[16px] font-semibold text-slate-900">{{ profit ? 'Doanh thu thuần và lợi nhuận gộp theo ngày' : 'Doanh thu thuần theo ngày' }}</h3>
                        <p class="mt-0.5 text-[13px] text-slate-500">Rê chuột hoặc chạm vào một ngày để xem số liệu. So với kỳ trước {{ dmy(data.previous_range.from) }} – {{ dmy(data.previous_range.to) }}.</p>
                    </header>
                    <div class="px-6 py-5"><FinanceChart :days="data.daily" :show-profit="profit" /></div>
                </section>

                <SideCard title="Phí sàn và tiền về" description="Bấm để xem từng đơn">
                    <div class="divide-y divide-slate-100 px-5">
                        <button type="button" class="flex w-full items-start gap-3 py-4 text-left" data-testid="finance-fees" @click="drill = 'fees'">
                            <span class="grid size-10 shrink-0 place-items-center rounded-[10px] bg-amber-50 text-amber-600"><Percent class="size-5" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-baseline justify-between gap-2">
                                    <span class="text-[14px] text-slate-500">Phí sàn</span>
                                    <b class="text-[18px] tabular-nums text-amber-600">{{ data.fees.total === 0 && data.fees.unknown_orders > 0 ? 'Chưa xác định' : `${money(data.fees.total)} đ` }}</b>
                                </span>
                                <span class="mt-1 block text-[12px] text-slate-500">Đã xác nhận {{ money(data.fees.confirmed) }} ({{ formatNumber(data.fees.confirmed_orders) }} đơn) · ước tính {{ money(data.fees.estimated) }} ({{ formatNumber(data.fees.estimated_orders) }} đơn)</span>
                                <span v-if="data.fees.unknown_orders" class="mt-1 block text-[12px] text-amber-700" data-testid="fees-unknown">{{ formatNumber(data.fees.unknown_orders) }} đơn chưa biết phí (chưa có bảng phí, chưa nhập đối soát): phí thật sẽ cao hơn</span>
                            </span>
                        </button>
                        <button type="button" class="flex w-full items-start gap-3 py-4 text-left" data-testid="finance-payout" @click="drill = 'payouts'">
                            <span class="grid size-10 shrink-0 place-items-center rounded-[10px] bg-emerald-50 text-emerald-600"><Landmark class="size-5" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-baseline justify-between gap-2">
                                    <span class="text-[14px] text-slate-500">Tiền sàn đã chuyển về</span>
                                    <b class="text-[18px] tabular-nums text-emerald-600">{{ data.payout.received === null ? 'Chưa có' : `${money(data.payout.received)} đ` }}</b>
                                </span>
                                <span class="mt-1 block text-[12px] text-slate-500">{{ formatNumber(data.payout.received_orders) }} đơn đã đối soát · không phải doanh thu mới</span>
                            </span>
                        </button>
                        <div class="flex items-start gap-3 py-4">
                            <span class="grid size-10 shrink-0 place-items-center rounded-[10px] bg-slate-100 text-slate-600"><Wallet class="size-5" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-baseline justify-between gap-2">
                                    <span class="text-[14px] text-slate-500">Chờ sàn đối soát</span>
                                    <b class="text-[18px] tabular-nums text-slate-900">{{ data.payout.pending_expected === null ? (data.payout.pending_orders ? 'Chưa xác định' : '0 đ') : `${money(data.payout.pending_expected)} đ` }}</b>
                                </span>
                                <span class="mt-1 block text-[12px] text-slate-500">
                                    {{ formatNumber(data.payout.pending_orders) }} đơn<template v-if="data.payout.pending_expected !== null"> · ước tính theo bảng phí</template><template v-else-if="data.payout.pending_orders"> · có đơn chưa biết phí</template>
                                </span>
                            </span>
                        </div>
                    </div>
                </SideCard>
            </div>

            <template v-if="profit">
                <h2 class="mb-3 text-[12px] font-semibold uppercase tracking-wider text-slate-400">Lợi nhuận ước tính · chưa phải lợi nhuận kế toán</h2>
                <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="finance-profit">
                    <ReportKpi label="Giá vốn" :icon="Package" tone="slate" :value="data.cogs === null ? null : num(data.cogs - (data.cogs_returned ?? 0))">
                        <template v-if="data.net_revenue && data.cogs !== null" #chips><ReportChip>{{ Math.round(((data.cogs - (data.cogs_returned ?? 0)) / data.net_revenue) * 100) }}% doanh thu</ReportChip></template>
                        <span v-if="data.cogs_missing_lines" class="text-amber-700">{{ data.cogs_missing_lines }} dòng hàng chưa có giá vốn, lợi nhuận đang cao hơn thực tế</span>
                        <template v-else>Xuất kho {{ money(data.cogs) }}<template v-if="(data.cogs_returned ?? 0) > 0"> − hàng trả nhập lại {{ money(data.cogs_returned) }}</template></template>
                    </ReportKpi>
                    <ReportKpi label="Lợi nhuận gộp" :tip="formulas.gross" :icon="TrendingUp" :tone="Number(data.estimated_gross_profit) < 0 ? 'red' : 'green'" :value="num(data.estimated_gross_profit)">
                        <template #chips>
                            <ReportChip v-if="changeChip(data.estimated_gross_profit, data.previous.estimated_gross_profit)" :tone="changeChip(data.estimated_gross_profit, data.previous.estimated_gross_profit)!.up ? 'up' : 'down'">
                                {{ changeChip(data.estimated_gross_profit, data.previous.estimated_gross_profit)!.text }}
                            </ReportChip>
                            <ReportChip>Biên {{ margin(data.estimated_gross_profit) ?? '—' }}%</ReportChip>
                        </template>
                        <span data-testid="gross-profit">Chưa trừ phí sàn</span>
                    </ReportKpi>
                    <ReportKpi label="Lợi nhuận đóng góp" :tip="formulas.contribution" :icon="Receipt" :tone="Number(data.contribution_profit) < 0 ? 'red' : 'green'" :value="num(data.contribution_profit)" data-testid="contribution-profit">
                        <template #chips>
                            <ReportChip v-if="changeChip(data.contribution_profit, data.previous.contribution_profit)" :tone="changeChip(data.contribution_profit, data.previous.contribution_profit)!.up ? 'up' : 'down'">
                                {{ changeChip(data.contribution_profit, data.previous.contribution_profit)!.text }}
                            </ReportChip>
                            <ReportChip>Biên {{ margin(data.contribution_profit) ?? '—' }}%</ReportChip>
                        </template>
                        Đã trừ phí sàn<template v-if="data.selling_expenses"> và chi phí bán hàng {{ money(data.selling_expenses) }}</template>
                    </ReportKpi>
                    <ReportKpi label="Lợi nhuận quản trị" :tip="formulas.profit" :icon="PiggyBank" primary :value="num(data.estimated_profit)" data-testid="other-pnl">
                        <template v-if="changeChip(data.estimated_profit, data.previous.estimated_profit)" #chips>
                            <ReportChip tone="onblue">{{ changeChip(data.estimated_profit, data.previous.estimated_profit)!.text }}</ReportChip>
                            <span class="text-blue-100">so với kỳ trước</span>
                        </template>
                        <template v-if="data.estimated_profit === null">Chỉ tính khi xem toàn doanh nghiệp (chi phí chung không chia theo shop)</template>
                        <template v-else>
                            Trừ hao hụt kho {{ money(data.stock_loss) }}, chi phí vận hành {{ money((data.other_expenses ?? 0) - (data.selling_expenses ?? 0)) }}, cộng thu khác {{ money(data.other_income) }}
                        </template>
                    </ReportKpi>
                </div>
            </template>

            <section v-if="!data.scoped" :class="cn(card, 'overflow-hidden')">
                <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                    <div>
                        <h3 class="text-[16px] font-semibold text-slate-900">Theo shop và kênh bán</h3>
                        <p class="mt-0.5 text-[13px] text-slate-500">Bấm một dòng để xem riêng shop đó.</p>
                    </div>
                    <RouterLink :to="{ name: 'report-profit-loss', query: { from, to } }" class="inline-flex items-center gap-1 text-[14px] font-medium text-blue-700 hover:underline">Báo cáo lãi lỗ <ArrowRight class="size-4" /></RouterLink>
                </header>
                <p v-if="!data.by_shop.length" class="px-6 py-6 text-[14px] text-slate-500">Chưa có đơn trong kỳ.</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-[14px]" data-testid="finance-by-shop">
                        <thead class="border-b border-slate-100 text-left text-[12px] font-semibold uppercase tracking-wider text-slate-400">
                            <tr class="[&>th]:whitespace-nowrap">
                                <th class="px-6 py-3.5">Shop / kênh</th>
                                <th class="px-3 py-3.5 text-right">Đơn đặt / hủy</th>
                                <th class="px-3 py-3.5 text-right">Doanh thu thuần</th>
                                <th v-if="profit" class="px-3 py-3.5 text-right">Giá vốn</th>
                                <th v-if="profit" class="px-3 py-3.5 text-right">Lãi gộp</th>
                                <th class="px-3 py-3.5 text-right">Phí sàn</th>
                                <th class="px-3 py-3.5 text-right">Thực nhận</th>
                                <th v-if="profit" class="px-3 py-3.5 text-right" :title="formulas.contribution">LN đóng góp</th>
                                <th v-if="profit" class="px-3 py-3.5 text-right">Biên</th>
                                <th class="px-3 py-3.5 text-right">TB/đơn</th>
                                <th class="px-6 py-3.5 text-right">So kỳ trước</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <tr v-for="s in data.by_shop" :key="s.id" class="cursor-pointer hover:bg-slate-50/70" :title="`Xem riêng ${s.shop}`" @click="shop = s.id">
                                <td class="px-6 py-3.5">
                                    <span class="flex items-center gap-3 whitespace-nowrap font-medium text-slate-900"><ChannelMark :kind="s.marketplace" :name="s.shop" /> {{ s.shop }}<ChevronRight class="size-4 text-slate-300" /></span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3.5 text-right tabular-nums">{{ formatNumber(s.ordered_orders) }}<span v-if="s.cancelled_orders" class="text-red-500"> / {{ formatNumber(s.cancelled_orders) }}</span></td>
                                <td class="whitespace-nowrap px-3 py-3.5 text-right font-semibold tabular-nums text-slate-900">{{ money(s.net_revenue) }}</td>
                                <td v-if="profit" class="whitespace-nowrap px-3 py-3.5 text-right tabular-nums">{{ money(s.cogs) }}</td>
                                <td v-if="profit" class="whitespace-nowrap px-3 py-3.5 text-right tabular-nums">{{ money(s.gross_profit) }}</td>
                                <td class="whitespace-nowrap px-3 py-3.5 text-right tabular-nums text-amber-600">
                                    {{ money(s.fees) }}<span v-if="s.fees_unknown_orders" class="block text-[11px]">+ {{ s.fees_unknown_orders }} đơn chưa biết phí</span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3.5 text-right tabular-nums" data-testid="shop-payout">{{ s.payout === null ? '—' : money(s.payout) }}</td>
                                <td v-if="profit" :class="cn('whitespace-nowrap px-3 py-3.5 text-right font-semibold tabular-nums', Number(s.profit) < 0 ? 'text-red-600' : 'text-emerald-600')">{{ money(s.profit) }}</td>
                                <td v-if="profit" class="whitespace-nowrap px-3 py-3.5 text-right tabular-nums">{{ s.margin === null ? '—' : s.margin + '%' }}</td>
                                <td class="whitespace-nowrap px-3 py-3.5 text-right tabular-nums">{{ money(s.average_order) }}</td>
                                <td class="whitespace-nowrap px-6 py-3.5 text-right">
                                    <span v-if="s.growth === null" class="text-slate-300">—</span>
                                    <span v-else :class="cn('rounded-md px-2 py-0.5 text-[13px] font-medium tabular-nums', s.growth >= 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-600')">{{ s.growth > 0 ? '+' : '' }}{{ s.growth.toLocaleString('vi-VN') }}%</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <p class="mt-5 flex items-start gap-2 text-[13px] text-slate-500">
                <Info class="mt-0.5 size-4 shrink-0 text-slate-400" />
                <span>
                    Doanh thu ghi nhận khi sàn báo đơn <b class="font-semibold text-slate-600">hoàn thành</b> (đơn website, đơn tự tạo và POS: khi giao thành công), không gồm phí ship khách trả<template v-if="data.completed.shipping > 0"> ({{ money(data.completed.shipping) }} đ tính riêng)</template>,
                    và không phải tiền thực nhận. Lợi nhuận ở đây là số ước tính để quản lý, chưa phải lợi nhuận kế toán.
                </span>
            </p>
        </div>

        <FinanceDetails :metric="drill" :params="params" @close="drill = null" />
    </div>
</template>
