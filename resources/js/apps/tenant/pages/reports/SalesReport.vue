<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Banknote, Download, Info, Package, ShoppingCart, TrendingUp } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import RangePicker from './RangePicker.vue';
import ReportsNav from './ReportsNav.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import KpiCard from '@/shared/ui/KpiCard.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { channelTypes, marketplaceNames } from '../orders/labels';
import { downloadCsv, toCsv } from './range';
import { useReportQuery } from './useReportQuery';

interface Row {
    label: string;
    kind: string | null;
    orders: number;
    units: number;
    revenue: number;
    goods: number;
    // Left out by the server without the "xem giá vốn & lợi nhuận" permission.
    cogs?: number;
    gross_profit?: number;
    margin?: number | null;
    missing_cost_lines?: number;
    shipping?: number;
    // "Theo kênh" only (Đợt 11): marketplace fees and the profit left after them.
    fees?: number;
    fees_confirmed_orders?: number;
    fees_unknown_orders?: number;
    profit_after_fees?: number;
    margin_after_fees?: number | null;
}
interface SalesData {
    rows: Row[];
    totals: Row;
    excluded: { orders: number; revenue: number };
    awaiting: { orders: number; goods: number };
    profit_hidden: boolean;
}

const { range, param } = useReportQuery();
type By = 'day' | 'channel' | 'staff' | 'customer' | 'warehouse';
const TABS: Record<By, string> = { day: 'Theo ngày', channel: 'Theo kênh', staff: 'Theo nhân viên', customer: 'Theo khách hàng', warehouse: 'Theo kho' };
const byParam = param('by', 'day');
const by = computed<By>({ get: () => (byParam.value in TABS ? (byParam.value as By) : 'day'), set: (v) => (byParam.value = v) });
const data = ref<SalesData | null>(null);
const error = ref<string | null>(null);
const loading = ref(false);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        data.value = (await api.get('/reports/sales', { params: { ...range.value, by: by.value } })).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
watch(() => [range.value.from, range.value.to, by.value], load);

const canProfit = computed(() => !data.value?.profit_hidden);
/** Phí sàn and Lãi sau phí sàn columns: the channel tab, for members who see profit. */
const showFees = computed(() => by.value === 'channel' && canProfit.value && data.value?.totals.fees !== undefined);
const maxRevenue = computed(() => Math.max(1, ...(data.value?.rows.map((r) => r.revenue) ?? [1])));
const firstColumn = computed(() => ({ day: 'Ngày', channel: 'Kênh bán', staff: 'Nhân viên', customer: 'Khách hàng', warehouse: 'Kho' })[by.value]);

function label(r: Row): string {
    if (by.value === 'day') {
        const [y, m, d] = r.label.split('-');
        return `${d}/${m}/${y}`;
    }
    return r.label;
}
function kindLabel(kind: string | null): string | null {
    if (!kind) return null;
    return marketplaceNames[kind] ?? channelTypes[kind] ?? kind;
}

function exportCsv() {
    if (!data.value) return;
    const profit = canProfit.value;
    downloadCsv(
        `ban-hang-${by.value}-${range.value.from}-${range.value.to}.csv`,
        toCsv(
            [firstColumn.value, 'Số đơn', 'Số lượng', 'Doanh thu', 'Tiền hàng', ...(profit ? ['Giá vốn', 'Lợi nhuận gộp', 'Biên LN %'] : []), ...(showFees.value ? ['Phí sàn', 'Phí giao khách trả', 'Lãi sau phí sàn', 'Biên sau phí %'] : [])],
            data.value.rows.map((r) => [
                label(r), r.orders, r.units, r.revenue, r.goods,
                ...(profit ? [r.cogs ?? 0, r.gross_profit ?? 0, r.margin ?? null] : []),
                ...(showFees.value ? [r.fees ?? 0, r.shipping ?? 0, r.profit_after_fees ?? 0, r.margin_after_fees ?? null] : []),
            ]),
        ),
    );
}
</script>

