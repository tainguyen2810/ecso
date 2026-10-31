<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { AlertTriangle, Clock, PackageX, Printer, RefreshCw, Search, ShoppingCart, Unlink, UserPlus } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Pagination from '../../components/Pagination.vue';
import Tabs from '../../components/Tabs.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime, formatMoney } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { actionLabels, deadlineLabel, marketplaceNames, orderFlags, orderStatus, orderTabs } from './labels';
import { downloadPackingSlips } from './packingSlip';
import type { MarketplaceAccountData, OrderSummary, Ref } from '@/shared/types';

const route = useRoute();
const router = useRouter();
const tenant = useTenantStore();
const toast = useToast();

const orders = ref<OrderSummary[]>([]);
const counts = ref<Record<string, number>>({});
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const loadError = ref<string | null>(null);
const accounts = ref<MarketplaceAccountData[]>([]);
const assignees = ref<Ref[]>([]);

const filters = reactive({
    tab: (route.query.tab as string) ?? 'all',
    q: (route.query.q as string) ?? '',
    account: (route.query.account as string) ?? '',
    assignee: (route.query.assignee as string) ?? '',
    flags: route.query.flags ? String(route.query.flags).split(',') : ([] as string[]),
    sort: (route.query.sort as string) ?? 'newest',
    page: Number(route.query.page ?? 1),
});

const selected = ref<Set<string>>(new Set());
const allSelected = computed(() => orders.value.length > 0 && orders.value.every((o) => selected.value.has(o.id)));
const busy = ref<string | null>(null);
const assignOpen = ref(false);
const assignTo = ref('');
const bulkResult = ref<{ id: string; ok: boolean; message: string | null }[] | null>(null);

const tabs = computed(() => orderTabs.map((t) => ({ ...t, count: t.value === 'all' ? null : (counts.value[t.value] ?? 0) })));

function query() {
    return {
        tab: filters.tab === 'all' ? undefined : filters.tab,
        q: filters.q || undefined,
        account: filters.account || undefined,
        assignee: filters.assignee || undefined,
        flags: filters.flags.length ? filters.flags : undefined,
        sort: filters.sort === 'newest' ? undefined : filters.sort,
        page: filters.page > 1 ? filters.page : undefined,
    };
}

async function load() {
    loading.value = true;
    loadError.value = null;
    router.replace({ query: { ...query(), flags: filters.flags.length ? filters.flags.join(',') : undefined } });
    try {
        const { data } = await api.get('/orders', { params: query() });
        orders.value = data.data;
        meta.value = data.meta;
        counts.value = data.meta.counts ?? {};
        selected.value = new Set([...selected.value].filter((id) => orders.value.some((o) => o.id === id)));
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    load();
    const [a, m] = await Promise.allSettled([api.get('/marketplace-accounts'), api.get('/orders/assignees')]);
    if (a.status === 'fulfilled') accounts.value = a.value.data.data;
    if (m.status === 'fulfilled') assignees.value = m.value.data.data;
});

let searchTimer: ReturnType<typeof setTimeout> | undefined;
watch(() => filters.q, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => ((filters.page = 1), load()), 300);
});
watch(() => [filters.tab, filters.account, filters.assignee, filters.sort, filters.flags.join()], () => ((filters.page = 1), (selected.value = new Set()), load()));

function toggleFlag(flag: string) {
    filters.flags = filters.flags.includes(flag) ? filters.flags.filter((f) => f !== flag) : [...filters.flags, flag];
}

