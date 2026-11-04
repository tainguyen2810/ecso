<script setup lang="ts">
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import { formatNumber } from '@/shared/lib/utils';

/** Footer of a server-paged customer tab. */
defineProps<{ meta: { current_page: number; last_page: number; total: number }; loading?: boolean }>();
defineEmits<{ page: [number] }>();
</script>

<template>
    <div v-if="meta.last_page > 1 || meta.total > 0" class="flex items-center justify-between border-t px-5 py-2 text-xs text-muted-foreground">
        <span>{{ formatNumber(meta.total) }} dòng</span>
        <div v-if="meta.last_page > 1" class="flex items-center gap-1">
            <Button variant="ghost" size="sm" aria-label="Trang trước" :disabled="loading || meta.current_page <= 1" @click="$emit('page', meta.current_page - 1)"><ChevronLeft /></Button>
            <span class="tabular-nums">{{ meta.current_page }} / {{ meta.last_page }}</span>
            <Button variant="ghost" size="sm" aria-label="Trang sau" :disabled="loading || meta.current_page >= meta.last_page" @click="$emit('page', meta.current_page + 1)"><ChevronRight /></Button>
        </div>
    </div>
</template>
