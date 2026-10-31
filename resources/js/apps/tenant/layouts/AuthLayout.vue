<script setup lang="ts">
import { Boxes, RefreshCw, ShieldCheck } from 'lucide-vue-next';

defineProps<{ title: string; subtitle?: string }>();
</script>

<template>
    <div class="grid min-h-screen lg:grid-cols-2">
        <aside class="relative hidden flex-col justify-between overflow-hidden bg-[oklch(0.27_0.06_264)] p-10 text-white lg:flex">
            <div class="flex items-center gap-2 text-lg font-semibold">
                <span class="flex size-8 items-center justify-center rounded-md bg-white/15">E</span>
                ECSO
            </div>
            <div class="max-w-md space-y-6">
                <h2 class="text-3xl font-semibold leading-tight">Một nơi quản lý đơn hàng và tồn kho cho mọi sàn.</h2>
                <ul class="space-y-4 text-sm text-white/80">
                    <li class="flex gap-3"><RefreshCw class="size-5 shrink-0 text-white" /> Đồng bộ tồn kho Shopee, TikTok Shop, Lazada, Tiki qua API chính thức.</li>
                    <li class="flex gap-3"><Boxes class="size-5 shrink-0 text-white" /> Sổ kho minh bạch, chống bán vượt tồn khi đơn về cùng lúc.</li>
                    <li class="flex gap-3"><ShieldCheck class="size-5 shrink-0 text-white" /> Phân quyền theo vai trò, nhật ký thao tác đầy đủ.</li>
                </ul>
            </div>
            <p class="text-xs text-white/50">© {{ new Date().getFullYear() }} ECSO</p>
        </aside>
        <main class="flex items-center justify-center px-6 py-12">
            <div class="w-full max-w-sm">
                <div class="mb-8 space-y-1.5">
                    <h1 class="text-2xl font-semibold tracking-tight">{{ title }}</h1>
                    <p v-if="subtitle" class="text-sm text-muted-foreground">{{ subtitle }}</p>
                </div>
                <slot />
            </div>
        </main>
    </div>
</template>
