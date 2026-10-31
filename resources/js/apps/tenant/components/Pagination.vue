<script setup lang="ts">
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import { formatNumber } from '@/shared/lib/utils';

defineProps<{ page: number; lastPage: number; total: number }>();
defineEmits<{ go: [page: number] }>();
</script>

<template>
    <div v-if="total > 0" class="flex items-center justify-between border-t px-4 py-2.5 text-xs text-muted-foreground">
        <span>{{ formatNumber(total) }} dòng</span>
        <div class="flex items-center gap-2">
            <Button variant="ghost" size="icon" class="size-7" :disabled="page <= 1" aria-label="Trang trước" @click="$emit('go', page - 1)"><ChevronLeft /></Button>
            <span class="tabular-nums">{{ page }} / {{ lastPage }}</span>
            <Button variant="ghost" size="icon" class="size-7" :disabled="page >= lastPage" aria-label="Trang sau" @click="$emit('go', page + 1)"><ChevronRight /></Button>
        </div>
    </div>
</template>
