<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch, type Component } from 'vue';
import { useRouter } from 'vue-router';
import { ArrowRight, Download, FileText, Package, Plus, Search, ShoppingCart, Truck, UserRound, Wallet } from 'lucide-vue-next';
import { api } from '@/shared/lib/api';
import { cn, formatDateTime, formatMoney } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import Badge from '@/shared/ui/Badge.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import { orderStatus } from '../pages/orders/labels';
import { allNavItems } from '../navigation';
import { matches, pushRecent, readRecent, type RecentItem } from './search';

/**
 * Tìm kiếm chung (Ctrl + K): orders, customers, products, suppliers and pages in one box. Each group comes from the
 * list endpoint the member may already open, so permissions and tenant scope are those of the lists themselves.
 */
const open = defineModel<boolean>('open', { required: true });
const router = useRouter();
const tenant = useTenantStore();

type Scope = 'all' | 'orders' | 'customers' | 'products' | 'suppliers' | 'pages';
type Row = { key: string; group: Exclude<Scope, 'all'> | 'recent'; title: string; detail?: string; right?: string; rightTone?: 'red'; status?: string; marketplace?: string; icon?: Component; to: string; recent?: RecentItem };

const q = ref('');
const scope = ref<Scope>('all');
const loading = ref(false);
const active = ref(0);
const input = ref<HTMLInputElement | null>(null);
const results = ref<Record<'orders' | 'customers' | 'products' | 'suppliers', { rows: Row[]; total: number }>>(emptyResults());
const recent = ref<RecentItem[]>([]);

function emptyResults() {
    return { orders: { rows: [], total: 0 }, customers: { rows: [], total: 0 }, products: { rows: [], total: 0 }, suppliers: { rows: [], total: 0 } };
}

const groupLabels: Record<Row['group'], string> = { recent: 'Mở gần đây', orders: 'Đơn hàng', customers: 'Khách hàng', products: 'Sản phẩm', suppliers: 'Nhà cung cấp', pages: 'Trang và thao tác' };
const kindIcon: Record<RecentItem['kind'], Component> = { order: ShoppingCart, customer: UserRound, product: Package, supplier: Truck };

/** Pages of the menu the member may open, plus the actions people use most. */
const pages = computed<Row[]>(() => {
    const rows: Row[] = [];
    const actions: [string, string, string, string, Component][] = [
        ['Tạo đơn hàng', 'Đơn kênh riêng, giao hàng', '/orders/new', 'orders.create', Plus],
        ['Đẩy qua vận chuyển', 'Đơn chờ đẩy sang hãng', '/shipments', 'orders.view', Truck],
        ['Tạo đơn nhập hàng', 'Sản phẩm › Nhập hàng', '/purchasing/new', 'purchasing.manage', Download],
        ['Thêm sản phẩm', 'Sản phẩm', '/products/new', 'products.manage', Plus],
        ['Phiếu thu, phiếu chi', 'Thu chi', '/cash', 'finance.view', Wallet],
    ];
    for (const [title, detail, to, perm, icon] of actions) if (tenant.can(perm)) rows.push({ key: `a:${to}`, group: 'pages', title, detail, to, icon });
    for (const item of allNavItems) {
        if (!tenant.can(item.permission) && !(item.anyPermission ?? []).some((p) => tenant.can(p))) continue;
        if (!item.children?.length) {
            if (!rows.some((r) => r.to === item.path)) rows.push({ key: `p:${item.path}`, group: 'pages', title: item.label, detail: 'Trang', to: item.path, icon: item.icon });
            continue;
        }
        for (const c of item.children) {
            if ((c.permission && !tenant.can(c.permission)) || rows.some((r) => r.to === c.path)) continue;
            rows.push({ key: `p:${c.path}`, group: 'pages', title: c.label, detail: item.label, to: c.path, icon: item.icon });
        }
    }
    return rows;
});
const pageMatches = computed(() => (q.value.trim() ? pages.value.filter((p) => matches(`${p.title} ${p.detail ?? ''}`, q.value)) : pages.value.slice(0, 4)));

