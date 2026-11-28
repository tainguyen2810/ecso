<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Check, ChevronDown, ChevronRight, ChevronsUpDown, LogOut, PanelLeftClose, PanelLeftOpen, Plus, RefreshCw, ScanBarcode, Search } from 'lucide-vue-next';
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
import ConnectChannelDialog from '../components/ConnectChannelDialog.vue';
import MobileNav from '../components/MobileNav.vue';
import InstallBanner from '../components/InstallBanner.vue';
import DemoBanner from '../components/DemoBanner.vue';
import { hidesPos } from '../pages/setup/setup';
import { inboxAvailable, inboxUnread, unreadLabel, watchInbox } from '../composables/ezchatInbox';

const auth = useAuthStore();
const tenant = useTenantStore();
const route = useRoute();
const router = useRouter();
/** Report, Thu chi, Kế toán thuế, Chấm công lương and Cấu hình pages use the whole width (Full HD first) so wide tables fit without sideways scrolling. */
const wide = computed(() => ['reports', 'accounting', 'cash', 'staff', 'settings'].includes(String(route.meta.nav)) || ['finance', 'report-cashbook'].includes(String(route.name)));
const toast = useToast();

const switcherOpen = ref(false);
const mobileOpen = ref(false);
const searchOpen = ref(false);

/** "Thu gọn menu" (wide screens): icons only, remembered on this browser. */
const COLLAPSED_KEY = 'ecso.sidebar.collapsed';
const collapsed = ref(readCollapsed());
function readCollapsed(): boolean {
    try {
        return localStorage.getItem(COLLAPSED_KEY) === '1';
    } catch {
        return false;
    }
}
function toggleCollapsed() {
    collapsed.value = !collapsed.value;
    flyout.value = null;
    switcherOpen.value = false;
    try {
        localStorage.setItem(COLLAPSED_KEY, collapsed.value ? '1' : '0');
    } catch {
        // Private window: the menu still folds, it is just not remembered.
    }
}
/** Folded menu: the item whose sub-pages show beside the menu. */
const flyout = ref<{ item: NavItem; top: number } | null>(null);
let flyoutTimer: ReturnType<typeof setTimeout> | undefined;
function showFlyout(item: NavItem, e: Event) {
    clearTimeout(flyoutTimer);
    const box = (e.currentTarget as HTMLElement).getBoundingClientRect();
    const rows = (item.children?.length ?? 0) * 32 + 40;
    flyout.value = { item, top: Math.max(8, Math.min(box.top, window.innerHeight - rows - 8)) };
}
function keepFlyout() {
    clearTimeout(flyoutTimer);
}
function leaveFlyout() {
    clearTimeout(flyoutTimer);
    flyoutTimer = setTimeout(() => (flyout.value = null), 150);
}

