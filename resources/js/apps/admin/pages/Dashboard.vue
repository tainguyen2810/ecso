<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { AlertOctagon, Building2, Clock, CreditCard, Layers, Lock, PlugZap, ShoppingCart, UserPlus, Users } from 'lucide-vue-next';
import Card from '@/shared/ui/Card.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';

interface Stats {
    tenants_total: number;
    tenants_new_30d: number;
    tenants_active: number;
    tenants_suspended: number;
    users_total: number;
    users_locked: number;
    subscriptions_by_plan: Record<string, number>;
    failed_jobs: number;
    queue_backlog: number;
    shops_connected: number | null;
    orders_processed: number | null;
    mrr: number | null;
    webhook_failures: number | null;
}

const stats = ref<Stats | null>(null);
const error = ref<string | null>(null);

async function load() {
    error.value = null;
    try {
        stats.value = (await api.get('/admin/dashboard')).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    }
}
onMounted(load);

const cards = computed(() => {
    const s = stats.value;
    if (!s) return [];
    return [
        { label: 'Tổng tenant', value: s.tenants_total, icon: Building2 },
        { label: 'Tenant mới 30 ngày', value: s.tenants_new_30d, icon: UserPlus },
        { label: 'Tenant hoạt động', value: s.tenants_active, icon: Layers },
        { label: 'Người dùng', value: s.users_total, icon: Users },
        { label: 'Tài khoản bị khóa', value: s.users_locked, icon: Lock },
        { label: 'Shop kết nối', value: s.shops_connected, icon: PlugZap, phase: 4 },
        { label: 'Đơn đã xử lý', value: s.orders_processed, icon: ShoppingCart, phase: 3 },
        { label: 'MRR', value: s.mrr, icon: CreditCard, phase: 6 },
        { label: 'Queue backlog', value: s.queue_backlog, icon: Clock },
        { label: 'Failed jobs', value: s.failed_jobs, icon: AlertOctagon, danger: s.failed_jobs > 0 },
        { label: 'Webhook lỗi', value: s.webhook_failures, icon: AlertOctagon, phase: 4 },
    ];
});
</script>

<template>
    <h1 class="mb-6 text-xl font-semibold">Tổng quan hệ thống</h1>
    <ErrorState v-if="error" :message="error" @retry="load" />
    <div v-else-if="!stats" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"><Skeleton v-for="i in 8" :key="i" class="h-24" /></div>
    <template v-else>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Card v-for="c in cards" :key="c.label" class="p-4">
                <div class="flex items-center justify-between text-xs text-muted-foreground">
                    {{ c.label }} <component :is="c.icon" class="size-4" />
                </div>
                <p :class="['mt-2 text-2xl font-semibold', c.danger && 'text-destructive', c.value === null && 'text-muted-foreground/50']">
                    {{ c.value === null ? '—' : c.value.toLocaleString('vi-VN') }}
                </p>
                <p v-if="c.value === null" class="text-xs text-muted-foreground">Có từ Phase {{ c.phase }}</p>
            </Card>
        </div>
        <Card class="mt-6" title="Subscription theo gói">
            <div class="flex flex-wrap gap-6 p-5">
                <div v-for="(count, plan) in stats.subscriptions_by_plan" :key="plan">
                    <p class="text-xs uppercase text-muted-foreground">{{ plan }}</p>
                    <p class="text-xl font-semibold">{{ count }}</p>
                </div>
            </div>
        </Card>
    </template>
</template>
