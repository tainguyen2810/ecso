<script setup lang="ts">
import { Calendar, ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { monthLabel, shiftMonth, thisMonth } from './staff';

/** ‹ Tháng 11/2026 › — no months after the current one. */
const month = defineModel<string>({ required: true });
</script>

<template>
    <div class="flex h-11 items-center rounded-[12px] border border-slate-200 bg-white text-[15px] text-slate-700" data-testid="month-switch">
        <button type="button" class="grid h-full w-10 place-items-center rounded-l-[12px] border-r border-slate-200 text-slate-500 hover:bg-slate-50" aria-label="Tháng trước" @click="month = shiftMonth(month, -1)"><ChevronLeft class="size-4" /></button>
        <span class="flex min-w-36 items-center justify-center gap-2 px-4 tabular-nums"><Calendar class="size-4 text-slate-400" /> {{ monthLabel(month) }}</span>
        <button type="button" class="grid h-full w-10 place-items-center rounded-r-[12px] border-l border-slate-200 text-slate-500 hover:bg-slate-50 disabled:opacity-40" aria-label="Tháng sau" :disabled="month >= thisMonth()" @click="month = shiftMonth(month, 1)"><ChevronRight class="size-4" /></button>
    </div>
</template>
