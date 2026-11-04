<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Search } from 'lucide-vue-next';
import Input from '@/shared/ui/Input.vue';
import { api } from '@/shared/lib/api';
import { formatNumber } from '@/shared/lib/utils';
import type { Variant } from '@/shared/types';

/**
 * Search SKU / barcode / product name. Pressing Enter on an exact barcode match picks it (scanner friendly).
 * `inline`: the results are a list in the page flow, shown right away (for dialogs, where a floating dropdown is
 * clipped by the dialog's scroll area).
 */
const props = defineProps<{ warehouse?: string; exclude?: string[]; placeholder?: string; stocked?: boolean; inline?: boolean; initialQuery?: string }>();
const emit = defineEmits<{ pick: [variant: Variant] }>();

const query = ref(props.initialQuery ?? '');
const results = ref<Variant[]>([]);
const searched = ref(false);
const open = ref(false);
const active = ref(0);
let timer: ReturnType<typeof setTimeout> | undefined;
let searchedFor: string | null = null;

/** Results per query text, so an Enter answers for the text it was pressed on even when the user typed on since. */
let pending: { q: string; run: Promise<Variant[]> } | null = null;

function search(): Promise<Variant[]> {
    const q = query.value;
    searchedFor = q;
    const run = load(q);
    pending = { q, run };
    return run;
}

async function load(q: string): Promise<Variant[]> {
    const { data } = await api.get('/variants', { params: { q: q || undefined, warehouse: props.warehouse || undefined, stocked: props.stocked ? 1 : undefined } });
    const found = (data.data as Variant[]).filter((v) => !props.exclude?.includes(v.id!));
    if (q === query.value) {
        // Only the answer for the current text fills the list; an older answer would overwrite a newer one.
        results.value = found;
        active.value = 0;
        searched.value = true;
    }
    return found;
}

watch(query, () => {
    clearTimeout(timer);
    timer = setTimeout(search, 200);
});
onBeforeUnmount(() => clearTimeout(timer));
onMounted(() => {
    if (props.inline) search();
});

function pick(v: Variant, forText = query.value) {
    emit('pick', v);
    if (props.inline) return;
    if (query.value === forText) query.value = ''; // keep what the user typed after pressing Enter
    open.value = false;
}

function onBlur() {
    setTimeout(() => (open.value = false), 150);
}

async function onKey(e: KeyboardEvent) {
    if (e.key === 'ArrowDown') active.value = Math.min(active.value + 1, results.value.length - 1);
    else if (e.key === 'ArrowUp') active.value = Math.max(active.value - 1, 0);
    else if (e.key === 'Enter') {
        e.preventDefault();
        const q = query.value;
        let list = results.value;
        // Barcode scanners type the code and press Enter faster than the debounce: search right away.
        if (searchedFor !== q) {
            clearTimeout(timer);
            list = await search();
        } else if (pending?.q === q) {
            list = await pending.run; // the search for this text is already on its way (debounce fired)
        }
        const exact = list.find((v) => v.barcode === q || v.sku.toLowerCase() === q.toLowerCase());
        const choice = exact ?? (q === query.value ? list[active.value] : list[0]);
        if (choice) pick(choice, q);
    } else if (e.key === 'Escape') open.value = false;
}
</script>

<template>
    <div class="relative">
        <Search class="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
        <Input
            v-model="query"
            data-testid="sku-picker"
            class="pl-8"
            :placeholder="placeholder ?? 'Quét mã vạch hoặc tìm SKU, tên sản phẩm…'"
            :autofocus="inline"
            @focus="inline || ((open = true), search())"
            @blur="inline || onBlur()"
            @keydown="onKey"
        />
        <div
            v-if="inline ? results.length : open && results.length"
            :class="inline ? 'mt-2 max-h-[45vh] overflow-y-auto rounded-md border bg-card py-1' : 'absolute z-30 mt-1 max-h-72 w-full overflow-y-auto rounded-md border bg-card py-1 shadow-lg'"
            data-testid="sku-picker-results"
        >
            <button
                v-for="(v, i) in results"
                :key="v.id"
                type="button"
                :class="['flex w-full items-center gap-3 px-3 py-2 text-left text-sm', i === active ? 'bg-muted' : 'hover:bg-muted/60']"
                @mousedown.prevent="pick(v)"
            >
                <span class="font-mono text-xs font-medium">{{ v.sku }}</span>
                <span class="flex-1 truncate text-muted-foreground">{{ v.product?.name }}<template v-if="v.name"> · {{ v.name }}</template></span>
                <span v-if="v.stock" class="text-xs tabular-nums text-muted-foreground">Khả dụng {{ formatNumber(v.stock.available) }}</span>
            </button>
        </div>
        <p v-else-if="inline && searched" class="mt-2 rounded-md border border-dashed px-3 py-6 text-center text-sm text-muted-foreground">Không tìm thấy SKU nào khớp “{{ query }}”.</p>
    </div>
</template>
