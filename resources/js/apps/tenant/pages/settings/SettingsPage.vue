<script setup lang="ts">
import SettingsShell from './SettingsShell.vue';
import Button from '@/shared/ui/Button.vue';

/** A settings form page: the settings frame with Hủy / Lưu on the right, then its sections as cards. */
defineProps<{ title: string; description?: string; saving?: boolean; dirty?: boolean; canSave?: boolean; wide?: boolean }>();
defineEmits<{ save: []; cancel: [] }>();
</script>

<template>
    <SettingsShell :title="title" :description="description" :wide="wide">
        <template v-if="canSave !== false" #actions>
            <span v-if="dirty" class="text-[13px] text-amber-600">Có thay đổi chưa lưu</span>
            <Button variant="outline" :disabled="!dirty || saving" @click="$emit('cancel')">Hủy</Button>
            <Button :loading="saving" :disabled="!dirty" data-testid="settings-save" @click="$emit('save')">Lưu</Button>
        </template>
        <p v-if="canSave === false" class="mb-4 rounded-[10px] bg-amber-50 px-4 py-2.5 text-sm text-amber-800">Bạn chỉ xem được cấu hình này. Chủ doanh nghiệp hoặc quản trị mới sửa được.</p>
        <div class="space-y-4"><slot /></div>
    </SettingsShell>
</template>
