<script setup lang="ts">
import { computed } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { cn, formatNumber } from '@/shared/lib/utils';

/** Pages under a Vận chuyển table: "Hiển thị 1–30 trong 148 vận đơn" and numbered pages. */
const props = defineProps<{ page: number; lastPage: number; total: number; perPage: number; unit: string }>();
defineEmits<{ go: [page: number] }>();

const first = computed(() => (props.page - 1) * props.perPage + 1);
const last = computed(() => Math.min(props.total, props.page * props.perPage));
/** At most 7 buttons: first, last, the current page and its neighbours, "…" between. */
const pages = computed(() => {
    const out: (number | '…')[] = [];
    for (let p = 1; p <= props.lastPage; p++) {
        if (p === 1 || p === props.lastPage || Math.abs(p - props.page) <= 1) out.push(p);
        else if (out[out.length - 1] !== '…') out.push('…');
    }
    return out;
});
</script>

<template>
    <div v-if="total > 0" class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-3.5 text-[14px] text-slate-500">
        <span>Hiển thị {{ formatNumber(first) }}–{{ formatNumber(last) }} trong {{ formatNumber(total) }} {{ unit }}<slot /></span>
        <div v-if="lastPage > 1" class="flex items-center gap-1">
            <button type="button" class="grid size-8 place-items-center rounded-lg hover:bg-slate-100 disabled:opacity-30" :disabled="page <= 1" aria-label="Trang trước" @click="$emit('go', page - 1)"><ChevronLeft class="size-4" /></button>
            <template v-for="(p, i) in pages" :key="i">
                <span v-if="p === '…'" class="px-1">…</span>
                <button v-else type="button" :class="cn('grid size-8 place-items-center rounded-lg tabular-nums', p === page ? 'bg-blue-600 font-medium text-white' : 'hover:bg-slate-100')" @click="$emit('go', p)">{{ p }}</button>
            </template>
            <button type="button" class="grid size-8 place-items-center rounded-lg hover:bg-slate-100 disabled:opacity-30" :disabled="page >= lastPage" aria-label="Trang sau" @click="$emit('go', page + 1)"><ChevronRight class="size-4" /></button>
        </div>
    </div>
</template>
