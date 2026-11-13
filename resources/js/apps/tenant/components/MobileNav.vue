<script setup lang="ts">
import { computed, ref, watch, type Component } from 'vue';
import { useRoute } from 'vue-router';
import {
    BarChart3,
    Boxes,
    Check,
    ClipboardCheck,
    Ellipsis,
    HandCoins,
    LayoutDashboard,
    LogOut,
    Package,
    PackagePlus,
    PlugZap,
    ScanBarcode,
    Plus,
    Settings,
    ShoppingCart,
    Smartphone,
    Store,
    Tag,
    Truck,
    Undo2,
    UsersRound,
    Wallet,
} from 'lucide-vue-next';
import { cn, initials } from '@/shared/lib/utils';
import { useAuthStore } from '@/shared/stores/auth';
import { useTenantStore } from '@/shared/stores/tenant';
import { inboxItems, ordersWaiting } from './inbox';
import { usePwaInstall } from '../pwa';

/**
 * Phone menu (B6): a bar at the bottom with the four places a shop owner opens most and "Thêm" for the rest.
 * Shown below the lg breakpoint, where the sidebar is hidden. The POS screen hides it to keep room for the cart.
 */
const emit = defineEmits<{ switchTenant: [id: string]; logout: [] }>();
const auth = useAuthStore();
const tenant = useTenantStore();
const route = useRoute();
const pwa = usePwaInstall();

const more = ref(false);
const iosHelp = ref(false);
watch(() => route.fullPath, () => (more.value = false));

type Tile = { label: string; to: string; icon: Component; can: boolean };
const any = (...p: string[]) => p.some((x) => tenant.can(x));
const groups = computed(() =>
    [
        {
            label: 'Bán hàng',
            tiles: [
                { label: 'Bán tại quầy', to: '/pos', icon: Store, can: any('orders.create') },
                { label: 'Khách hàng', to: '/customers', icon: UsersRound, can: any('customers.view') },
                { label: 'Đóng gói', to: '/orders/pack', icon: ScanBarcode, can: any('orders.pack') },
                { label: 'Vận chuyển', to: '/shipments', icon: Truck, can: any('orders.view') },
                { label: 'Trả hàng', to: '/returns', icon: Undo2, can: any('returns.view') },
            ],
        },
        {
            label: 'Hàng hóa',
            tiles: [
                { label: 'Sản phẩm', to: '/products', icon: Tag, can: any('products.view') },
                { label: 'Nhập hàng', to: '/purchasing', icon: PackagePlus, can: any('purchasing.view') },
                { label: 'Kiểm kho', to: '/inventory/stocktake', icon: ClipboardCheck, can: any('inventory.view') },
                { label: 'Kết nối sàn', to: '/marketplaces', icon: PlugZap, can: any('marketplace.view') },
            ],
        },
        {
            label: 'Tiền và cài đặt',
            tiles: [
                { label: 'Thu chi', to: '/cash', icon: HandCoins, can: any('finance.view') },
                { label: 'Tài chính', to: '/finance', icon: Wallet, can: any('finance.view') },
                { label: 'Báo cáo', to: '/reports', icon: BarChart3, can: any('reports.view') },
                { label: 'Cấu hình', to: '/settings', icon: Settings, can: any('settings.manage', 'members.view', 'billing.view', 'audit.view') },
            ],
        },
    ]
        .map((g) => ({ ...g, tiles: g.tiles.filter((t: Tile) => t.can) }))
        .filter((g) => g.tiles.length),
);

// The middle button creates an order; a member who cannot (e.g. warehouse staff) gets the stock list there.
const centre = computed(() => (tenant.can('orders.create') ? { label: 'Tạo đơn', to: '/orders/new', icon: Plus } : tenant.can('inventory.view') ? { label: 'Tồn kho', to: '/inventory', icon: Boxes } : null));
const waiting = computed(() => ordersWaiting(inboxItems.value));
const isActive = (prefixes: string[]) => prefixes.some((p) => (p === '/' ? route.path === '/' : route.path === p || route.path.startsWith(p + '/')));
const ordersActive = computed(() => isActive(['/orders']) && route.path !== '/orders/new');
const hidden = computed(() => route.path === '/pos');
</script>

