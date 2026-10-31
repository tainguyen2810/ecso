<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { Lock } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';

interface VariantRef {
    id: string;
    sku: string;
    name: string;
}
interface BestRow {
    variant: VariantRef | null;
    units: number;
    orders: number;
    revenue: number;
    on_hand: number;
}
interface SlowRow {
    variant: VariantRef;
    on_hand: number;
    units_sold: number;
    last_sale_at: string | null;
    stock_value: number;
}

const best = ref<BestRow[] | null>(null);
const slow = ref<SlowRow[] | null>(null);
const slowLocked = ref<string | null>(null);
const error = ref<string | null>(null);
const days = ref('30');

async function loadBest() {
    try {
        best.value = (await api.get('/reports/best-sellers')).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    }
}
async function loadSlow() {
    slow.value = null;
    try {
        slow.value = (await api.get('/reports/slow-movers', { params: { days: days.value } })).data.data;
        slowLocked.value = null;
    } catch (e) {
        const err = e as ApiError;
        if (err.status === 403 && (err.body as { code?: string }).code === 'FEATURE_NOT_IN_PLAN') slowLocked.value = err.message;
        else error.value = err.message;
    }
}
onMounted(() => {
    loadBest();
    loadSlow();
});
</script>

<template>
    <PageHeader title="Báo cáo" description="Sản phẩm bán chạy (tháng này) và hàng tồn bán chậm." />
    <ErrorState v-if="error" :message="error" @retry="() => { error = null; loadBest(); loadSlow(); }" />
    <template v-else>
        <Card title="Bán chạy tháng này" description="Tính theo số lượng đã xuất kho (đơn đã bàn giao trở đi)." data-testid="best-sellers">
            <div v-if="!best" class="space-y-2 p-5"><Skeleton v-for="i in 4" :key="i" class="h-6" /></div>
            <p v-else-if="!best.length" class="p-5 text-sm text-muted-foreground">Chưa có hàng xuất kho trong tháng.</p>
            <table v-else class="w-full text-sm">
                <thead class="border-b text-left text-muted-foreground">
                    <tr>
                        <th class="px-5 py-2.5 font-medium">#</th>
                        <th class="px-5 py-2.5 font-medium">SKU</th>
                        <th class="px-5 py-2.5 text-right font-medium">Đã bán</th>
                        <th class="px-5 py-2.5 text-right font-medium">Đơn</th>
                        <th class="px-5 py-2.5 text-right font-medium">Doanh thu</th>
                        <th class="px-5 py-2.5 text-right font-medium">Tồn hiện tại</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="(r, i) in best" :key="r.variant?.id ?? i">
                        <td class="px-5 py-2.5 text-muted-foreground">{{ i + 1 }}</td>
                        <td class="px-5 py-2.5">
                            <RouterLink v-if="r.variant" :to="`/inventory/sku/${r.variant.id}`" class="font-mono text-xs hover:underline">{{ r.variant.sku }}</RouterLink>
                            <p class="text-xs text-muted-foreground">{{ r.variant?.name }}</p>
                        </td>
                        <td class="px-5 py-2.5 text-right font-semibold tabular-nums">{{ formatNumber(r.units) }}</td>
                        <td class="px-5 py-2.5 text-right tabular-nums">{{ formatNumber(r.orders) }}</td>
                        <td class="px-5 py-2.5 text-right tabular-nums">{{ formatMoney(r.revenue) }}</td>
                        <td class="px-5 py-2.5 text-right tabular-nums">{{ formatNumber(r.on_hand) }}</td>
                    </tr>
                </tbody>
            </table>
        </Card>

        <Card class="mt-6" title="Hàng bán chậm" description="SKU còn tồn nhưng không bán được trong khoảng thời gian chọn, xếp theo giá trị tồn." data-testid="slow-movers">
            <template #actions>
                <Select
                    v-if="!slowLocked"
                    v-model="days"
                    class="w-40"
                    :options="[{ value: '30', label: '30 ngày' }, { value: '60', label: '60 ngày' }, { value: '90', label: '90 ngày' }]"
                    @change="loadSlow"
                />
            </template>
            <EmptyState v-if="slowLocked" :icon="Lock" title="Cần gói có Báo cáo nâng cao" :description="slowLocked">
                <RouterLink to="/subscription" class="text-sm font-medium text-primary hover:underline">Xem các gói</RouterLink>
            </EmptyState>
            <div v-else-if="!slow" class="space-y-2 p-5"><Skeleton v-for="i in 3" :key="i" class="h-6" /></div>
            <p v-else-if="!slow.length" class="p-5 text-sm text-muted-foreground">Không có hàng tồn bán chậm.</p>
            <table v-else class="w-full text-sm">
                <thead class="border-b text-left text-muted-foreground">
                    <tr>
                        <th class="px-5 py-2.5 font-medium">SKU</th>
                        <th class="px-5 py-2.5 text-right font-medium">Tồn</th>
                        <th class="px-5 py-2.5 text-right font-medium">Đã bán</th>
                        <th class="px-5 py-2.5 font-medium">Bán lần cuối</th>
                        <th class="px-5 py-2.5 text-right font-medium">Giá trị tồn</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="r in slow" :key="r.variant.id">
                        <td class="px-5 py-2.5">
                            <RouterLink :to="`/inventory/sku/${r.variant.id}`" class="font-mono text-xs hover:underline">{{ r.variant.sku }}</RouterLink>
                            <p class="text-xs text-muted-foreground">{{ r.variant.name }}</p>
                        </td>
                        <td class="px-5 py-2.5 text-right tabular-nums">{{ formatNumber(r.on_hand) }}</td>
                        <td class="px-5 py-2.5 text-right tabular-nums">{{ formatNumber(r.units_sold) }}</td>
                        <td class="px-5 py-2.5 text-muted-foreground">{{ r.last_sale_at ? formatDateTime(r.last_sale_at) : 'Chưa từng bán' }}</td>
                        <td class="px-5 py-2.5 text-right font-semibold tabular-nums">{{ formatMoney(r.stock_value) }}</td>
                    </tr>
                </tbody>
            </table>
        </Card>
    </template>
</template>
