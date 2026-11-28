<script setup lang="ts">
import { computed } from 'vue';
import type { RouteLocationRaw } from 'vue-router';
import { ArrowDown, ArrowLeftRight, ArrowUp, CircleDollarSign, CreditCard, Download } from 'lucide-vue-next';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api } from '@/shared/lib/api';
import { formatMoney, formatNumber } from '@/shared/lib/utils';
import { paymentMethods } from '../orders/labels';
import ReportKpi from './ReportKpi.vue';
import ReportChip from './ReportChip.vue';
import ReportAlert from './ReportAlert.vue';
import ReportLinkCard from './ReportLinkCard.vue';
import SideCard from './SideCard.vue';
import PeriodBars from './PeriodBars.vue';
import type { ReportLink } from './groups';
import { change, initials, pct, previousRange, short, useDashboard } from './dashboard';
import { downloadCsv, toCsv } from './range';

/**
 * "Báo cáo nhập hàng" (mẫu 28/11): goods received, paid to suppliers, still owed and returned to suppliers, then the
 * three reports of the menu with their figures, purchases per day and the top suppliers. Figures from
 * /reports/purchases, /reports/supplier-payments and /reports/payables (supplier debt is as of today).
 */
const props = defineProps<{ range: { from: string; to: string }; links: ReportLink[]; target: (l: ReportLink, by?: string) => RouteLocationRaw }>();

const { data, error, loading, load } = useDashboard(
    () => props.range,
    async (r) => {
        const prev = previousRange(r.from, r.to);
        const [days, suppliers, methods, payables, before] = await Promise.all([
            api.get('/reports/purchases', { params: { ...r, by: 'day' } }),
            api.get('/reports/purchases', { params: { ...r, by: 'supplier' } }),
            api.get('/reports/supplier-payments', { params: { ...r, by: 'method' } }),
            api.get('/reports/payables'),
            prev ? api.get('/reports/purchases', { params: { ...prev, by: 'day' } }).catch(() => null) : Promise.resolve(null),
        ]);
        return { days: days.data.data, suppliers: suppliers.data.data, methods: methods.data.data, payables: payables.data.data, before: before?.data.data ?? null };
    },
);

const link = (t: string) => props.links.find((l) => l.title === t);
const totals = computed(() => data.value?.days.totals ?? { receipts: 0, quantity: 0, value: 0, returned: 0, net: 0 });
const paid = computed(() => Number(data.value?.methods.totals.paid ?? 0));
const delta = computed(() => change(totals.value.net, data.value?.before?.totals.net));
const paidShare = computed(() => (totals.value.net > 0 ? (paid.value / totals.value.net) * 100 : null));
const byMethod = computed(() => ((data.value?.methods.rows ?? []) as any[]).filter((m) => Number(m.paid) > 0).map((m) => `${paymentMethods[m.label] ?? m.label} ${short(m.paid)}`).join(' · '));
const debtRows = computed(() => (data.value?.payables.rows ?? []) as any[]);
const overdueRows = computed(() => debtRows.value.filter((r) => Number(r.overdue) > 0));
const debt = computed(() => Number(data.value?.payables.totals.debt ?? 0));
const overdue = computed(() => Number(data.value?.payables.totals.overdue ?? 0));

/** Every day of the period, so quiet days show as gaps. */
const perDay = computed(() => {
    const rows = new Map(((data.value?.days.rows ?? []) as any[]).map((r) => [r.label, Number(r.net)]));
    const out: { day: string; value: number }[] = [];
    const end = new Date(`${props.range.to}T00:00:00`);
    for (let d = new Date(`${props.range.from}T00:00:00`); d <= end && out.length < 400; d.setDate(d.getDate() + 1)) {
        const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        out.push({ day: key, value: rows.get(key) ?? 0 });
    }
    return out;
});
/** Decoration of the blue card: purchases per day as a soft area. */
const spark = computed(() => {
    const v = perDay.value.map((d) => d.value);
    const max = Math.max(...v, 0);
    if (v.length < 2 || max <= 0) return null;
    return `M0,40 L${v.map((x, i) => `${(i / (v.length - 1)) * 100},${40 - (x / max) * 36}`).join(' L')} L100,40 Z`;
});

