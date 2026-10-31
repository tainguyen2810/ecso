<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { cn } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';

const route = useRoute();
const tenant = useTenantStore();
const links = computed(() =>
    [
        { to: '/inventory', label: 'Tồn kho', match: ['inventory', 'inventory-card'] },
        { to: '/inventory/receipts', label: 'Nhập kho', match: ['inventory-receipts'] },
        { to: '/inventory/adjustments', label: 'Điều chỉnh', match: ['inventory-adjustments'] },
        { to: '/inventory/transfers', label: 'Chuyển kho', match: ['inventory-transfers'] },
        { to: '/inventory/warehouses', label: 'Danh sách kho', match: ['warehouses'] },
    ].filter(() => tenant.can('inventory.view')),
);
const section = computed(() => String(route.meta.section ?? route.name));
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
