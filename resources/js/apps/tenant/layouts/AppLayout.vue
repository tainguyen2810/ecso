<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Check, ChevronDown, ChevronRight, ChevronsUpDown, LogOut, Plus, ScanBarcode, Search } from 'lucide-vue-next';
import { useAuthStore } from '@/shared/stores/auth';
import { useTenantStore } from '@/shared/stores/tenant';
import { useGoShortcuts } from '@/shared/composables/useShortcuts';
import { useToast } from '@/shared/composables/useToast';
import { cn, initials } from '@/shared/lib/utils';
import Badge from '@/shared/ui/Badge.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import { api } from '@/shared/lib/api';
import { allNavItems, navGroups, type NavItem } from '../navigation';
import GlobalSearch from '../components/GlobalSearch.vue';
import NotificationBell from '../components/NotificationBell.vue';
import MobileNav from '../components/MobileNav.vue';
import InstallBanner from '../components/InstallBanner.vue';
import DemoBanner from '../components/DemoBanner.vue';
import { hidesPos } from '../pages/setup/setup';

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
                    // A2: the POS pages leave the menu when the shop said it does not sell in a store.
                    const children = i.children?.filter((c) => (!c.permission || tenant.can(c.permission)) && !(c.path.startsWith('/pos') && hidesPos(tenant.current?.channels)));
                    // A member who only sees part of a group lands on the first sub-page they may open.
                    return { ...i, children, path: tenant.can(i.permission) ? i.path : (children?.[0]?.path ?? i.path) };
                }),
        }))
        .filter((g) => g.items.length > 0),
);

// Sidebar sub-menus (like Sapo): the section of the current page is open; others open with the chevron.
const isActive = (item: NavItem) => {
    // Kênh bán: one line per marketplace (?san=key), "Tất cả kênh" for the rest of the marketplace pages, the sync centre on its own.
    if (item.marketplace) return route.meta.nav === 'marketplaces' && route.query.san === item.marketplace;
    if (item.name === 'marketplace-sync') return route.name === 'marketplace-sync';
    if (item.name === 'marketplaces') return route.meta.nav === 'marketplaces' && !route.query.san && route.name !== 'marketplace-sync';
    return route.meta.nav === item.name || (item.alsoActive ?? []).includes(String(route.meta.nav));
};

