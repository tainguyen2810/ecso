<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { AlarmClock, BarChart3, BellRing, CalendarClock, FilePlus2, Globe, Plus, RefreshCw, Search, Server, Settings2, ShieldCheck, TrendingUp, UsersRound } from 'lucide-vue-next';
import RenewDialog from './RenewDialog.vue';
import ProductsHead from '../products/ui/ProductsHead.vue';
import HeadButton from '../products/ui/HeadButton.vue';
import HeadLink from '../products/ui/HeadLink.vue';
import LineTabs from '../products/ui/LineTabs.vue';
import NumberPager from '../products/ui/NumberPager.vue';
import StatusChip from '../products/ui/StatusChip.vue';
import { card, chip, chipBase, row, rowButton, rowPrimary, shortMoney, th, thead, type ChipTone } from '../products/ui/ui';
import Button from '@/shared/ui/Button.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import type { Product, SubscriptionRow } from '@/shared/types';
import { day, leftText, stateLabels } from './subscriptions';

/** Thuê bao, gia hạn: every service a customer uses for a time, what is about to end, and renewing it in one click. */
const tenant = useTenantStore();
const toast = useToast();
const route = useRoute();
const router = useRouter();
const canSell = computed(() => tenant.can('orders.create'));

const TABS = [
    { value: 'all', label: 'Tất cả' },
    { value: 'expiring', label: 'Sắp hết hạn' },
    { value: 'overdue', label: 'Quá hạn' },
    { value: 'active', label: 'Đang dùng' },
    { value: 'pending', label: 'Chờ xác nhận' },
    { value: 'paused', label: 'Tạm dừng' },
    { value: 'cancelled', label: 'Đã huỷ' },
] as const;

const q = (key: string) => (typeof route.query[key] === 'string' ? (route.query[key] as string) : '');
const filters = reactive({ tab: q('tab') || 'all', q: q('q'), product: q('product'), page: 1 });
const rows = ref<SubscriptionRow[]>([]);
interface Kpi {
    active: number; customers: number; expiring: number; expiring_value: string; overdue: number; in_grace: number; monthly: string; monthly_prev: string;
    calendar?: { id: string; ends_on: string | null; customer: string | null; product: string | null; price: string | null }[];
    by_product?: { name: string; subscriptions: number; monthly: string }[];
}
const meta = ref<{ current_page: number; last_page: number; total: number; counts: Record<string, number>; kpi: Kpi } | null>(null);
const products = ref<Product[]>([]);
const loading = ref(true);
const loadError = ref<string | null>(null);
const selected = ref<string[]>([]);
const renewId = ref<string | null>(null);
const renewOpen = ref(false);
const bulkBusy = ref(false);

const tabs = computed(() => TABS.map((t) => ({ ...t, count: meta.value?.counts[t.value] ?? null })));
const kpi = computed(() => meta.value?.kpi ?? null);
const monthlyChange = computed(() => {
    const now = Number(kpi.value?.monthly ?? 0);
    const prev = Number(kpi.value?.monthly_prev ?? 0);
    if (!prev) return null;
    const pct = Math.round(((now - prev) / prev) * 100);
    return pct === 0 ? 'Bằng tháng trước' : `${pct > 0 ? '+' : ''}${pct}% so với tháng trước`;
});
const allChecked = computed(() => rows.value.length > 0 && rows.value.every((r) => selected.value.includes(r.id)));

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get('/subscriptions', { params: { tab: filters.tab, q: filters.q || undefined, product: filters.product || undefined, page: filters.page, per_page: 50 } });
        rows.value = data.data;
        meta.value = data.meta;
        selected.value = selected.value.filter((id) => rows.value.some((r) => r.id === id));
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}

let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    () => [filters.tab, filters.product],
    () => {
        filters.page = 1;
        router.replace({ query: { ...route.query, tab: filters.tab === 'all' ? undefined : filters.tab, product: filters.product || undefined } });
        load();
    },
);
watch(
    () => filters.q,
    () => {
        clearTimeout(timer);
        timer = setTimeout(() => ((filters.page = 1), load()), 300);
    },
);

onMounted(async () => {
    load();
    products.value = (await api.get('/products', { params: { type: 'subscription', per_page: 100 } }).catch(() => null))?.data.data ?? [];
});

function toggleAll() {
    selected.value = allChecked.value ? [] : rows.value.map((r) => r.id);
}
function renew(r: SubscriptionRow) {
    renewId.value = r.id;
    renewOpen.value = true;
}