<template>
    <PageHeader title="Bán hàng & lợi nhuận" description="Doanh thu đã ghi nhận, giá vốn và lợi nhuận gộp trên mọi kênh bán: sàn và kênh riêng.">
        <RangePicker :from="range.from" :to="range.to" @update:from="(v) => (range = { ...range, from: v })" @update:to="(v) => (range = { ...range, to: v })" />
    </PageHeader>
    <ReportsNav />

    <ErrorState v-if="error" :message="error" @retry="load" />
    <div v-else-if="!data" class="grid gap-4 md:grid-cols-4"><Skeleton v-for="i in 4" :key="i" class="h-24" /></div>
    <template v-else>
        <div class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-testid="sales-kpis">
            <KpiCard label="Đơn hàng" :icon="ShoppingCart" :value="formatNumber(data.totals.orders)">{{ formatNumber(data.totals.units) }} sản phẩm</KpiCard>
            <KpiCard label="Doanh thu (gồm phí giao)" :icon="Banknote" tone="blue" :value="formatMoney(data.totals.revenue)">Tiền hàng {{ formatMoney(data.totals.goods) }}</KpiCard>
            <template v-if="canProfit">
                <KpiCard label="Giá vốn" :icon="Package" :value="formatMoney(data.totals.cogs ?? 0)" />
                <KpiCard label="Lợi nhuận gộp" :icon="TrendingUp" :tone="(data.totals.gross_profit ?? 0) < 0 ? 'red' : 'green'" :value="formatMoney(data.totals.gross_profit ?? 0)">Biên {{ data.totals.margin ?? '—' }}%</KpiCard>
            </template>
        </div>
        <p class="mb-4 flex items-start gap-2 rounded-md border bg-muted/40 px-3 py-2 text-xs text-muted-foreground" data-testid="sales-basis">
            <Info class="mt-0.5 size-3.5 shrink-0" />
            <span>
                Tính các đơn đã ghi nhận doanh thu trong kỳ, giống trang Tài chính: đơn sàn khi sàn báo hoàn thành, đơn kênh riêng khi giao xong, bán tại quầy khi bán.
                <template v-if="data.awaiting.orders">{{ formatNumber(data.awaiting.orders) }} đơn sàn đã giao nhưng sàn chưa hoàn thành ({{ formatMoney(data.awaiting.goods) }} tiền hàng) chưa được tính.</template>
            </span>
        </p>
        <p v-if="showFees" class="mb-4 flex items-start gap-2 rounded-md border bg-muted/40 px-3 py-2 text-xs text-muted-foreground" data-testid="channel-fees-note">
            <Info class="mt-0.5 size-3.5 shrink-0" />
            <span>Lãi sau phí sàn = lợi nhuận gộp − phí sàn + phí giao khách trả, giống "Lợi nhuận đóng góp" ở trang Tài chính của từng shop. Phí sàn lấy theo đối soát của sàn; đơn chưa đối soát thì ước tính theo bảng phí của shop. Kênh bán riêng không có phí sàn.</span>
        </p>
        <p v-if="data.totals.missing_cost_lines || data.excluded.orders" class="mb-4 flex items-start gap-2 rounded-md border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
            <Info class="mt-0.5 size-3.5 shrink-0" />
            <span>
                <template v-if="data.totals.missing_cost_lines">{{ formatNumber(data.totals.missing_cost_lines) }} dòng hàng chưa có giá vốn (đơn chưa xuất kho hoặc SKU sàn chưa ghép), lợi nhuận của các đơn này đang tính bằng tiền hàng. </template>
                <template v-if="data.excluded.orders">Không tính {{ formatNumber(data.excluded.orders) }} đơn hủy/hoàn ({{ formatMoney(data.excluded.revenue) }}).</template>
            </span>
        </p>

        <Card>
            <div class="flex flex-wrap items-center gap-2 border-b p-3">
                <div class="flex overflow-hidden rounded-md border text-sm" role="tablist">
                    <button v-for="(l, k) in TABS" :key="k" type="button" :class="cn('border-r px-3 py-1.5 last:border-r-0', by === k ? 'bg-primary text-primary-foreground' : 'hover:bg-muted')" :data-testid="`sales-by-${k}`" @click="by = k">{{ l }}</button>
                </div>
                <Button variant="outline" size="sm" class="ml-auto" @click="exportCsv"><Download /> Xuất CSV</Button>
            </div>
            <div class="overflow-x-auto" :class="loading && 'opacity-60'">
                <table class="w-full text-sm" data-testid="sales-table">
                    <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">{{ firstColumn }}</th>
                            <th class="px-4 py-2.5 text-right font-medium">Đơn</th>
                            <th class="px-4 py-2.5 text-right font-medium">SL</th>
                            <th class="w-1/4 px-4 py-2.5 font-medium">Doanh thu</th>
                            <template v-if="canProfit">
                                <th class="px-4 py-2.5 text-right font-medium">Giá vốn</th>
                                <th class="px-4 py-2.5 text-right font-medium">Lợi nhuận gộp</th>
                                <th class="px-4 py-2.5 text-right font-medium">Biên</th>
                            </template>
                            <template v-if="showFees">
                                <th class="px-4 py-2.5 text-right font-medium">Phí sàn</th>
                                <th class="px-4 py-2.5 text-right font-medium">Lãi sau phí sàn</th>
                                <th class="px-4 py-2.5 text-right font-medium">Biên sau phí</th>
                            </template>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="r in data.rows" :key="r.label + r.kind" :class="!r.orders && 'text-muted-foreground'">
                            <td class="whitespace-nowrap px-4 py-2">{{ label(r) }}<span v-if="kindLabel(r.kind)" class="ml-1.5 text-xs text-muted-foreground">{{ kindLabel(r.kind) }}</span></td>
                            <td class="px-4 py-2 whitespace-nowrap text-right tabular-nums">{{ r.orders ? formatNumber(r.orders) : '—' }}</td>
                            <td class="px-4 py-2 whitespace-nowrap text-right tabular-nums">{{ r.units ? formatNumber(r.units) : '—' }}</td>
                            <td class="px-4 py-2">
                                <div class="flex items-center gap-2">
                                    <div class="h-2 flex-1 rounded-full bg-muted"><div class="h-2 rounded-full bg-primary" :style="{ width: `${(r.revenue / maxRevenue) * 100}%` }" /></div>
                                    <span class="w-28 whitespace-nowrap text-right tabular-nums">{{ r.revenue ? formatMoney(r.revenue) : '—' }}</span>
                                </div>
                            </td>
                            <template v-if="canProfit">
                                <td class="px-4 py-2 whitespace-nowrap text-right tabular-nums">{{ r.cogs ? formatMoney(r.cogs) : '—' }}</td>
                                <td class="px-4 py-2 whitespace-nowrap text-right tabular-nums" :class="(r.gross_profit ?? 0) < 0 && 'text-destructive'">{{ r.orders ? formatMoney(r.gross_profit ?? 0) : '—' }}</td>
                                <td class="px-4 py-2 whitespace-nowrap text-right tabular-nums">{{ r.margin == null ? '—' : `${r.margin}%` }}</td>
                            </template>
                            <template v-if="showFees">
                                <td class="px-4 py-2 whitespace-nowrap text-right tabular-nums" data-testid="channel-fees">
                                    {{ r.fees ? formatMoney(r.fees) : '—' }}
                                    <span v-if="r.fees_unknown_orders" class="block whitespace-normal text-xs text-amber-600">{{ formatNumber(r.fees_unknown_orders) }} đơn chưa có bảng phí</span>
                                    <span v-else-if="r.fees && r.fees_confirmed_orders !== undefined && r.fees_confirmed_orders < r.orders" class="block whitespace-normal text-xs text-muted-foreground">có phần ước tính</span>
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap text-right font-medium tabular-nums" :class="(r.profit_after_fees ?? 0) < 0 && 'text-destructive'">{{ r.orders ? formatMoney(r.profit_after_fees ?? 0) : '—' }}</td>
                                <td class="px-4 py-2 whitespace-nowrap text-right tabular-nums">{{ r.margin_after_fees == null ? '—' : `${r.margin_after_fees}%` }}</td>
                            </template>
                        </tr>
                    </tbody>
                    <tfoot class="border-t bg-muted/30 font-medium">
                        <tr>
                            <td class="px-4 py-2.5">Tổng</td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-right tabular-nums">{{ formatNumber(data.totals.orders) }}</td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-right tabular-nums">{{ formatNumber(data.totals.units) }}</td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-right tabular-nums">{{ formatMoney(data.totals.revenue) }}</td>
                            <template v-if="canProfit">
                                <td class="px-4 py-2.5 whitespace-nowrap text-right tabular-nums">{{ formatMoney(data.totals.cogs ?? 0) }}</td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-right tabular-nums">{{ formatMoney(data.totals.gross_profit ?? 0) }}</td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-right tabular-nums">{{ data.totals.margin == null ? '—' : `${data.totals.margin}%` }}</td>
                            </template>
                            <template v-if="showFees">
                                <td class="px-4 py-2.5 whitespace-nowrap text-right tabular-nums">{{ formatMoney(data.totals.fees ?? 0) }}</td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-right tabular-nums">{{ formatMoney(data.totals.profit_after_fees ?? 0) }}</td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-right tabular-nums">{{ data.totals.margin_after_fees == null ? '—' : `${data.totals.margin_after_fees}%` }}</td>
                            </template>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </Card>
    </template>
</template>
