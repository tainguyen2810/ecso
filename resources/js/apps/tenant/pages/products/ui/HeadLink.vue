<script setup lang="ts">
import type { Component } from 'vue';
import type { RouteLocationRaw } from 'vue-router';

/** Quick link under a Sản phẩm page title: a route, or a button when `to` is left out. */
defineProps<{ icon: Component; to?: RouteLocationRaw; disabled?: boolean }>();
defineEmits<{ click: [] }>();
</script>

<template>
    <RouterLink v-if="to" :to="to" class="inline-flex items-center gap-1.5 hover:text-blue-900 hover:underline"><component :is="icon" class="size-4" /><slot /></RouterLink>
    <button v-else type="button" class="inline-flex items-center gap-1.5 hover:text-blue-900 hover:underline disabled:opacity-50" :disabled="disabled" @click="$emit('click')"><component :is="icon" class="size-4" /><slot /></button>
</template>
