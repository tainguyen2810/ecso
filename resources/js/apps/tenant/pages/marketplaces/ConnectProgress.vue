<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { Check, Loader2 } from 'lucide-vue-next';

/**
 * While the server finishes a connection (a WooCommerce site can take 10-30 s to answer): what is being done, one
 * step every few seconds. The steps are the order the server works in; the last one stays until the answer comes.
 */
const props = defineProps<{ woo: boolean }>();
const steps = props.woo
    ? ['Nhận quyền truy cập từ website', 'Kiểm tra kết nối tới website', 'Cài webhook để đơn mới tự về', 'Đọc thông tin cửa hàng và sản phẩm']
    : ['Nhận quyền truy cập từ sàn', 'Kiểm tra gian hàng', 'Lưu kết nối'];
const elapsed = ref(0);
const tick = setInterval(() => elapsed.value++, 1000);
onBeforeUnmount(() => clearInterval(tick));
const step = computed(() => Math.min(steps.length - 1, Math.floor(elapsed.value / 3)));
</script>

<template>
    <div class="py-2" data-testid="connect-progress" aria-live="polite">
        <div class="flex items-center gap-3">
            <Loader2 class="size-8 shrink-0 animate-spin text-primary" />
            <div>
                <h2 class="font-semibold">Đang kết nối {{ woo ? 'website' : 'gian hàng' }}…</h2>
                <p class="text-sm text-muted-foreground">Thường mất 5–30 giây. Vui lòng không đóng trang này.</p>
            </div>
        </div>
        <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="connect-bar h-full w-1/3 rounded-full bg-primary" /></div>
        <ul class="mt-4 space-y-2 text-sm">
            <li v-for="(s, i) in steps" :key="s" :class="['flex items-center gap-2.5', i > step && 'text-muted-foreground/60']">
                <Check v-if="i < step" class="size-4 shrink-0 text-success" />
                <Loader2 v-else-if="i === step" class="size-4 shrink-0 animate-spin text-primary" />
                <span v-else class="size-4 shrink-0 rounded-full border" />
                <span :class="i === step && 'font-medium'">{{ s }}</span>
            </li>
        </ul>
        <p v-if="elapsed >= 20" class="mt-4 text-xs text-muted-foreground">{{ woo ? 'Website' : 'Sàn' }} trả lời hơi chậm ({{ elapsed }} giây), vẫn đang chờ…</p>
    </div>
</template>

<style scoped>
/* An indeterminate bar: a block that slides across while the answer is awaited. */
.connect-bar {
    animation: connect-slide 1.4s ease-in-out infinite;
}
@keyframes connect-slide {
    0% {
        transform: translateX(-100%);
    }
    100% {
        transform: translateX(300%);
    }
}
</style>
