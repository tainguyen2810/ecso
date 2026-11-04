<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { ChevronLeft } from 'lucide-vue-next';
import { groupOf, reportGroups } from './groups';

/** Back to the report menu this page belongs to (the sidebar lists the five menus, like Sapo). */
const route = useRoute();
const group = computed(() => groupOf(String(route.name), route.params.report as string | undefined));
</script>

<template>
    <RouterLink :to="{ name: 'report-group', params: { group }, query: { from: route.query.from, to: route.query.to } }" class="-mt-3 mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground" data-testid="back-to-reports">
        <ChevronLeft class="size-4" /> {{ reportGroups[group].title }}
    </RouterLink>
</template>
