<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ArrowRight, Boxes, CheckCircle2, Circle, PackageX, PlugZap, ShoppingCart, TrendingUp, Users } from 'lucide-vue-next';
import PageHeader from '../PageHeader.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import { api } from '@/shared/lib/api';
import { formatNumber } from '@/shared/lib/utils';
import { useAuthStore } from '@/shared/stores/auth';
import { useTenantStore } from '@/shared/stores/tenant';

const auth = useAuthStore();
const tenant = useTenantStore();

const stock = ref<{ low_stock: number; out_of_stock: number } | null>(null);
onMounted(async () => {
    if (tenant.can('inventory.view')) stock.value = (await api.get('/inventory/summary')).data.data;
});

// Order KPIs arrive with the order engine (Phase 3); stock KPIs are live from Phase 2.
const kpis = computed(() => [
    { label: 'Doanh thu đặt hàng hôm nay', icon: TrendingUp, value: null, hint: 'Chưa có dữ liệu', to: null },
    { label: 'Đơn mới', icon: ShoppingCart, value: null, hint: 'Chưa có dữ liệu', to: null },
    { label: 'Đơn quá hạn xử lý', icon: PackageX, value: null, hint: 'Chưa có dữ liệu', to: null },
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
    { done: false, label: 'Kết nối shop Shopee hoặc TikTok Shop (Phase 4–5)', to: null },
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
        <Card class="lg:col-span-2" title="Đơn hàng gần đây" description="Đơn từ tất cả các sàn sẽ hiển thị tại đây.">
            <EmptyState :icon="PlugZap" title="Chưa kết nối sàn nào" description="Kết nối Shopee, TikTok Shop, Lazada hoặc Tiki để bắt đầu nhận đơn. Tính năng có từ Phase 4." />
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
