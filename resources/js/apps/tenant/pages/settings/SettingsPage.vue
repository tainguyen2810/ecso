<script setup lang="ts">
import PageHeader from '../../PageHeader.vue';
import Button from '@/shared/ui/Button.vue';

/** Frame of a settings page like Sapo: back to Cấu hình, title, Hủy / Lưu, then sections. */
defineProps<{ title: string; saving?: boolean; dirty?: boolean; canSave?: boolean }>();
defineEmits<{ save: []; cancel: [] }>();
</script>

<template>
    <PageHeader :title="title" :back="{ to: '/settings', label: 'Cấu hình' }">
        <template v-if="canSave !== false">
            <Button variant="outline" :disabled="!dirty || saving" @click="$emit('cancel')">Hủy</Button>
            <Button :loading="saving" :disabled="!dirty" data-testid="settings-save" @click="$emit('save')">Lưu</Button>
        </template>
    </PageHeader>
    <p v-if="canSave === false" class="mb-2 text-sm text-muted-foreground">Bạn chỉ xem được cấu hình này. Chủ doanh nghiệp hoặc quản trị mới sửa được.</p>
    <div class="divide-y"><slot /></div>
</template>