/** Rows shown, in display order (keyboard moves through this list). */
const rows = computed<Row[]>(() => {
    const term = q.value.trim();
    if (!term) {
        const rec: Row[] = recent.value.map((r) => ({ key: `r:${r.to}`, group: 'recent', title: r.title, detail: r.detail, to: r.to, icon: kindIcon[r.kind], recent: r }));
        return [...rec, ...pageMatches.value];
    }
    const groups: Exclude<Scope, 'all' | 'pages'>[] = ['customers', 'orders', 'products', 'suppliers'];
    if (scope.value === 'pages') return pageMatches.value;
    if (scope.value !== 'all') return results.value[scope.value].rows;
    return [...groups.flatMap((g) => results.value[g].rows.slice(0, 3)), ...pageMatches.value.slice(0, 3)];
});
const counts = computed(() => ({
    orders: results.value.orders.total,
    customers: results.value.customers.total,
    products: results.value.products.total,
    suppliers: results.value.suppliers.total,
    pages: pageMatches.value.length,
}));
const totalCount = computed(() => Object.values(counts.value).reduce((a, b) => a + b, 0));
const scopes = computed(() =>
    (
        [
            ['all', 'Tất cả'],
            ['orders', 'Đơn hàng', 'orders.view'],
            ['customers', 'Khách hàng', 'customers.view'],
            ['products', 'Sản phẩm', 'products.view'],
            ['suppliers', 'Nhà cung cấp', 'purchasing.view'],
            ['pages', 'Trang'],
        ] as [Scope, string, string?][]
    ).filter(([, , perm]) => !perm || tenant.can(perm)),
);
const seeAll: Partial<Record<Row['group'], (term: string) => string>> = {
    orders: (t) => `/orders?q=${encodeURIComponent(t)}`,
    customers: (t) => `/customers?q=${encodeURIComponent(t)}`,
    products: (t) => `/products?q=${encodeURIComponent(t)}`,
};

let timer: ReturnType<typeof setTimeout> | undefined;
let seq = 0;
watch(q, (term) => {
    active.value = 0;
    clearTimeout(timer);
    if (term.trim().length < 2) {
        results.value = emptyResults();
        loading.value = false;
        return;
    }
    loading.value = true;
    timer = setTimeout(() => search(term.trim()), 250);
});
watch(scope, () => (active.value = 0));

type Paged<T> = { data: T[]; meta?: { total?: number } };
async function search(term: string) {
    const mine = ++seq;
    const get = <T,>(perm: string, url: string, params: Record<string, unknown>) =>
        tenant.can(perm) ? api.get<Paged<T>>(url, { params: { q: term, ...params } }).then((r) => r.data).catch(() => ({ data: [] as T[], meta: { total: 0 } })) : Promise.resolve({ data: [] as T[], meta: { total: 0 } });

    type O = { id: string; external_order_id: string; marketplace: string; status: string; total: string; placed_at: string | null; buyer_name: string | null; customer?: { name: string } | null; shop?: { name: string } | null; channel?: { name: string } | null };
    type C = { id: string; name: string; phone: string | null; code: string | null; debt?: string | null; orders_count?: number | null };
    type V = { id: string; sku: string; name: string | null; product?: { id: string; name: string } };
    type S = { id: string; name: string; code: string | null; phone: string | null; debt?: string | null };
    const [o, c, v, s] = await Promise.all([
        get<O>('orders.view', '/orders', { per_page: 10 }),
        get<C>('customers.view', '/customers', { per_page: 5 }),
        get<V>('products.view', '/variants', {}),
        get<S>('purchasing.view', '/suppliers', { per_page: 5 }),
    ]);
    if (mine !== seq) return;
    results.value = {
        orders: {
            total: o.meta?.total ?? o.data.length,
            rows: o.data.map((x) => ({
                key: `o:${x.id}`,
                group: 'orders',
                title: x.external_order_id,
                detail: [x.shop?.name ?? x.channel?.name, x.customer?.name ?? x.buyer_name, formatDateTime(x.placed_at)].filter(Boolean).join(' · '),
                right: formatMoney(x.total),
                status: x.status,
                marketplace: x.marketplace,
                to: `/orders/${x.id}`,
            })),
        },
        customers: {
            total: c.meta?.total ?? c.data.length,
            rows: c.data.map((x) => ({
                key: `c:${x.id}`,
                group: 'customers',
                title: x.name,
                detail: [x.phone, x.code, x.orders_count ? `${x.orders_count} đơn` : null].filter(Boolean).join(' · '),
                right: Number(x.debt ?? 0) > 0 ? `Còn nợ ${formatMoney(x.debt)}` : undefined,
                rightTone: 'red',
                icon: UserRound,
                to: `/customers/${x.id}`,
            })),
        },
        products: {
            total: v.data.length,
            rows: v.data.slice(0, 10).map((x) => ({
                key: `v:${x.id}`,
                group: 'products',
                title: [x.product?.name, x.name].filter(Boolean).join(' · '),
                detail: x.sku,
                icon: Package,
                to: x.product ? `/products/${x.product.id}` : `/products?q=${encodeURIComponent(x.sku)}`,
            })),
        },
        suppliers: {
            total: s.meta?.total ?? s.data.length,
            rows: s.data.map((x) => ({
                key: `s:${x.id}`,
                group: 'suppliers',
                title: x.name,
                detail: [x.code, x.phone].filter(Boolean).join(' · '),
                right: Number(x.debt ?? 0) > 0 ? `Còn nợ ${formatMoney(x.debt)}` : undefined,
                rightTone: 'red',
                icon: Truck,
                to: `/purchasing/suppliers/${x.id}`,
            })),
        },
    };
    loading.value = false;
}

