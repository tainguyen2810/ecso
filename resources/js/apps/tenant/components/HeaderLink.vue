<script setup lang="ts">
import type { Component } from 'vue';
import type { RouteLocationRaw } from 'vue-router';

/** A secondary page action shown in PageHeader's `links` row: a route when `to` is set, otherwise a button. */
defineProps<{ icon?: Component; to?: RouteLocationRaw }>();
const cls = 'inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-primary [&_svg]:size-4 disabled:opacity-50';
</script>

<template>
    <RouterLink v-if="to" :to="to" :class="cls"><component :is="icon" v-if="icon" /><slot /></RouterLink>
    <button v-else type="button" :class="cls"><component :is="icon" v-if="icon" /><slot /></button>
</template>