const topSuppliers = computed(() => {
    const debts = new Map(debtRows.value.map((r) => [r.supplier.id, r]));
    return ((data.value?.suppliers.rows ?? []) as any[])
        .slice()
        .sort((a, b) => Number(b.net) - Number(a.net))
        .slice(0, 5)
        .map((s) => ({ ...s, debt: s.id ? Number(debts.get(s.id)?.debt ?? 0) : null }));
});
const avatarTones = ['bg-blue-50 text-blue-600', 'bg-red-50 text-red-500', 'bg-emerald-50 text-emerald-600', 'bg-violet-50 text-violet-600', 'bg-slate-100 text-slate-600'];

function exportCsv() {
    if (!data.value) return;
    const t = totals.value;
    const rows: (string | number | null)[][] = [
        ['Báo cáo nhập hàng', `${props.range.from} – ${props.range.to}`, null],
        [],
        ['Giá trị nhập kho (đã trừ trả NCC)', t.net, null],
        ['Số phiếu nhập', t.receipts, null],
        ['Số lượng nhập', t.quantity, null],
        ['Hàng trả nhà cung cấp', t.returned, null],
        ['Đã trả nhà cung cấp', paid.value, null],
        ['Còn phải trả nhà cung cấp (hôm nay)', debt.value, null],
        ['Quá hạn', overdue.value, null],
        [],
        ['Nhà cung cấp', 'Giá trị nhập', 'Còn nợ'],
        ...((data.value.suppliers.rows ?? []) as any[]).map((s) => [s.label, s.net, s.id ? (debtRows.value.find((r) => r.supplier.id === s.id)?.debt ?? 0) : null]),
        [],
        ['Ngày', 'Giá trị nhập', null],
        ...perDay.value.filter((d) => d.value).map((d) => [d.day, d.value, null]),
    ];
    downloadCsv(`bao-cao-nhap-hang_${props.range.from}_${props.range.to}.csv`, toCsv(['Mục', 'Giá trị', ''], rows));
}
defineExpose({ exportCsv });
</script>

