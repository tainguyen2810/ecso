<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import { adminNav } from '../navigation';

const route = useRoute();
const item = computed(() => adminNav.find((i) => i.name === route.name)!);
</script>

<template>
    <h1 class="mb-6 text-xl font-semibold">{{ item.label }}</h1>
    <Card>
        <EmptyState :icon="item.icon" :title="`${item.label} đang được xây dựng`" :description="`Có trong Phase ${item.phase} theo lộ trình.`" />
    </Card>
</template>
