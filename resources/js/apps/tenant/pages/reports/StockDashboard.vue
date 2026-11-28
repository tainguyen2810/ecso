<script setup lang="ts">
import { computed } from 'vue';
import type { RouteLocationRaw } from 'vue-router';
import { ArrowDown, ArrowUp, Layers, SquareCheckBig, TrendingDown, TrendingUp } from 'lucide-vue-next';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api } from '@/shared/lib/api';
import { formatMoney, formatNumber } from '@/shared/lib/utils';
import ReportKpi from './ReportKpi.vue';
import ReportChip from './ReportChip.vue';
import ReportAlert from './ReportAlert.vue';
import ReportLinkCard from './ReportLinkCard.vue';
import SideCard from './SideCard.vue';
import type { ReportLink } from './groups';
import { change, initials, pct, previousRange, short, useDashboard } from './dashboard';
import { downloadCsv, toCsv } from './range';

/**
 * "Báo cáo kho" (mẫu 28/11): stock value at the end of the period, sellable stock, SKUs under and over their limits,
 * the reports of the menu with their figures, what to reorder and stock per warehouse. Sellable, limits and expiry are
 * as of today; value is at the end of the chosen period.
 */
const props = defineProps<{ range: { from: string; to: string }; links: ReportLink[]; target: (l: ReportLink, by?: string) => RouteLocationRaw }>();

const { data, error, loading, load } = useDashboard(
    () => props.range,
    async (r) => {
        const prev = previousRange(r.from, r.to);
        const [onHand, detail, low, over, expiry, ledger, adjustments, before] = await Promise.all([
            api.get('/reports/stock-on-hand', { params: { date: r.to } }),
            api.get('/reports/stock-detail'),
            api.get('/reports/low-stock'),
            api.get('/reports/over-stock'),
            api.get('/reports/expiry', { params: { by: 'd30' } }),
            api.get('/reports/stock-ledger', { params: r }),
            api.get('/reports/adjustments', { params: { ...r, by: 'document' } }),
            prev ? api.get('/reports/stock-on-hand', { params: { date: prev.to } }).catch(() => null) : Promise.resolve(null),
        ]);
        return {
            onHand: onHand.data.data,
            detail: detail.data.data,
            low: low.data.data,
            over: over.data.data,
            expiry: expiry.data.data,
            ledger: ledger.data.data,
            adjustments: adjustments.data.data,
            before: before?.data.data ?? null,
        };
    },
);

const link = (t: string) => props.links.find((l) => l.title === t);
const value = computed(() => Number(data.value?.onHand.totals.value ?? 0));
const delta = computed(() => change(value.value, data.value?.before?.totals.value));
const warehouses = computed(() => ((data.value?.onHand.warehouses ?? []) as any[]).filter((w) => Number(w.quantity) !== 0 || Number(w.value) !== 0));
const detail = computed(() => data.value?.detail.totals ?? { available: 0, reserved: 0, incoming: 0 });
const lowCount = computed(() => Number(data.value?.low.totals.skus ?? 0));
const outCount = computed(() => Number(data.value?.low.totals.out_of_stock ?? 0));
const overCount = computed(() => Number(data.value?.over.totals.skus ?? 0));
const expiringLots = computed(() => Number(data.value?.expiry.totals.expired.lots ?? 0) + Number(data.value?.expiry.totals.d30.lots ?? 0));
const moved = computed(() => ((data.value?.ledger.rows ?? []) as any[]).filter((r) => Number(r.in) || Number(r.out)).length);
const reorder = computed(() => ((data.value?.low.rows ?? []) as any[]).slice().sort((a, b) => Number(b.shortfall) - Number(a.shortfall)).slice(0, 5));
const avatarTones = ['bg-blue-50 text-blue-600', 'bg-red-50 text-red-500', 'bg-violet-50 text-violet-600', 'bg-emerald-50 text-emerald-600', 'bg-amber-50 text-amber-600'];