async function bulk(action: 'renewal_orders' | 'remind') {
    bulkBusy.value = true;
    try {
        const { data } = await api.post('/subscriptions/bulk', { action, ids: selected.value });
        const { done, skipped } = data.data as { done: number; skipped: string[] };
        const what = action === 'renewal_orders' ? `Đã tạo ${done} đơn gia hạn chờ xác nhận` : `Đã nhắc ${done} khách`;
        toast.success(skipped.length ? `${what}. Bỏ qua ${skipped.length} thuê bao (chưa có kỳ, đã có đơn chờ hoặc vừa nhắc).` : `${what}.`);
        selected.value = [];
        load();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        bulkBusy.value = false;
    }
}

/** Coloured bar under the end date: how much of the current period is left (mẫu 3). */
const barOf = (r: SubscriptionRow) => {
    if (r.days_left === null || r.status !== 'active') return { w: 100, c: 'bg-slate-200' };
    if (r.days_left < 0) return { w: 100, c: 'bg-red-500' };
    const total = Math.max(30, (r.variant?.period_months ?? 12) * 30);
    const w = Math.max(4, Math.min(100, Math.round((r.days_left / total) * 100)));
    return { w: r.days_left <= 30 ? 100 - Math.min(96, r.days_left * 3) : w, c: r.days_left <= 30 ? 'bg-amber-500' : 'bg-emerald-500' };
};
const stateTone: Record<SubscriptionRow['state'], ChipTone> = { pending: 'blue', active: 'green', expiring: 'amber', overdue: 'red', expired: 'slate', paused: 'slate', cancelled: 'slate' };
/** Service icon by its name: hosting, domain, else a shield (bảo trì / phần mềm). */
const serviceIcon = (name?: string | null) => (/host|server|vps|cloud/i.test(name ?? '') ? Server : /miền|domain|\.vn|\.com/i.test(name ?? '') ? Globe : ShieldCheck);
const serviceTone = (name?: string | null) => (/host|server|vps|cloud/i.test(name ?? '') ? 'bg-indigo-50 text-indigo-600' : /miền|domain|\.vn|\.com/i.test(name ?? '') ? 'bg-sky-50 text-sky-600' : 'bg-emerald-50 text-emerald-600');
const monthlyMax = computed(() => Math.max(1, ...(kpi.value?.by_product ?? []).map((p) => Number(p.monthly))));
const barColors = ['bg-blue-600', 'bg-sky-500', 'bg-emerald-500', 'bg-violet-500', 'bg-amber-500', 'bg-rose-500'];
const calDay = (d: string | null) => (d ? { d: d.slice(8, 10), m: `TH${Number(d.slice(5, 7))}` } : { d: '—', m: '' });
const calTone = (d: string | null) => {
    if (!d) return 'bg-slate-50 text-slate-500';
    const left = Math.round((new Date(d).getTime() - Date.now()) / 86400000);
    return left <= 7 ? 'bg-red-50 text-red-600' : 'bg-amber-50 text-amber-700';
};

const leftTone = (r: SubscriptionRow) =>
    r.status !== 'active' || r.days_left === null ? 'text-muted-foreground' : r.days_left < 0 ? 'text-destructive font-medium' : r.days_left <= 7 ? 'text-amber-600 font-medium' : r.days_left <= 30 ? 'text-amber-600' : 'text-muted-foreground';
</script>

