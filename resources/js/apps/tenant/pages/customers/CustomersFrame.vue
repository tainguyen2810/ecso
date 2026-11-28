<script setup lang="ts">
import { computed } from 'vue';
import { Copy, Crown, TicketPercent, UsersRound, UserRoundCog, type LucideIcon } from 'lucide-vue-next';
import { cn } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { duplicateGroups, runningPromotions } from './frame';

/**
 * Frame of the Khách hàng pages (mẫu 28/11): breadcrumb, title, description, the page's buttons on the right and the
 * five pages as tabs. Khuyến mãi carries the number running, Khách trùng the groups to check, once known.
 */
defineProps<{ page: string; title?: string; description: string }>();
const tenant = useTenantStore();

const tabs = computed(() => {
    const all: { label: string; to: string; icon: LucideIcon; badge?: number | null; tone?: string }[] = [
        { label: 'Danh sách khách hàng', to: '/customers', icon: UsersRound },
        { label: 'Nhóm khách hàng', to: '/customers/groups', icon: UserRoundCog },
    ];
    if (tenant.can('orders.create') || tenant.can('promotions.manage')) all.push({ label: 'Khuyến mãi', to: '/promotions', icon: TicketPercent, badge: runningPromotions.value, tone: 'bg-blue-100 text-blue-700' });
    all.push({ label: 'Tích điểm và hạng', to: '/customers/loyalty', icon: Crown });
    if (tenant.can('customers.merge')) all.push({ label: 'Khách trùng', to: '/customers/duplicates', icon: Copy, badge: duplicateGroups.value, tone: 'bg-amber-100 text-amber-700' });
    return all;
});
</script>

<template>
    <div class="mb-6">
        <p class="text-[14px] text-slate-500">Khách hàng</p>
        <div class="mt-1 flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-[26px] font-bold leading-tight tracking-tight text-slate-900 sm:text-[30px]">{{ title ?? page }}</h1>
                <p class="mt-1 text-[15px] text-slate-500">{{ description }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3"><slot /></div>
        </div>
        <nav class="mt-5 flex gap-5 overflow-x-auto border-b border-slate-200 sm:gap-8" aria-label="Khách hàng" data-testid="customers-tabs">
            <RouterLink
                v-for="t in tabs"
                :key="t.to"
                :to="t.to"
                :class="cn('-mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-1 pb-3 pt-1 text-[15px]', t.label === page ? 'border-blue-600 font-semibold text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-900')"
            >
                <component :is="t.icon" class="size-[17px]" /> {{ t.label }}
                <span v-if="t.badge" :class="cn('rounded-full px-1.5 text-[12px] font-semibold tabular-nums', t.tone)">{{ t.badge }}</span>
            </RouterLink>
        </nav>
    </div>
</template>
