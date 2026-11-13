<script setup lang="ts">
import { ref } from 'vue';
import { bannerSnoozed, snoozeBanner, usePwaInstall } from '../pwa';

/** Phone-only invitation to add EzSell to the home screen (Chrome/Edge); "Để sau" hides it for 30 days. */
const pwa = usePwaInstall();
const snoozed = ref(bannerSnoozed());

function later() {
    snoozeBanner();
    snoozed.value = true;
}
</script>

<template>
    <div
        v-if="pwa.canInstall.value && !snoozed"
        class="fixed inset-x-3 bottom-[calc(5rem+env(safe-area-inset-bottom))] z-30 flex items-center gap-3 rounded-2xl border bg-card px-3.5 py-3 shadow-xl lg:hidden"
        role="dialog"
        aria-label="Cài EzSell"
        data-testid="pwa-banner"
    >
        <img src="/icons/icon-192.png" alt="" class="size-10 shrink-0 rounded-xl" />
        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold">Thêm EzSell vào màn hình chính</p>
            <p class="text-xs text-muted-foreground">Mở nhanh như app, xem đơn và doanh thu mọi lúc.</p>
        </div>
        <div class="flex shrink-0 flex-col items-end gap-1">
            <button type="button" class="rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground" @click="pwa.install()">Cài đặt</button>
            <button type="button" class="text-[11px] text-muted-foreground" @click="later">Để sau</button>
        </div>
    </div>
</template>
