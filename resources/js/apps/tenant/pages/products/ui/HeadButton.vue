<script setup lang="ts">
import type { Component } from 'vue';
import type { RouteLocationRaw } from 'vue-router';
import { cn } from '@/shared/lib/utils';

/** 44px action button of a Sản phẩm page head: `primary` (blue) or outline; a route when `to` is given. */
const props = defineProps<{ icon?: Component; to?: RouteLocationRaw; primary?: boolean; disabled?: boolean }>();
defineEmits<{ click: [] }>();
const cls = () =>
    cn(
        'inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-[12px] px-4 text-[15px] font-medium disabled:opacity-50',
        props.primary ? 'bg-blue-600 text-white shadow-sm hover:bg-blue-700' : 'border border-slate-200 bg-white text-slate-700 hover:border-blue-300 hover:text-blue-700',
    );
</script>

<template>
    <RouterLink v-if="to" :to="to" :class="cls()"><component :is="icon" v-if="icon" class="size-4" /><slot /></RouterLink>
    <button v-else type="button" :class="cls()" :disabled="disabled" @click="$emit('click')"><component :is="icon" v-if="icon" class="size-4" /><slot /></button>
</template>
