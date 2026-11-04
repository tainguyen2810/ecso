<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Banknote, CircleCheck, Clock, CircleX, HelpCircle, Info, Package, TrendingUp, Undo2 } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Card from '@/shared/ui/Card.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import KpiCard from '@/shared/ui/KpiCard.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatMoney, formatNumber } from '@/shared/lib/utils';
import { marketplaceNames } from '../orders/labels';
import { marginPercent } from '../billing/labels';

interface FinanceData {
    range: { from: string; to: string };
    ordered: { orders: number; revenue: number; goods: number };
    completed: { orders: number; revenue: number; goods: number; shipping: number };
    awaiting_completion: { orders: number; revenue: number; goods: number };
    cancelled: { orders: number; revenue: number };
    refunds: number;
    can_view_profit: boolean;
    cogs: number | null;
    cogs_returned: number | null;
    cogs_missing_lines: number | null;
    estimated_gross_profit: number | null;
    other_income: number | null;
    other_expenses: number | null;
    estimated_profit: number | null;
    marketplace_fees: number | null;
    payout_received: number | null;
    unknown_reason: string;
    daily: { day: string; orders: number; revenue: number }[];
    by_shop: { shop: string; marketplace: string; orders: number; revenue: number }[];
}

const today = new Date();
const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const from = ref(iso(new Date(today.getFullYear(), today.getMonth(), 1)));
const to = ref(iso(today));
const data = ref<FinanceData | null>(null);
const error = ref<string | null>(null);
const hover = ref<number | null>(null);

async function load() {
    error.value = null;
    try {
        data.value = (await api.get('/finance/summary', { params: { from: from.value, to: to.value } })).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    }
}
onMounted(load);
watch([from, to], load);

const margin = computed(() => (data.value?.estimated_gross_profit != null ? marginPercent(data.value.estimated_gross_profit, data.value.completed.goods) : null));
const maxRevenue = computed(() => Math.max(1, ...(data.value?.daily.map((d) => d.revenue) ?? [1])));
const hovered = computed(() => (hover.value === null ? null : (data.value?.daily[hover.value] ?? null)));

function shortDay(day: string): string {
    const [, m, d] = day.split('-');
    return `${d}/${m}`;
}
</script>

