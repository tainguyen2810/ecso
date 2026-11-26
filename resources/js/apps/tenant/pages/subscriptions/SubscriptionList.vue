<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { AlarmClock, BellRing, CalendarClock, FilePlus2, RefreshCw, Search, Settings2, TrendingUp, UsersRound } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import HeaderLink from '../../components/HeaderLink.vue';
import Pagination from '../../components/Pagination.vue';
import Tabs from '../../components/Tabs.vue';
import RenewDialog from './RenewDialog.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import KpiCard from '@/shared/ui/KpiCard.vue';
import Select from '@/shared/ui/Select.vue';
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
    { value: 'expiring', label: 'Sắp hết hạn (30 ngày)' },
    { value: 'overdue', label: 'Quá hạn' },
    { value: 'active', label: 'Đang dùng' },
    { value: 'pending', label: 'Chờ xác nhận' },
    { value: 'paused', label: 'Tạm dừng' },
    { value: 'cancelled', label: 'Đã huỷ' },
] as const;

const q = (key: string) => (typeof route.query[key] === 'string' ? (route.query[key] as string) : '');
const filters = reactive({ tab: q('tab') || 'all', q: q('q'), product: q('product'), page: 1 });
const rows = ref<SubscriptionRow[]>([]);
const meta = ref<{ current_page: number; last_page: number; total: number; counts: Record<string, number>; kpi: Record<string, number | string> } | null>(null);
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

const leftTone = (r: SubscriptionRow) =>
    r.status !== 'active' || r.days_left === null ? 'text-muted-foreground' : r.days_left < 0 ? 'text-destructive font-medium' : r.days_left <= 7 ? 'text-amber-600 font-medium' : r.days_left <= 30 ? 'text-amber-600' : 'text-muted-foreground';
</script>

