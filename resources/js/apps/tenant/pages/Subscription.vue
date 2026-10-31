<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Check, Minus } from 'lucide-vue-next';
import PageHeader from '../PageHeader.vue';
import Badge from '@/shared/ui/Badge.vue';
import Card from '@/shared/ui/Card.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatVnd } from '@/shared/lib/utils';
import type { Entitlement } from '@/shared/types';

interface PlanRow {
    key: string;
    name: string;
    price_monthly: number;
    features: Record<string, Entitlement>;
}
interface SubscriptionData {
    plan: { key: string; name: string } | null;
    status: string;
    entitlements: Record<string, Entitlement>;
    usage: Record<string, number>;
    plans: PlanRow[];
}

const data = ref<SubscriptionData | null>(null);
const error = ref<string | null>(null);

const featureLabels: Record<string, string> = {
    'limit.users': 'Người dùng',
    'limit.shops': 'Shop kết nối',
    'limit.warehouses': 'Kho hàng',
    'limit.orders_per_month': 'Đơn mỗi tháng',
    'limit.data_retention_days': 'Lưu dữ liệu (ngày)',
    'reports.advanced': 'Báo cáo nâng cao',
    'automation.rules': 'Tự động hóa',
};

async function load() {
    error.value = null;
    try {
        data.value = (await api.get('/subscription')).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    }
}
onMounted(load);

function limitText(e?: Entitlement): string {
    if (!e || !e.enabled) return '—';
    return e.limit === null ? 'Không giới hạn' : e.limit.toLocaleString('vi-VN');
}
function usagePercent(key: string): number {
    const limit = data.value?.entitlements[key]?.limit;
    const used = data.value?.usage[key] ?? 0;
    return limit ? Math.min(100, Math.round((used / limit) * 100)) : 0;
}
</script>

<template>
    <PageHeader title="Gói dịch vụ" description="Gói hiện tại, giới hạn sử dụng và so sánh các gói." />
    <ErrorState v-if="error" :message="error" @retry="load" />
    <div v-else-if="!data" class="grid gap-4 md:grid-cols-3"><Skeleton v-for="i in 3" :key="i" class="h-32" /></div>
    <template v-else>
        <div class="grid gap-4 md:grid-cols-3">
            <Card class="p-5">
                <p class="text-sm text-muted-foreground">Gói hiện tại</p>
                <p class="mt-2 flex items-center gap-2 text-2xl font-semibold">
                    {{ data.plan?.name }} <Badge tone="success">{{ data.status === 'active' ? 'Đang dùng' : data.status }}</Badge>
                </p>
                <p class="mt-1 text-xs text-muted-foreground">Nâng cấp và thanh toán sẽ có từ Phase 6.</p>
            </Card>
            <Card class="p-5 md:col-span-2">
                <p class="text-sm text-muted-foreground">Người dùng</p>
                <p class="mt-2 text-2xl font-semibold">
                    {{ data.usage['limit.users'] }} <span class="text-base font-normal text-muted-foreground">/ {{ limitText(data.entitlements['limit.users']) }}</span>
                </p>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-muted">
                    <div class="h-full rounded-full bg-primary" :style="{ width: usagePercent('limit.users') + '%' }" />
                </div>
            </Card>
        </div>

        <Card class="mt-6 overflow-x-auto" title="So sánh gói" description="Giá đang là giá tạm, cấu hình được trong quản trị hệ thống.">
            <table class="w-full min-w-[640px] text-sm">
                <thead>
                    <tr class="border-b">
                        <th class="px-5 py-3 text-left font-medium text-muted-foreground">Tính năng</th>
                        <th
                            v-for="p in data.plans"
                            :key="p.key"
                            :class="cn('px-5 py-3 text-left', p.key === data.plan?.key && 'bg-secondary/60')"
                        >
                            <p class="font-semibold">{{ p.name }}</p>
                            <p class="text-xs font-normal text-muted-foreground">{{ p.price_monthly ? formatVnd(p.price_monthly) + '/tháng' : 'Miễn phí' }}</p>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="(label, key) in featureLabels" :key="key">
                        <td class="px-5 py-2.5 text-muted-foreground">{{ label }}</td>
                        <td v-for="p in data.plans" :key="p.key" :class="cn('px-5 py-2.5', p.key === data.plan?.key && 'bg-secondary/60')">
                            <template v-if="key.startsWith('limit.')">{{ limitText(p.features[key]) }}</template>
                            <Check v-else-if="p.features[key]?.enabled" class="size-4 text-success" />
                            <Minus v-else class="size-4 text-muted-foreground" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </Card>
    </template>
</template>