const kindOf: Partial<Record<Row['group'], RecentItem['kind']>> = { orders: 'order', customers: 'customer', products: 'product', suppliers: 'supplier' };
function go(row: Row, newTab = false) {
    const kind = row.recent?.kind ?? kindOf[row.group];
    if (kind && tenant.current) recent.value = pushRecent(tenant.current.id, { kind, title: row.title, detail: row.detail ?? '', to: row.to });
    if (newTab) {
        window.open(router.resolve(row.to).href, '_blank');
        return;
    }
    open.value = false;
    router.push(row.to);
}

function onKey(e: KeyboardEvent) {
    const n = rows.value.length;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        active.value = n ? (active.value + 1) % n : 0;
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        active.value = n ? (active.value - 1 + n) % n : 0;
    } else if (e.key === 'Enter') {
        e.preventDefault();
        const row = rows.value[active.value];
        if (row) go(row, e.ctrlKey || e.metaKey);
    } else if (e.key === 'Tab' && q.value.trim()) {
        e.preventDefault();
        const list = scopes.value.map(([s]) => s);
        const i = list.indexOf(scope.value);
        scope.value = list[(i + (e.shiftKey ? list.length - 1 : 1)) % list.length]!;
    } else if (e.key === 'Escape') {
        open.value = false;
    }
}
watch(active, async (i) => {
    await nextTick();
    document.querySelector(`[data-search-index="${i}"]`)?.scrollIntoView({ block: 'nearest' });
});

watch(open, async (v) => {
    if (!v) return;
    q.value = '';
    scope.value = 'all';
    active.value = 0;
    results.value = emptyResults();
    recent.value = tenant.current ? readRecent(tenant.current.id) : [];
    await nextTick();
    input.value?.focus();
});

// Ctrl + K (⌘ K on Mac) opens the search from anywhere, even while typing in a field.
function onGlobalKey(e: KeyboardEvent) {
    if ((e.ctrlKey || e.metaKey) && !e.altKey && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        open.value = !open.value;
    }
}
onMounted(() => window.addEventListener('keydown', onGlobalKey));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onGlobalKey);
    clearTimeout(timer);
});