<template>
    <PageHeader title="Thuê bao, gia hạn" description="Dịch vụ khách dùng theo kỳ: hosting, tên miền, phần mềm, bảo trì… Gia hạn nối tiếp ngày hết hạn cũ, nhắc khách trước khi hết.">
        <template #links>
            <HeaderLink :icon="Settings2" to="/settings/subscriptions">Cấu hình nhắc gia hạn</HeaderLink>
            <HeaderLink v-if="tenant.can('products.manage')" :icon="RefreshCw" :to="{ path: '/products/new', query: { type: 'subscription' } }">Thêm dịch vụ thuê bao</HeaderLink>
        </template>
        <RouterLink v-if="canSell" to="/orders/new"><Button data-testid="sell-subscription"><FilePlus2 /> Bán thuê bao</Button></RouterLink>
    </PageHeader>

    <div class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" data-testid="subscription-kpis">
        <KpiCard label="Đang dùng" :value="kpi ? formatNumber(kpi.active) : null" :icon="UsersRound">{{ kpi ? `${formatNumber(kpi.customers)} khách hàng` : '' }}</KpiCard>
        <KpiCard label="Sắp hết hạn (30 ngày)" :value="kpi ? formatNumber(kpi.expiring) : null" :icon="CalendarClock" tone="amber" :active="filters.tab === 'expiring'">{{ kpi ? `Giá trị gia hạn ${formatMoney(kpi.expiring_value)}` : '' }}</KpiCard>
        <KpiCard label="Quá hạn" :value="kpi ? formatNumber(kpi.overdue) : null" :icon="AlarmClock" tone="red" :active="filters.tab === 'overdue'">{{ kpi ? `${formatNumber(kpi.in_grace)} còn trong ngày ân hạn` : '' }}</KpiCard>
        <KpiCard label="Doanh thu thuê bao / tháng" :value="kpi ? formatMoney(kpi.monthly) : null" :icon="TrendingUp" tone="green">{{ monthlyChange ?? 'Quy đổi từ các kỳ đang chạy' }}</KpiCard>
    </div>

    <Card>
        <div class="border-b px-4"><Tabs v-model="filters.tab" :tabs="tabs" variant="underline" /></div>
        <div class="flex flex-wrap items-center gap-2 border-b px-4 py-3">
            <div class="relative min-w-64 flex-1">
                <Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="filters.q" class="pl-9" placeholder="Tìm mã thuê bao, khách hàng, SĐT, tên miền…" data-testid="subscription-search" />
            </div>
            <Select v-model="filters.product" class="w-56" aria-label="Dịch vụ" :options="[{ value: '', label: 'Mọi dịch vụ' }, ...products.map((p) => ({ value: p.id, label: p.name }))]" data-testid="subscription-product" />
        </div>
        <div v-if="selected.length && canSell" class="flex flex-wrap items-center gap-2 border-b bg-blue-50/60 px-4 py-2 text-sm" data-testid="bulk-bar">
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
                <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th v-if="canSell" class="w-10 px-4 py-2"><input type="checkbox" class="size-4" :checked="allChecked" aria-label="Chọn tất cả" @change="toggleAll" /></th>
                        <th class="px-3 py-2 font-medium">Thuê bao</th>
                        <th class="px-3 py-2 font-medium">Khách hàng</th>
                        <th class="px-3 py-2 font-medium">Dịch vụ · gói</th>
                        <th class="px-3 py-2 font-medium">Hết hạn</th>
                        <th class="px-3 py-2 font-medium">Trạng thái</th>
                        <th class="px-3 py-2 text-right font-medium">Giá gia hạn</th>
                        <th class="px-3 py-2 font-medium">Phụ trách</th>
                        <th class="px-3 py-2" />
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="r in rows" :key="r.id" :class="cn('align-middle', selected.includes(r.id) && 'bg-blue-50/40')">
                        <td v-if="canSell" class="px-4 py-2.5"><input v-model="selected" type="checkbox" class="size-4" :value="r.id" :aria-label="`Chọn ${r.code}`" /></td>
                        <td class="px-3 py-2.5">
                            <RouterLink :to="`/subscriptions/${r.id}`" class="font-mono text-xs font-medium text-primary hover:underline">{{ r.code }}</RouterLink>
                            <p v-if="r.label" class="max-w-48 truncate text-xs text-muted-foreground" :title="r.label">{{ r.label }}</p>
                        </td>
                        <td class="px-3 py-2.5">
                            <RouterLink v-if="r.customer" :to="`/customers/${r.customer.id}`" class="hover:text-primary">{{ r.customer.name }}</RouterLink>
                            <p class="text-xs text-muted-foreground">{{ r.customer?.phone }}</p>
                        </td>
                        <td class="px-3 py-2.5">
                            <p class="max-w-56 truncate" :title="r.product?.name">{{ r.product?.name }}</p>
                            <p class="text-xs text-muted-foreground">{{ r.variant?.name ?? (r.variant?.period_months ? `${r.variant.period_months} tháng` : '—') }}</p>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2.5">
                            <p class="tabular-nums">{{ day(r.ends_on) }}</p>
                            <p :class="cn('text-xs', leftTone(r))">{{ r.status === 'active' ? leftText(r.days_left) : '' }}</p>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2.5">
                            <Badge :tone="stateLabels[r.state].tone">{{ stateLabels[r.state].label }}</Badge>
                            <RouterLink v-if="r.pending_order" :to="`/orders/${r.pending_order.id}`" class="mt-0.5 block text-xs text-primary hover:underline" title="Đơn gia hạn chờ xác nhận">Đơn chờ {{ r.pending_order.number }}</RouterLink>
                            <p v-else-if="r.reminders" class="mt-0.5 text-xs text-muted-foreground">Đã nhắc {{ r.reminders }} lần</p>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">{{ r.variant?.ref_price ? formatMoney(r.variant.ref_price) : '—' }}</td>
                        <td class="px-3 py-2.5 text-muted-foreground">{{ r.assignee?.name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2.5 text-right">
                            <Button v-if="canSell && r.status !== 'cancelled'" size="sm" variant="outline" :data-testid="`renew-${r.code}`" @click="renew(r)"><RefreshCw /> Gia hạn</Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination v-if="meta" :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((filters.page = p), load())" />
    </Card>

    <RenewDialog v-model:open="renewOpen" :subscription-id="renewId" @done="load" />
</template>