<template>
    <PageHeader title="Tài chính" description="Doanh thu đặt hàng, doanh thu hoàn thành, giá vốn và lợi nhuận gộp ước tính.">
        <div class="flex items-center gap-2 text-sm">
            <Input v-model="from" type="date" class="w-40" aria-label="Từ ngày" data-testid="finance-from" />
            <span class="text-muted-foreground">–</span>
            <Input v-model="to" type="date" class="w-40" aria-label="Đến ngày" data-testid="finance-to" />
        </div>
    </PageHeader>

    <ErrorState v-if="error" :message="error" @retry="load" />
    <div v-else-if="!data" class="grid gap-4 md:grid-cols-4"><Skeleton v-for="i in 8" :key="i" class="h-24" /></div>
    <template v-else>
        <div class="mb-4 flex items-start gap-2 rounded-lg border bg-muted/40 p-3 text-xs text-muted-foreground">
            <Info class="mt-0.5 size-4 shrink-0" />
            <p>
                Doanh thu hoàn thành tính khi sàn báo đơn <strong>hoàn thành</strong> (đơn website, đơn tự tạo và POS: khi giao thành công), không gồm phí ship khách trả,
                và <strong>không phải tiền thực nhận</strong>. Phí sàn và tiền sàn đã chuyển về chưa có vì cần dữ liệu đối soát từ API sàn
                (chưa tích hợp), nên hiển thị “Chưa xác định” thay vì ước đoán.
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-testid="finance-kpis">
            <KpiCard label="Doanh thu đặt hàng" :icon="Banknote" tone="blue" :value="formatMoney(data.ordered.revenue)">{{ formatNumber(data.ordered.orders) }} đơn (trừ đơn hủy)</KpiCard>
            <KpiCard label="Doanh thu hoàn thành" :icon="CircleCheck" tone="green" :value="formatMoney(data.completed.goods)">
                {{ formatNumber(data.completed.orders) }} đơn<template v-if="data.completed.shipping > 0"> · phí ship khách trả {{ formatMoney(data.completed.shipping) }} tính riêng</template>
            </KpiCard>
            <KpiCard v-if="data.can_view_profit" label="Giá vốn hàng đã giao" :icon="Package" :value="formatMoney(data.cogs)">
                <span v-if="data.cogs_missing_lines" class="text-amber-600">{{ data.cogs_missing_lines }} dòng hàng chưa có giá vốn</span>
                <template v-else-if="(data.cogs_returned ?? 0) > 0">−{{ formatMoney(data.cogs_returned) }} hàng trả đã nhập lại kho</template>
                <template v-else>Theo giá vốn bình quân lúc xuất kho</template>
            </KpiCard>
            <KpiCard v-if="data.can_view_profit" label="Lợi nhuận gộp ước tính" :icon="TrendingUp" :tone="Number(data.estimated_gross_profit) < 0 ? 'red' : 'green'" :value="formatMoney(data.estimated_gross_profit)"><template #value><span data-testid="gross-profit">{{ formatMoney(data.estimated_gross_profit) }}</span></template>{{ margin === null ? '—' : margin + '%' }} · chưa trừ phí sàn</KpiCard>
            <KpiCard label="Chờ sàn hoàn thành" :icon="Clock" tone="amber" :value="formatMoney(data.awaiting_completion.goods)" data-testid="awaiting-completion">
                {{ formatNumber(data.awaiting_completion.orders) }} đơn đã giao, tạm tính, chưa vào lợi nhuận
            </KpiCard>
            <KpiCard label="Hoàn tiền" :icon="Undo2" :value="formatMoney(data.refunds)">Phiếu trả đã duyệt (không tính phiếu chờ duyệt)</KpiCard>
            <KpiCard label="Đơn hủy" :icon="CircleX" :tone="data.cancelled.orders > 0 ? 'red' : 'slate'" :value="formatNumber(data.cancelled.orders)">{{ formatMoney(data.cancelled.revenue) }}</KpiCard>
            <Card v-if="data.estimated_profit !== null" class="p-4 sm:col-span-2" data-testid="other-pnl">
                <p class="text-xs text-muted-foreground">Lợi nhuận sau thu chi khác</p>
                <p class="mt-2 text-xl font-semibold tabular-nums">{{ formatMoney(data.estimated_profit) }}</p>
                <p class="text-xs text-muted-foreground">
                    = Lãi gộp {{ formatMoney(data.estimated_gross_profit) }} <span class="text-success">+ thu khác {{ formatMoney(data.other_income) }}</span>
                    <span class="text-destructive">− chi phí {{ formatMoney(data.other_expenses) }}</span> từ
                    <RouterLink to="/cash" class="text-primary hover:underline">phiếu thu chi</RouterLink> có tính KQKD. <strong>Chưa trừ phí sàn.</strong>
                </p>
            </Card>
            <Card class="p-4" data-testid="fees-unknown">
                <p class="flex items-center gap-1 text-xs text-muted-foreground">Phí sàn <HelpCircle class="size-3" :title="data.unknown_reason" /></p>
                <p class="mt-2 text-xl font-semibold text-muted-foreground">Chưa xác định</p>
            </Card>
            <Card class="p-4">
                <p class="flex items-center gap-1 text-xs text-muted-foreground">Tiền sàn đã chuyển <HelpCircle class="size-3" :title="data.unknown_reason" /></p>
                <p class="mt-2 text-xl font-semibold text-muted-foreground">Chưa xác định</p>
            </Card>
        </div>

        <Card class="mt-6" title="Doanh thu đặt hàng theo ngày">
            <div class="p-5">
                <div class="mb-2 h-5 text-sm">
                    <template v-if="hovered">
                        <span class="text-muted-foreground">{{ shortDay(hovered.day) }}:</span>
                        <strong class="tabular-nums"> {{ formatMoney(hovered.revenue) }}</strong>
                        <span class="text-muted-foreground"> · {{ hovered.orders }} đơn</span>
                    </template>
                </div>
                <div class="flex h-48 items-end gap-0.5 border-b border-border" role="img" aria-label="Biểu đồ doanh thu theo ngày" data-testid="daily-chart">
                    <div
                        v-for="(d, i) in data.daily"
                        :key="d.day"
                        class="flex h-full flex-1 cursor-default items-end"
                        @mouseenter="hover = i"
                        @mouseleave="hover = null"
                    >
                        <div
                            class="w-full rounded-t-[4px] transition-colors"
                            :class="hover === i ? 'bg-primary' : 'bg-primary/70'"
                            :style="{ height: d.revenue > 0 ? Math.max(2, (d.revenue / maxRevenue) * 100) + '%' : '0' }"
                        />
                    </div>
                </div>
                <div class="mt-1 flex justify-between text-xs text-muted-foreground">
                    <span>{{ shortDay(data.range.from) }}</span><span>{{ shortDay(data.range.to) }}</span>
                </div>
            </div>
        </Card>

        <Card class="mt-6" title="Theo shop">
            <p v-if="!data.by_shop.length" class="p-5 text-sm text-muted-foreground">Chưa có đơn trong kỳ.</p>
            <table v-else class="w-full text-sm" data-testid="finance-by-shop">
                <thead class="border-b text-left text-muted-foreground">
                    <tr>
                        <th class="px-5 py-2.5 font-medium">Shop</th>
                        <th class="px-5 py-2.5 font-medium">Sàn</th>
                        <th class="px-5 py-2.5 text-right font-medium">Đơn</th>
                        <th class="px-5 py-2.5 text-right font-medium">Doanh thu đặt hàng</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="s in data.by_shop" :key="s.shop + s.marketplace">
                        <td class="px-5 py-2.5">{{ s.shop }}</td>
                        <td class="px-5 py-2.5 text-muted-foreground">{{ marketplaceNames[s.marketplace] ?? s.marketplace }}</td>
                        <td class="px-5 py-2.5 text-right tabular-nums">{{ formatNumber(s.orders) }}</td>
                        <td class="px-5 py-2.5 text-right tabular-nums">{{ formatMoney(s.revenue) }}</td>
                    </tr>
                </tbody>
            </table>
        </Card>
    </template>
</template>
