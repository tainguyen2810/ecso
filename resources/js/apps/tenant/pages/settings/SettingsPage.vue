<script setup lang="ts">
import { ChevronLeft } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';

/** Frame of a settings page like Sapo: back to Cấu hình, title, Hủy / Lưu, then sections. */
defineProps<{ title: string; saving?: boolean; dirty?: boolean; canSave?: boolean }>();
defineEmits<{ save: []; cancel: [] }>();
</script>

<template>
    <RouterLink to="/settings" class="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ChevronLeft class="size-4" /> Cấu hình</RouterLink>
    <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold tracking-tight">{{ title }}</h1>
        <div v-if="canSave !== false" class="flex gap-2">
            <Button variant="outline" :disabled="!dirty || saving" @click="$emit('cancel')">Hủy</Button>
            <Button :loading="saving" :disabled="!dirty" data-testid="settings-save" @click="$emit('save')">Lưu</Button>
        </div>
    </div>
    <p v-if="canSave === false" class="mb-2 text-sm text-muted-foreground">Bạn chỉ xem được cấu hình này. Chủ doanh nghiệp hoặc quản trị mới sửa được.</p>
    <div class="divide-y"><slot /></div>
</template>
