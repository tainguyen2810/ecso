<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { ArrowRight, Banknote, CircleAlert, PackageCheck, PackageOpen, Send, Truck, Undo2 } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import RangePicker from '../reports/RangePicker.vue';
import { presetRange } from '../reports/range';
import Card from '@/shared/ui/Card.vue';
import CarrierLogo from '@/shared/ui/CarrierLogo.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import KpiCard from '@/shared/ui/KpiCard.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import StackedBarChart from '@/shared/ui/charts/StackedBarChart.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';

/** Vận chuyển › Tổng quan: where parcels are now, what needs the shop today, and how each carrier did. */
interface Money { count: number; amount: string }
interface Overview {
    now: {
        to_push: number; awaiting: number; overdue_pickup: number; delivering: number; late: number; late_days: number;
        failed: number; returning: number; returning_slow: number; cod_held: Money; cod_held_slow: Money; fee_gap: Money; slow_days: number;
    };
    range: { from: string; to: string };
    total: number;
    days: { date: string; delivered: number; problem: number; open: number }[];
    carriers: { carrier: string; carrier_name: string | null; marketplace: boolean; count: number; delivered_rate: number | null; returned_rate: number | null; road_days: number | null; avg_fee: string | null; cod_held: string }[];
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
        { show: x.to_push > 0, tone: 'amber', text: `${n(x.to_push)} đơn đã xác nhận chưa đẩy sang hãng vận chuyển`, to: '/shipments', action: 'Đẩy đơn' },
        { show: x.overdue_pickup > 0, tone: 'red', text: `${n(x.overdue_pickup)} đơn quá hạn giao cho hãng`, to: '/shipments?tab=awaiting', action: 'Xem đơn' },
        { show: x.failed > 0, tone: 'red', text: `${n(x.failed)} đơn giao thất bại, cần gọi khách hoặc báo hãng giao lại`, to: '/shipments?tab=failed', action: 'Xử lý' },
        { show: x.late > 0, tone: 'amber', text: `${n(x.late)} đơn đã lấy hơn ${x.late_days} ngày mà chưa giao xong`, to: '/shipments?tab=delivering&late=1', action: 'Xem đơn' },
        { show: x.returning_slow > 0, tone: 'amber', text: `${n(x.returning_slow)} đơn đang hoàn hơn ${x.slow_days} ngày chưa về kho`, to: '/shipments?tab=returning', action: 'Xem đơn' },
        { show: canFinance.value && x.cod_held_slow.count > 0, tone: 'amber', text: `${formatMoney(x.cod_held_slow.amount)} COD hãng giữ quá ${x.slow_days} ngày (${n(x.cod_held_slow.count)} đơn)`, to: '/shipping/cod', action: 'Đối soát' },
        { show: canFinance.value && x.fee_gap.count > 0, tone: 'amber', text: `${n(x.fee_gap.count)} đơn hãng tính phí cao hơn lúc báo giá (lệch ${formatMoney(x.fee_gap.amount)})`, to: '/shipping/cod?tab=fee', action: 'Xem phí' },
    ].filter((t) => t.show);
});

const rate = (v: number | null) => (v === null ? '—' : `${String(v).replace('.', ',')}%`);
const days = (v: number | null) => (v === null ? '—' : `${String(v).replace('.', ',')} ngày`);
</script>

