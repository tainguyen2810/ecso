<script setup lang="ts">
import { computed } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { cn, formatNumber } from '@/shared/lib/utils';

/** Footer of a Khách hàng table (mẫu 28/11): "Hiển thị 1–20 trong 642 khách", numbered pages and rows per page. */
const props = defineProps<{ page: number; lastPage: number; total: number; count: number; unit: string }>();
const perPage = defineModel<number>('perPage', { required: true });
defineEmits<{ go: [page: number] }>();

const pages = computed(() => {
    const set = new Set([1, props.lastPage, props.page - 1, props.page, props.page + 1].filter((p) => p >= 1 && p <= props.lastPage));
    const out: (number | '…')[] = [];
    [...set].sort((a, b) => a - b).forEach((p, i, all) => {
        if (i && p - all[i - 1]! > 1) out.push('…');
        out.push(p);
    });
    return out;
});
const from = computed(() => (props.page - 1) * perPage.value + 1);
</script>

<template>
    <div v-if="total" class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-3.5 text-[13px] text-slate-500">
        <span>Hiển thị {{ formatNumber(from) }}–{{ formatNumber(from + count - 1) }} trong {{ formatNumber(total) }} {{ unit }}</span>
        <div class="flex items-center gap-1">
            <button type="button" class="grid size-8 place-items-center rounded-[8px] text-slate-600 hover:bg-slate-100 disabled:text-slate-300" :disabled="page <= 1" aria-label="Trang trước" @click="$emit('go', page - 1)"><ChevronLeft class="size-4" /></button>
            <template v-for="(p, i) in pages" :key="i">
                <span v-if="p === '…'" class="grid size-8 place-items-center">…</span>
                <button v-else type="button" :class="cn('grid size-8 place-items-center rounded-[8px] tabular-nums', p === page ? 'bg-blue-600 font-semibold text-white' : 'text-slate-600 hover:bg-slate-100')" @click="$emit('go', p)">{{ p }}</button>
            </template>
            <button type="button" class="grid size-8 place-items-center rounded-[8px] text-slate-600 hover:bg-slate-100 disabled:text-slate-300" :disabled="page >= lastPage" aria-label="Trang sau" @click="$emit('go', page + 1)"><ChevronRight class="size-4" /></button>
            <select v-model.number="perPage" class="ml-3 h-9 rounded-[10px] border border-slate-200 bg-white px-2.5 text-[13px] text-slate-700" aria-label="Số dòng mỗi trang">
                <option v-for="n in [10, 20, 50, 100]" :key="n" :value="n">{{ n }} dòng</option>
            </select>
        </div>
    </div>
</template>