<template>
    <template v-if="!hidden">
        <nav class="fixed inset-x-0 bottom-0 z-30 border-t bg-card pb-[env(safe-area-inset-bottom)] lg:hidden" aria-label="Menu chính" data-testid="mobile-nav">
            <div class="mx-auto flex h-16 max-w-xl items-start pt-2">
                <RouterLink v-if="tenant.can('dashboard.view')" to="/" :class="cn('flex flex-1 flex-col items-center gap-0.5 text-[11px] font-medium text-slate-500', isActive(['/']) && !more && 'text-blue-600')">
                    <LayoutDashboard class="size-5" :stroke-width="1.75" />Tổng quan
                </RouterLink>
                <RouterLink v-if="tenant.can('orders.view')" to="/orders" :class="cn('relative flex flex-1 flex-col items-center gap-0.5 text-[11px] font-medium text-slate-500', ordersActive && !more && 'text-blue-600')">
                    <ShoppingCart class="size-5" :stroke-width="1.75" />Đơn hàng
                    <span v-if="waiting" class="absolute left-1/2 top-[-5px] ml-1 grid h-4 min-w-4 place-items-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white" data-testid="mobile-orders-badge">{{ waiting > 99 ? '99+' : waiting }}</span>
                </RouterLink>
                <RouterLink v-if="centre" :to="centre.to" class="flex flex-1 flex-col items-center gap-0.5 text-[11px] font-medium text-slate-500" data-testid="mobile-create">
                    <span class="-mt-5 grid size-12 place-items-center rounded-2xl bg-primary text-white shadow-lg shadow-blue-600/30"><component :is="centre.icon" class="size-6" /></span>
                    {{ centre.label }}
                </RouterLink>
                <RouterLink v-if="tenant.can('inventory.view')" to="/inventory" :class="cn('flex flex-1 flex-col items-center gap-0.5 text-[11px] font-medium text-slate-500', isActive(['/inventory']) && !more && 'text-blue-600')">
                    <Package class="size-5" :stroke-width="1.75" />Kho
                </RouterLink>
                <button type="button" :class="cn('flex flex-1 flex-col items-center gap-0.5 text-[11px] font-medium text-slate-500', more && 'text-blue-600')" :aria-expanded="more" data-testid="mobile-more" @click="more = !more">
                    <Ellipsis class="size-5" :stroke-width="1.75" />Thêm
                </button>
            </div>
        </nav>

        <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition-opacity" leave-active-class="transition-opacity">
            <div v-if="more" class="fixed inset-0 z-20 bg-slate-900/40 lg:hidden" @click="more = false" />
        </Transition>
        <Transition enter-from-class="translate-y-full" leave-to-class="translate-y-full" enter-active-class="transition-transform duration-200" leave-active-class="transition-transform duration-150">
            <div v-if="more" class="fixed inset-x-0 bottom-0 z-[25] max-h-[85vh] overflow-y-auto rounded-t-2xl bg-card pb-[calc(5rem+env(safe-area-inset-bottom))] pt-2 lg:hidden" role="dialog" aria-label="Thêm" data-testid="mobile-more-sheet">
                <div class="mx-auto mb-2 h-1.5 w-10 rounded-full bg-slate-300" />
                <template v-for="g in groups" :key="g.label">
                    <p class="px-5 pb-1 pt-2 text-[11px] font-bold uppercase tracking-wide text-slate-400">{{ g.label }}</p>
                    <div class="grid grid-cols-4 gap-1 px-3">
                        <RouterLink v-for="t in g.tiles" :key="t.to" :to="t.to" class="flex flex-col items-center gap-1.5 rounded-xl px-1 py-2 text-center text-xs text-slate-700 active:bg-slate-100">
                            <span class="grid size-11 place-items-center rounded-xl bg-slate-100"><component :is="t.icon" class="size-5" :stroke-width="1.75" /></span>
                            {{ t.label }}
                        </RouterLink>
                    </div>
                </template>

                <button v-if="pwa.canInstall.value" type="button" class="mx-4 mt-3 flex w-[calc(100%-2rem)] items-center gap-3 rounded-xl border border-blue-100 bg-blue-50 px-3 py-2.5 text-left text-sm" data-testid="pwa-install" @click="pwa.install()">
                    <Smartphone class="size-5 text-blue-600" /><span class="flex-1"><b>Cài lên màn hình chính</b><span class="block text-xs text-muted-foreground">Mở EzSell nhanh như một app.</span></span>
                </button>
                <div v-else-if="pwa.isIos.value && !pwa.standalone.value" class="mx-4 mt-3 rounded-xl border border-blue-100 bg-blue-50 px-3 py-2.5 text-sm">
                    <button type="button" class="flex w-full items-center gap-3 text-left" @click="iosHelp = !iosHelp">
                        <Smartphone class="size-5 text-blue-600" /><span class="flex-1"><b>Cài lên màn hình chính</b><span class="block text-xs text-muted-foreground">Mở EzSell nhanh như một app.</span></span>
                    </button>
                    <ol v-if="iosHelp" class="mt-2 list-decimal space-y-1 pl-9 text-xs text-slate-700">
                        <li>Bấm nút <b>Chia sẻ</b> (ô vuông có mũi tên lên) ở thanh dưới của Safari.</li>
                        <li>Chọn <b>Thêm vào MH chính</b>, rồi bấm <b>Thêm</b>.</li>
                    </ol>
                </div>

                <div class="mx-4 mt-3 rounded-xl bg-slate-50 px-3 py-2.5">
                    <div class="flex items-center gap-3">
                        <span class="grid size-9 place-items-center rounded-full bg-primary text-xs font-semibold text-white">{{ initials(auth.user?.name ?? '') }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold">{{ auth.user?.name }}</span>
                            <span class="block truncate text-xs text-muted-foreground">{{ tenant.context?.role.name }} · {{ tenant.current?.name }}</span>
                        </span>
                        <button type="button" class="rounded p-2 text-muted-foreground hover:bg-muted" aria-label="Đăng xuất" @click="emit('logout')"><LogOut class="size-4" /></button>
                    </div>
                    <div v-if="tenant.tenants.length > 1" class="mt-2 space-y-0.5 border-t pt-2">
                        <button v-for="t in tenant.tenants" :key="t.id" type="button" class="flex w-full items-center gap-2 rounded px-1 py-1.5 text-left text-sm hover:bg-muted" @click="(more = false), emit('switchTenant', t.id)">
                            <span class="flex-1 truncate">{{ t.name }}</span><Check v-if="t.id === tenant.current?.id" class="size-4 text-primary" />
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </template>
</template>
