<script setup lang="ts">
import { computed, ref } from 'vue';
import { CalendarDays, ChevronDown, X } from 'lucide-vue-next';
import { cn } from '@/shared/lib/utils';
import { daysBetween, displayDate, fromIso, presetOf, rangeOf, rangePresetLabels, type RangePreset } from '@/shared/lib/dates';
import { usePopover } from '@/shared/composables/usePopover';
import Button from './Button.vue';
import CalendarMonth from './CalendarMonth.vue';

/**
 * Date range field (from – to, dd/mm/yyyy): ready-made ranges on the left, two months to pick from, applied with
 * "Áp dụng". A ready-made range applies at once. Values stay yyyy-mm-dd; `clearable` lets a filter go back to
 * "any date" (both empty).
 */
defineOptions({ inheritAttrs: false });
const from = defineModel<string>('from', { default: '' });
const to = defineModel<string>('to', { default: '' });
const props = withDefaults(
    defineProps<{
        class?: string;
        placeholder?: string;
        clearable?: boolean;
        presets?: RangePreset[];
        max?: string;
    }>(),
    { placeholder: 'Chọn khoảng ngày', presets: () => Object.keys(rangePresetLabels) as RangePreset[] },
);
const emit = defineEmits<{ change: [range: { from: string; to: string }] }>();

const anchor = ref<HTMLElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const pop = usePopover(anchor, panel);

const draft = ref({ from: '', to: '' });
const hover = ref<string | null>(null);
const view = ref({ year: 0, month: 0 });

function openPanel() {
    draft.value = { from: from.value || '', to: to.value || '' };
    const end = fromIso(to.value) ?? fromIso(from.value) ?? new Date();
    // The right-hand month shows the end of the range; the left one the month before.
    view.value = { year: end.getFullYear(), month: end.getMonth() - 1 };
    normalize();
    pop.show();
}
function normalize() {
    const { year, month } = view.value;
    view.value = { year: year + Math.floor(month / 12), month: ((month % 12) + 12) % 12 };
}
const right = computed(() => {
    const m = view.value.month + 1;
    return { year: view.value.year + Math.floor(m / 12), month: m % 12 };
});
function shift(step: number) {
    view.value = { ...view.value, month: view.value.month + step };
    normalize();
}

function pick(iso: string) {
    const d = draft.value;
    if (!d.from || d.to) draft.value = { from: iso, to: '' };
    else if (iso < d.from) draft.value = { from: iso, to: d.from };
    else draft.value = { from: d.from, to: iso };
}
const shownTo = computed(() => draft.value.to || (draft.value.from && hover.value && hover.value >= draft.value.from ? hover.value : draft.value.from));

function apply(range = { from: draft.value.from, to: draft.value.to || draft.value.from }) {
    from.value = range.from;
    to.value = range.to;
    emit('change', { ...range });
    pop.close();
}
function usePreset(p: RangePreset) {
    apply(rangeOf(p));
}
function clear() {
    apply({ from: '', to: '' });
}

const activePreset = computed(() => presetOf(draft.value.from, draft.value.to || draft.value.from));
const label = computed(() => {
    if (!from.value && !to.value) return '';
    if (from.value === to.value) return displayDate(from.value);
    return `${displayDate(from.value) || '…'} – ${displayDate(to.value) || '…'}`;
});
const days = computed(() => (draft.value.from ? daysBetween(draft.value.from, draft.value.to || draft.value.from) : 0));
</script>

<template>
    <div ref="anchor" :class="cn('relative inline-flex w-full sm:w-64', props.class)">
        <button
            v-bind="$attrs"
            type="button"
            :class="
                cn(
                    'flex h-9 w-full items-center gap-2 rounded-md border border-input bg-card pl-2.5 pr-2 text-left text-sm tabular-nums shadow-xs hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    pop.open.value && 'ring-2 ring-ring',
                )
            "
            @click="pop.open.value ? pop.close() : openPanel()"
        >
            <CalendarDays class="size-4 shrink-0 text-muted-foreground" :stroke-width="1.75" />
            <span :class="cn('flex-1 truncate', !label && 'text-muted-foreground')">{{ label || placeholder }}</span>
            <ChevronDown v-if="!(clearable && label)" class="size-4 shrink-0 text-muted-foreground" />
        </button>
        <button v-if="clearable && label" type="button" class="absolute right-1.5 top-1/2 -translate-y-1/2 rounded p-1 text-muted-foreground hover:bg-slate-100 hover:text-foreground" aria-label="Bỏ lọc ngày" @click="clear">
            <X class="size-3.5" />
        </button>
        <Teleport to="body">
            <div
                v-if="pop.open.value"
                ref="panel"
                :style="pop.style.value"
                class="z-[60] flex max-w-[calc(100vw-16px)] flex-col overflow-hidden rounded-lg border bg-card text-foreground shadow-lg sm:flex-row"
                data-testid="range-popover"
            >
                <div class="flex flex-wrap gap-1 border-b p-2 sm:w-36 sm:flex-col sm:flex-nowrap sm:border-b-0 sm:border-r">
                    <button
                        v-for="p in presets"
                        :key="p"
                        type="button"
                        :class="cn('rounded-md px-2.5 py-1.5 text-left text-[13px] text-slate-700 hover:bg-slate-100', activePreset === p && 'bg-blue-50 font-semibold text-blue-700 hover:bg-blue-50')"
                        @click="usePreset(p)"
                    >
                        {{ rangePresetLabels[p] }}
                    </button>
                </div>
                <div>
                    <div class="flex gap-5 p-3">
                        <CalendarMonth
                            :year="view.year"
                            :month="view.month"
                            :range-from="draft.from"
                            :range-to="shownTo"
                            :max="max"
                            hide-next
                            class="hidden sm:block"
                            @pick="pick"
                            @hover="hover = $event"
                            @prev="shift(-1)"
                        />
                        <CalendarMonth
                            :year="right.year"
                            :month="right.month"
                            :range-from="draft.from"
                            :range-to="shownTo"
                            :max="max"
                            @pick="pick"
                            @hover="hover = $event"
                            @prev="shift(-1)"
                            @next="shift(1)"
                        />
                    </div>
                    <div class="flex flex-wrap items-center gap-2 border-t px-3 py-2.5 text-xs text-muted-foreground">
                        <span class="rounded-md border px-2 py-1 text-[13px] tabular-nums text-foreground">{{ displayDate(draft.from) || 'Từ ngày' }}</span>
                        <span>–</span>
                        <span class="rounded-md border px-2 py-1 text-[13px] tabular-nums text-foreground">{{ displayDate(draft.to || draft.from) || 'Đến ngày' }}</span>
                        <span v-if="days">{{ days }} ngày</span>
                        <span class="ml-auto flex gap-2">
                            <Button size="sm" variant="outline" @click="pop.close()">Hủy</Button>
                            <Button size="sm" :disabled="!draft.from" data-testid="range-apply" @click="apply()">Áp dụng</Button>
                        </span>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
