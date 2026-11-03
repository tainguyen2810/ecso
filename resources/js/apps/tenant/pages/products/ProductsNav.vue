<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { cn } from '@/shared/lib/utils';

const route = useRoute();
const links = [
    { to: '/products', label: 'Danh sách sản phẩm', match: ['products'] },
    { to: '/products/taxonomy', label: 'Danh mục và thương hiệu', match: ['product-taxonomy'] },
    { to: '/products/labels', label: 'In tem mã vạch', match: ['product-labels'] },
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
