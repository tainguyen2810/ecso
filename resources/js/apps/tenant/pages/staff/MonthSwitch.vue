<script setup lang="ts">
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { monthLabel, shiftMonth, thisMonth } from './staff';

/** ‹ Tháng 11/2026 › — no months after the current one. */
const month = defineModel<string>({ required: true });
</script>

<template>
    <div class="flex items-center rounded-md border bg-card">
        <button type="button" class="grid size-9 place-items-center hover:bg-muted" aria-label="Tháng trước" @click="month = shiftMonth(month, -1)"><ChevronLeft class="size-4" /></button>
        <span class="min-w-28 px-2 text-center text-sm font-medium">{{ monthLabel(month) }}</span>
        <button type="button" class="grid size-9 place-items-center hover:bg-muted disabled:opacity-40" aria-label="Tháng sau" :disabled="month >= thisMonth()" @click="month = shiftMonth(month, 1)"><ChevronRight class="size-4" /></button>
    </div>
</template>
