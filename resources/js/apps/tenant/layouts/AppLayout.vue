<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Check, ChevronsUpDown, LogOut, Menu, Plus, X } from 'lucide-vue-next';
import { useAuthStore } from '@/shared/stores/auth';
import { useTenantStore } from '@/shared/stores/tenant';
import { useGoShortcuts } from '@/shared/composables/useShortcuts';
import { useToast } from '@/shared/composables/useToast';
import { cn, initials } from '@/shared/lib/utils';
import Badge from '@/shared/ui/Badge.vue';
import { allNavItems, navGroups } from '../navigation';

const auth = useAuthStore();
const tenant = useTenantStore();
const route = useRoute();
const router = useRouter();
const toast = useToast();

const switcherOpen = ref(false);
const mobileOpen = ref(false);

const visibleGroups = computed(() =>
    navGroups.map((g) => ({ ...g, items: g.items.filter((i) => tenant.can(i.permission)) })).filter((g) => g.items.length > 0),
);

async function switchTenant(id: string) {
    switcherOpen.value = false;
    if (id === tenant.current?.id) return;
    await tenant.select(id);
    toast.success(`Đã chuyển sang ${tenant.current?.name}`);
    router.push('/');
}

async function logout() {
    await auth.logout();
    tenant.reset();
    router.push({ name: 'login' });
}

useGoShortcuts(
    Object.fromEntries(
        allNavItems.filter((i) => i.shortcut && tenant.can(i.permission)).map((i) => [i.shortcut!, () => router.push(i.path)]),
    ),
);
</script>

<template>
    <div class="flex min-h-screen">
        <div v-if="mobileOpen" class="fixed inset-0 z-30 bg-black/30 lg:hidden" @click="mobileOpen = false" />
        <aside
            :class="
                cn(
                    'fixed inset-y-0 left-0 z-40 flex w-60 flex-col border-r bg-sidebar transition-transform lg:sticky lg:top-0 lg:h-screen lg:translate-x-0',
                    mobileOpen ? 'translate-x-0' : '-translate-x-full',
                )
            "
        >
            <div class="relative border-b p-3">
                <button
                    class="flex w-full items-center gap-2.5 rounded-md p-2 text-left hover:bg-muted"
                    data-testid="tenant-switcher"
                    @click="switcherOpen = !switcherOpen"
                >
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary text-xs font-semibold text-primary-foreground">
                        {{ initials(tenant.current?.name ?? '?') }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold">{{ tenant.current?.name }}</span>
                        <span class="block truncate text-xs text-muted-foreground">{{ tenant.context?.role.name }}</span>
                    </span>
                    <ChevronsUpDown class="size-4 text-muted-foreground" />
                </button>
                <div v-if="switcherOpen" class="absolute left-3 right-3 top-full z-50 mt-1 rounded-md border bg-card p-1 shadow-lg">
                    <p class="px-2 py-1.5 text-xs font-medium text-muted-foreground">Doanh nghiệp của bạn</p>
                    <button
                        v-for="t in tenant.tenants"
                        :key="t.id"
                        class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-muted"
                        @click="switchTenant(t.id)"
                    >
                        <span class="flex-1 truncate text-left">{{ t.name }}</span>
                        <Check v-if="t.id === tenant.current?.id" class="size-4 text-primary" />
                    </button>
                    <div class="my-1 border-t" />
                    <RouterLink to="/tenants" class="flex items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-muted" @click="switcherOpen = false">
                        <Plus class="size-4" /> Tạo doanh nghiệp mới
                    </RouterLink>
                </div>
            </div>

            <nav class="flex-1 space-y-5 overflow-y-auto px-3 py-4">
                <div v-for="group in visibleGroups" :key="group.label">
                    <p class="px-2 pb-1.5 text-[11px] font-medium uppercase tracking-wider text-muted-foreground">{{ group.label }}</p>
                    <RouterLink
                        v-for="item in group.items"
                        :key="item.name"
                        :to="item.path"
                        :class="
                            cn(
                                'group flex items-center gap-2.5 rounded-md px-2 py-1.5 text-sm text-foreground/80 hover:bg-muted hover:text-foreground',
                                route.meta.nav === item.name && 'bg-secondary font-medium text-primary hover:bg-secondary',
                            )
                        "
                        @click="mobileOpen = false"
                    >
                        <component :is="item.icon" class="size-4 shrink-0" />
                        <span class="flex-1">{{ item.label }}</span>
                        <Badge v-if="item.phase" tone="muted" class="px-1.5 text-[10px]">P{{ item.phase }}</Badge>
                    </RouterLink>
                </div>
            </nav>

            <div class="border-t p-3">
                <div class="flex items-center gap-2.5 rounded-md p-2">
                    <span class="flex size-8 items-center justify-center rounded-full bg-muted text-xs font-semibold">{{ initials(auth.user?.name ?? '') }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium">{{ auth.user?.name }}</span>
                        <span class="block truncate text-xs text-muted-foreground">{{ auth.user?.email }}</span>
                    </span>
                    <button class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground" title="Đăng xuất" @click="logout">
                        <LogOut class="size-4" />
                    </button>
                </div>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b bg-background/85 px-4 backdrop-blur lg:hidden">
                <button class="rounded p-1.5 hover:bg-muted" aria-label="Mở menu" @click="mobileOpen = !mobileOpen">
                    <X v-if="mobileOpen" class="size-5" />
                    <Menu v-else class="size-5" />
                </button>
                <span class="font-semibold">{{ tenant.current?.name }}</span>
            </header>
            <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6 lg:px-8">
                <RouterView :key="tenant.current?.id" />
            </main>
        </div>
    </div>
</template>
