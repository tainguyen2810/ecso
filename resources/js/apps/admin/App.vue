<script setup lang="ts">
import Toaster from '@/shared/ui/Toaster.vue';
import AdminLayout from './AdminLayout.vue';
</script>

<template>
    <AdminLayout><RouterView /></AdminLayout>
    <Toaster />
</template>