/** Group heading before a row when the group changes. */
const headingAt = (i: number) => (i === 0 || rows.value[i - 1]!.group !== rows.value[i]!.group ? rows.value[i]!.group : null);
const highlight = (text: string) => {
    const term = q.value.trim();
    if (term.length < 2) return [{ text, hit: false }];
    const i = text.toLowerCase().indexOf(term.toLowerCase());
    return i < 0 ? [{ text, hit: false }] : [{ text: text.slice(0, i), hit: false }, { text: text.slice(i, i + term.length), hit: true }, { text: text.slice(i + term.length), hit: false }];
};
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/40 p-3 pt-[8vh] sm:p-4 sm:pt-[10vh]" data-testid="global-search" @mousedown.self="open = false">
            <div class="flex max-h-[80vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl bg-card shadow-2xl" role="dialog" aria-label="Tìm kiếm">
                <div class="flex h-14 items-center gap-3 border-b px-4">
                    <Search :class="cn('size-5 shrink-0', q ? 'text-blue-600' : 'text-muted-foreground')" />
                    <input
                        ref="input"
                        v-model="q"
                        type="search"
                        class="h-full flex-1 bg-transparent text-base outline-none placeholder:text-muted-foreground"
                        placeholder="Tìm mã đơn, mã vận đơn, SĐT khách, SKU, tên sản phẩm… hoặc tên trang"
                        aria-label="Từ khóa tìm kiếm"
                        data-testid="global-search-input"
                        @keydown="onKey"
                    />
                    <span v-if="q.trim().length >= 2" class="shrink-0 text-xs text-muted-foreground">{{ loading ? 'Đang tìm…' : `${totalCount} kết quả` }}</span>
                    <kbd class="hidden rounded border border-b-2 bg-card px-1.5 text-[11px] text-muted-foreground sm:inline">Esc</kbd>
                </div>
                <div v-if="q.trim().length >= 2" class="flex gap-1.5 overflow-x-auto border-b px-4 py-2.5">
                    <button
                        v-for="[s, label] in scopes"
                        :key="s"
                        type="button"
                        :class="cn('shrink-0 rounded-full border px-3 py-0.5 text-xs text-slate-600 hover:bg-slate-50', scope === s && 'border-blue-200 bg-blue-50 font-semibold text-blue-700')"
                        @click="(scope = s), input?.focus()"
                    >
                        {{ label }}<template v-if="s !== 'all'"> {{ counts[s] }}</template>
                    </button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto py-1.5">
                    <template v-for="(row, i) in rows" :key="row.key">
                        <div v-if="headingAt(i)" class="flex items-center justify-between px-4 pb-1 pt-2.5 text-[11px] font-bold uppercase tracking-wide text-slate-400">
                            {{ groupLabels[row.group] }}
                            <button
                                v-if="scope === 'all' && q.trim() && seeAll[row.group] && counts[row.group as 'orders'] > 3"
                                type="button"
                                class="text-xs font-medium normal-case tracking-normal text-blue-600 hover:underline"
                                @click="(open = false), router.push(seeAll[row.group]!(q.trim()))"
                            >
                                Xem tất cả {{ counts[row.group as 'orders'] }} ›
                            </button>
                        </div>
                        <button
                            type="button"
                            :data-search-index="i"
                            :class="cn('mx-1.5 flex w-[calc(100%-12px)] items-center gap-3 rounded-lg px-2.5 py-2 text-left', i === active ? 'bg-blue-50' : 'hover:bg-slate-50')"
                            data-testid="search-row"
                            @mouseenter="active = i"
                            @click="go(row, false)"
                        >
                            <MarketplaceLogo v-if="row.marketplace" :marketplace="row.marketplace" size="md" class="rounded-full" />
                            <span v-else :class="cn('grid size-8 shrink-0 place-items-center rounded-full', row.group === 'pages' ? 'bg-blue-50 text-blue-600' : 'bg-slate-100 text-slate-600')">
                                <component :is="row.icon ?? FileText" class="size-4" :stroke-width="1.75" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold">
                                    <template v-for="(part, j) in highlight(row.title)" :key="j"><mark v-if="part.hit" class="rounded-sm bg-yellow-200 px-px">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template>
                                </span>
                                <span v-if="row.detail" class="block truncate text-xs text-muted-foreground">
                                    <template v-for="(part, j) in highlight(row.detail)" :key="j"><mark v-if="part.hit" class="rounded-sm bg-yellow-200 px-px">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template>
                                </span>
                            </span>
                            <span v-if="row.right || row.status" class="shrink-0 text-right text-xs">
                                <span v-if="row.right" :class="cn('block font-semibold tabular-nums', row.rightTone === 'red' && 'text-red-600')">{{ row.right }}</span>
                                <Badge v-if="row.status" :tone="orderStatus[row.status]?.tone ?? 'muted'" class="mt-0.5">{{ orderStatus[row.status]?.label ?? row.status }}</Badge>
                            </span>
                            <ArrowRight v-if="i === active" class="size-4 shrink-0 text-slate-400" />
                        </button>
                    </template>
                    <p v-if="!rows.length && !loading" class="px-4 py-10 text-center text-sm text-muted-foreground">
                        {{ q.trim().length >= 2 ? 'Không tìm thấy kết quả nào.' : 'Gõ ít nhất 2 ký tự để tìm.' }}
                    </p>
                </div>
                <div class="hidden items-center gap-4 border-t bg-slate-50 px-4 py-2 text-xs text-muted-foreground sm:flex">
                    <span><kbd class="rounded border border-b-2 bg-card px-1">↑</kbd> <kbd class="rounded border border-b-2 bg-card px-1">↓</kbd> chọn</span>
                    <span><kbd class="rounded border border-b-2 bg-card px-1">↵</kbd> mở</span>
                    <span><kbd class="rounded border border-b-2 bg-card px-1">Ctrl</kbd> + <kbd class="rounded border border-b-2 bg-card px-1">↵</kbd> mở tab mới</span>
                    <span v-if="q.trim()"><kbd class="rounded border border-b-2 bg-card px-1">Tab</kbd> đổi nhóm</span>
                    <span class="ml-auto">Chỉ hiện mục bạn có quyền xem</span>
                </div>
            </div>
        </div>
    </Teleport>
</template>