<template>
    <ErrorState v-if="error" :message="error" @retry="load" />
    <div v-else-if="!data" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><Skeleton v-for="i in 4" :key="i" class="h-36" /></div>
    <div v-else :class="loading && 'opacity-60'" data-testid="report-group-purchases">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <ReportKpi primary label="Giá trị nhập kho" tip="Hàng nhập kho trong kỳ theo giá nhập, đã trừ hàng trả nhà cung cấp." :icon="Download" :value="totals.net">
                <template #chips>
                    <ReportChip v-if="delta !== null" tone="onblue"><component :is="delta >= 0 ? ArrowUp : ArrowDown" class="size-3" />{{ pct(delta) }}</ReportChip>
                    <span v-if="delta !== null" class="text-blue-100">so với kỳ trước</span>
                </template>
                {{ formatNumber(totals.receipts) }} phiếu nhập · {{ formatNumber(totals.quantity) }} sản phẩm
                <template #decor>
                    <svg v-if="spark" viewBox="0 0 100 40" preserveAspectRatio="none" class="pointer-events-none absolute inset-x-0 bottom-0 h-12 w-full opacity-[0.12]" aria-hidden="true"><path :d="spark" fill="white" /></svg>
                </template>
            </ReportKpi>
            <ReportKpi label="Đã trả nhà cung cấp" tip="Các khoản đã trả nhà cung cấp trong kỳ (phiếu chi trả NCC)." :icon="CreditCard" tone="green" :value="paid">
                <template v-if="paidShare !== null" #chips><ReportChip>{{ pct(paidShare) }} giá trị nhập</ReportChip></template>
                {{ byMethod || 'Chưa có khoản trả nào trong kỳ' }}
            </ReportKpi>
            <ReportKpi label="Còn phải trả NCC" tip="Tổng công nợ nhà cung cấp tính đến hôm nay (không theo kỳ đã chọn)." :icon="CircleDollarSign" tone="amber" :value="debt">
                <template v-if="overdueRows.length" #chips><ReportChip tone="red">{{ overdueRows.length }} NCC quá hạn</ReportChip></template>
                {{ formatNumber(debtRows.length) }} nhà cung cấp còn công nợ
            </ReportKpi>
            <ReportKpi label="Hàng trả nhà cung cấp" tip="Giá trị hàng trả lại nhà cung cấp trong kỳ." :icon="ArrowLeftRight" tone="violet" plain :value="totals.returned">
                <template v-if="totals.returned" #chips><ReportChip>Đã trừ khỏi giá trị nhập</ReportChip></template>
                Trừ vào giá trị nhập kho
            </ReportKpi>
        </div>

        <ReportAlert v-if="overdue > 0 && link('Công nợ nhà cung cấp')" :to="target(link('Công nợ nhà cung cấp')!)" action="Xem công nợ">
            <b>{{ overdueRows.length }} nhà cung cấp</b> có công nợ quá hạn thanh toán
            <span class="mx-1 rounded-full border border-amber-200 bg-white px-2 py-0.5 text-xs font-medium tabular-nums">{{ formatMoney(overdue) }}</span>
            nên xử lý để giữ giá nhập tốt với nhà cung cấp.
        </ReportAlert>

        <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <div class="min-w-0 space-y-6">
                <section>
                    <h2 class="mb-3 text-[12px] font-semibold uppercase tracking-wider text-slate-400">Nhập kho</h2>
                    <ReportLinkCard
                        v-if="link('Báo cáo hàng nhập kho')"
                        :link="link('Báo cáo hàng nhập kho')!"
                        :target="target"
                        :stats="[
                            { label: 'Phiếu nhập', value: formatNumber(totals.receipts) },
                            { label: 'Số lượng', value: `${formatNumber(totals.quantity)} sp` },
                            { label: 'Giá trị', value: formatMoney(totals.value) },
                            { label: 'Trả NCC', value: formatMoney(totals.returned), tone: totals.returned ? 'violet' : undefined },
                        ]"
                    />
                </section>
                <section>
                    <h2 class="mb-3 text-[12px] font-semibold uppercase tracking-wider text-slate-400">Thanh toán và công nợ</h2>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <ReportLinkCard
                            v-if="link('Báo cáo thanh toán nhập hàng')"
                            :link="link('Báo cáo thanh toán nhập hàng')!"
                            :target="target"
                            tone="green"
                            :stats="[
                                { label: 'Đã trả', value: short(paid), tone: paid ? 'green' : undefined },
                                { label: 'Số phiếu', value: formatNumber(data.methods.totals.entries) },
                            ]"
                        />
                        <ReportLinkCard
                            v-if="link('Công nợ nhà cung cấp')"
                            :link="link('Công nợ nhà cung cấp')!"
                            :target="target"
                            tone="amber"
                            :badge="overdueRows.length ? `${overdueRows.length} quá hạn` : null"
                            :stats="[
                                { label: 'Còn phải trả', value: short(debt), tone: debt ? 'amber' : undefined },
                                { label: 'NCC', value: formatNumber(debtRows.length) },
                                ...(overdueRows.length ? [{ label: 'Quá hạn', value: formatNumber(overdueRows.length), tone: 'red' as const }] : []),
                            ]"
                        />
                    </div>
                </section>
            </div>

            <aside class="space-y-4">
                <SideCard :title="perDay.length > 10 ? 'Nhập hàng theo tuần' : 'Nhập hàng theo ngày'" description="Giá trị nhập kho, đã trừ trả NCC">
                    <PeriodBars :days="perDay" />
                </SideCard>
                <SideCard title="Top nhà cung cấp" description="Theo giá trị nhập trong kỳ">
                    <ul v-if="topSuppliers.length" class="divide-y divide-slate-100 px-5">
                        <li v-for="(s, i) in topSuppliers" :key="s.label" class="flex items-center gap-3 py-3">
                            <span :class="['grid size-9 shrink-0 place-items-center rounded-[10px] text-xs font-semibold', avatarTones[i % avatarTones.length]]">{{ initials(s.label) }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-[14px] font-medium text-slate-800">{{ s.label }}</p>
                                <p class="text-xs text-slate-500">{{ formatNumber(s.receipts) }} phiếu · {{ formatNumber(s.quantity) }} sp</p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-semibold tabular-nums text-slate-900">{{ formatNumber(s.net) }}</p>
                                <p v-if="s.debt !== null" class="text-xs tabular-nums text-slate-500">{{ s.debt > 0 ? `còn nợ ${short(s.debt)}` : 'đã trả đủ' }}</p>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="p-5 text-sm text-slate-500">Chưa nhập hàng trong kỳ.</p>
                </SideCard>
            </aside>
        </div>
    </div>
</template>
