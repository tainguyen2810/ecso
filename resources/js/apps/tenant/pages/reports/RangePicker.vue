<script setup lang="ts">
import Input from '@/shared/ui/Input.vue';
import { presetLabels, presetRange, type Preset } from './range';

const from = defineModel<string>('from', { required: true });
const to = defineModel<string>('to', { required: true });

function apply(p: Preset) {
    const r = presetRange(p);
    from.value = r.from;
    to.value = r.to;
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 text-sm">
        <div class="flex overflow-hidden rounded-md border">
            <button v-for="(label, p) in presetLabels" :key="p" type="button" class="border-r px-2.5 py-1.5 text-xs last:border-r-0 hover:bg-muted" @click="apply(p)">{{ label }}</button>
        </div>
        <Input v-model="from" type="date" class="w-38" aria-label="Từ ngày" />
        <span class="text-muted-foreground">–</span>
        <Input v-model="to" type="date" class="w-38" aria-label="Đến ngày" />
    </div>
</template>
