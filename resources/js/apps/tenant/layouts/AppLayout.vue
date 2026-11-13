<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Check, ChevronDown, ChevronRight, ChevronsUpDown, LogOut, Plus, Search } from 'lucide-vue-next';
import { useAuthStore } from '@/shared/stores/auth';
import { useTenantStore } from '@/shared/stores/tenant';
import { useGoShortcuts } from '@/shared/composables/useShortcuts';
import { useToast } from '@/shared/composables/useToast';
import { cn, initials } from '@/shared/lib/utils';
import Badge from '@/shared/ui/Badge.vue';
import { allNavItems, navGroups, type NavItem } from '../navigation';
import GlobalSearch from '../components/GlobalSearch.vue';
import NotificationBell from '../components/NotificationBell.vue';
import MobileNav from '../components/MobileNav.vue';
import InstallBanner from '../components/InstallBanner.vue';

const auth = useAuthStore();
const tenant = useTenantStore();
const route = useRoute();
const router = useRouter();
const toast = useToast();

const switcherOpen = ref(false);
const mobileOpen = ref(false);
const searchOpen = ref(false);

const canSee = (i: NavItem) => tenant.can(i.permission) || (i.anyPermission ?? []).some((p) => tenant.can(p));
const visibleGroups = computed(() =>
    navGroups
        .map((g) => ({
            ...g,
            items: g.items
                .filter((i) => !i.hidden && canSee(i))
                .map((i) => {
                    const children = i.children?.filter((c) => !c.permission || tenant.can(c.permission));
                    // A member who only sees part of a group lands on the first sub-page they may open.
                    return { ...i, children, path: tenant.can(i.permission) ? i.path : (children?.[0]?.path ?? i.path) };
                }),
        }))
        .filter((g) => g.items.length > 0),
);