<template>
    <ProductsHead section="Đơn hàng" title="Thuê bao, gia hạn" description="Dịch vụ khách dùng theo kỳ: hosting, tên miền, phần mềm, bảo trì. Gia hạn nối tiếp ngày hết hạn cũ, nhắc khách trước khi hết.">
        <template #links>
            <HeadLink v-if="tenant.can('products.manage')" :icon="RefreshCw" :to="{ path: '/products/new', query: { type: 'subscription' } }">Thêm dịch vụ thuê bao</HeadLink>
        </template>
        <HeadButton :icon="Settings2" to="/settings/subscriptions">Cấu hình nhắc gia hạn</HeadButton>
        <HeadButton v-if="canSell" primary :icon="Plus" to="/orders/new" data-testid="sell-subscription">Bán thuê bao</HeadButton>
    </ProductsHead>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="subscription-kpis">
        <button type="button" class="relative overflow-hidden rounded-[14px] bg-gradient-to-br from-blue-600 to-indigo-600 p-5 text-left text-white shadow-sm" @click="filters.tab = 'active'">
            <div class="flex items-start justify-between"><span class="text-[14px] text-blue-100">Đang dùng</span><span class="grid size-10 place-items-center rounded-[11px] bg-white/15"><UsersRound class="size-5" /></span></div>
            <p class="mt-1 text-[30px] font-bold leading-none tabular-nums">{{ kpi ? formatNumber(kpi.active) : '…' }} <span class="text-[15px] font-medium text-blue-100">thuê bao</span></p>
            <span class="mt-3 inline-block rounded-md bg-white/15 px-2 py-0.5 text-[12px]">{{ kpi ? `${formatNumber(kpi.customers)} khách` : '' }}</span>
            <p class="mt-2 text-[13px] text-blue-100">Thuê bao còn hạn</p>
        </button>
        <button type="button" :class="cn(card, 'p-5 text-left', filters.tab === 'expiring' && 'ring-2 ring-amber-200')" @click="filters.tab = 'expiring'">
            <div class="flex items-start justify-between"><span class="text-[14px] text-slate-500">Sắp hết hạn</span><span class="grid size-10 place-items-center rounded-[11px] bg-amber-50 text-amber-600"><CalendarClock class="size-5" /></span></div>
            <p class="mt-1 text-[30px] font-bold leading-none text-amber-600 tabular-nums">{{ kpi ? formatNumber(kpi.expiring) : '…' }} <span class="text-[15px] font-medium text-slate-400">thuê bao</span></p>
            <span :class="cn(chipBase, chip.amber, 'mt-3')">30 ngày tới</span>
            <p class="mt-2 text-[13px] text-slate-500">Giá trị gia hạn {{ kpi ? formatMoney(kpi.expiring_value) : '' }}</p>
        </button>
        <button type="button" :class="cn(card, 'p-5 text-left', filters.tab === 'overdue' && 'ring-2 ring-red-200')" @click="filters.tab = 'overdue'">
            <div class="flex items-start justify-between"><span class="text-[14px] text-slate-500">Quá hạn</span><span class="grid size-10 place-items-center rounded-[11px] bg-red-50 text-red-600"><AlarmClock class="size-5" /></span></div>
            <p class="mt-1 text-[30px] font-bold leading-none text-red-600 tabular-nums">{{ kpi ? formatNumber(kpi.overdue) : '…' }} <span class="text-[15px] font-medium text-slate-400">thuê bao</span></p>
            <span :class="cn(chipBase, chip.red, 'mt-3')">{{ kpi ? `${formatNumber(kpi.in_grace)} còn trong ân hạn` : '' }}</span>
            <p class="mt-2 text-[13px] text-slate-500">Gia hạn sớm để khách không bị ngắt</p>
        </button>
        <div :class="cn(card, 'p-5')">
            <div class="flex items-start justify-between"><span class="text-[14px] text-slate-500">Doanh thu / tháng</span><span class="grid size-10 place-items-center rounded-[11px] bg-emerald-50 text-emerald-600"><TrendingUp class="size-5" /></span></div>
            <p class="mt-1 text-[30px] font-bold leading-none text-emerald-600 tabular-nums">{{ kpi ? formatMoney(kpi.monthly) : '…' }}</p>
            <span v-if="monthlyChange" :class="cn(chipBase, chip.green, 'mt-3')">{{ monthlyChange }}</span>
            <p class="mt-2 text-[13px] text-slate-500">Quy đổi từ các kỳ đang chạy</p>
        </div>
    </div>

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_400px]">
        <div :class="cn(card, 'min-w-0')">
            <LineTabs v-model="filters.tab" :tabs="tabs" />
            <div class="flex flex-wrap items-center gap-3 px-5 py-4">
                <div class="relative min-w-64 flex-1">
                    <Search class="pointer-events-none absolute left-3 top-3 size-4 text-slate-400" />
                    <input v-model="filters.q" class="h-10 w-full rounded-[10px] border border-slate-200 pl-9 pr-3 text-sm outline-none focus:border-blue-400" placeholder="Tìm mã thuê bao, khách hàng, SĐT, tên miền…" data-testid="subscription-search" />
                </div>
                <select v-model="filters.product" class="h-10 w-56 rounded-[10px] border border-slate-200 bg-white px-3 text-sm" aria-label="Dịch vụ" data-testid="subscription-product">
                    <option value="">Mọi dịch vụ</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
            </div>
            <div v-if="selected.length && canSell" class="flex flex-wrap items-center gap-2 border-t border-slate-100 bg-blue-50/60 px-5 py-2 text-sm" data-testid="bulk-bar">
                <span class="font-medium">Đã chọn {{ selected.length }}</span>
                <Button size="sm" variant="outline" :disabled="bulkBusy" data-testid="bulk-orders" @click="bulk('renewal_orders')"><FilePlus2 /> Tạo đơn gia hạn</Button>
                <Button size="sm" variant="outline" :disabled="bulkBusy" data-testid="bulk-remind" @click="bulk('remind')"><BellRing /> Nhắc khách ngay</Button>
                <button type="button" class="ml-auto text-xs text-muted-foreground hover:text-foreground" @click="selected = []">Bỏ chọn</button>
            </div>

            <ErrorState v-if="loadError" :message="loadError" class="m-4" />
            <div v-else-if="loading && !rows.length" class="space-y-2 p-4"><Skeleton v-for="n in 5" :key="n" class="h-12" /></div>
            <EmptyState v-else-if="!rows.length" :icon="RefreshCw" title="Chưa có thuê bao nào" :description="filters.tab === 'all' && !filters.q ? 'Tạo sản phẩm loại Thuê bao rồi bán cho khách như một đơn bình thường. Thuê bao sẽ hiện ở đây với ngày hết hạn.' : 'Không có thuê bao khớp bộ lọc.'" />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="subscription-table">
                    <thead :class="thead">
                        <tr>
                            <th v-if="canSell" :class="cn(th, 'w-10 pr-0')"><input type="checkbox" class="size-4" :checked="allChecked" aria-label="Chọn tất cả" @change="toggleAll" /></th>
                            <th :class="th">Dịch vụ</th>
                            <th :class="th">Khách hàng</th>
                            <th :class="th">Hết hạn</th>
                            <th :class="cn(th, 'text-right')">Giá trị kỳ</th>
                            <th :class="th">Trạng thái</th>
                            <th :class="th" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.id" :class="cn(row, selected.includes(r.id) && 'bg-blue-50/40')">
                            <td v-if="canSell" class="py-3.5 pl-4"><input v-model="selected" type="checkbox" class="size-4" :value="r.id" :aria-label="`Chọn ${r.code}`" /></td>
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-3">
                                    <span :class="cn('grid size-10 shrink-0 place-items-center rounded-[10px]', serviceTone(r.product?.name))"><component :is="serviceIcon(r.product?.name)" class="size-5" /></span>
                                    <div class="min-w-0">
                                        <p class="max-w-64 truncate text-[14px] font-medium text-slate-900" :title="r.product?.name">{{ r.product?.name }}<template v-if="r.label"> · <span class="font-normal text-slate-500">{{ r.label }}</span></template></p>
                                        <p class="text-[12px] text-slate-500"><RouterLink :to="`/subscriptions/${r.id}`" class="font-mono text-blue-700 hover:underline">{{ r.code }}</RouterLink> · {{ r.variant?.name ?? (r.variant?.period_months ? `${r.variant.period_months} tháng` : '—') }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3.5">
                                <RouterLink v-if="r.customer" :to="`/customers/${r.customer.id}`" class="text-[14px] text-slate-900 hover:text-primary">{{ r.customer.name }}</RouterLink>
                                <p class="text-[12px] text-slate-500">{{ r.customer?.phone }}</p>
                            </td>
                            <td class="w-56 px-4 py-3.5">
                                <div class="flex items-baseline justify-between gap-3 whitespace-nowrap">
                                    <span class="tabular-nums text-slate-800">{{ r.ends_on ? day(r.ends_on) : '—' }}</span>
                                    <span :class="cn('text-[12px]', leftTone(r))">{{ r.status === 'active' ? leftText(r.days_left) : '' }}</span>
                                </div>
                                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100"><div :class="cn('h-full rounded-full', barOf(r).c)" :style="{ width: `${barOf(r).w}%` }" /></div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3.5 text-right font-medium tabular-nums">{{ r.variant?.ref_price ? formatMoney(r.variant.ref_price) : '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3.5">
                                <StatusChip :tone="stateTone[r.state]">{{ stateLabels[r.state].label }}</StatusChip>
                                <RouterLink v-if="r.pending_order" :to="`/orders/${r.pending_order.id}`" class="mt-0.5 block text-[12px] text-primary hover:underline" title="Đơn gia hạn chờ xác nhận">Đơn chờ {{ r.pending_order.number }}</RouterLink>
                                <p v-else-if="r.reminders" class="mt-0.5 text-[12px] text-slate-500">Đã nhắc {{ r.reminders }} lần</p>
                                <p v-else-if="r.assignee" class="mt-0.5 text-[12px] text-slate-500">{{ r.assignee.name }}</p>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3.5 text-right">
                                <button v-if="canSell && r.status !== 'cancelled' && ['expiring', 'overdue', 'expired'].includes(r.state)" type="button" :class="rowPrimary" :data-testid="`renew-${r.code}`" @click="renew(r)">Gia hạn</button>
                                <button v-else-if="canSell && r.status !== 'cancelled'" type="button" :class="rowButton" :data-testid="`renew-${r.code}`" @click="renew(r)">Gia hạn</button>
                                <RouterLink v-else :to="`/subscriptions/${r.id}`" :class="rowButton">Chi tiết</RouterLink>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <NumberPager v-if="meta && rows.length" :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" :per-page="50" :count="rows.length" unit="thuê bao" @go="(p) => ((filters.page = p), load())" />
        </div>

        <aside class="space-y-6">
            <div :class="card" data-testid="subscription-calendar">
                <div class="flex items-start justify-between border-b border-slate-100 px-5 py-4">
                    <div><h3 class="font-semibold text-slate-900">Lịch hết hạn</h3><p class="mt-0.5 text-[13px] text-slate-500">30 ngày tới · nhắc khách tự động</p></div>
                    <button type="button" class="text-[13px] font-medium text-blue-700 hover:underline" @click="filters.tab = 'expiring'">Xem tất cả</button>
                </div>
                <p v-if="!kpi?.calendar?.length" class="px-5 py-8 text-center text-sm text-slate-400">Không có thuê bao nào hết hạn trong 30 ngày tới.</p>
                <RouterLink v-for="c in kpi?.calendar ?? []" :key="c.id" :to="`/subscriptions/${c.id}`" class="flex items-center gap-3 border-t border-slate-100 px-5 py-3 first-of-type:border-t-0 hover:bg-slate-50">
                    <span :class="cn('grid w-11 shrink-0 place-items-center rounded-[10px] py-1 leading-tight', calTone(c.ends_on))"><b class="text-[15px]">{{ calDay(c.ends_on).d }}</b><span class="text-[10px]">{{ calDay(c.ends_on).m }}</span></span>
                    <span class="min-w-0 flex-1"><span class="block truncate text-[14px] font-medium text-slate-900">{{ c.customer ?? 'Khách lẻ' }}</span><span class="block truncate text-[12px] text-slate-500">{{ c.product }}</span></span>
                    <span class="text-[13px] font-semibold tabular-nums text-slate-800">{{ c.price ? shortMoney(c.price) : '—' }}</span>
                </RouterLink>
            </div>
            <div :class="card" data-testid="subscription-by-product">
                <div class="flex items-start justify-between border-b border-slate-100 px-5 py-4">
                    <div><h3 class="font-semibold text-slate-900">Doanh thu theo dịch vụ</h3><p class="mt-0.5 text-[13px] text-slate-500">Quy đổi theo tháng</p></div>
                    <span class="grid size-10 place-items-center rounded-[11px] bg-emerald-50 text-emerald-600"><BarChart3 class="size-5" /></span>
                </div>
                <p v-if="!kpi?.by_product?.length" class="px-5 py-8 text-center text-sm text-slate-400">Chưa có kỳ thuê bao nào đang chạy.</p>
                <div v-else class="space-y-4 px-5 py-4">
                    <div v-for="(p, i) in kpi.by_product" :key="p.name">
                        <div class="flex items-baseline justify-between gap-3 text-[13px]">
                            <span class="truncate font-medium text-slate-800">{{ p.name }}</span>
                            <span class="whitespace-nowrap text-slate-500">{{ p.subscriptions }} thuê bao · <b class="text-slate-900">{{ shortMoney(p.monthly) }}</b>/tháng</span>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100"><div :class="cn('h-full rounded-full', barColors[i % barColors.length])" :style="{ width: `${Math.max(3, (Number(p.monthly) / monthlyMax) * 100)}%` }" /></div>
                    </div>
                </div>
            </div>
        </aside>
    </div>

    <RenewDialog v-model:open="renewOpen" :subscription-id="renewId" @done="load" />
</template>
