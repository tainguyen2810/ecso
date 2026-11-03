<script setup lang="ts">
import { computed, ref } from 'vue';
import { X } from 'lucide-vue-next';

/** Tags as chips: type and press Enter (or comma) to add, Backspace on an empty box removes the last one. */
const props = withDefaults(defineProps<{ suggestions?: string[]; max?: number; placeholder?: string; disabled?: boolean }>(), { suggestions: () => [], max: 10, disabled: false, placeholder: 'Thêm tag, nhấn Enter' });
const model = defineModel<string[]>({ required: true });

const text = ref('');
const focused = ref(false);
const matches = computed(() => {
    const q = text.value.trim().toLowerCase();
    const taken = new Set(model.value.map((t) => t.toLowerCase()));
    return props.suggestions.filter((s) => !taken.has(s.toLowerCase()) && (!q || s.toLowerCase().includes(q))).slice(0, 8);
});

function add(tag: string) {
    const clean = tag.trim().replace(/\s+/g, ' ');
    text.value = '';
    if (!clean || model.value.length >= props.max || model.value.some((t) => t.toLowerCase() === clean.toLowerCase())) return;
    model.value = [...model.value, clean];
}
function remove(index: number) {
    model.value = model.value.filter((_, i) => i !== index);
}
function onBlur() {
    setTimeout(() => {
        focused.value = false;
        add(text.value);
    }, 150);
}
function onKey(e: KeyboardEvent) {
    if (e.key === 'Enter' || e.key === ',') {
        e.preventDefault();
        add(text.value);
    } else if (e.key === 'Backspace' && !text.value && model.value.length) {
        remove(model.value.length - 1);
    }
}
</script>

<template>
    <div class="relative">
        <div class="flex min-h-9 flex-wrap items-center gap-1 rounded-md border border-input bg-card px-2 py-1 text-sm focus-within:ring-2 focus-within:ring-ring">
            <span v-for="(t, i) in model" :key="t" class="inline-flex items-center gap-0.5 rounded-full bg-primary/10 py-0.5 pl-2 pr-1 text-xs text-primary">
                {{ t }}
                <button v-if="!disabled" type="button" class="rounded-full p-0.5 hover:bg-primary/20" :aria-label="`Bỏ tag ${t}`" @click="remove(i)"><X class="size-3" /></button>
            </span>
            <input
                v-model="text"
                class="min-w-24 flex-1 bg-transparent py-0.5 outline-none"
                :placeholder="model.length ? '' : placeholder"
                :disabled="disabled || model.length >= max"
                data-testid="tag-input"
                @keydown="onKey"
                @focus="focused = true"
                @blur="onBlur"
            />
        </div>
        <ul v-if="focused && matches.length" class="absolute z-20 mt-1 max-h-48 w-full overflow-auto rounded-md border bg-card py-1 text-sm shadow-md">
            <li v-for="s in matches" :key="s">
                <button type="button" class="w-full px-3 py-1.5 text-left hover:bg-muted" @mousedown.prevent="add(s)">{{ s }}</button>
            </li>
        </ul>
    </div>
</template>