function toggle(id: string) {
    const next = new Set(selected.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    selected.value = next;
}
function toggleAll() {
    selected.value = allSelected.value ? new Set() : new Set(orders.value.map((o) => o.id));
}

async function bulk(action: string, extra: Record<string, unknown> = {}) {
    busy.value = action;
    try {
        const { data } = await api.post('/orders/bulk', { ids: [...selected.value], action, ...extra });
        const results = data.data as { id: string; ok: boolean; message: string | null }[];
        const failed = results.filter((r) => !r.ok);
        if (failed.length) bulkResult.value = results;
        else toast.success(`Đã xử lý ${results.length} đơn.`);
        selected.value = new Set();
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}

async function assignSelected() {
    await bulk('assign', { user_id: assignTo.value || null });
    assignOpen.value = false;
}

async function print() {
    busy.value = 'print';
    try {
        const { data } = await api.post('/orders/packing-slips', { ids: [...selected.value] });
        await downloadPackingSlips(data.data);
        toast.success(`Đã tạo phiếu đóng gói cho ${data.data.length} đơn.`);
        await load();
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Không tạo được file PDF.');
    } finally {
        busy.value = null;
    }
}

const orderLabel = (id: string) => orders.value.find((o) => o.id === id)?.external_order_id ?? id;
</script>

<template>
    <PageHeader title="Đơn hàng" description="Đơn từ mọi gian hàng, cập nhật theo webhook và đối soát định kỳ. Thao tác trạng thái được gửi lên sàn qua API chính thức.">
        <Button variant="outline" :loading="loading" @click="load"><RefreshCw /> Làm mới</Button>
    </PageHeader>

    <Card>
        <div class="space-y-3 border-b p-4">
            <Tabs v-model="filters.tab" :tabs="tabs" data-testid="order-tabs" />
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative min-w-60 flex-1">
                    <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                    <Input v-model="filters.q" class="pl-8" placeholder="Mã đơn, mã vận đơn, SKU, tên sản phẩm…" data-testid="order-search" />
                </div>
                <Select v-model="filters.account" class="w-48" :options="[{ value: '', label: 'Tất cả gian hàng' }, ...accounts.map((a) => ({ value: a.id, label: a.shop_name }))]" aria-label="Gian hàng" />
                <Select v-model="filters.assignee" class="w-44" :options="[{ value: '', label: 'Mọi người xử lý' }, { value: 'me', label: 'Đơn của tôi' }, { value: 'none', label: 'Chưa phân công' }, ...assignees.map((a) => ({ value: a.id, label: a.name }))]" aria-label="Người xử lý" />
                <Select v-model="filters.sort" class="w-44" :options="[{ value: 'newest', label: 'Mới nhất' }, { value: 'oldest', label: 'Cũ nhất' }, { value: 'ship_by', label: 'Hạn giao gần nhất' }]" aria-label="Sắp xếp" />
            </div>
            <div class="flex flex-wrap gap-1.5">
                <button
                    v-for="f in orderFlags"
                    :key="f.value"
                    type="button"
                    :class="cn('rounded-full border px-2.5 py-0.5 text-xs', filters.flags.includes(f.value) ? 'border-primary bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-muted')"
                    :data-testid="`flag-${f.value}`"
                    @click="toggleFlag(f.value)"
                >
                    {{ f.label }}
                </button>
            </div>
        </div>

        <div v-if="selected.size" class="flex flex-wrap items-center gap-2 border-b bg-primary/5 px-4 py-2 text-sm" data-testid="bulk-bar">
            <span class="font-medium">Đã chọn {{ selected.size }} đơn</span>
            <template v-if="tenant.can('orders.update')">
                <Button size="sm" variant="outline" :loading="busy === 'confirm'" @click="bulk('confirm')">{{ actionLabels.confirm }}</Button>
                <Button size="sm" variant="outline" :loading="busy === 'pack'" @click="bulk('pack')">{{ actionLabels.pack }}</Button>
                <Button size="sm" variant="outline" :loading="busy === 'handover'" @click="bulk('handover')">{{ actionLabels.handover }}</Button>
            </template>
            <Button v-if="tenant.can('orders.assign')" size="sm" variant="outline" @click="assignOpen = true"><UserPlus /> Phân công</Button>
            <Button v-if="tenant.can('orders.print')" size="sm" :loading="busy === 'print'" data-testid="print-slips" @click="print"><Printer /> In phiếu đóng gói</Button>
            <button class="ml-auto text-xs text-muted-foreground hover:underline" @click="selected = new Set()">Bỏ chọn</button>
        </div>

        <div v-if="loading && !orders.length" class="space-y-3 p-4"><Skeleton v-for="i in 6" :key="i" class="h-14" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState
            v-else-if="!orders.length"
            :icon="ShoppingCart"
            title="Không có đơn nào"
            :description="accounts.length ? 'Không có đơn khớp bộ lọc. Đơn mới từ sàn sẽ xuất hiện ở đây trong vài giây sau khi sàn gửi webhook.' : 'Kết nối gian hàng để bắt đầu nhận đơn.'"
        >
            <RouterLink v-if="!accounts.length" to="/marketplaces"><Button>Kết nối gian hàng</Button></RouterLink>
        </EmptyState>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="order-table">
                <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="w-10 px-4 py-2.5"><input type="checkbox" :checked="allSelected" aria-label="Chọn tất cả" @change="toggleAll" /></th>
                        <th class="px-2 py-2.5 font-medium">Đơn hàng</th>
                        <th class="px-4 py-2.5 font-medium">Sản phẩm</th>
                        <th class="px-4 py-2.5 text-right font-medium">Tổng tiền</th>
                        <th class="px-4 py-2.5 font-medium">Trạng thái</th>
                        <th class="px-4 py-2.5 font-medium">Hạn giao</th>
                        <th class="px-4 py-2.5 font-medium">Xử lý</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="o in orders" :key="o.id" :class="cn('cursor-pointer hover:bg-muted/30', selected.has(o.id) && 'bg-primary/5')" @click="$router.push(`/orders/${o.id}`)">
                        <td class="px-4 py-3" @click.stop><input type="checkbox" :checked="selected.has(o.id)" :aria-label="`Chọn đơn ${o.external_order_id}`" @change="toggle(o.id)" /></td>
                        <td class="px-2 py-3">
                            <div class="font-mono text-xs font-medium">{{ o.external_order_id }}</div>
                            <div class="mt-0.5 text-xs text-muted-foreground">{{ marketplaceNames[o.marketplace] ?? o.marketplace }} · {{ o.shop?.name }}</div>
                            <div class="text-xs text-muted-foreground">{{ formatDateTime(o.placed_at) }}</div>
                        </td>
                        <td class="max-w-80 px-4 py-3">
                            <div v-for="(i, idx) in o.items_preview" :key="idx" class="truncate text-xs">
                                <span class="font-medium tabular-nums">{{ i.quantity }}×</span> {{ i.name }}<span v-if="i.variant_name" class="text-muted-foreground"> · {{ i.variant_name }}</span>
                            </div>
                            <div v-if="(o.items_count ?? 0) > 3" class="text-xs text-muted-foreground">+{{ (o.items_count ?? 0) - 3 }} sản phẩm khác</div>
                            <div class="mt-1 flex flex-wrap gap-1">
                                <Badge v-if="o.flags.shortage" tone="danger"><PackageX class="mr-1 size-3" />Thiếu hàng</Badge>
                                <Badge v-if="o.flags.unmapped" tone="warning"><Unlink class="mr-1 size-3" />Chưa map SKU</Badge>
                                <Badge v-if="o.sync_warning" tone="warning"><AlertTriangle class="mr-1 size-3" />Cảnh báo</Badge>
                                <Badge v-if="o.flags.printed" tone="muted"><Printer class="mr-1 size-3" />Đã in</Badge>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatMoney(o.total) }}
                            <div v-if="o.is_cod" class="text-xs text-muted-foreground">COD</div>
                        </td>
                        <td class="px-4 py-3"><Badge :tone="orderStatus[o.status]?.tone">{{ orderStatus[o.status]?.label ?? o.status }}</Badge></td>
                        <td class="px-4 py-3 text-xs">
                            <span v-if="o.flags.overdue" class="inline-flex items-center gap-1 font-medium text-destructive"><Clock class="size-3" />{{ deadlineLabel(o.ship_by_at) }}</span>
                            <span v-else-if="o.flags.due_soon" class="inline-flex items-center gap-1 font-medium text-[oklch(0.5_0.13_70)]"><Clock class="size-3" />{{ deadlineLabel(o.ship_by_at) }}</span>
                            <span v-else class="text-muted-foreground">{{ formatDateTime(o.ship_by_at) }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs text-muted-foreground">{{ o.assignee?.name ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((filters.page = p), load())" />
    </Card>

    <Dialog v-model:open="assignOpen" title="Phân công đơn" :description="`${selected.size} đơn được chọn`">
        <Select v-model="assignTo" :options="[{ value: '', label: '— Bỏ phân công —' }, ...assignees.map((a) => ({ value: a.id, label: a.name }))]" aria-label="Nhân viên" />
        <template #footer>
            <Button variant="outline" @click="assignOpen = false">Hủy</Button>
            <Button :loading="busy === 'assign'" @click="assignSelected">Lưu</Button>
        </template>
    </Dialog>

    <Dialog :open="bulkResult !== null" title="Kết quả xử lý hàng loạt" size="lg" @update:open="(v) => !v && (bulkResult = null)">
        <p class="mb-3 text-sm text-muted-foreground">
            {{ bulkResult?.filter((r) => r.ok).length }} đơn thành công, {{ bulkResult?.filter((r) => !r.ok).length }} đơn không xử lý được. Mỗi đơn được gửi riêng lên sàn; lỗi của đơn này không ảnh hưởng đơn khác.
        </p>
        <ul class="divide-y rounded-md border text-sm" data-testid="bulk-result">
            <li v-for="r in bulkResult?.filter((x) => !x.ok)" :key="r.id" class="flex items-start justify-between gap-3 px-3 py-2">
                <span class="font-mono text-xs">{{ orderLabel(r.id) }}</span>
                <span class="text-right text-xs text-destructive">{{ r.message }}</span>
            </li>
        </ul>
        <template #footer><Button @click="bulkResult = null">Đóng</Button></template>
    </Dialog>
</template>
