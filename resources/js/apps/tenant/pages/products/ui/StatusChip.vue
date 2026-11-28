<script setup lang="ts">
import { chip, chipBase, type ChipTone } from './ui';

withDefaults(defineProps<{ tone?: ChipTone }>(), { tone: 'slate' });
</script>

<template>
    <span :class="[chipBase, chip[tone]]"><slot /></span>
</template>