<template>
    <PageHeader title="Tổng quan vận chuyển" description="Đơn đang ở đâu, việc cần làm hôm nay và hãng nào giao tốt.">
        <RangePicker v-model:from="range.from" v-model:to="range.to" />
    </PageHeader>
    <ErrorState v-if="error && !data" :message="error" @retry="load" />
    <div v-else-if="!data" class="space-y-4"><Skeleton class="h-24" /><Skeleton class="h-72" /></div>
    <div v-else class="space-y-5" :class="loading && 'opacity-60'" data-testid="shipping-overview">
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            <RouterLink to="/shipments"><KpiCard label="Chờ đẩy" :value="n(data.now.to_push)" :icon="Send" :tone="data.now.to_push ? 'amber' : 'slate'" class="h-full hover:border-primary/40">Chưa gửi sang hãng</KpiCard></RouterLink>
            <RouterLink to="/shipments?tab=awaiting"><KpiCard label="Chờ lấy hàng" :value="n(data.now.awaiting)" :icon="PackageOpen" class="h-full hover:border-primary/40"><template v-if="data.now.overdue_pickup">{{ n(data.now.overdue_pickup) }} đơn quá hạn</template><template v-else>Hãng sắp đến lấy</template></KpiCard></RouterLink>
            <RouterLink to="/shipments?tab=delivering"><KpiCard label="Đang giao" :value="n(data.now.delivering)" :icon="Truck" tone="blue" class="h-full hover:border-primary/40"><template v-if="data.now.late">{{ n(data.now.late) }} đơn chậm</template><template v-else>Đúng tiến độ</template></KpiCard></RouterLink>
            <RouterLink to="/shipments?tab=failed"><KpiCard label="Giao thất bại" :value="n(data.now.failed)" :icon="CircleAlert" :tone="data.now.failed ? 'red' : 'slate'" class="h-full hover:border-primary/40">Cần xử lý</KpiCard></RouterLink>
            <RouterLink to="/shipments?tab=returning"><KpiCard label="Đang hoàn" :value="n(data.now.returning)" :icon="Undo2" :tone="data.now.returning ? 'amber' : 'slate'" class="h-full hover:border-primary/40">Đang về kho</KpiCard></RouterLink>
            <component :is="canFinance ? RouterLink : 'div'" to="/shipping/cod">
                <KpiCard label="COD hãng đang giữ" :value="canFinance ? formatMoney(data.now.cod_held.amount) : null" :icon="Banknote" tone="blue" class="h-full hover:border-primary/40">{{ n(data.now.cod_held.count) }} đơn đã giao</KpiCard>
            </component>
        </div>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
            <Card caps title="Đơn tạo theo ngày" :description="`${n(data.total)} đơn trong khoảng đã chọn, tô theo kết quả giao hiện tại`">
                <div class="px-5 py-4">
                    <StackedBarChart v-if="data.total" :labels="chart.labels" :series="chart.series" :tips="chart.tips" :height="240" />
                    <EmptyState v-else :icon="PackageCheck" title="Chưa có đơn giao trong khoảng này" />
                </div>
            </Card>
            <Card caps title="Cần xử lý ngay">
                <div v-if="todo.length" class="divide-y" data-testid="shipping-todo">
                    <RouterLink v-for="t in todo" :key="t.text" :to="t.to" class="flex items-center gap-3 px-5 py-3 text-sm hover:bg-muted/40">
                        <span :class="['size-2 shrink-0 rounded-full', t.tone === 'red' ? 'bg-rose-500' : 'bg-amber-500']" />
                        <span class="min-w-0 flex-1">{{ t.text }}</span>
                        <span class="flex shrink-0 items-center gap-1 text-xs font-medium text-primary">{{ t.action }} <ArrowRight class="size-3.5" /></span>
                    </RouterLink>
                </div>
                <div v-else class="flex items-center gap-3 px-5 py-6 text-sm text-muted-foreground"><PackageCheck class="size-5 text-emerald-500" /> Không có việc gấp. Mọi đơn đang đúng tiến độ.</div>
            </Card>
        </div>

        <Card caps title="Hiệu quả theo hãng vận chuyển" description="Tính trên đơn tạo trong khoảng đã chọn. Tỉ lệ giao và hoàn chỉ tính đơn đã có kết quả.">
            <div v-if="data.carriers.length" class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-sm" data-testid="carrier-table">
                    <thead class="border-b bg-muted/40 text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="px-5 py-2.5 font-medium">Hãng</th>
                            <th class="px-3 py-2.5 text-right font-medium">Số đơn</th>
                            <th class="px-3 py-2.5 text-right font-medium">Giao thành công</th>
                            <th class="px-3 py-2.5 text-right font-medium">Hoàn</th>
                            <th class="px-3 py-2.5 text-right font-medium">Thời gian giao</th>
                            <th class="px-3 py-2.5 text-right font-medium">Phí ship trung bình</th>
                            <th v-if="canFinance" class="px-5 py-2.5 text-right font-medium">COD đang giữ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="c in data.carriers" :key="c.carrier">
                            <td class="px-5 py-2.5">
                                <div class="flex items-center gap-3">
                                    <CarrierLogo :carrier="c.carrier" :name="c.carrier_name" :width="72" :height="24" />
                                    <div class="min-w-0">
                                        <div class="font-medium">{{ c.carrier_name ?? (c.carrier === 'other' ? 'Tự giao hoặc hãng khác' : c.carrier) }}</div>
                                        <div v-if="c.marketplace" class="text-xs text-muted-foreground">Vận chuyển của sàn</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ n(c.count) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-emerald-600">{{ rate(c.delivered_rate) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums" :class="(c.returned_rate ?? 0) >= 10 ? 'text-rose-600' : ''">{{ rate(c.returned_rate) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ days(c.road_days) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ c.avg_fee === null ? (c.marketplace ? 'Sàn trả' : '—') : formatMoney(c.avg_fee) }}</td>
                            <td v-if="canFinance" class="px-5 py-2.5 text-right tabular-nums">{{ formatMoney(c.cod_held) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else :icon="Truck" title="Chưa có đơn giao trong khoảng này" />
        </Card>
    </div>
</template>
