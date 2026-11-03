<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { cn } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';

const route = useRoute();
const tenant = useTenantStore();
const links = computed(() =>
    [
        { to: '/reports', label: 'Tất cả báo cáo', name: 'reports', show: true },
        { to: '/reports/sales', label: 'Bán hàng & lợi nhuận', name: 'report-sales', show: true },
        { to: '/reports/profit-loss', label: 'Lãi lỗ', name: 'report-profit-loss', show: tenant.can('finance.view') },
        { to: '/reports/stock', label: 'Xuất nhập tồn', name: 'report-stock', show: tenant.can('inventory.view') },
        { to: '/reports/cashbook', label: 'Sổ quỹ', name: 'report-cashbook', show: tenant.can('finance.view') },
        { to: '/reports/products', label: 'Bán chạy / bán chậm', name: 'report-products', show: true },
    ].filter((l) => l.show),
);
</script>

<template>
    <nav class="-mt-2 mb-5 flex gap-1 overflow-x-auto border-b text-sm">
        <RouterLink
            v-for="l in links"
            :key="l.to"
            :to="l.to"
            :class="cn('-mb-px whitespace-nowrap border-b-2 border-transparent px-3 py-2 text-muted-foreground hover:text-foreground', route.name === l.name && 'border-primary font-medium text-foreground')"
        >
            {{ l.label }}
        </RouterLink>
    </nav>
</template>
