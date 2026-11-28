<script setup lang="ts">
import { computed } from 'vue';
import { deliveryTypes } from './labels';
import { cn } from '@/shared/lib/utils';

/** Kiểu giao of a marketplace order (Hỏa tốc, Nhanh, Tiết kiệm, Cồng kềnh), with the marketplace's own words as hint. */
const props = defineProps<{ type?: string | null; option?: string | null; class?: string }>();
const info = computed(() => (props.type ? deliveryTypes.find((t) => t.value === props.type) : undefined));
</script>

<template>
    <span v-if="info" :class="cn('inline-flex items-center gap-1 whitespace-nowrap rounded border px-1.5 text-xs font-semibold', info.tone, props.class)" :title="option ?? info.label" data-testid="delivery-badge">
        <component :is="info.icon" class="size-3" />{{ type === 'other' && option ? option : info.label }}
    </span>
</template>