// Sidebar sub-menus (like Sapo): the section of the current page is open; others open with the chevron.
const isActive = (item: NavItem) => route.meta.nav === item.name || (item.alsoActive ?? []).includes(String(route.meta.nav));
const open = ref<Set<string>>(new Set());
watch(
    () => route.meta.nav,
    () => {
        const active = allNavItems.find((i) => i.children && isActive(i));
        if (active) open.value = new Set([...open.value, active.name]);
    },
    { immediate: true },
);
function toggleOpen(name: string) {
    const next = new Set(open.value);
    if (next.has(name)) next.delete(name);
    else next.add(name);
    open.value = next;
}
/** Sub-item of the current page: the longest child path that the URL starts with. */
const activeChild = computed(() => {
    const children = allNavItems.flatMap((i) => i.children ?? []);
    return children
        .filter((c) => route.path === c.path || route.path.startsWith(c.path + '/'))
        .sort((a, b) => b.path.length - a.path.length)[0]?.path;
});

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
                    'fixed inset-y-0 left-0 z-40 flex w-60 flex-col bg-sidebar text-sidebar-foreground transition-transform lg:sticky lg:top-0 lg:h-screen lg:translate-x-0',
                    mobileOpen ? 'translate-x-0' : '-translate-x-full',
                )
            "
        >
            <div class="relative border-b border-sidebar-border p-3">
                <button
                    class="flex w-full items-center gap-2.5 rounded-md p-2 text-left hover:bg-sidebar-hover"
                    data-testid="tenant-switcher"
                    @click="switcherOpen = !switcherOpen"
                >
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-md bg-sidebar-active text-xs font-semibold text-white">
                        {{ initials(tenant.current?.name ?? '?') }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-white">{{ tenant.current?.name }}</span>
                        <span class="block truncate text-xs text-sidebar-muted">{{ tenant.context?.role.name }}</span>
                    </span>
                    <ChevronsUpDown class="size-4 text-sidebar-muted" />
                </button>
                <div v-if="switcherOpen" class="absolute left-3 right-3 top-full z-50 mt-1 rounded-md border bg-card p-1 text-foreground shadow-lg">
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

            <nav class="flex-1 overflow-y-auto px-2 py-3">
                <div v-for="(group, i) in visibleGroups" :key="group.label" :class="i > 0 && 'mt-3 border-t border-sidebar-border pt-3'">
                    <p class="px-3 pb-1.5 text-xs font-bold uppercase text-white/90">{{ group.label }}</p>
                    <div v-for="item in group.items" :key="item.name">
                        <div
                            :class="
                                cn(
                                    'group flex items-center rounded-md text-sm text-sidebar-foreground hover:bg-sidebar-hover hover:text-white',
                                    isActive(item) && 'bg-sidebar-active font-medium text-white hover:bg-sidebar-active',
                                )
                            "
                        >
                            <!-- Like Sapo: an item with a sub-menu only opens / closes it; its pages are the sub-items. -->
                            <button
                                v-if="item.children?.length"
                                type="button"
                                class="flex min-w-0 flex-1 items-center gap-3 px-3 py-2 text-left"
                                :aria-expanded="open.has(item.name)"
                                @click="toggleOpen(item.name)"
                            >
                                <component :is="item.icon" class="size-[18px] shrink-0" :stroke-width="1.75" />
                                <span class="flex-1 truncate">{{ item.label }}</span>
                                <ChevronDown v-if="open.has(item.name)" class="size-4 opacity-80" /><ChevronRight v-else class="size-4 opacity-80" />
                            </button>
                            <RouterLink v-else :to="item.path" class="flex min-w-0 flex-1 items-center gap-3 px-3 py-2" @click="mobileOpen = false">
                                <component :is="item.icon" class="size-[18px] shrink-0" :stroke-width="1.75" />
                                <span class="flex-1 truncate">{{ item.label }}</span>
                                <Badge v-if="item.phase" tone="muted" class="px-1.5 text-[10px]">{{ item.phase > 8 ? "Sau MVP" : "P" + item.phase }}</Badge>
                            </RouterLink>
                        </div>
                        <div v-if="item.children?.length && open.has(item.name)" class="mb-1 mt-0.5 space-y-0.5">
                            <RouterLink
                                v-for="c in item.children"
                                :key="c.path"
                                :to="c.path"
                                :class="cn('block rounded-md py-1.5 pl-10 pr-3 text-[13px] text-sidebar-muted hover:bg-sidebar-hover hover:text-white', activeChild === c.path && 'font-medium text-sky-300 hover:text-sky-200')"
                                @click="mobileOpen = false"
                            >
                                {{ c.label }}
                            </RouterLink>
                        </div>
                    </div>
                </div>
            </nav>

            <div class="border-t border-sidebar-border p-3">
                <div class="flex items-center gap-2.5 rounded-md p-2">
                    <span class="flex size-8 items-center justify-center rounded-full bg-sidebar-hover text-xs font-semibold text-white">{{ initials(auth.user?.name ?? '') }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-white">{{ auth.user?.name }}</span>
                        <span class="block truncate text-xs text-sidebar-muted">{{ auth.user?.email }}</span>
                    </span>
                    <button class="rounded p-1.5 text-sidebar-muted hover:bg-sidebar-hover hover:text-white" title="Đăng xuất" @click="logout">
                        <LogOut class="size-4" />
                    </button>
                </div>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <!-- Top bar (B1, B2): global search (Ctrl + K) and the bell; on phones also the menu button. -->
            <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b bg-card/95 px-4 backdrop-blur lg:px-8">
                <!-- Phones use the bottom menu (MobileNav); the sidebar is for wide screens. -->
                <span class="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary text-xs font-semibold text-white lg:hidden">{{ initials(tenant.current?.name ?? '?') }}</span>
                <span class="min-w-0 flex-1 truncate font-semibold sm:hidden">{{ tenant.current?.name }}</span>
                <button
                    type="button"
                    class="hidden h-9 w-full max-w-md items-center gap-2 rounded-lg border bg-slate-50 px-3 text-sm text-muted-foreground hover:bg-slate-100 sm:flex"
                    data-testid="open-search"
                    @click="searchOpen = true"
                >
                    <Search class="size-4" />
                    <span class="flex-1 truncate text-left">Tìm đơn, khách, sản phẩm, vận đơn…</span>
                    <kbd class="rounded border border-b-2 bg-card px-1.5 text-[11px]">Ctrl K</kbd>
                </button>
                <div class="ml-auto flex items-center gap-1">
                    <button type="button" class="grid size-9 place-items-center rounded-lg text-slate-600 hover:bg-slate-100 sm:hidden" aria-label="Tìm kiếm" @click="searchOpen = true">
                        <Search class="size-[18px]" :stroke-width="1.75" />
                    </button>
                    <NotificationBell :key="tenant.current?.id" />
                </div>
            </header>
            <GlobalSearch v-model:open="searchOpen" />
            <main :class="cn('mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:pb-6', route.path === '/pos' ? 'pb-6' : 'pb-28')">
                <RouterView :key="tenant.current?.id" />
            </main>
            <MobileNav @switch-tenant="switchTenant" @logout="logout" />
            <InstallBanner />
        </div>
    </div>
</template>
