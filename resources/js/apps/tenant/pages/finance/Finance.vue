<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Banknote, CircleCheck, Clock, CircleX, Download, HelpCircle, Info, Landmark, Package, Percent, Receipt, TrendingUp, Undo2, Wallet } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import KpiCard from '@/shared/ui/KpiCard.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { marketplaceNames } from '../orders/labels';
import { downloadCsv, presetLabels, presetRange, toCsv, type Preset } from '../reports/range';
import FinanceChart from './FinanceChart.vue';
import FinanceDetails from './FinanceDetails.vue';
import { change, formulas, type FinanceData, type Metric } from './finance';

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

function changeText(current: number | null, previous: number | null): string {
    const c = change(current, previous);
    return c === null ? '' : `${c > 0 ? '▲' : c < 0 ? '▼' : ''} ${Math.abs(c).toLocaleString('vi-VN')}% so với kỳ trước`;
}
function changeClass(current: number | null, previous: number | null): string {
    const c = change(current, previous);
    return c === null || c === 0 ? 'text-muted-foreground' : c > 0 ? 'text-success' : 'text-destructive';
}

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
        ...(p ? [s.cogs, s.gross_profit] : []), s.fees, s.fees_unknown_orders, s.shipping, ...(p ? [s.profit, s.margin] : []), s.average_order, s.growth,
    ]);
    const shopHead = ['Shop / kênh', 'Sàn', 'Đơn đặt', 'Đơn hủy', 'Đơn ghi nhận', 'Doanh thu thuần', ...(p ? ['Giá vốn', 'Lãi gộp'] : []), 'Phí sàn', 'Đơn chưa biết phí', 'Ship khách trả', ...(p ? ['Lợi nhuận đóng góp', 'Biên %'] : []), 'Giá trị TB/đơn', 'Tăng trưởng %'];
    const csv = toCsv(head, lines) + '\n\n' + toCsv(shopHead, shops).replace(/^﻿/, '');
    downloadCsv(`tai-chinh-${d.range.from}-${d.range.to}.csv`, csv);
}
</script>

