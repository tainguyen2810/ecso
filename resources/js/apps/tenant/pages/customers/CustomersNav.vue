<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { cn } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';

const route = useRoute();
const tenant = useTenantStore();
const links = computed(() => [
    { to: '/customers', label: 'Danh sách khách hàng', match: ['customers'] },
    { to: '/customers/groups', label: 'Nhóm khách hàng', match: ['customer-groups'] },
    { to: '/customers/loyalty', label: 'Tích điểm và hạng', match: ['customer-loyalty'] },
    ...(tenant.can('orders.create') || tenant.can('promotions.manage') ? [{ to: '/promotions', label: 'Khuyến mãi', match: ['promotions', 'promotion-new', 'promotion-edit'] }] : []),
    ...(tenant.can('customers.merge') ? [{ to: '/customers/duplicates', label: 'Khách trùng', match: ['customer-duplicates'] }] : []),
]);
const section = computed(() => String(route.name));
</script>

<template>
    <nav class="-mt-2 mb-5 flex gap-1 overflow-x-auto border-b text-sm">
        <RouterLink
            v-for="l in links"
            :key="l.to"
            :to="l.to"
            :class="cn('-mb-px whitespace-nowrap border-b-2 border-transparent px-3 py-2 text-muted-foreground hover:text-foreground', l.match.includes(section) && 'border-primary font-medium text-foreground')"
        >
            {{ l.label }}
        </RouterLink>
    </nav>
</template>