function exportCsv() {
    if (!data.value) return;
    const rows: (string | number | null)[][] = [
        ['Báo cáo kho', `${props.range.from} – ${props.range.to}`, null],
        [],
        [`Giá trị tồn kho ngày ${props.range.to}`, value.value, null],
        ['Số lượng tồn', data.value.onHand.totals.quantity, null],
        ['Có thể bán (hôm nay)', detail.value.available, null],
        ['Đang giữ cho đơn', detail.value.reserved, null],
        ['Đang về từ đơn nhập', detail.value.incoming, null],
        ['SKU dưới định mức', lowCount.value, null],
        ['SKU vượt định mức', overCount.value, null],
        ['Lô hết hạn hoặc hết hạn trong 30 ngày', expiringLots.value, null],
        ['Nhập trong kỳ', data.value.ledger.totals.in, null],
        ['Xuất trong kỳ', data.value.ledger.totals.out, null],
        [],
        ['Kho', 'Số lượng', 'Giá trị'],
        ...warehouses.value.map((w) => [w.name, w.quantity, w.value]),
        [],
        ['Cần nhập thêm (SKU)', 'Có thể bán', 'Gợi ý nhập'],
        ...((data.value.low.rows ?? []) as any[]).map((r) => [`${r.variant.name} (${r.variant.sku})`, r.available, r.shortfall]),
    ];
    downloadCsv(`bao-cao-kho_${props.range.from}_${props.range.to}.csv`, toCsv(['Mục', 'Giá trị', ''], rows));
}
defineExpose({ exportCsv });
</script>

