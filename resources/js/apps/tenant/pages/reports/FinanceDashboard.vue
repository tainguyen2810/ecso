<script setup lang="ts">
import { computed } from 'vue';
import type { RouteLocationRaw } from 'vue-router';
import { ArrowDown, ArrowUp, ChartColumn, CircleDollarSign, TrendingUp, WalletCards } from 'lucide-vue-next';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import ReportKpi from './ReportKpi.vue';
import ReportChip from './ReportChip.vue';
import ReportLinkCard from './ReportLinkCard.vue';
import SideCard from './SideCard.vue';
import type { ReportLink } from './groups';
import { change, pct, short, useDashboard } from './dashboard';
import { downloadCsv, toCsv } from './range';

/**
 * "Báo cáo tài chính" (mẫu 28/11): net revenue and estimated profit (Báo cáo lãi lỗ), money in the funds at the end of
 * the period (Sổ quỹ), what customers owe; the reports of the menu with their figures, the profit and loss in short and
 * money in and out per week. Debts are as of today.
 */
const props = defineProps<{ range: { from: string; to: string }; links: ReportLink[]; target: (l: ReportLink, by?: string) => RouteLocationRaw }>();
const tenant = useTenantStore();

const { data, error, loading, load } = useDashboard(
    () => props.range,
    async (r) => {
        const profit = tenant.can('finance.profit');
        const payables = tenant.can('purchasing.view');
        const [pl, book, accounts, receivables, debts] = await Promise.all([
            profit ? api.get('/reports/profit-loss', { params: r }) : Promise.resolve(null),
            api.get('/reports/cashbook', { params: r }),
            api.get('/cash-accounts').catch(() => null),
            api.get('/reports/receivables'),
            payables ? api.get('/reports/payables').catch(() => null) : Promise.resolve(null),
        ]);
        return {
            pl: pl?.data.data ?? null,
            book: book.data.data,
            accounts: (accounts?.data.data ?? []) as any[],
            receivables: receivables.data.data,
            payables: debts?.data.data ?? null,
        };
    },
);

const link = (t: string) => props.links.find((l) => l.title === t);
const lines = computed(() => new Map(((data.value?.pl?.lines ?? []) as any[]).map((l) => [l.key, l])));
const line = (k: string) => Number(lines.value.get(k)?.current ?? 0);
const lineChange = (k: string) => (lines.value.get(k)?.change ?? null) as number | null;
const hasPl = computed(() => !!data.value?.pl);
const margin = computed(() => (line('revenue') > 0 ? (line('profit') / line('revenue')) * 100 : null));
const fundsDelta = computed(() => change(data.value?.book.closing, data.value?.book.opening));
const fundsNow = computed(() => {
    const sum = (kinds: string[]) => (data.value?.accounts ?? []).filter((a) => a.is_active && kinds.includes(a.kind)).reduce((t, a) => t + Number(a.balance ?? 0), 0);
    return { cash: sum(['cash']), bank: sum(['bank', 'ewallet']) };
});
const overdueSuppliers = computed(() => ((data.value?.payables?.rows ?? []) as any[]).filter((r) => Number(r.overdue) > 0).length);

/** Profit and loss in short: main lines always, the others only when they hold money. */
const summary = computed(() => {
    if (!hasPl.value) return [];
    const rows: { label: string; value: number; sign: '' | '−' | '+'; strong?: boolean }[] = [
        { label: 'Tiền hàng bán ra', value: line('goods'), sign: '' },
        { label: 'Chiết khấu', value: line('discount'), sign: '−' },
        { label: 'Hàng trả lại', value: line('returns'), sign: '−' },
        { label: 'Doanh thu thuần', value: line('revenue'), sign: '', strong: true },
        { label: 'Giá vốn', value: line('cogs'), sign: '−' },
        { label: 'Lợi nhuận gộp', value: line('gross_profit'), sign: '', strong: true },
        { label: 'Phí sàn', value: line('marketplace_fees'), sign: '−' },
        { label: 'Phí giao hàng thu khách', value: line('shipping'), sign: '+' },
        { label: 'Chi phí bán hàng', value: line('selling_expenses'), sign: '−' },
        { label: 'Hao hụt kho', value: line('stock_loss'), sign: '−' },
        { label: 'Chi phí vận hành, chi phí khác', value: line('operating_expenses') + line('misc_expenses'), sign: '−' },
        { label: 'Thu nhập khác', value: line('other_income'), sign: '+' },
    ];
    const always = ['Doanh thu thuần', 'Giá vốn', 'Lợi nhuận gộp', 'Phí sàn', 'Chi phí vận hành, chi phí khác'];
    return rows.filter((r) => r.strong || always.includes(r.label) || r.value !== 0);
});

