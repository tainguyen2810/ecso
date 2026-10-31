<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { Construction } from 'lucide-vue-next';
import PageHeader from '../PageHeader.vue';
import Badge from '@/shared/ui/Badge.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import { allNavItems } from '../navigation';

const route = useRoute();
const item = computed(() => allNavItems.find((i) => i.name === route.name));
</script>

<template>
    <template v-if="item">
        <PageHeader :title="item.label" :description="item.description">
            <Badge tone="muted">Phase {{ item.phase }}</Badge>
        </PageHeader>
        <Card>
            <EmptyState :icon="item.icon ?? Construction" :title="`${item.label} đang được xây dựng`" :description="`Module này sẽ có trong Phase ${item.phase} theo lộ trình. Quyền truy cập đã được áp dụng sẵn theo vai trò của bạn.`" />
        </Card>
    </template>
</template>
