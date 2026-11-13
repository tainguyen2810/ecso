<script setup lang="ts">
import { computed, ref, useAttrs, watch } from 'vue';
import { CalendarDays } from 'lucide-vue-next';
import { cn } from '@/shared/lib/utils';
import { addDays, displayDate, fromIso, isoDate, parseDate, weekdayOf } from '@/shared/lib/dates';
import { usePopover } from '@/shared/composables/usePopover';
import CalendarMonth from './CalendarMonth.vue';

/**
 * Date field shown and typed as dd/mm/yyyy (replaces the browser's <input type="date">, which shows mm/dd/yyyy on
 * many machines). The value stays yyyy-mm-dd. Typing 15/11, 151126 or 2026-11-15 works; a calendar opens on focus.
 * `change` fires when the date is committed (picked, Enter, or leaving the field).
 */
defineOptions({ inheritAttrs: false });
const model = defineModel<string | null | undefined>();
const props = defineProps<{
    class?: string;
    min?: string;
    max?: string;
    disabled?: boolean;
    invalid?: boolean;
    placeholder?: string;
    /** Show the weekday after the date ("Chủ nhật"). */
    weekday?: boolean;
    /** Shortcut chips under the calendar: upcoming days (delivery dates) or none. */
    quick?: 'future';
}>();
const emit = defineEmits<{ change: [value: string] }>();
const attrs = useAttrs();

const text = ref(displayDate(model.value));
const focused = ref(false);
const anchor = ref<HTMLElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const pop = usePopover(anchor, panel);

const start = fromIso(model.value) ?? new Date();
const view = ref({ year: start.getFullYear(), month: start.getMonth() });
watch(model, (v) => {
    if (!focused.value || parseDate(text.value) !== (v || null)) text.value = displayDate(v);
    const d = fromIso(v);
    if (d) view.value = { year: d.getFullYear(), month: d.getMonth() };
});

// The value when the field was last committed: `change` fires only when the date really changed since then.
let committed = model.value || '';
watch(model, (v) => {
    if (!focused.value) committed = v || '';
});
function set(value: string) {
    if ((model.value || '') !== value) model.value = value;
    if (committed !== value) {
        committed = value;
        emit('change', value);
    }
}

// A complete date commits while typing (filters react at once); a partial one waits for Enter or leaving the field.
const complete = /^(\d{1,2}[/.-]\d{1,2}[/.-]\d{4}|\d{4}-\d{2}-\d{2})$/;
function onInput(e: Event) {
    text.value = (e.target as HTMLInputElement).value;
    const t = text.value.trim();
    if (complete.test(t)) {
        const v = parseDate(t);
        if (v) model.value = v;
    }
}
function commit() {
    const t = text.value.trim();
    if (t === '') {
        set('');
        return;
    }
    const v = parseDate(t);
    if (v) {
        set(v);
        text.value = displayDate(v);
    } else {
        text.value = displayDate(model.value);
    }
}
function onBlur() {
    focused.value = false;
    commit();
}
function onEnter() {
    commit();
    pop.close();
}

function pick(iso: string) {
    set(iso);
    text.value = displayDate(iso);
    pop.close();
}
function shift(step: number) {
    const m = view.value.month + step;
    view.value = { year: view.value.year + Math.floor(m / 12), month: ((m % 12) + 12) % 12 };
}

const today = isoDate(new Date());
const quickDays = computed(() =>
    props.quick === 'future'
        ? [
              { label: 'Hôm nay', iso: today },
              { label: 'Ngày mai', iso: addDays(today, 1) },
              { label: '+2 ngày', iso: addDays(today, 2) },
          ]
        : [{ label: 'Hôm nay', iso: today }],
);
const weekdayText = computed(() => (props.weekday ? weekdayOf(model.value) : ''));
</script>

<template>
    <div ref="anchor" :class="cn('relative w-full', props.class)">
        <CalendarDays class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" :stroke-width="1.75" />
        <input
            v-bind="attrs"
            :value="text"
            type="text"
            inputmode="numeric"
            autocomplete="off"
            :placeholder="placeholder ?? 'dd/mm/yyyy'"
            :disabled="disabled"
            :aria-invalid="invalid || undefined"
            :class="
                cn(
                    'flex h-9 w-full rounded-md border border-input bg-card py-1 pl-8 pr-2 text-sm tabular-nums shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
                    invalid && 'border-destructive focus-visible:ring-destructive',
                    weekdayText && 'pr-20',
                )
            "
            @focus="(focused = true), pop.show()"
            @click="pop.show()"
            @input="onInput"
            @blur="onBlur"
            @keydown.enter.prevent="onEnter"
            @keydown.tab="pop.close()"
        />
        <span v-if="weekdayText" class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">{{ weekdayText }}</span>
        <Teleport to="body">
            <div
                v-if="pop.open.value"
                ref="panel"
                :style="pop.style.value"
                class="z-[60] rounded-lg border bg-card p-3 text-foreground shadow-lg"
                data-testid="date-popover"
                @mousedown.prevent
            >
                <CalendarMonth :year="view.year" :month="view.month" :selected="model ?? ''" :min="min" :max="max" @pick="pick" @prev="shift(-1)" @next="shift(1)" />
                <div class="mt-2 flex gap-1.5 border-t pt-2">
                    <button
                        v-for="q in quickDays"
                        :key="q.label"
                        type="button"
                        :disabled="(!!min && q.iso < min) || (!!max && q.iso > max)"
                        :class="cn('rounded-full border px-2.5 py-0.5 text-xs text-slate-600 hover:bg-slate-50 disabled:opacity-40', model === q.iso && 'border-blue-200 bg-blue-50 font-semibold text-blue-700')"
                        @click="pick(q.iso)"
                    >
                        {{ q.label }}
                    </button>
                    <button v-if="model" type="button" class="ml-auto rounded px-2 text-xs text-muted-foreground hover:text-foreground" @click="pick('')">Xóa</button>
                </div>
            </div>
        </Teleport>
    </div>
</template>
