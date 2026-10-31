<script setup lang="ts">
import { useRoute } from 'vue-router';
import { LogOut, ShieldCheck } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import { cn } from '@/shared/lib/utils';
import { useAuthStore } from '@/shared/stores/auth';
import { adminNav } from './navigation';

const route = useRoute();
const auth = useAuthStore();

async function logout() {
    await auth.logout();
    window.location.href = '/login';
}
</script>

<template>
    <div class="flex min-h-screen">
        <aside class="sticky top-0 hidden h-screen w-60 flex-col bg-[oklch(0.24_0.04_264)] text-white/80 md:flex">
            <div class="flex items-center gap-2 border-b border-white/10 px-5 py-4 font-semibold text-white">
                <ShieldCheck class="size-5" /> ECSO Admin
            </div>
            <nav class="flex-1 space-y-0.5 overflow-y-auto p-3">
                <RouterLink
                    v-for="item in adminNav"
                    :key="item.name"
                    :to="item.path"
                    :class="cn('flex items-center gap-2.5 rounded-md px-2.5 py-1.5 text-sm hover:bg-white/10 hover:text-white', route.name === item.name && 'bg-white/15 text-white')"
                >
                    <component :is="item.icon" class="size-4" />
                    <span class="flex-1">{{ item.label }}</span>
                    <span v-if="item.phase" class="text-[10px] text-white/40">{{ item.phase > 8 ? "Sau MVP" : "P" + item.phase }}</span>
                </RouterLink>
            </nav>
            <div class="flex items-center gap-2 border-t border-white/10 p-4 text-sm">
                <span class="min-w-0 flex-1 truncate">{{ auth.user?.email }}</span>
                <button class="rounded p-1 hover:bg-white/10" title="Đăng xuất" @click="logout"><LogOut class="size-4" /></button>
            </div>
        </aside>
        <main class="min-w-0 flex-1">
            <div class="flex items-center justify-between border-b bg-card px-6 py-3 text-sm">
                <span class="text-muted-foreground">Quản trị toàn hệ thống SaaS</span>
                <Badge tone="warning">Không xem dữ liệu nghiệp vụ của tenant</Badge>
            </div>
            <div class="mx-auto max-w-7xl px-6 py-6"><slot /></div>
        </main>
    </div>
</template>
