<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { ChevronDown } from 'lucide-vue-next';
import { fold, matches } from '@/shared/lib/geo';
import { cn } from '@/shared/lib/utils';

/**
 * A select with a search box: typing (with or without Vietnamese marks) filters the options; ↑↓ Enter choose,
 * Esc closes. Options may carry a hint line and their own search text (e.g. a ward's former names).
 */
export interface ComboOption {
    value: string;
    label: string;
    hint?: string | null;
    search?: string;
}

defineOptions({ inheritAttrs: false });
const model = defineModel<string>({ default: '' });
const props = withDefaults(defineProps<{ options: ComboOption[]; placeholder?: string; disabled?: boolean; invalid?: boolean; id?: string; limit?: number; emptyText?: string }>(), {
    placeholder: '',
    limit: 60,
    emptyText: 'Không tìm thấy',
});
const emit = defineEmits<{ select: [option: ComboOption] }>();

const open = ref(false);
const query = ref('');
const active = ref(0);
const input = ref<HTMLInputElement | null>(null);
const list = ref<HTMLUListElement | null>(null);

const selected = computed(() => props.options.find((o) => o.value === model.value) ?? null);
const prepared = computed(() => props.options.map((o) => ({ option: o, search: o.search ?? fold(`${o.label} ${o.hint ?? ''}`) })));
const filtered = computed(() => {
    const q = query.value.trim();
    if (!q) return prepared.value.slice(0, props.limit).map((p) => p.option);
    // Best first: the name starts with what was typed ("binh thanh" -> Phường Bình Thạnh), then the name contains it,
    // then a former name contains it as typed, then all the words are found somewhere.
    const f = fold(q);
    const rank = (p: { option: ComboOption; search: string }) => {
        const name = fold(p.option.label);
        const bare = name.replace(/^(phuong|xa|dac khu|tinh|thanh pho) /, '');
        if (bare.startsWith(f)) return 0;
        if (` ${name}`.includes(` ${f}`)) return 1;
        if (` ${p.search}`.includes(` ${f}`)) return 2;
        return 3;
    };
    return prepared.value
        .filter((p) => matches(p.search, q))
        .map((p) => ({ p, r: rank(p) }))
        .sort((a, b) => a.r - b.r)
        .slice(0, props.limit)
        .map((x) => x.p.option);
});

watch(filtered, () => (active.value = 0));

function show() {
    if (props.disabled) return;
    open.value = true;
    query.value = '';
    const i = filtered.value.findIndex((o) => o.value === model.value);
    active.value = Math.max(0, i);
    nextTick(() => scrollActive());
}
function choose(o: ComboOption) {
    model.value = o.value;
    emit('select', o);
    open.value = false;
    query.value = '';
}
function close() {
    // Let a click on an option land first.
    setTimeout(() => {
        open.value = false;
        query.value = '';
    }, 150);
}
function scrollActive() {
    list.value?.querySelectorAll('li')[active.value]?.scrollIntoView({ block: 'nearest' });
}
function onKey(e: KeyboardEvent) {
    if (!open.value && (e.key === 'ArrowDown' || e.key === 'Enter')) {
        show();
        e.preventDefault();
        return;
    }
    if (e.key === 'ArrowDown') active.value = Math.min(filtered.value.length - 1, active.value + 1);
    else if (e.key === 'ArrowUp') active.value = Math.max(0, active.value - 1);
    else if (e.key === 'Enter') {
        const o = filtered.value[active.value];
        if (o) choose(o);
    } else if (e.key === 'Escape') open.value = false;
    else return;
    e.preventDefault();
    nextTick(scrollActive);
}
defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div class="relative" v-bind="$attrs">
        <input
            :id="id"
            ref="input"
            :value="open ? query : (selected?.label ?? '')"
            :placeholder="open ? (selected?.label ?? placeholder) : placeholder"
            :disabled="disabled"
            :aria-invalid="invalid || undefined"
            role="combobox"
            :aria-expanded="open"
            autocomplete="off"
            :class="
                cn(
                    'flex h-9 w-full rounded-md border border-input bg-card py-1 pl-3 pr-8 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
                    invalid && 'border-destructive focus-visible:ring-destructive',
                )
            "
            @focus="show"
            @click="!open && show()"
            @input="((query = ($event.target as HTMLInputElement).value), (open = true))"
            @keydown="onKey"
            @blur="close"
        />
        <ChevronDown class="pointer-events-none absolute right-2.5 top-2.5 size-4 text-muted-foreground" />
        <ul v-if="open" ref="list" role="listbox" class="absolute z-30 mt-1 max-h-72 w-full min-w-56 overflow-y-auto rounded-md border bg-card py-1 shadow-lg">
            <li v-for="(o, i) in filtered" :key="o.value" role="option" :aria-selected="o.value === model">
                <button
                    type="button"
                    tabindex="-1"
                    :class="cn('block w-full px-3 py-1.5 text-left text-sm', i === active && 'bg-blue-50/80', o.value === model && 'font-medium text-primary')"
                    @mousedown.prevent="choose(o)"
                    @mousemove="active = i"
                >
                    {{ o.label }}
                    <span v-if="o.hint" class="block text-[11px] font-normal text-muted-foreground">Gồm {{ o.hint }}</span>
                </button>
            </li>
            <li v-if="!filtered.length" class="px-3 py-2 text-sm text-muted-foreground">{{ emptyText }}</li>
        </ul>
    </div>
</template>
