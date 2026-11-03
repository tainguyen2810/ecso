<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { cn } from '@/shared/lib/utils';

const route = useRoute();
const links = [
    { to: '/purchasing', label: 'Đơn nhập hàng', match: ['purchasing', 'purchase-new', 'purchase-edit', 'purchase-show'] },
    { to: '/purchasing/suppliers', label: 'Nhà cung cấp', match: ['suppliers', 'supplier-show'] },
    { to: '/purchasing/returns', label: 'Trả hàng NCC', match: ['supplier-returns'] },
    { to: '/purchasing/reorder', label: 'Gợi ý nhập hàng', match: ['reorder'] },
];
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
