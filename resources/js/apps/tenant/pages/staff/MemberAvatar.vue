<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/shared/lib/utils';
import { avatar } from './staff';

/** 36px round avatar with a member's initials. */
const props = defineProps<{ name: string }>();
const a = computed(() => avatar(props.name));
</script>

<template>
    <span :class="cn('grid size-9 shrink-0 place-items-center rounded-full text-[12px] font-semibold', a.tone)" aria-hidden="true">{{ a.text }}</span>
</template>