/** Connected shops per marketplace, shown beside each marketplace line. */
const shopCounts = ref<Record<string, number>>({});
async function loadShopCounts() {
    shopCounts.value = {};
    if (!tenant.can('marketplace.view')) return;
    try {
        const { data } = await api.get('/marketplace-accounts');
        const counts: Record<string, number> = {};
        for (const a of data.data as { marketplace: string; status: string }[]) if (a.status === 'connected') counts[a.marketplace] = (counts[a.marketplace] ?? 0) + 1;
        shopCounts.value = counts;
    } catch {
        // The menu still works without the counts.
    }
}
watch(() => tenant.current?.id, loadShopCounts, { immediate: true });
const shopCountLabel = (key: string) => {
    const n = shopCounts.value[key] ?? 0;
    return n ? `${n} ${key === 'woocommerce' ? 'web' : 'shop'}` : 'Chưa kết nối';
};
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
                    'fixed inset-y-0 left-0 z-40 flex w-60 flex-col border-r border-slate-200 bg-white text-slate-700 transition-transform lg:sticky lg:top-0 lg:h-screen lg:translate-x-0',
                    mobileOpen ? 'translate-x-0' : '-translate-x-full',
                )
            "
        >
            <div class="relative border-b border-slate-200 p-3">
                <button
                    class="flex w-full items-center gap-2.5 rounded-lg p-2 text-left hover:bg-slate-100"
                    data-testid="tenant-switcher"
                    @click="switcherOpen = !switcherOpen"
                >
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary text-xs font-semibold text-white">
                        {{ initials(tenant.current?.name ?? '?') }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-slate-900">{{ tenant.current?.name }}</span>
                        <span class="block truncate text-xs text-slate-500">{{ tenant.context?.role.name }}</span>
                    </span>
                    <ChevronsUpDown class="size-4 text-slate-400" />
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
                        <span v-if="t.is_demo" class="rounded bg-amber-100 px-1.5 text-[10px] font-bold text-amber-700">MẪU</span>
                        <Check v-if="t.id === tenant.current?.id" class="size-4 text-primary" />
                    </button>
                    <div class="my-1 border-t" />
                    <RouterLink to="/setup?new=1" class="flex items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-muted" @click="switcherOpen = false">
                        <Plus class="size-4" /> Tạo doanh nghiệp mới
                    </RouterLink>
                </div>
            </div>

            <div v-if="tenant.can('orders.create')" class="px-3 pt-3">
                <RouterLink to="/orders/new" class="flex h-9 items-center justify-center gap-2 rounded-lg bg-primary text-sm font-medium text-white shadow-sm hover:bg-primary/90" data-testid="sidebar-new-order" @click="mobileOpen = false">
                    <Plus class="size-4" /> Tạo đơn
                </RouterLink>
            </div>

            <nav class="flex-1 overflow-y-auto px-3 py-3">
                <div v-for="(group, i) in visibleGroups" :key="group.label" :class="i > 0 && 'mt-4'">
                    <p v-if="i > 0" class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ group.label }}</p>
                    <div v-for="item in group.items" :key="item.name">
                        <div
                            :class="
                                cn(
                                    'group flex items-center rounded-lg text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                                    isActive(item) && (item.children?.length ? 'text-primary hover:text-primary' : 'bg-primary/10 text-primary hover:bg-primary/10 hover:text-primary'),
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
                                <component :is="item.icon" :class="cn('size-[18px] shrink-0', isActive(item) ? 'text-primary' : 'text-slate-400')" :stroke-width="1.75" />
                                <span class="flex-1 truncate">{{ item.label }}</span>
                                <ChevronDown v-if="open.has(item.name)" class="size-4 text-slate-400" /><ChevronRight v-else class="size-4 text-slate-400" />
                            </button>
                            <RouterLink v-else :to="item.path" class="flex min-w-0 flex-1 items-center gap-3 px-3 py-2" :data-testid="item.marketplace ? `nav-${item.marketplace}` : undefined" @click="mobileOpen = false">
                                <MarketplaceLogo v-if="item.marketplace" :marketplace="item.marketplace" :name="item.label" size="sm" :class="cn('size-[18px]', !shopCounts[item.marketplace] && 'opacity-40')" />
                                <component :is="item.icon" v-else :class="cn('size-[18px] shrink-0', isActive(item) ? 'text-primary' : 'text-slate-400')" :stroke-width="1.75" />
                                <span :class="cn('flex-1 truncate', item.marketplace && !shopCounts[item.marketplace] && !isActive(item) && 'text-slate-400')">{{ item.label }}</span>
                                <span v-if="item.marketplace" class="shrink-0 text-[11px] font-normal text-slate-400">{{ shopCountLabel(item.marketplace) }}</span>
                                <Badge v-if="item.phase" tone="muted" class="px-1.5 text-[10px]">{{ item.phase > 8 ? "Sau MVP" : "P" + item.phase }}</Badge>
                            </RouterLink>
                        </div>
                        <div v-if="item.children?.length && open.has(item.name)" class="mb-1 ml-[21px] mt-0.5 space-y-px border-l border-slate-200 pl-2">
                            <RouterLink
                                v-for="c in item.children"
                                :key="c.path"
                                :to="c.path"
                                :class="cn('block rounded-md px-2.5 py-1.5 text-[13px] text-slate-600 hover:bg-slate-100 hover:text-slate-900', activeChild === c.path && 'bg-primary/10 font-medium text-primary hover:bg-primary/10 hover:text-primary')"
                                @click="mobileOpen = false"
                            >
                                {{ c.label }}
                            </RouterLink>
                        </div>
                    </div>
                </div>
            </nav>

            <div class="border-t border-slate-200 p-3">
                <div class="flex items-center gap-2.5 rounded-lg p-2">
                    <span class="flex size-8 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600">{{ initials(auth.user?.name ?? '') }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-slate-900">{{ auth.user?.name }}</span>
                        <span class="block truncate text-xs text-slate-500">{{ auth.user?.email }}</span>
                    </span>
                    <button class="rounded p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700" title="Đăng xuất" @click="logout">
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
                    <RouterLink v-if="tenant.can('orders.pack')" to="/orders/pack" class="mr-1 hidden h-9 items-center gap-2 rounded-lg border bg-card px-3 text-sm font-medium text-slate-700 hover:bg-slate-50 sm:flex" data-testid="topbar-scan">
                        <ScanBarcode class="size-4" :stroke-width="1.75" /> Quét đóng gói
                    </RouterLink>
                    <button type="button" class="grid size-9 place-items-center rounded-lg text-slate-600 hover:bg-slate-100 sm:hidden" aria-label="Tìm kiếm" @click="searchOpen = true">
                        <Search class="size-[18px]" :stroke-width="1.75" />
                    </button>
                    <NotificationBell :key="tenant.current?.id" />
                </div>
            </header>
            <DemoBanner />
            <GlobalSearch v-model:open="searchOpen" />
            <main :class="cn('mx-auto w-full max-w-[1600px] flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:pb-6', route.path === '/pos' ? 'pb-6' : 'pb-28')">
                <RouterView :key="tenant.current?.id" />
            </main>
            <MobileNav @switch-tenant="switchTenant" @logout="logout" />
            <InstallBanner />
        </div>
    </div>
</template>