const costs = computed(() => line('selling_expenses') + line('stock_loss') + line('operating_expenses') + line('misc_expenses'));
const oldestDebt = computed(() => {
    const ages = ((data.value?.receivables.rows ?? []) as any[]).map((r) => r.age_days).filter((d): d is number => d !== null && d !== undefined);
    return ages.length ? Math.max(...ages) : null;
});
const weeks = computed(() => (data.value?.book.weeks ?? []) as { from: string; to: string; in: number; out: number }[]);
const weekMax = computed(() => Math.max(...weeks.value.flatMap((w) => [w.in, w.out]), 0));
const dm = (d: string) => `${d.slice(8, 10)}/${d.slice(5, 7)}`;

function exportCsv() {
    if (!data.value) return;
    const b = data.value.book;
    const rows: (string | number | null)[][] = [
        ['Báo cáo tài chính', `${props.range.from} – ${props.range.to}`],
        [],
        ...summary.value.map((r) => [`${r.sign ? `${r.sign} ` : ''}${r.label}`, r.value]),
        ...(hasPl.value ? [['Lợi nhuận ước tính', line('profit')]] : []),
        [],
        ['Tồn quỹ đầu kỳ', b.opening],
        ['Thu trong kỳ', b.in],
        ['Chi trong kỳ', b.out],
        ['Tồn quỹ cuối kỳ', b.closing],
        [],
        ['Khách còn nợ (hôm nay)', data.value.receivables.totals.due],
        ...(data.value.payables ? [['Phải trả nhà cung cấp (hôm nay)', data.value.payables.totals.debt]] : []),
        [],
        ['Tuần', 'Thu', 'Chi'],
        ...weeks.value.map((w) => [`${w.from} – ${w.to}`, w.in, w.out]),
    ];
    downloadCsv(`bao-cao-tai-chinh_${props.range.from}_${props.range.to}.csv`, toCsv(['Mục', 'Giá trị'], rows as any));
}
defineExpose({ exportCsv });
</script>

