<script setup lang="ts">
import { Check, Loader2 } from 'lucide-vue-next';
import SettingsShell from './SettingsShell.vue';

/**
 * A settings form page (mẫu Cấu hình): the settings frame with "Có thay đổi chưa lưu", Hủy and Lưu thay đổi on the
 * right, the sections as cards, and an optional preview column (#aside).
 */
defineProps<{ title: string; description?: string; saving?: boolean; dirty?: boolean; canSave?: boolean; wide?: boolean }>();
defineEmits<{ save: []; cancel: [] }>();
</script>

<template>
    <SettingsShell :title="title" :description="description" :wide="wide">
        <template v-if="canSave !== false" #actions>
            <span v-if="dirty" class="flex items-center gap-1.5 text-[13px] text-amber-600"><span class="size-1.5 rounded-full bg-amber-500" />Có thay đổi chưa lưu</span>
            <button
                type="button"
                class="flex h-11 items-center rounded-[12px] border border-slate-200 bg-white px-5 text-[15px] text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                :disabled="!dirty || saving"
                @click="$emit('cancel')"
            >
                Hủy
            </button>
            <button
                type="button"
                class="flex h-11 items-center gap-2 rounded-[12px] bg-blue-600 px-5 text-[15px] font-medium text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
                :disabled="!dirty || saving"
                data-testid="settings-save"
                @click="$emit('save')"
            >
                <Loader2 v-if="saving" class="size-4 animate-spin" /><Check v-else class="size-4" /> Lưu thay đổi
            </button>
        </template>
        <p v-if="canSave === false" class="mb-5 rounded-[12px] border border-amber-200 bg-amber-50 px-4 py-3 text-[14px] text-amber-800">Bạn chỉ xem được cấu hình này. Chủ doanh nghiệp hoặc quản trị mới sửa được.</p>
        <div class="space-y-6"><slot /></div>
        <template v-if="$slots.aside" #aside><slot name="aside" /></template>
    </SettingsShell>
</template>
