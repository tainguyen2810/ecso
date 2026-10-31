<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { Search } from 'lucide-vue-next';
import Input from '@/shared/ui/Input.vue';
import { api } from '@/shared/lib/api';
import { formatNumber } from '@/shared/lib/utils';
import type { Variant } from '@/shared/types';

/** Search SKU / barcode / product name. Pressing Enter on an exact barcode match picks it (scanner friendly). */
const props = defineProps<{ warehouse?: string; exclude?: string[]; placeholder?: string }>();
const emit = defineEmits<{ pick: [variant: Variant] }>();

const query = ref('');
const results = ref<Variant[]>([]);
const open = ref(false);
const active = ref(0);
let timer: ReturnType<typeof setTimeout> | undefined;
let searchedFor: string | null = null;

async function search() {
    searchedFor = query.value;
    const { data } = await api.get('/variants', { params: { q: query.value || undefined, warehouse: props.warehouse || undefined } });
    results.value = (data.data as Variant[]).filter((v) => !props.exclude?.includes(v.id!));
    active.value = 0;
}

watch(query, () => {
    clearTimeout(timer);
    timer = setTimeout(search, 200);
});
onBeforeUnmount(() => clearTimeout(timer));

function pick(v: Variant) {
    emit('pick', v);
    query.value = '';
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
        // Barcode scanners type the code and press Enter faster than the debounce: search right away.
        if (searchedFor !== query.value) {
            clearTimeout(timer);
            await search();
        }
        const exact = results.value.find((v) => v.barcode === query.value || v.sku.toLowerCase() === query.value.toLowerCase());
        const choice = exact ?? results.value[active.value];
        if (choice) pick(choice);
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
            @focus="open = true; search()"
            @blur="onBlur"
            @keydown="onKey"
        />
        <div v-if="open && results.length" class="absolute z-30 mt-1 max-h-72 w-full overflow-y-auto rounded-md border bg-card py-1 shadow-lg">
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
    </div>
</template>