<template>
    <ErrorState v-if="error" :message="error" @retry="load" />
    <div v-else-if="!data" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><Skeleton v-for="i in 4" :key="i" class="h-36" /></div>
    <div v-else :class="loading && 'opacity-60'" data-testid="report-group-stock">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <ReportKpi label="Giá trị tồn kho" :tip="`Tồn kho cuối ngày ${range.to.split('-').reverse().join('/')} của mọi kho, theo giá vốn.`" :icon="Layers" tone="blue" plain :value="value">
                <template #chips>
                    <ReportChip v-if="delta !== null" :tone="delta >= 0 ? 'up' : 'down'"><component :is="delta >= 0 ? ArrowUp : ArrowDown" class="size-3" />{{ pct(delta) }}</ReportChip>
                    <ReportChip>{{ warehouses.length }} kho</ReportChip>
                </template>
                {{ formatNumber(data.onHand.totals.quantity) }} sản phẩm · {{ formatNumber(data.onHand.totals.skus) }} SKU
            </ReportKpi>
            <ReportKpi label="Có thể bán" tip="Tồn thực tế trừ hàng đang giữ cho đơn, tính đến hôm nay." :icon="SquareCheckBig" tone="green" unit="sp" :value="detail.available">
                <template #chips><ReportChip>Đang giữ cho đơn {{ formatNumber(detail.reserved) }}</ReportChip></template>
                Đang về từ đơn nhập: {{ formatNumber(detail.incoming) }} sp
            </ReportKpi>
            <ReportKpi label="Dưới định mức" tip="SKU có thể bán đã chạm tồn an toàn đặt ở sản phẩm." :icon="TrendingDown" :tone="lowCount ? 'red' : 'slate'" unit="SKU" :value="lowCount">
                <template v-if="lowCount" #chips><ReportChip tone="amber">{{ outCount ? `${outCount} SKU hết hàng` : 'Cần nhập ngay' }}</ReportChip></template>
                {{ lowCount ? 'Có gợi ý số lượng nhập' : 'Chưa có SKU nào chạm tồn an toàn' }}
            </ReportKpi>
            <ReportKpi label="Vượt định mức" tip="SKU tồn nhiều hơn tồn tối đa đặt ở sản phẩm." :icon="TrendingUp" :tone="overCount ? 'amber' : 'slate'" unit="SKU" :value="overCount">
                <template v-if="overCount" #chips><ReportChip>Vốn chôn {{ short(data.over.totals.excess_value) }}</ReportChip></template>
                {{ overCount ? 'Nên đẩy bán hoặc giảm nhập' : 'Không có SKU nào vượt tồn tối đa' }}
            </ReportKpi>
        </div>

        <ReportAlert v-if="(lowCount || expiringLots) && link('Tồn kho dưới định mức')" :to="target(link('Tồn kho dưới định mức')!)" action="Xem gợi ý nhập hàng">
            <template v-if="lowCount"><b>{{ formatNumber(lowCount) }} SKU</b> đã chạm tồn an toàn</template>
            <template v-if="lowCount && expiringLots"> và </template>
            <template v-if="expiringLots"><b>{{ formatNumber(expiringLots) }} lô hàng</b> đã hoặc sắp hết hạn trong 30 ngày tới</template>.
        </ReportAlert>

        <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <div class="min-w-0 space-y-6">
                <section>
                    <h2 class="mb-3 text-xs font-bold uppercase tracking-wider text-slate-500">Tồn kho</h2>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <ReportLinkCard v-if="link('Báo cáo tồn kho')" :link="link('Báo cáo tồn kho')!" :target="target" :stats="[{ label: 'Giá trị', value: short(value) }, { label: 'SKU', value: formatNumber(data.onHand.totals.skus) }]" />
                        <ReportLinkCard v-if="link('Báo cáo tồn kho chi tiết')" :link="link('Báo cáo tồn kho chi tiết')!" :target="target" :stats="[{ label: 'Có thể bán', value: formatNumber(detail.available) }, { label: 'Đang về', value: formatNumber(detail.incoming) }]" />
                        <ReportLinkCard v-if="link('Xuất nhập tồn')" :link="link('Xuất nhập tồn')!" :target="target" tone="violet" :stats="[{ label: 'Nhập', value: formatNumber(data.ledger.totals.in) }, { label: 'Xuất', value: formatNumber(data.ledger.totals.out) }]" />
                        <ReportLinkCard v-if="link('Sổ kho')" :link="link('Sổ kho')!" :target="target" tone="slate" :stats="[{ label: 'SKU có biến động', value: formatNumber(moved) }]" />
                    </div>
                </section>
                <section>
                    <h2 class="mb-3 text-xs font-bold uppercase tracking-wider text-slate-500">Cảnh báo & kiểm kê</h2>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <ReportLinkCard v-if="link('Tồn kho dưới định mức')" :link="link('Tồn kho dưới định mức')!" :target="target" tone="red" :badge="lowCount ? String(lowCount) : null" />
                        <ReportLinkCard v-if="link('Tồn kho vượt định mức')" :link="link('Tồn kho vượt định mức')!" :target="target" tone="amber" :badge="overCount ? String(overCount) : null" badge-tone="amber" />
                        <ReportLinkCard v-if="link('Hàng sắp hết hạn')" :link="link('Hàng sắp hết hạn')!" :target="target" tone="rose" :badge="expiringLots ? `${expiringLots} lô` : null" />
                        <ReportLinkCard
                            v-if="link('Báo cáo kiểm kê hàng hóa')"
                            :link="link('Báo cáo kiểm kê hàng hóa')!"
                            :target="target"
                            tone="green"
                            :stats="data.adjustments.totals.documents ? [{ label: 'Phiếu', value: formatNumber(data.adjustments.totals.documents) }, { label: 'Giá trị lệch', value: short(data.adjustments.totals.value) }] : []"
                        />
                    </div>
                </section>
            </div>

            <aside class="space-y-4">
                <SideCard title="Cần nhập thêm" description="SKU dưới định mức, sắp hết" :badge="lowCount ? `${lowCount} SKU` : null">
                    <ul v-if="reorder.length" class="divide-y divide-slate-100 px-5">
                        <li v-for="(r, i) in reorder" :key="`${r.variant.id}-${r.warehouse.id}`" class="flex items-center gap-3 py-3">
                            <span :class="['grid size-9 shrink-0 place-items-center rounded-[10px] text-xs font-bold', avatarTones[i % avatarTones.length]]">{{ initials(r.variant.name) }}</span>
                            <div class="min-w-0 flex-1">
                                <RouterLink :to="`/inventory/sku/${r.variant.id}`" class="block truncate text-sm font-medium text-slate-800 hover:text-blue-600">{{ r.variant.name }}</RouterLink>
                                <p class="text-xs text-slate-500">Còn {{ formatNumber(r.available) }} · định mức {{ formatNumber(r.safety_stock) }}<template v-if="warehouses.length > 1"> · {{ r.warehouse.name }}</template></p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-semibold tabular-nums text-blue-600">+{{ formatNumber(Math.max(1, r.shortfall)) }}</p>
                                <p class="text-xs text-blue-600">gợi ý nhập</p>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="p-5 text-sm text-slate-500">Chưa có SKU nào chạm tồn an toàn.</p>
                </SideCard>
                <SideCard title="Tồn theo kho" description="Giá trị tồn cuối kỳ">
                    <ul v-if="warehouses.length" class="divide-y divide-slate-100 px-5">
                        <li v-for="(w, i) in warehouses" :key="w.id" class="flex items-center gap-3 py-3">
                            <span :class="['grid size-9 shrink-0 place-items-center rounded-[10px] text-xs font-bold', i === 0 ? 'bg-emerald-50 text-emerald-600' : 'bg-blue-50 text-blue-600']">{{ initials(w.name) }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-slate-800">{{ w.name }}</p>
                                <p class="text-xs text-slate-500">{{ formatNumber(w.quantity) }} sp</p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-semibold tabular-nums text-slate-900">{{ formatNumber(w.value) }}</p>
                                <p class="text-xs tabular-nums text-slate-500">{{ value ? Math.round((Number(w.value) / value) * 100) : 0 }}%</p>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="p-5 text-sm text-slate-500">Chưa có hàng tồn.</p>
                </SideCard>
            </aside>
        </div>
    </div>
</template>
