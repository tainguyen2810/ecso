<script setup lang="ts">
import DateRangeInput from '@/shared/ui/DateRangeInput.vue';

/** Period of the report pages: ready-made ranges and a two-month calendar (dd/mm/yyyy). */
const from = defineModel<string>('from', { required: true });
const to = defineModel<string>('to', { required: true });
</script>

<template>
    <DateRangeInput v-model:from="from" v-model:to="to" aria-label="Khoảng ngày" data-testid="report-range" />
</template>
