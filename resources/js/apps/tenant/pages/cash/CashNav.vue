<script setup lang="ts">
import { useRoute } from 'vue-router';
import { cn } from '@/shared/lib/utils';

const route = useRoute();
const links = [
    { to: '/cash', label: 'Phiếu thu, phiếu chi', name: 'cash' },
    { to: '/reports/cashbook', label: 'Sổ quỹ', name: 'report-cashbook' },
    { to: '/cash/types', label: 'Loại phiếu', name: 'cash-types' },
];
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