<template>
    <ErrorState v-if="error" :message="error" @retry="load" />
    <div v-else-if="!data" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><Skeleton v-for="i in 4" :key="i" class="h-36" /></div>
    <div v-else :class="loading && 'opacity-60'" data-testid="report-group-finance">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <ReportKpi v-if="hasPl" primary label="Doanh thu thuần" tip="Cùng con số với Báo cáo lãi lỗ và Báo cáo bán hàng: đơn đã hoàn thành trong kỳ, trừ chiết khấu và hàng trả." :icon="ChartColumn" :value="line('revenue')">
                <template v-if="lineChange('revenue') !== null" #chips>
                    <ReportChip tone="onblue"><component :is="lineChange('revenue')! >= 0 ? ArrowUp : ArrowDown" class="size-3" />{{ pct(lineChange('revenue')!) }}</ReportChip>
                    <span class="text-blue-100">so với kỳ trước</span>
                </template>
                Đã trừ chiết khấu và hàng trả lại
            </ReportKpi>
            <ReportKpi v-if="hasPl" label="Lợi nhuận ước tính" tip="Lợi nhuận quản trị ước tính của Báo cáo lãi lỗ: đã trừ giá vốn, phí sàn, chi phí và hao hụt kho." :icon="TrendingUp" :tone="line('profit') < 0 ? 'red' : 'green'" :value="line('profit')">
                <template #chips>
                    <ReportChip v-if="lineChange('profit') !== null" :tone="lineChange('profit')! >= 0 ? 'up' : 'down'"><component :is="lineChange('profit')! >= 0 ? ArrowUp : ArrowDown" class="size-3" />{{ pct(lineChange('profit')!) }}</ReportChip>
                    <ReportChip v-if="margin !== null">Biên {{ margin.toLocaleString('vi-VN', { maximumFractionDigits: 1 }) }}%</ReportChip>
                </template>
                Đã trừ giá vốn, phí sàn và chi phí
            </ReportKpi>
            <ReportKpi :primary="!hasPl" label="Tồn quỹ cuối kỳ" tip="Tiền trong các quỹ (tiền mặt, ngân hàng, ví…) cuối kỳ, theo Sổ quỹ." :icon="WalletCards" tone="blue" plain :value="data.book.closing">
                <template v-if="fundsDelta !== null" #chips>
                    <ReportChip :tone="!hasPl ? 'onblue' : fundsDelta >= 0 ? 'up' : 'down'"><component :is="fundsDelta >= 0 ? ArrowUp : ArrowDown" class="size-3" />{{ pct(fundsDelta) }} so với đầu kỳ</ReportChip>
                </template>
                Hiện có: tiền mặt {{ short(fundsNow.cash) }} · ngân hàng {{ short(fundsNow.bank) }}
            </ReportKpi>
            <ReportKpi label="Khách còn nợ" tip="Tổng khách còn nợ tính đến hôm nay (không theo kỳ đã chọn)." :icon="CircleDollarSign" tone="amber" :value="data.receivables.totals.due">
                <template #chips><ReportChip tone="amber">{{ formatNumber(data.receivables.totals.customers) }} khách</ReportChip></template>
                <template v-if="data.payables">Phải trả NCC: {{ formatMoney(data.payables.totals.debt) }}</template>
                <template v-else>{{ formatNumber(data.receivables.totals.orders) }} đơn còn nợ</template>
            </ReportKpi>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <div class="min-w-0 space-y-6">
                <section v-if="link('Báo cáo lãi lỗ')">
                    <h2 class="mb-3 text-[12px] font-semibold uppercase tracking-wider text-slate-400">Kết quả kinh doanh</h2>
                    <ReportLinkCard
                        :link="link('Báo cáo lãi lỗ')!"
                        :target="target"
                        tone="green"
                        :stats="[
                            { label: 'Doanh thu thuần', value: formatMoney(line('revenue')) },
                            { label: 'Giá vốn', value: formatMoney(line('cogs')) },
                            { label: 'Phí sàn', value: formatMoney(line('marketplace_fees')) },
                            { label: 'Chi phí', value: formatMoney(costs) },
                            { label: 'Lợi nhuận', value: formatMoney(line('profit')), tone: line('profit') < 0 ? 'red' : 'green' },
                        ]"
                    />
                </section>
                <section>
                    <h2 class="mb-3 text-[12px] font-semibold uppercase tracking-wider text-slate-400">Dòng tiền</h2>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <ReportLinkCard v-if="link('Sổ quỹ')" :link="link('Sổ quỹ')!" :target="target" :stats="[{ label: 'Đầu kỳ', value: short(data.book.opening) }, { label: 'Cuối kỳ', value: short(data.book.closing), tone: 'green' }]" />
                        <ReportLinkCard v-if="link('Thu chi theo loại')" :link="link('Thu chi theo loại')!" :target="target" tone="violet" :stats="[{ label: 'Thu', value: short(data.book.in), tone: 'green' }, { label: 'Chi', value: short(data.book.out), tone: 'red' }]" />
                    </div>
                </section>
                <section>
                    <h2 class="mb-3 text-[12px] font-semibold uppercase tracking-wider text-slate-400">Công nợ</h2>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <ReportLinkCard v-if="link('Công nợ khách hàng')" :link="link('Công nợ khách hàng')!" :target="target" tone="amber" :stats="[{ label: 'Còn nợ', value: short(data.receivables.totals.due), tone: 'amber' }, { label: 'Khách', value: formatNumber(data.receivables.totals.customers) }, ...(oldestDebt !== null ? [{ label: 'Lâu nhất', value: `${formatNumber(oldestDebt)} ngày` }] : [])]" />
                        <ReportLinkCard
                            v-if="link('Công nợ nhà cung cấp') && data.payables"
                            :link="link('Công nợ nhà cung cấp')!"
                            :target="target"
                            tone="red"
                            :badge="overdueSuppliers ? `${overdueSuppliers} quá hạn` : null"
                            :stats="[{ label: 'Phải trả', value: short(data.payables.totals.debt), tone: 'red' }, { label: 'NCC', value: formatNumber(data.payables.totals.suppliers) }]"
                        />
                    </div>
                </section>
            </div>

            <aside class="space-y-4">
                <SideCard v-if="hasPl" title="Lãi lỗ tóm tắt" :description="`${dm(range.from)} – ${range.to.split('-').reverse().join('/')}`">
                    <dl class="px-5 py-2 text-sm">
                        <div v-for="r in summary" :key="r.label" :class="cn('flex items-center justify-between gap-3 border-b border-slate-100 py-2.5', r.strong && 'font-semibold text-slate-900')">
                            <dt :class="r.strong ? '' : 'text-slate-600'">{{ r.sign && !r.strong ? `${r.sign} ` : '' }}{{ r.label }}</dt>
                            <dd :class="cn('tabular-nums', !r.strong && r.sign === '−' && r.value ? 'text-red-600' : '', !r.strong && r.sign === '+' && r.value ? 'text-emerald-600' : '')">{{ r.sign === '−' && r.value ? '−' : '' }}{{ formatNumber(r.value) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 py-3">
                            <dt class="text-[15px] text-slate-700">Lợi nhuận ước tính</dt>
                            <dd :class="cn('text-lg font-bold tabular-nums', line('profit') < 0 ? 'text-red-600' : 'text-emerald-600')">{{ formatMoney(line('profit')) }}</dd>
                        </div>
                    </dl>
                </SideCard>
                <SideCard title="Thu chi theo tuần" description="Tiền vào, tiền ra các quỹ và chênh lệch">
                    <div class="space-y-3 px-5 py-4">
                        <div v-for="(w, i) in weeks" :key="w.from" class="flex items-center gap-3" :title="`${dm(w.from)} – ${dm(w.to)}: thu ${formatMoney(w.in)}, chi ${formatMoney(w.out)}`">
                            <span class="w-14 shrink-0 text-[13px] text-slate-600">Tuần {{ i + 1 }}</span>
                            <div class="min-w-0 flex-1 space-y-1">
                                <div class="h-2 rounded-full bg-emerald-500" :style="{ width: `${weekMax ? Math.max(w.in ? 2 : 0, (w.in / weekMax) * 100) : 0}%` }" />
                                <div class="h-2 rounded-full bg-rose-400" :style="{ width: `${weekMax ? Math.max(w.out ? 2 : 0, (w.out / weekMax) * 100) : 0}%` }" />
                            </div>
                            <span :class="cn('w-14 shrink-0 text-right text-[13px] font-medium tabular-nums', w.in - w.out < 0 ? 'text-red-600' : 'text-emerald-600')">{{ w.in - w.out > 0 ? '+' : w.in - w.out < 0 ? '−' : '' }}{{ short(Math.abs(w.in - w.out)) }}</span>
                        </div>
                        <p v-if="!weeks.length" class="text-sm text-slate-500">Chưa có thu chi trong kỳ.</p>
                        <div class="flex gap-4 pt-1 text-xs text-slate-600">
                            <span class="flex items-center gap-1.5"><i class="size-2.5 rounded-sm bg-emerald-500" />Thu {{ short(data.book.in) }}</span>
                            <span class="flex items-center gap-1.5"><i class="size-2.5 rounded-sm bg-rose-400" />Chi {{ short(data.book.out) }}</span>
                        </div>
                    </div>
                </SideCard>
            </aside>
        </div>
    </div>
</template>
