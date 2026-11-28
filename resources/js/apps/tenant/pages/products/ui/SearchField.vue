<script setup lang="ts">
import { Search } from 'lucide-vue-next';

/** 44px search box of a list toolbar. */
defineProps<{ placeholder: string }>();
const model = defineModel<string>({ required: true });
</script>

<template>
    <div class="relative min-w-60 flex-1">
        <Search class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
        <input v-model="model" class="h-11 w-full rounded-[10px] border border-slate-200 bg-white pl-10 pr-3 text-[15px] outline-none placeholder:text-slate-400 focus:border-blue-400" :placeholder="placeholder" :aria-label="placeholder" v-bind="$attrs" />
    </div>
</template>
