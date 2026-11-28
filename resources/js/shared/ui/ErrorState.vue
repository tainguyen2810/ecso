<script setup lang="ts">
import { AlertTriangle } from 'lucide-vue-next';
import Button from './Button.vue';

defineProps<{ message?: string }>();
defineEmits<{ retry: [] }>();
</script>

<template>
    <div class="flex flex-col items-center justify-center px-6 py-12 text-center">
        <AlertTriangle class="mb-3 size-8 text-destructive" />
        <p class="text-sm font-medium">Không tải được dữ liệu</p>
        <p class="mt-1 text-sm text-muted-foreground">{{ message ?? 'Đã có lỗi xảy ra, vui lòng thử lại.' }}</p>
        <Button variant="outline" size="sm" class="mt-4" @click="$emit('retry')">Thử lại</Button>
    </div>
</template>