<template>
    <PageHeader title="Tài chính" description="Doanh thu, phí sàn, tiền sàn chuyển về và lợi nhuận ước tính, theo shop và theo kỳ.">
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <Select v-model="shop" :options="shopOptions" class="w-56" aria-label="Shop hoặc kênh bán" data-testid="finance-shop" />
            <Input v-model="from" type="date" class="w-36" aria-label="Từ ngày" data-testid="finance-from" />
            <span class="text-muted-foreground">–</span>
            <Input v-model="to" type="date" class="w-36" aria-label="Đến ngày" data-testid="finance-to" />
            <Button variant="outline" size="sm" :disabled="!data" @click="exportCsv"><Download /> Xuất CSV</Button>
        </div>
    </PageHeader>
    <div class="-mt-2 mb-4 flex flex-wrap gap-1.5">
        <button
            v-for="(label, p) in presetLabels"
            :key="p"
            :class="cn('rounded-full border px-3 py-1 text-xs', activePreset === p ? 'border-primary bg-primary/10 text-primary' : 'bg-card text-muted-foreground hover:bg-muted')"
            @click="preset(p)"
        >
            {{ label }}
        </button>
    </div>

    <ErrorState v-if="error" :message="error" @retry="load" />
    <div v-else-if="!data" class="grid gap-4 md:grid-cols-4"><Skeleton v-for="i in 8" :key="i" class="h-24" /></div>
    <div v-else :class="loading && 'opacity-60'">
        <div class="mb-4 flex items-start gap-2 rounded-lg border bg-muted/40 p-3 text-xs text-muted-foreground">
            <Info class="mt-0.5 size-4 shrink-0" />
            <p>
                Doanh thu ghi nhận khi sàn báo đơn <strong>hoàn thành</strong> (đơn website, đơn tự tạo và POS: khi giao thành công), không gồm phí ship khách trả,
                và <strong>không phải tiền thực nhận</strong>. Lợi nhuận ở đây là số <strong>ước tính để quản lý, chưa phải lợi nhuận kế toán</strong>.
                Bấm vào một ô số liệu để xem danh sách đơn tạo nên con số đó. So sánh với kỳ trước {{ dmy(data.previous_range.from) }} – {{ dmy(data.previous_range.to) }}.
            </p>
        </div>

        <h2 class="mb-2 text-sm font-semibold">Doanh thu</h2>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" data-testid="finance-kpis">
            <KpiCard label="Doanh thu đặt hàng" :icon="Banknote" tone="blue" :value="formatMoney(data.ordered.revenue)">{{ formatNumber(data.ordered.orders) }} đơn (trừ đơn hủy)</KpiCard>
            <button class="text-left" data-testid="kpi-revenue" @click="drill = 'revenue'">
                <KpiCard label="Doanh thu thuần" :icon="CircleCheck" tone="green" :value="formatMoney(data.net_revenue)" class="h-full hover:border-primary/40">
                    <template #label>Doanh thu thuần <HelpCircle class="size-3" :title="formulas.net_revenue" /></template>
                    {{ formatNumber(data.completed.orders) }} đơn ghi nhận<template v-if="data.completed.shipping > 0"> · ship khách trả {{ formatMoney(data.completed.shipping) }} tính riêng</template>
                    <span :class="cn('block', changeClass(data.net_revenue, data.previous.net_revenue))">{{ changeText(data.net_revenue, data.previous.net_revenue) }}</span>
                </KpiCard>
            </button>
            <button class="text-left" data-testid="awaiting-completion" @click="drill = 'awaiting'">
                <KpiCard label="Chờ sàn hoàn thành" :icon="Clock" tone="amber" :value="formatMoney(data.awaiting_completion.goods)" class="h-full hover:border-primary/40">
                    {{ formatNumber(data.awaiting_completion.orders) }} đơn đã giao, tạm tính, chưa vào lợi nhuận
                </KpiCard>
            </button>
            <button class="text-left" @click="drill = 'returns'">
                <KpiCard label="Hàng trả đã duyệt" :icon="Undo2" :value="formatMoney(data.refunds)" class="h-full hover:border-primary/40">Đã trừ khỏi doanh thu thuần</KpiCard>
            </button>
            <button class="text-left" @click="drill = 'cancelled'">
                <KpiCard label="Đơn hủy" :icon="CircleX" :tone="data.cancelled.orders > 0 ? 'red' : 'slate'" :value="formatNumber(data.cancelled.orders)" class="h-full hover:border-primary/40">{{ formatMoney(data.cancelled.revenue) }}</KpiCard>
            </button>
        </div>

        <h2 class="mb-2 mt-6 text-sm font-semibold">Phí sàn và tiền về</h2>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <button class="text-left sm:col-span-2" data-testid="finance-fees" @click="drill = 'fees'">
                <KpiCard label="Phí sàn" :icon="Percent" tone="amber" :value="data.fees.total === 0 && data.fees.unknown_orders > 0 ? 'Chưa xác định' : formatMoney(data.fees.total)" class="h-full hover:border-primary/40">
                    <template #label>Phí sàn <HelpCircle class="size-3" :title="formulas.fees" /></template>
                    Đã xác nhận {{ formatMoney(data.fees.confirmed) }} ({{ formatNumber(data.fees.confirmed_orders) }} đơn) · ước tính {{ formatMoney(data.fees.estimated) }} ({{ formatNumber(data.fees.estimated_orders) }} đơn)
                    <span v-if="data.fees.unknown_orders" class="block text-amber-600" data-testid="fees-unknown">
                        {{ formatNumber(data.fees.unknown_orders) }} đơn chưa biết phí (shop chưa có bảng phí, chưa nhập đối soát): phí thật sẽ cao hơn
                    </span>
                </KpiCard>
            </button>
            <button class="text-left" data-testid="finance-payout" @click="drill = 'payouts'">
                <KpiCard label="Tiền sàn đã chuyển về" :icon="Landmark" :value="data.payout.received === null ? 'Chưa có đối soát' : formatMoney(data.payout.received)" class="h-full hover:border-primary/40">
                    <template #label>Tiền sàn đã chuyển về <HelpCircle class="size-3" :title="formulas.payout" /></template>
                    {{ formatNumber(data.payout.received_orders) }} đơn đã đối soát · không phải doanh thu mới
                </KpiCard>
            </button>
            <KpiCard label="Chờ sàn đối soát" :icon="Wallet" :value="data.payout.pending_expected === null ? (data.payout.pending_orders ? 'Chưa xác định' : formatMoney(0)) : formatMoney(data.payout.pending_expected)">
                {{ formatNumber(data.payout.pending_orders) }} đơn<template v-if="data.payout.pending_expected !== null"> · ước tính theo bảng phí</template><template v-else-if="data.payout.pending_orders"> · có đơn chưa biết phí</template>
            </KpiCard>
        </div>

        <template v-if="profit">
            <h2 class="mb-2 mt-6 text-sm font-semibold">Lợi nhuận ước tính <span class="font-normal text-muted-foreground">(chưa phải lợi nhuận kế toán)</span></h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-testid="finance-profit">
                <KpiCard label="Giá vốn" :icon="Package" :value="formatMoney(data.cogs === null ? null : data.cogs - (data.cogs_returned ?? 0))">
                    <span v-if="data.cogs_missing_lines" class="text-amber-600">{{ data.cogs_missing_lines }} dòng hàng chưa có giá vốn, lợi nhuận đang cao hơn thực tế</span>
                    <template v-else>Xuất kho {{ formatMoney(data.cogs) }}<template v-if="(data.cogs_returned ?? 0) > 0"> − hàng trả nhập lại {{ formatMoney(data.cogs_returned) }}</template></template>
                </KpiCard>
                <KpiCard label="Lợi nhuận gộp" :icon="TrendingUp" :tone="Number(data.estimated_gross_profit) < 0 ? 'red' : 'green'" :value="formatMoney(data.estimated_gross_profit)">
                    <template #label>Lợi nhuận gộp <HelpCircle class="size-3" :title="formulas.gross" /></template>
                    <template #value><span data-testid="gross-profit">{{ formatMoney(data.estimated_gross_profit) }}</span></template>
                    Biên {{ margin(data.estimated_gross_profit) ?? '—' }}% · chưa trừ phí sàn
                    <span :class="cn('block', changeClass(data.estimated_gross_profit, data.previous.estimated_gross_profit))">{{ changeText(data.estimated_gross_profit, data.previous.estimated_gross_profit) }}</span>
                </KpiCard>
                <KpiCard label="Lợi nhuận đóng góp" :icon="Receipt" :tone="Number(data.contribution_profit) < 0 ? 'red' : 'green'" :value="formatMoney(data.contribution_profit)" data-testid="contribution-profit">
                    <template #label>Lợi nhuận đóng góp <HelpCircle class="size-3" :title="formulas.contribution" /></template>
                    Biên {{ margin(data.contribution_profit) ?? '—' }}% · đã trừ phí sàn<template v-if="data.selling_expenses"> và chi phí bán hàng {{ formatMoney(data.selling_expenses) }}</template>
                    <span :class="cn('block', changeClass(data.contribution_profit, data.previous.contribution_profit))">{{ changeText(data.contribution_profit, data.previous.contribution_profit) }}</span>
                </KpiCard>
                <KpiCard label="Lợi nhuận quản trị ước tính" :icon="TrendingUp" :tone="Number(data.estimated_profit) < 0 ? 'red' : 'green'" :value="data.estimated_profit === null ? null : formatMoney(data.estimated_profit)" data-testid="other-pnl">
                    <template #label>Lợi nhuận quản trị ước tính <HelpCircle class="size-3" :title="formulas.profit" /></template>
                    <template v-if="data.estimated_profit === null">Chỉ tính khi xem toàn doanh nghiệp (chi phí chung không chia theo shop)</template>
                    <template v-else>
                        − hao hụt kho {{ formatMoney(data.stock_loss) }} − chi phí vận hành, khác {{ formatMoney((data.other_expenses ?? 0) - (data.selling_expenses ?? 0)) }} + thu khác {{ formatMoney(data.other_income) }}
                        từ <RouterLink to="/cash" class="text-primary hover:underline" @click.stop>phiếu thu chi</RouterLink>
                        <span :class="cn('block', changeClass(data.estimated_profit, data.previous.estimated_profit))">{{ changeText(data.estimated_profit, data.previous.estimated_profit) }}</span>
                    </template>
                </KpiCard>
            </div>
        </template>

        <Card class="mt-6" :title="profit ? 'Doanh thu thuần và lợi nhuận gộp theo ngày' : 'Doanh thu thuần theo ngày'">
            <div class="p-5"><FinanceChart :days="data.daily" :show-profit="profit" /></div>
        </Card>

        <Card v-if="!data.scoped" class="mt-6" title="Theo shop và kênh bán">
            <p v-if="!data.by_shop.length" class="p-5 text-sm text-muted-foreground">Chưa có đơn trong kỳ.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="finance-by-shop">
                    <thead class="border-b text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Shop / kênh</th>
                            <th class="px-3 py-2.5 text-right font-medium">Đơn đặt / hủy</th>
                            <th class="px-3 py-2.5 text-right font-medium">Doanh thu thuần</th>
                            <th v-if="profit" class="px-3 py-2.5 text-right font-medium">Giá vốn</th>
                            <th v-if="profit" class="px-3 py-2.5 text-right font-medium">Lãi gộp</th>
                            <th class="px-3 py-2.5 text-right font-medium">Phí sàn</th>
                            <th v-if="profit" class="px-3 py-2.5 text-right font-medium" :title="formulas.contribution">Lợi nhuận đóng góp</th>
                            <th v-if="profit" class="px-3 py-2.5 text-right font-medium">Biên</th>
                            <th class="px-3 py-2.5 text-right font-medium">TB/đơn</th>
                            <th class="px-4 py-2.5 text-right font-medium">So kỳ trước</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="s in data.by_shop" :key="s.id" class="cursor-pointer hover:bg-muted/40" :title="`Xem riêng ${s.shop}`" @click="shop = s.id">
                            <td class="px-4 py-2.5">
                                <span class="flex items-center gap-2"><MarketplaceLogo :marketplace="s.marketplace" size="sm" /> {{ s.shop }}</span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">{{ formatNumber(s.ordered_orders) }}<span v-if="s.cancelled_orders" class="text-destructive"> / {{ formatNumber(s.cancelled_orders) }}</span></td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">{{ formatMoney(s.net_revenue) }}</td>
                            <td v-if="profit" class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">{{ formatMoney(s.cogs) }}</td>
                            <td v-if="profit" class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">{{ formatMoney(s.gross_profit) }}</td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">
                                {{ formatMoney(s.fees) }}<span v-if="s.fees_unknown_orders" class="block text-[11px] text-amber-600">+ {{ s.fees_unknown_orders }} đơn chưa biết phí</span>
                            </td>
                            <td v-if="profit" :class="cn('whitespace-nowrap px-3 py-2.5 text-right tabular-nums font-medium', Number(s.profit) < 0 && 'text-destructive')">{{ formatMoney(s.profit) }}</td>
                            <td v-if="profit" class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">{{ s.margin === null ? '—' : s.margin + '%' }}</td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">{{ formatMoney(s.average_order) }}</td>
                            <td :class="cn('whitespace-nowrap px-4 py-2.5 text-right tabular-nums', s.growth === null ? 'text-muted-foreground' : s.growth >= 0 ? 'text-success' : 'text-destructive')">
                                {{ s.growth === null ? '—' : `${s.growth > 0 ? '+' : ''}${s.growth.toLocaleString('vi-VN')}%` }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </div>

    <FinanceDetails :metric="drill" :params="params" @close="drill = null" />
</template>
