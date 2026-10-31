<script setup lang="ts">
import { computed } from 'vue';
import { ArrowRight, Boxes, CheckCircle2, Circle, PackageX, PlugZap, ShoppingCart, TrendingUp, Users } from 'lucide-vue-next';
import PageHeader from '../PageHeader.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import { useAuthStore } from '@/shared/stores/auth';
import { useTenantStore } from '@/shared/stores/tenant';

const auth = useAuthStore();
const tenant = useTenantStore();

const kpis = [
    { label: 'Doanh thu đặt hàng hôm nay', icon: TrendingUp },
    { label: 'Đơn mới', icon: ShoppingCart },
    { label: 'Đơn quá hạn xử lý', icon: PackageX },
    { label: 'SKU sắp hết hàng', icon: Boxes },
];

const steps = computed(() => [
    { done: true, label: 'Tạo doanh nghiệp', to: null },
    { done: false, label: 'Mời nhân viên và phân quyền', to: tenant.can('members.view') ? '/members' : null },
    { done: false, label: 'Tạo kho và nhập sản phẩm (Phase 2)', to: null },
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
            <p class="mt-3 text-2xl font-semibold tracking-tight text-muted-foreground/60">—</p>
            <p class="mt-1 text-xs text-muted-foreground">Chưa có dữ liệu</p>
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
