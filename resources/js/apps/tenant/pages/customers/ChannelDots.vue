<script setup lang="ts">
import ChannelMark from '../reports/ChannelMark.vue';
import { channelKinds } from './labels';

/** The channels a customer bought through, as small real logos (marketplaces, Zalo, Facebook) or icons. */
defineProps<{ channels?: { kind: string; name: string }[] }>();
</script>

<template>
    <div v-if="channels?.length" class="flex items-center gap-1">
        <span v-for="c in channels" :key="c.kind + c.name" :title="c.name || channelKinds[c.kind]" class="inline-flex"><ChannelMark :kind="c.kind" :name="c.name" size="sm" class="!size-5" /></span>
    </div>
    <span v-else class="text-slate-300">—</span>
</template>
