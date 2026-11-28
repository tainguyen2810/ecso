<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { ImageIcon, PlusCircle, Search } from 'lucide-vue-next';
import Input from '@/shared/ui/Input.vue';
import { api } from '@/shared/lib/api';
import { cn, formatNumber } from '@/shared/lib/utils';
import type { Variant } from '@/shared/types';

/**
 * Search SKU / barcode / product name. Pressing Enter on an exact barcode match picks it (scanner friendly).
 * `inline`: the results are a list in the page flow, shown right away (for dialogs, where a floating dropdown is
 * clipped by the dialog's scroll area).
 */
/**
 * `detailed`: rows like Sapo's order form (photo, full name, SKU + version, price, Tồn | Có thể bán) and, with
 * `createLabel`, a first row that emits `create` (Thêm mới sản phẩm).
 */
const props = defineProps<{ warehouse?: string; exclude?: string[]; placeholder?: string; stocked?: boolean; inline?: boolean; initialQuery?: string; detailed?: boolean; createLabel?: string }>();
const emit = defineEmits<{ pick: [variant: Variant]; create: [query: string] }>();

function money(v: string | number | null | undefined) {
    return v === null || v === undefined || v === '' ? '' : formatNumber(Math.round(Number(v)));
}
function create() {
    emit('create', query.value);
    open.value = false;
}

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
            v-if="inline ? results.length : open && (results.length || createLabel)"
            :class="inline ? 'mt-2 max-h-[45vh] overflow-y-auto rounded-md border bg-card py-1' : cn('absolute z-30 mt-1 w-full overflow-y-auto rounded-md border bg-card py-1 shadow-lg', detailed ? 'max-h-[420px] rounded-xl' : 'max-h-72')"
            data-testid="sku-picker-results"
        >
            <template v-if="detailed">
                <button v-if="createLabel" type="button" class="flex w-full items-center gap-2.5 border-b border-slate-100 px-4 py-3 text-left text-sm font-semibold text-blue-600 hover:bg-blue-50/60" data-testid="sku-picker-create" @mousedown.prevent="create">
                    <PlusCircle class="size-5" /> {{ createLabel }}
                </button>
                <button
                    v-for="(v, i) in results"
                    :key="v.id"
                    type="button"
                    :class="cn('flex w-full items-center gap-3 px-4 py-2.5 text-left', i === active ? 'bg-blue-50/70' : 'hover:bg-slate-50')"
                    data-testid="sku-picker-row"
                    @mousedown.prevent="pick(v)"
                    @mousemove="active = i"
                >
                    <span class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
                        <img v-if="v.image || v.product?.image" :src="v.image || v.product?.image || ''" alt="" class="size-full object-cover" loading="lazy" />
                        <ImageIcon v-else class="size-5 text-slate-300" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[14px] text-slate-800" :title="v.product?.name">{{ v.product?.name ?? v.sku }}</span>
                        <span class="mt-0.5 flex items-center gap-2 text-[13px]">
                            <span class="text-slate-500">{{ v.sku }}</span>
                            <span class="truncate text-blue-600">{{ v.name || 'Mặc định' }}</span>
                        </span>
                    </span>
                    <span class="shrink-0 text-right">
                        <span class="block text-[14px] font-semibold tabular-nums text-slate-900">{{ money(v.ref_price) }}</span>
                        <span v-if="v.stock" class="mt-0.5 block text-[13px] tabular-nums text-slate-500" data-testid="sku-picker-stock">
                            Tồn: <b :class="v.stock.on_hand > 0 ? 'font-medium text-blue-600' : 'font-medium text-rose-500'">{{ formatNumber(v.stock.on_hand) }}</b>
                            <span class="text-slate-300"> | </span>Có thể bán: <b :class="v.stock.available > 0 ? 'font-medium text-blue-600' : 'font-medium text-rose-500'">{{ formatNumber(v.stock.available) }}</b>
                        </span>
                    </span>
                </button>
                <p v-if="searched && !results.length" class="px-4 py-4 text-center text-sm text-slate-500">Không tìm thấy sản phẩm khớp “{{ query }}”.</p>
            </template>
            <button
                v-for="(v, i) in detailed ? [] : results"
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
