<script setup lang="ts">
import { onMounted } from 'vue';
import { BellRing } from 'lucide-vue-next';
import { useToast } from '@/shared/composables/useToast';
import { usePush } from '../push';

/** "Báo đơn mới trên máy này": one switch, with what to do when the phone cannot be told yet. */
const push = usePush();
const toast = useToast();
onMounted(() => push.refresh().catch(() => {}));

async function toggle(on: boolean) {
    try {
        if (on) {
            await push.enable();
            if (push.state.value === 'on') toast.success('Đã bật. Máy này sẽ báo khi có đơn mới.');
        } else {
            await push.disable();
        }
    } catch {
        toast.error('Chưa bật được báo đơn mới trên máy này.');
    }
}

async function test() {
    await push.test();
    toast.success('Đã gửi thông báo thử.');
}
</script>

<template>
    <div class="rounded-xl border border-blue-100 bg-blue-50/60 px-3 py-2.5 text-sm" data-testid="push-toggle">
        <div class="flex items-center gap-3">
            <BellRing class="size-5 shrink-0 text-blue-600" />
            <span class="min-w-0 flex-1">
                <b>Báo đơn mới trên máy này</b>
                <span v-if="push.state.value === 'on'" class="block text-xs text-muted-foreground">Đang bật. <button type="button" class="font-medium text-blue-600 hover:underline" @click="test">Gửi thử</button></span>
                <span v-else-if="push.state.value === 'needs-install'" class="block text-xs text-muted-foreground">iPhone: cài app lên màn hình chính trước, rồi mở app và bật ở đây.</span>
                <span v-else-if="push.state.value === 'denied'" class="block text-xs text-muted-foreground">Trình duyệt đang chặn thông báo. Mở cài đặt trang web, cho phép Thông báo rồi bật lại.</span>
                <span v-else-if="push.state.value === 'unsupported'" class="block text-xs text-muted-foreground">Trình duyệt này chưa hỗ trợ thông báo. Dùng Chrome trên Android hoặc máy tính.</span>
                <span v-else class="block text-xs text-muted-foreground">Điện thoại rung báo ngay khi có đơn sàn hoặc đơn chat mới, kể cả khi đóng app.</span>
            </span>
            <button
                v-if="push.state.value === 'on' || push.state.value === 'off'"
                type="button"
                role="switch"
                :aria-checked="push.state.value === 'on'"
                aria-label="Báo đơn mới trên máy này"
                :disabled="push.busy.value"
                :class="['relative h-5 w-9 shrink-0 rounded-full transition-colors disabled:opacity-60', push.state.value === 'on' ? 'bg-primary' : 'bg-slate-300']"
                data-testid="push-switch"
                @click="toggle(push.state.value !== 'on')"
            >
                <span :class="['absolute top-0.5 size-4 rounded-full bg-white shadow transition-all', push.state.value === 'on' ? 'left-[18px]' : 'left-0.5']" />
            </button>
        </div>
    </div>
</template>
