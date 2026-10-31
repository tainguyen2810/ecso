<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ArrowRight, Boxes, CheckCircle2, Circle, PackageX, PlugZap, ShoppingCart, TrendingUp, Users } from 'lucide-vue-next';
import PageHeader from '../PageHeader.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import { api } from '@/shared/lib/api';
import { formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import Badge from '@/shared/ui/Badge.vue';
import { marketplaceNames, orderStatus } from './orders/labels';
import type { OrderSummary } from '@/shared/types';
import { useAuthStore } from '@/shared/stores/auth';
import { useTenantStore } from '@/shared/stores/tenant';

const auth = useAuthStore();
const tenant = useTenantStore();

const stock = ref<{ low_stock: number; out_of_stock: number } | null>(null);
const orders = ref<{ today_orders: number; today_ordered_revenue: string; to_process: number; overdue: number; shortage: number; recent: OrderSummary[] } | null>(null);
const accountsCount = ref<number | null>(null);
onMounted(async () => {
    if (tenant.can('inventory.view')) api.get('/inventory/summary').then(({ data }) => (stock.value = data.data)).catch(() => {});
    if (tenant.can('orders.view')) api.get('/orders/summary').then(({ data }) => (orders.value = data.data)).catch(() => {});
    if (tenant.can('marketplace.view')) api.get('/marketplace-accounts').then(({ data }) => (accountsCount.value = data.data.filter((a: { status: string }) => a.status === 'connected').length)).catch(() => {});
});

const kpis = computed(() => [
    {
        label: 'Doanh thu đặt hàng hôm nay',
        icon: TrendingUp,
        value: orders.value ? formatMoney(orders.value.today_ordered_revenue) : null,
        hint: orders.value ? 'Giá trị khách trả, chưa trừ phí sàn' : 'Chưa có dữ liệu',
        to: null,
    },
    {
        label: 'Đơn hôm nay',
        icon: ShoppingCart,
        value: orders.value ? formatNumber(orders.value.today_orders) : null,
        hint: orders.value ? `${formatNumber(orders.value.to_process)} đơn đang chờ xử lý` : 'Chưa có dữ liệu',
        to: orders.value ? '/orders?tab=to_pack' : null,
    },
    {
        label: 'Đơn quá hạn giao',
        icon: PackageX,
        value: orders.value ? formatNumber(orders.value.overdue) : null,
        hint: orders.value ? `${formatNumber(orders.value.shortage)} đơn đang thiếu hàng` : 'Chưa có dữ liệu',
        to: orders.value ? '/orders?flags=overdue' : null,
    },
    {
        label: 'SKU sắp hết hàng',
        icon: Boxes,
        value: stock.value ? formatNumber(stock.value.low_stock) : null,
        hint: stock.value ? `${formatNumber(stock.value.out_of_stock)} SKU đã hết hàng` : 'Chưa có dữ liệu',
        to: stock.value ? '/inventory' : null,
    },
]);

const steps = computed(() => [
    { done: true, label: 'Tạo doanh nghiệp', to: null },
    { done: false, label: 'Mời nhân viên và phân quyền', to: tenant.can('members.view') ? '/members' : null },
    { done: false, label: 'Tạo sản phẩm và nhập kho', to: tenant.can('products.view') ? '/products' : null },
    { done: (accountsCount.value ?? 0) > 0, label: 'Kết nối gian hàng', to: tenant.can('marketplace.view') ? '/marketplaces' : null },
]);
</script>

<template>
    <PageHeader :title="`Xin chào, ${auth.user?.name?.split(' ').slice(-1)[0] ?? ''}`" :description="`Tổng quan hoạt động của ${tenant.current?.name}`" />

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Card v-for="k in kpis" :key="k.label" class="p-5">
            <div class="flex items-center justify-between text-sm text-muted-foreground">
                {{ k.label }}
                <component :is="k.icon" class="size-4" />
            </div>
            <p v-if="k.value !== null" class="mt-3 text-2xl font-semibold tracking-tight">{{ k.value }}</p>
            <p v-else class="mt-3 text-2xl font-semibold tracking-tight text-muted-foreground/60">—</p>
            <p class="mt-1 text-xs text-muted-foreground">
                {{ k.hint }}
                <RouterLink v-if="k.to" :to="k.to" class="ml-1 text-primary hover:underline">Xem</RouterLink>
            </p>
        </Card>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <Card class="lg:col-span-2" title="Đơn hàng gần đây" description="Đơn từ tất cả các sàn.">
            <template v-if="orders?.recent.length" #actions><RouterLink to="/orders" class="text-sm text-primary hover:underline">Xem tất cả</RouterLink></template>
            <ul v-if="orders?.recent.length" class="divide-y" data-testid="recent-orders">
                <li v-for="o in orders.recent" :key="o.id">
                    <RouterLink :to="`/orders/${o.id}`" class="flex items-center gap-3 px-5 py-3 text-sm hover:bg-muted/30">
                        <div class="flex-1">
                            <div class="font-mono text-xs font-medium">{{ o.external_order_id }}</div>
                            <div class="text-xs text-muted-foreground">{{ marketplaceNames[o.marketplace] ?? o.marketplace }} · {{ o.shop?.name }} · {{ formatDateTime(o.placed_at) }}</div>
                        </div>
                        <span class="tabular-nums">{{ formatMoney(o.total) }}</span>
                        <Badge :tone="orderStatus[o.status]?.tone">{{ orderStatus[o.status]?.label ?? o.status }}</Badge>
                    </RouterLink>
                </li>
            </ul>
            <EmptyState v-else :icon="PlugZap" title="Chưa có đơn hàng" description="Kết nối gian hàng để bắt đầu nhận đơn. Đơn mới sẽ xuất hiện tại đây.">
                <RouterLink v-if="tenant.can('marketplace.view')" to="/marketplaces" class="text-sm text-primary hover:underline">Kết nối sàn</RouterLink>
            </EmptyState>
        </Card>
        <Card title="Bắt đầu" description="Các bước thiết lập cửa hàng">
            <ul class="divide-y">
                <li v-for="s in steps" :key="s.label" class="flex items-center gap-3 px-5 py-3 text-sm">
                    <CheckCircle2 v-if="s.done" class="size-4 text-success" />
                    <Circle v-else class="size-4 text-muted-foreground" />
                    <span :class="['flex-1', s.done && 'text-muted-foreground line-through']">{{ s.label }}</span>
                    <RouterLink v-if="s.to" :to="s.to" class="text-primary"><ArrowRight class="size-4" /></RouterLink>
                </li>
            </ul>
            <div class="flex items-center gap-2 border-t px-5 py-3 text-xs text-muted-foreground">
                <Users class="size-3.5" /> Mẹo: gõ <kbd class="rounded border bg-muted px-1">g</kbd> rồi <kbd class="rounded border bg-muted px-1">m</kbd> để mở Nhân viên.
            </div>
        </Card>
    </div>
</template>