const canSee = (i: NavItem) => tenant.can(i.permission) || (i.anyPermission ?? []).some((p) => tenant.can(p));
const visibleGroups = computed(() =>
    navGroups
        .map((g) => ({
            ...g,
            items: g.items
                // "Tin nhắn" only once EzChat is joined and this person can open its inbox.
                .filter((i) => !i.hidden && canSee(i) && (i.name !== 'messages' || inboxAvailable.value))
                .map((i) => {
                    // A2: the POS pages leave the menu when the shop said it does not sell in a store.
                    const children = i.children?.filter((c) => (!c.permission || tenant.can(c.permission)) && !(c.path.startsWith('/pos') && hidesPos(tenant.current?.channels)) && (!c.advanced || tenant.current?.advanced_menu !== false || activeChild.value === c.path));
                    // A member who only sees part of a group lands on the first sub-page they may open.
                    return { ...i, children, path: tenant.can(i.permission) ? i.path : (children?.[0]?.path ?? i.path) };
                })
                .flatMap((i) => channelLine(i)),
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
const countsLoaded = ref(false);
/** A connected shop whose sync is failing: the dot on the "Trung tâm đồng bộ" button turns orange. */
const syncTrouble = ref(false);
async function loadShopCounts() {
    shopCounts.value = {};
    countsLoaded.value = false;
    syncTrouble.value = false;
    if (!tenant.can('marketplace.view')) return;
    try {
        const { data } = await api.get('/marketplace-accounts');
        const counts: Record<string, number> = {};
        const accounts = data.data as { marketplace: string; status: string; circuit_open?: boolean; last_error?: string | null }[];
        for (const a of accounts) if (a.status === 'connected') counts[a.marketplace] = (counts[a.marketplace] ?? 0) + 1;
        shopCounts.value = counts;
        syncTrouble.value = accounts.some((a) => a.status === 'connected' && (a.circuit_open || !!a.last_error));
        countsLoaded.value = true;
    } catch {
        // The menu still works without the counts.
    }
}
watch(() => tenant.current?.id, loadShopCounts, { immediate: true });
watch(() => tenant.current?.id, (id) => watchInbox(id), { immediate: true });
const shopCountLabel = (key: string) => {
    const n = shopCounts.value[key] ?? 0;
    return n ? `${n} ${key === 'woocommerce' ? 'web' : 'shop'}` : 'Chưa kết nối';
};
const hasChannel = computed(() => Object.values(shopCounts.value).some((n) => n > 0));
/**
 * Kênh bán lists only the marketplaces that have a shop (each on its own line, Tai's rule). Open menu (mẫu A): the
 * heading opens "Tất cả kênh" and carries + (Kết nối thêm kênh) and the sync button. Folded menu: those three stay
 * lines with icons. A shop with no channel yet sees only the "Kết nối kênh bán đầu tiên" line.
 */
function channelLine(i: NavItem): NavItem[] {
    if (i.marketplace) return countsLoaded.value && (shopCounts.value[i.marketplace] || isActive(i)) ? [i] : [];
    if (!collapsed.value && hasChannel.value && (i.name === 'marketplaces' || i.name === 'marketplace-sync')) return [];
    if (i.name === 'marketplaces' && countsLoaded.value && !hasChannel.value && !isActive(i)) return [];
    if (i.name !== 'marketplace-sync') return [i];
    const add = tenant.can('marketplace.connect') && countsLoaded.value ? [{ name: 'connect-channel', label: '', path: '', icon: Plus } as unknown as NavItem] : [];
    return [...add, ...(countsLoaded.value && !hasChannel.value && !isActive(i) ? [] : [i])];
}
const connectOpen = ref(false);
/** Kênh bán heading (open menu, mẫu A): a link to "Tất cả kênh" and the two small buttons beside it. */
const channelsHeading = computed(() => !collapsed.value && hasChannel.value);
const canSeeChannels = computed(() => tenant.can('marketplace.view'));
const allChannelsActive = computed(() => route.meta.nav === 'marketplaces' && !route.query.san && route.name !== 'marketplace-sync');
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
    <!-- Bán tại quầy runs full screen: its own dark top bar replaces the menu and the top bar. -->
    <div v-if="route.meta.fullscreen" class="min-h-screen bg-slate-50">
        <RouterView :key="tenant.current?.id" />
    </div>
    <div v-else class="flex min-h-screen">
        <div v-if="mobileOpen" class="fixed inset-0 z-30 bg-black/30 lg:hidden" @click="mobileOpen = false" />
        <aside
            :class="
                cn(
                    'fixed inset-y-0 left-0 z-40 flex w-60 flex-col bg-[#182537] text-slate-300 transition-[transform,width] duration-200 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0',
                    mobileOpen ? 'translate-x-0' : '-translate-x-full',
                    collapsed && 'lg:w-[68px]',
                )
            "
            data-testid="sidebar"
            :data-collapsed="collapsed || undefined"
        >
            <div :class="cn('relative flex items-center gap-1 border-b border-white/10 p-3', collapsed && 'lg:flex-col lg:px-2')">
                <button
                    :class="cn('flex min-w-0 flex-1 items-center gap-2.5 rounded-lg p-2 text-left hover:bg-white/5', collapsed && 'lg:flex-none lg:p-1')"
                    data-testid="tenant-switcher"
                    :title="collapsed ? tenant.current?.name : undefined"
                    @click="switcherOpen = !switcherOpen"
                >
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary text-xs font-semibold text-white">
                        {{ initials(tenant.current?.name ?? '?') }}
                    </span>
                    <span :class="cn('min-w-0 flex-1', collapsed && 'lg:hidden')">
                        <span class="block truncate text-sm font-semibold text-white">{{ tenant.current?.name }}</span>
                        <span class="block truncate text-xs text-slate-400">{{ tenant.context?.role.name }}</span>
                    </span>
                    <ChevronsUpDown :class="cn('size-4 text-slate-500', collapsed && 'lg:hidden')" />
                </button>
                <button
                    type="button"
                    class="hidden size-8 shrink-0 place-items-center rounded-lg text-slate-400 hover:bg-white/10 hover:text-white lg:grid"
                    :title="collapsed ? 'Mở rộng menu' : 'Thu gọn menu'"
                    :aria-label="collapsed ? 'Mở rộng menu' : 'Thu gọn menu'"
                    data-testid="sidebar-toggle"
                    @click="toggleCollapsed"
                >
                    <PanelLeftOpen v-if="collapsed" class="size-[18px]" /><PanelLeftClose v-else class="size-[18px]" />
                </button>
                <div v-if="switcherOpen" :class="cn('absolute left-3 right-3 top-full z-50 mt-1 rounded-md border bg-card p-1 text-foreground shadow-lg', collapsed && 'lg:right-auto lg:w-60')">
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

            <div v-if="tenant.can('orders.create')" :class="cn('px-2 pt-3', collapsed && 'lg:px-2')">
                <RouterLink to="/orders/new" class="flex h-10 items-center justify-center gap-2 rounded bg-primary text-sm font-medium text-white shadow-sm hover:bg-primary/90" data-testid="sidebar-new-order" :title="collapsed ? 'Tạo đơn' : undefined" @click="mobileOpen = false">
                    <Plus class="size-4 shrink-0" /> <span :class="collapsed ? 'lg:hidden' : ''">Tạo đơn</span>
                </RouterLink>
            </div>

            <nav :class="cn('sidebar-scroll flex-1 overflow-y-auto px-1 py-2', collapsed && 'lg:px-1')" @scroll="flyout = null">
                <div v-for="(group, i) in visibleGroups" :key="group.label" :class="i > 0 && 'mt-1 border-t border-white/10'">
                    <!-- Kênh bán (mẫu A): the heading opens "Tất cả kênh"; + and the sync button sit beside it. -->
                    <div v-if="group.label === 'Kênh bán' && channelsHeading" class="flex h-11 items-center gap-1 pl-3.5 pr-2" data-testid="channels-heading">
                        <RouterLink
                            v-if="canSeeChannels"
                            to="/marketplaces"
                            :class="cn('-ml-1.5 flex-1 truncate rounded px-1.5 py-1 text-[13px] font-semibold uppercase tracking-wide hover:bg-white/5', allChannelsActive ? 'text-[#4db1ff]' : 'text-slate-100')"
                            title="Tất cả kênh"
                            aria-label="Tất cả kênh"
                            data-testid="nav-all-channels"
                            @click="mobileOpen = false"
                        >{{ group.label }}</RouterLink>
                        <span v-else class="flex-1 text-[13px] font-semibold uppercase tracking-wide text-slate-100">{{ group.label }}</span>
                        <button
                            v-if="tenant.can('marketplace.connect')"
                            type="button"
                            class="grid size-7 place-items-center rounded-md bg-white/5 text-slate-300 hover:bg-white/10 hover:text-white"
                            title="Kết nối thêm kênh"
                            aria-label="Kết nối thêm kênh"
                            data-testid="nav-connect-channel"
                            @click="connectOpen = true; mobileOpen = false"
                        >
                            <Plus class="size-4" />
                        </button>
                        <RouterLink
                            v-if="tenant.can('sync.view')"
                            to="/marketplaces/sync"
                            :class="cn('relative grid size-7 place-items-center rounded-md hover:bg-white/10 hover:text-white', route.name === 'marketplace-sync' ? 'bg-[#0088ff] text-white' : 'bg-white/5 text-slate-300')"
                            :title="syncTrouble ? 'Trung tâm đồng bộ · có shop đang lỗi' : 'Trung tâm đồng bộ'"
                            aria-label="Trung tâm đồng bộ"
                            data-testid="nav-sync"
                            @click="mobileOpen = false"
                        >
                            <RefreshCw class="size-4" />
                            <span :class="cn('absolute right-1 top-1 size-[7px] rounded-full ring-[1.5px] ring-[#182537]', syncTrouble ? 'bg-amber-400' : 'bg-emerald-400')" />
                        </RouterLink>
                    </div>
                    <p v-else-if="i > 0" :class="cn('flex h-12 items-center px-3.5 text-[13px] font-semibold uppercase tracking-wide text-slate-100', collapsed && 'lg:hidden')">{{ group.label }}</p>
                    <div v-for="item in group.items" :key="item.name">
                        <button
                            v-if="item.name === 'connect-channel'"
                            type="button"
                            :class="cn('my-1 flex h-12 w-full items-center gap-2.5 rounded border border-dashed border-white/20 pl-2.5 pr-3 text-left text-sm text-slate-200 hover:border-white/40 hover:bg-white/5 hover:text-white', collapsed && 'lg:justify-center lg:px-0')"
                            data-testid="nav-connect-channel"
                            :title="collapsed ? (hasChannel ? 'Kết nối thêm kênh' : 'Kết nối kênh bán đầu tiên') : undefined"
                            @click="connectOpen = true; mobileOpen = false"
                        >
                            <Plus class="size-6 shrink-0" :stroke-width="1.5" />
                            <span :class="cn('flex-1 truncate', collapsed && 'lg:hidden')">{{ hasChannel ? 'Kết nối thêm kênh' : 'Kết nối kênh bán đầu tiên' }}</span>
                        </button>
                        <div
                            v-else
                            :class="
                                cn(
                                    'group relative flex items-center rounded text-sm text-slate-200 hover:bg-white/5 hover:text-white',
                                    item.marketplace && !collapsed ? 'my-0.5' : 'my-1',
                                    isActive(item) && (item.children?.length && !collapsed ? 'text-white' : 'bg-[#0088ff] text-white hover:bg-[#0088ff]'),
                                )
                            "
                            @mouseenter="collapsed && item.children?.length ? showFlyout(item, $event) : (flyout = null)"
                            @mouseleave="leaveFlyout"
                        >
                            <!-- Like Sapo: an item with a sub-menu only opens / closes it; its pages are the sub-items. Folded, they open beside it. -->
                            <button
                                v-if="item.children?.length"
                                type="button"
                                :class="cn('flex h-12 min-w-0 flex-1 items-center gap-2.5 pl-2.5 pr-3 text-left', collapsed && 'lg:justify-center lg:px-0')"
                                :aria-expanded="open.has(item.name)"
                                :title="collapsed ? item.label : undefined"
                                @click="collapsed ? showFlyout(item, $event) : toggleOpen(item.name)"
                            >
                                <component :is="item.icon" :class="cn('size-6 shrink-0', isActive(item) ? 'text-white' : 'text-slate-300 group-hover:text-white')" :stroke-width="1.5" />
                                <span :class="cn('flex-1 truncate', collapsed && 'lg:hidden')">{{ item.label }}</span>
                                <span :class="collapsed ? 'lg:hidden' : ''">
                                    <ChevronDown v-if="open.has(item.name)" class="size-4 text-slate-400" /><ChevronRight v-else class="size-4 text-slate-400" />
                                </span>
                            </button>
                            <RouterLink
                                v-else
                                :to="item.path"
                                :class="cn('flex min-w-0 flex-1 items-center gap-2.5 pl-2.5 pr-3', item.marketplace && !collapsed ? 'h-9' : 'h-12', collapsed && 'lg:justify-center lg:px-0')"
                                :title="collapsed ? item.label : undefined"
                                :data-testid="item.marketplace ? `nav-${item.marketplace}` : undefined"
                                @click="mobileOpen = false"
                            >
                                <MarketplaceLogo v-if="item.marketplace" :marketplace="item.marketplace" :name="item.label" size="sm" :class="cn('size-6', !shopCounts[item.marketplace] && 'opacity-40')" />
                                <component :is="item.icon" v-else :class="cn('size-6 shrink-0', isActive(item) ? 'text-white' : 'text-slate-300 group-hover:text-white')" :stroke-width="1.5" />
                                <span :class="cn('flex-1 truncate', item.marketplace && !shopCounts[item.marketplace] && !isActive(item) && 'text-slate-500', collapsed && 'lg:hidden')">{{ item.label }}</span>
                                <span
                                    v-if="item.marketplace && shopCounts[item.marketplace]"
                                    :class="cn('grid h-[18px] min-w-5 shrink-0 place-items-center rounded-full px-1.5 text-[11px] font-normal', isActive(item) ? 'bg-white/25 text-white' : 'bg-white/10 text-slate-300', collapsed && 'lg:hidden')"
                                    :title="shopCountLabel(item.marketplace)"
                                >{{ shopCounts[item.marketplace] }}</span>
                                <span v-else-if="item.marketplace" :class="cn('shrink-0 text-[11px] font-normal', isActive(item) ? 'text-white/80' : 'text-slate-500', collapsed && 'lg:hidden')">{{ shopCountLabel(item.marketplace) }}</span>
                                <span
                                    v-if="item.name === 'messages' && unreadLabel(inboxUnread)"
                                    :class="cn('grid h-5 min-w-[22px] shrink-0 place-items-center rounded-full bg-rose-500 px-1.5 text-[11px] font-bold text-white', collapsed && 'lg:absolute lg:right-2 lg:top-1 lg:h-4 lg:min-w-4 lg:px-1 lg:text-[10px]')"
                                    data-testid="messages-unread"
                                >{{ unreadLabel(inboxUnread) }}</span>
                                <Badge v-if="item.phase" tone="muted" :class="cn('px-1.5 text-[10px]', collapsed && 'lg:hidden')">{{ item.phase > 8 ? "Sau MVP" : "P" + item.phase }}</Badge>
                            </RouterLink>
                        </div>
                        <div v-if="item.children?.length && open.has(item.name)" :class="cn('mb-1', collapsed && 'lg:hidden')">
                            <RouterLink
                                v-for="c in item.children"
                                :key="c.path"
                                :to="c.path"
                                :class="cn('my-0.5 flex h-10 items-center rounded pl-[46px] pr-3 text-sm text-slate-300 hover:bg-white/5 hover:text-white', activeChild === c.path && 'bg-white/10 font-medium text-white hover:bg-white/10')"
                                @click="mobileOpen = false"
                            >
                                {{ c.label }}
                            </RouterLink>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Folded menu: the sub-pages of the item under the mouse, beside the menu. -->
            <div
                v-if="collapsed && flyout"
                class="fixed z-50 hidden w-56 rounded-lg border border-white/10 bg-[#182537] p-1.5 shadow-xl lg:block"
                :style="{ top: `${flyout.top}px`, left: '72px' }"
                data-testid="sidebar-flyout"
                @mouseenter="keepFlyout"
                @mouseleave="leaveFlyout"
            >
                <p class="px-2.5 pb-1 pt-1 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ flyout.item.label }}</p>
                <RouterLink
                    v-for="c in flyout.item.children"
                    :key="c.path"
                    :to="c.path"
                    :class="cn('block rounded-md px-2.5 py-1.5 text-[13px] text-slate-300 hover:bg-white/10 hover:text-white', activeChild === c.path && 'bg-[#0088ff] font-medium text-white hover:bg-[#0088ff]')"
                    @click="flyout = null"
                >
                    {{ c.label }}
                </RouterLink>
            </div>

            <div :class="cn('border-t border-white/10 p-3', collapsed && 'lg:px-2')">
                <div :class="cn('flex items-center gap-2.5 rounded-lg p-2', collapsed && 'lg:flex-col lg:p-0')">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-white/10 text-xs font-semibold text-slate-200" :title="collapsed ? auth.user?.name : undefined">{{ initials(auth.user?.name ?? '') }}</span>
                    <span :class="cn('min-w-0 flex-1', collapsed && 'lg:hidden')">
                        <span class="block truncate text-sm font-medium text-white">{{ auth.user?.name }}</span>
                        <span class="block truncate text-xs text-slate-400">{{ auth.user?.email }}</span>
                    </span>
                    <button class="rounded p-1.5 text-slate-400 hover:bg-white/10 hover:text-white" title="Đăng xuất" @click="logout">
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
                <span v-if="!route.meta.actionBar" class="min-w-0 flex-1 truncate font-semibold sm:hidden">{{ tenant.current?.name }}</span>
                <!-- Form pages like Tạo đơn (Sapo): their title and buttons take the place of the search box. -->
                <div v-if="route.meta.actionBar" id="page-actionbar" class="flex min-w-0 flex-1 items-center" />
                <button
                    v-else
                    type="button"
                    class="hidden h-9 w-full max-w-md items-center gap-2 rounded-lg border bg-slate-50 px-3 text-sm text-muted-foreground hover:bg-slate-100 sm:flex"
                    data-testid="open-search"
                    @click="searchOpen = true"
                >
                    <Search class="size-4" />
                    <span class="flex-1 truncate text-left">Tìm đơn, khách, sản phẩm, vận đơn…</span>
                    <kbd class="rounded border border-b-2 bg-card px-1.5 text-[11px]">Ctrl K</kbd>
                </button>
                <div v-if="!route.meta.actionBar" class="ml-auto flex items-center gap-1">
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
            <ConnectChannelDialog v-model:open="connectOpen" :counts="shopCounts" @connected="loadShopCounts" />
            <!-- Full-bleed pages (Tin nhắn): no padding, the page fills the space under the top bar. -->
            <main :class="route.meta.flush ? 'flex min-h-0 w-full flex-1 flex-col pb-16 lg:pb-0' : cn('mx-auto w-full flex-1 px-4 py-6 pb-28 sm:px-6 lg:px-8 lg:pb-6', !wide && 'max-w-[1600px]')">
                <RouterView :key="tenant.current?.id" />
            </main>
            <MobileNav @switch-tenant="switchTenant" @logout="logout" />
            <InstallBanner />
        </div>
    </div>
</template>
