<script setup lang="ts">
import Toaster from '@/shared/ui/Toaster.vue';
import { useRoute } from 'vue-router';
import AdminLayout from './AdminLayout.vue';

const route = useRoute();
</script>

<template>
    <RouterView v-if="route.meta.bare" />
    <AdminLayout v-else><RouterView /></AdminLayout>
    <Toaster />
</template>
