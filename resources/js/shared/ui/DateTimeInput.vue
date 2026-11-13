<script setup lang="ts">
import { ref, watch } from 'vue';
import { cn } from '@/shared/lib/utils';
import { parseTime } from '@/shared/lib/dates';
import DateInput from './DateInput.vue';

/**
 * Date and time typed as dd/mm/yyyy and 24-hour HH:mm (replaces <input type="datetime-local">, which shows
 * mm/dd/yyyy and AM/PM on many machines). The value keeps the datetime-local format: yyyy-mm-ddTHH:mm.
 */
defineOptions({ inheritAttrs: false });
const model = defineModel<string | null | undefined>();
const props = defineProps<{ class?: string; max?: string; disabled?: boolean; invalid?: boolean }>();

const date = ref((model.value ?? '').slice(0, 10));
const time = ref((model.value ?? '').slice(11, 16));
watch(model, (v) => {
    date.value = (v ?? '').slice(0, 10);
    time.value = (v ?? '').slice(11, 16);
});

function emitValue() {
    model.value = date.value ? `${date.value}T${time.value || '00:00'}` : '';
}
function onDate(v: string | null | undefined) {
    date.value = v ?? '';
    emitValue();
}
function onTime(e: Event) {
    const input = e.target as HTMLInputElement;
    const t = parseTime(input.value);
    if (t) time.value = t;
    input.value = time.value;
    emitValue();
}
</script>

<template>
    <div :class="cn('flex gap-2', props.class)">
        <DateInput :model-value="date" class="min-w-0 flex-1" :max="max?.slice(0, 10)" :disabled="disabled" :invalid="invalid" v-bind="$attrs" @update:model-value="onDate" />
        <input
            :value="time"
            :disabled="disabled"
            inputmode="numeric"
            placeholder="hh:mm"
            aria-label="Giờ"
            :class="cn('h-9 w-20 rounded-md border border-input bg-card px-2 text-center text-sm tabular-nums shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50', invalid && 'border-destructive')"
            @change="onTime"
            @keydown.enter.prevent="onTime"
        />
    </div>
</template>
