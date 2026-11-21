<script setup lang="ts">
import { Sparkles } from 'lucide-vue-next';

/** Why AI is not available here, with the way to fix it. */
defineProps<{ reason: string | null; message?: string | null }>();
</script>

<template>
    <div class="flex gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" data-testid="ai-notice">
        <Sparkles class="mt-0.5 size-4 shrink-0" />
        <div v-if="message">{{ message }}</div>
        <div v-else-if="reason === 'not_configured'">Máy chủ chưa nối với EzChat (thiếu EZCHAT_URL, EZCHAT_SECRET), nên chưa dùng được AI.</div>
        <div v-else>
            AI đọc ảnh dùng credit EzChat AI của workspace EzChat. Hãy
            <RouterLink to="/sales-channels" class="font-medium underline">nối với EzChat</RouterLink>
            hoặc đăng nhập bằng EzChat để dùng.
        </div>
    </div>
</template>
