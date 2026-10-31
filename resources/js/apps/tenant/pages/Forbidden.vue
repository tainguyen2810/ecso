<script setup lang="ts">
import { ShieldX } from 'lucide-vue-next';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
</script>

<template>
    <Card>
        <EmptyState :icon="ShieldX" title="Bạn không có quyền truy cập trang này" description="Liên hệ chủ doanh nghiệp hoặc quản trị viên để được cấp quyền." />
    </Card>
</template>
