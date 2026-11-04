<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Cake, Columns3, Download, Search, SlidersHorizontal, Upload, UserPlus, UsersRound, X } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Pagination from '../../components/Pagination.vue';
import Tabs from '../../components/Tabs.vue';
import CustomerDialog from './CustomerDialog.vue';
import CustomerImportDialog from './CustomerImportDialog.vue';
import CustomersNav from './CustomersNav.vue';
import { customerSources, customerStatus, customerTypes, genderLabels, months, segments } from './labels';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { cn, formatDate, formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import type { Customer, CustomerGroup, CustomerImportJob } from '@/shared/types';

const tenant = useTenantStore();
const toast = useToast();
const route = useRoute();
const router = useRouter();
const canManage = computed(() => tenant.can('customers.manage'));
const canImport = computed(() => tenant.can('customers.import'));
const canExport = computed(() => tenant.can('customers.export'));
const canDelete = computed(() => tenant.can('customers.delete'));

const rows = ref<Customer[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const groups = ref<CustomerGroup[]>([]);
const staff = ref<{ id: string; name: string }[]>([]);
const tags = ref<string[]>([]);
const loading = ref(true);
const loadError = ref<string | null>(null);
const dialog = ref(false);
const importOpen = ref(false);
const exporting = ref(false);

// Filters live in the URL so a filtered list (VIP customers in debt, birthdays this month) can be bookmarked or shared.
const q = (key: string) => (typeof route.query[key] === 'string' ? (route.query[key] as string) : '');
const EXTRA = ['type', 'source', 'gender', 'birth_month', 'assigned', 'tag', 'spent_min', 'spent_max', 'orders_min', 'last_order_from', 'last_order_to', 'created_from', 'created_to'] as const;
const filters = reactive({
    q: q('q'),
    view: q('view') || 'all',
    group: q('group'),
    sort: q('sort') || 'newest',
    dir: q('dir'),
    segment: q('segment'),
    ...Object.fromEntries(EXTRA.map((k) => [k, q(k)])),
    page: 1,
}) as { q: string; view: string; group: string; sort: string; dir: string; segment: string; page: number } & Record<(typeof EXTRA)[number], string>;
const moreFilters = ref(EXTRA.some((k) => filters[k]));
const activeExtra = computed(() => EXTRA.filter((k) => filters[k]).length);
function clearExtra() {
    for (const k of EXTRA) filters[k] = '';
}
const thisMonth = String(new Date().getMonth() + 1);

// Columns the user chose to see, remembered on this device.
const COLUMNS = [
    { key: 'phone', label: 'Điện thoại' },
    { key: 'group', label: 'Nhóm' },
    { key: 'assignee', label: 'Phụ trách' },
    { key: 'tags', label: 'Tags' },
    { key: 'orders', label: 'Số đơn' },
    { key: 'spent', label: 'Tổng chi tiêu' },
    { key: 'debt', label: 'Công nợ' },
    { key: 'overdue', label: 'Nợ quá hạn' },
    { key: 'last_order', label: 'Mua gần nhất' },
    { key: 'birthday', label: 'Ngày sinh' },
    { key: 'status', label: 'Trạng thái' },
    { key: 'created', label: 'Ngày thêm' },
] as const;
type ColumnKey = (typeof COLUMNS)[number]['key'];
const DEFAULT_COLUMNS: ColumnKey[] = ['phone', 'group', 'orders', 'spent', 'debt', 'last_order'];
const STORAGE_KEY = 'ecso.customers.columns';
function savedColumns(): ColumnKey[] {
    try {
        const v = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? 'null');
        return Array.isArray(v) ? v.filter((k) => COLUMNS.some((c) => c.key === k)) : DEFAULT_COLUMNS;
    } catch {
        return DEFAULT_COLUMNS;
    }
}
const columns = ref<ColumnKey[]>(savedColumns());
const columnsOpen = ref(false);
const show = (key: ColumnKey) => columns.value.includes(key);
function toggleColumn(key: ColumnKey) {
    columns.value = show(key) ? columns.value.filter((k) => k !== key) : COLUMNS.map((c) => c.key).filter((k) => k === key || columns.value.includes(k));
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(columns.value));
    } catch {
        /* private mode: keep for this visit only */
    }
}
function resetColumns() {
    columns.value = [...DEFAULT_COLUMNS];
    try {
        localStorage.removeItem(STORAGE_KEY);
    } catch {
        /* ignore */
    }
}

/** The list's filters as the API takes them (the export uses the same, so the file holds what the list shows). */
function apiParams(): Record<string, string | number | undefined> {
    const { page: _page, view, ...rest } = filters;
    const params = Object.fromEntries(Object.entries(rest).filter(([k, v]) => v && !(k === 'sort' && v === 'newest')));
    return { ...params, status: ['active', 'inactive'].includes(view) ? view : undefined, debt: view === 'debt' ? 1 : undefined, overdue: view === 'overdue' ? 1 : undefined };
}

/** Column header sort: first click sorts the column (names A → Z, figures largest first), the next one reverses it. */
function sortBy(sort: string) {
    if (filters.sort === sort) {
        const natural = sort === 'name' ? 'asc' : 'desc';
        filters.dir = (filters.dir || natural) === 'asc' ? 'desc' : 'asc';
    } else {
        filters.sort = sort;
        filters.dir = '';
    }
}
function sortMark(sort: string): string {
    if (filters.sort !== sort) return '';
    return (filters.dir || (sort === 'name' ? 'asc' : 'desc')) === 'asc' ? '↑' : '↓';
}

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { page, view, ...rest } = filters;
        const query = Object.fromEntries(Object.entries({ ...rest, view }).filter(([k, v]) => v && !(k === 'view' && v === 'all') && !(k === 'sort' && v === 'newest')));
        // Only while still on the list: a slow load must not pull the user back from a page they opened.
        const key = (o: Record<string, unknown>) => JSON.stringify(Object.entries(o).filter(([, v]) => v).sort(([a], [b]) => a.localeCompare(b)));
        if (key(query) !== key(route.query) && route.name === 'customers') router.replace({ query });
        const { data } = await api.get('/customers', { params: { ...apiParams(), page } });
        rows.value = data.data;
        meta.value = data.meta;
        selected.value = new Set();
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}

// A large file being imported in the background: shown above the list until it ends, then the list reloads.
const importJob = ref<CustomerImportJob | null>(null);
let importTimer: ReturnType<typeof setTimeout> | undefined;
async function watchImport() {
    clearTimeout(importTimer);
    if (!canImport.value) return;
    try {
        const { data } = await api.get('/customers/imports/latest');
        const was = importJob.value?.status;
        const job: CustomerImportJob | null = data.data;
        const active = !!job && ['queued', 'importing'].includes(job.status);
        importJob.value = active || (job && was && was !== job.status) ? job : null;
        if (was && was !== 'done' && job?.status === 'done') load();
        if (active) importTimer = setTimeout(watchImport, 4000);
    } catch {
        importJob.value = null;
    }
}
onBeforeUnmount(() => clearTimeout(importTimer));

onMounted(async () => {
    load();
    watchImport();
    const [g, s, t] = await Promise.all([api.get('/customer-groups'), api.get('/customers/staff'), api.get('/customers/tags')]).catch(() => [null, null, null]);
    groups.value = g?.data.data ?? [];
    staff.value = s?.data.data ?? [];
    tags.value = t?.data.data ?? [];
});

let timer: ReturnType<typeof setTimeout> | undefined;
watch(() => filters.q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => ((filters.page = 1), load()), 300);
});
watch(() => [filters.view, filters.group, filters.segment, filters.sort, filters.dir, ...EXTRA.map((k) => filters[k])], () => {
    clearTimeout(timer);
    timer = setTimeout(() => ((filters.page = 1), load()), 250);
});

// Selection and bulk actions.
const selected = ref<Set<string>>(new Set());
const allSelected = computed(() => rows.value.length > 0 && rows.value.every((c) => selected.value.has(c.id)));
function toggleAll() {
    selected.value = allSelected.value ? new Set() : new Set(rows.value.map((c) => c.id));
}
function toggle(id: string) {
    const next = new Set(selected.value);
    next.has(id) ? next.delete(id) : next.add(id);
    selected.value = next;
}
const bulkAction = ref('');
const bulkValue = ref('');
const bulkBusy = ref(false);
watch(bulkAction, () => (bulkValue.value = ''));
const BULK_ACTIONS = computed(() => [
    { value: '', label: 'Chọn thao tác…' },
    { value: 'set_group', label: 'Chuyển nhóm' },
    { value: 'add_tag', label: 'Gắn tag' },
    { value: 'remove_tag', label: 'Gỡ tag' },
    { value: 'assign', label: 'Giao nhân viên phụ trách' },
    { value: 'set_status', label: 'Đổi trạng thái giao dịch' },
    ...(canDelete.value ? [{ value: 'delete', label: 'Xóa khách' }] : []),
]);
const bulkReady = computed(() => bulkAction.value && (['delete', 'set_group', 'assign'].includes(bulkAction.value) || bulkValue.value.trim() !== ''));
async function applyBulk() {
    if (bulkAction.value === 'delete' && !window.confirm(`Xóa ${selected.value.size} khách đã chọn? Khách đã có đơn hàng sẽ được giữ lại.`)) return;
    bulkBusy.value = true;
    const action = bulkAction.value;
    try {
        const { data } = await api.post('/customers/bulk', { ids: [...selected.value], action, value: bulkValue.value || null });
        if (data.updated) toast.success(action === 'delete' ? `Đã xóa ${data.updated} khách.` : `Đã cập nhật ${data.updated} khách.`);
        if (data.skipped.length) toast.error(`${data.skipped.length} khách không xóa được vì đã có đơn hàng. Hãy chuyển sang ngừng giao dịch.`);
        if (!data.updated && !data.skipped.length) toast.success('Không có gì thay đổi.');
        bulkAction.value = '';
        await load();
        if (action === 'add_tag' || action === 'remove_tag') tags.value = (await api.get('/customers/tags')).data.data;
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        bulkBusy.value = false;
    }
}

async function exportFile(onlySelected: boolean) {
    exporting.value = true;
    try {
        const params = onlySelected ? { ids: [...selected.value].join(',') } : apiParams();
        const query = new URLSearchParams(Object.entries({ format: 'xlsx', ...params }).filter(([, v]) => v !== undefined && v !== '').map(([k, v]) => [k, String(v)]));
        await downloadFile(`/customers/export?${query}`, 'khach-hang.xlsx');
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        exporting.value = false;
    }
}
</script>

<template>
    <PageHeader title="Khách hàng" description="Danh bạ khách mua qua các kênh: nhóm, người phụ trách, lịch sử mua và công nợ (công nợ chỉ tính đơn kênh bán riêng).">
        <Button v-if="canExport" variant="outline" :loading="exporting" data-testid="customer-export" @click="exportFile(false)"><Download /> Xuất Excel</Button>
        <Button v-if="canImport" variant="outline" data-testid="customer-import" @click="importOpen = true"><Upload /> Nhập file</Button>
        <Button v-if="canManage" data-testid="add-customer" @click="dialog = true"><UserPlus /> Thêm khách</Button>
    </PageHeader>
    <CustomersNav />

    <div v-if="importJob" class="mb-3 rounded-md border px-3 py-2 text-sm" :class="importJob.status === 'failed' ? 'border-destructive/30 bg-destructive/5 text-destructive' : 'bg-muted/40'" data-testid="customer-import-banner">
        <template v-if="importJob.status === 'queued' || importJob.status === 'importing'">
            Đang nhập tệp <b>{{ importJob.file_name }}</b>: {{ formatNumber(importJob.processed) }} / {{ formatNumber(importJob.total) }} dòng. Khách mới chỉ hiện khi đã nhập xong toàn bộ.
        </template>
        <template v-else-if="importJob.status === 'done'">
            Đã nhập xong tệp <b>{{ importJob.file_name }}</b>: thêm {{ formatNumber(importJob.created) }} khách, cập nhật {{ formatNumber(importJob.updated) }} khách.
        </template>
        <template v-else>Không nhập được tệp <b>{{ importJob.file_name }}</b>: {{ importJob.message }} Mở "Nhập file" để xem chi tiết.</template>
    </div>

    <Card>
        <div class="border-b px-3">
            <Tabs
                v-model="filters.view"
                :tabs="[
                    { value: 'all', label: 'Tất cả' },
                    { value: 'active', label: 'Đang giao dịch' },
                    { value: 'inactive', label: 'Ngừng giao dịch' },
                    { value: 'debt', label: 'Đang nợ' },
                    { value: 'overdue', label: 'Nợ quá hạn' },
                ]"
                variant="underline"
                data-testid="customer-view"
            />
        </div>
        <div class="flex flex-wrap items-center gap-2 border-b px-4 py-3">
            <div class="relative min-w-60 flex-1">
                <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="filters.q" class="pl-8" placeholder="Tên, mã khách, email, số điện thoại (đủ số hoặc 4 số cuối)…" data-testid="customer-q" />
            </div>
            <Select v-model="filters.group" class="w-full sm:w-44" aria-label="Nhóm" :options="[{ value: '', label: 'Mọi nhóm' }, ...groups.map((g) => ({ value: g.id, label: g.name })), { value: 'none', label: 'Chưa xếp nhóm' }]" />
            <Select
                v-model="filters.segment"
                class="w-full sm:w-44"
                aria-label="Phân khúc"
                :title="segments.find((x) => x.value === filters.segment)?.hint"
                :options="[{ value: '', label: 'Mọi phân khúc' }, ...segments.map((x) => ({ value: x.value, label: x.label }))]"
                data-testid="customer-segment"
            />
            <Button variant="outline" size="sm" data-testid="more-filters" :class="cn(moreFilters && 'bg-muted')" @click="moreFilters = !moreFilters">
                <SlidersHorizontal /> Bộ lọc khác<span v-if="activeExtra" class="rounded-full bg-primary px-1.5 text-xs text-primary-foreground">{{ activeExtra }}</span>
            </Button>
            <Select
                v-model="filters.sort"
                class="w-full sm:w-44"
                aria-label="Sắp xếp"
                :options="[
                    { value: 'newest', label: 'Mới thêm' },
                    { value: 'recent', label: 'Mua gần đây' },
                    { value: 'spent', label: 'Chi tiêu nhiều' },
                    { value: 'orders', label: 'Nhiều đơn nhất' },
                    { value: 'debt', label: 'Nợ nhiều' },
                    { value: 'overdue', label: 'Quá hạn nhiều' },
                    { value: 'name', label: 'Tên A → Z' },
                ]"
            />
            <div class="relative">
                <Button variant="outline" size="sm" aria-label="Chọn cột hiển thị" data-testid="column-picker" @click="columnsOpen = !columnsOpen" @keydown.escape="columnsOpen = false"><Columns3 /> Cột</Button>
                <div v-if="columnsOpen" class="fixed inset-0 z-40" @click="columnsOpen = false" />
                <div v-if="columnsOpen" class="absolute right-0 z-50 mt-1 w-56 rounded-md border bg-card p-2 text-sm shadow-lg" @keydown.escape="columnsOpen = false">
                    <p class="px-2 pb-1 text-xs text-muted-foreground">Cột hiển thị</p>
                    <label v-for="c in COLUMNS" :key="c.key" class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 hover:bg-muted">
                        <input type="checkbox" class="size-4" :checked="show(c.key)" @change="toggleColumn(c.key)" /> {{ c.label }}
                    </label>
                    <button type="button" class="mt-1 w-full rounded px-2 py-1.5 text-left text-xs text-primary hover:bg-muted" @click="resetColumns">Về mặc định</button>
                </div>
            </div>
        </div>
        <div v-if="moreFilters" class="flex flex-wrap items-end gap-3 border-b bg-muted/30 px-4 py-3 text-sm" data-testid="extra-filters">
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">Loại khách</span>
                <Select v-model="filters.type" class="w-36" aria-label="Loại khách" :options="[{ value: '', label: 'Tất cả' }, ...Object.entries(customerTypes).map(([value, label]) => ({ value, label }))]" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">Nguồn khách</span>
                <Select v-model="filters.source" class="w-36" aria-label="Nguồn khách" :options="[{ value: '', label: 'Tất cả' }, ...Object.entries(customerSources).map(([value, label]) => ({ value, label }))]" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">Giới tính</span>
                <Select v-model="filters.gender" class="w-32" aria-label="Giới tính" :options="[{ value: '', label: 'Tất cả' }, ...Object.entries(genderLabels).map(([value, label]) => ({ value, label }))]" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">Sinh nhật</span>
                <div class="flex gap-1">
                    <Select v-model="filters.birth_month" class="w-32" aria-label="Tháng sinh" :options="[{ value: '', label: 'Mọi tháng' }, ...months]" />
                    <Button variant="ghost" size="sm" title="Sinh nhật tháng này" :class="cn(filters.birth_month === thisMonth && 'bg-muted')" @click="filters.birth_month = filters.birth_month === thisMonth ? '' : thisMonth"><Cake /> Tháng này</Button>
                </div>
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">Nhân viên phụ trách</span>
                <Select v-model="filters.assigned" class="w-44" aria-label="Nhân viên phụ trách" :options="[{ value: '', label: 'Tất cả' }, ...staff.map((s) => ({ value: s.id, label: s.name })), { value: 'none', label: 'Chưa giao' }]" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">Tag</span>
                <Select v-model="filters.tag" class="w-36" aria-label="Tag" :options="[{ value: '', label: 'Mọi tag' }, ...tags.map((t) => ({ value: t, label: t }))]" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">Tổng chi tiêu từ</span>
                <Input v-model="filters.spent_min" type="number" min="0" step="1000" class="w-32" placeholder="0" aria-label="Chi tiêu từ" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">đến</span>
                <Input v-model="filters.spent_max" type="number" min="0" step="1000" class="w-32" placeholder="Không giới hạn" aria-label="Chi tiêu đến" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">Số đơn tối thiểu</span>
                <Input v-model="filters.orders_min" type="number" min="0" class="w-28" aria-label="Số đơn tối thiểu" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">Mua lần cuối từ</span>
                <Input v-model="filters.last_order_from" type="date" class="w-40" aria-label="Mua lần cuối từ ngày" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">đến</span>
                <Input v-model="filters.last_order_to" type="date" class="w-40" aria-label="Mua lần cuối đến ngày" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">Thêm từ ngày</span>
                <Input v-model="filters.created_from" type="date" class="w-40" aria-label="Thêm từ ngày" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">đến</span>
                <Input v-model="filters.created_to" type="date" class="w-40" aria-label="Thêm đến ngày" />
            </label>
            <Button v-if="activeExtra" variant="ghost" size="sm" @click="clearExtra"><X /> Xóa bộ lọc</Button>
        </div>

        <div v-if="selected.size" class="flex flex-wrap items-center gap-2 border-b bg-secondary/60 px-4 py-2 text-sm" data-testid="bulk-bar">
            <span class="font-medium">Đã chọn {{ selected.size }}</span>
            <Select v-model="bulkAction" class="w-56" aria-label="Thao tác hàng loạt" :options="BULK_ACTIONS" data-testid="bulk-action" />
            <Select v-if="bulkAction === 'set_group'" v-model="bulkValue" class="w-44" aria-label="Nhóm mới" :options="[{ value: '', label: 'Bỏ khỏi nhóm' }, ...groups.map((g) => ({ value: g.id, label: g.name }))]" />
            <Select v-else-if="bulkAction === 'assign'" v-model="bulkValue" class="w-44" aria-label="Nhân viên" :options="[{ value: '', label: 'Bỏ phụ trách' }, ...staff.map((s) => ({ value: s.id, label: s.name }))]" />
            <Select v-else-if="bulkAction === 'set_status'" v-model="bulkValue" class="w-44" aria-label="Trạng thái" :options="[{ value: '', label: 'Chọn…' }, { value: 'active', label: 'Đang giao dịch' }, { value: 'inactive', label: 'Ngừng giao dịch' }]" />
            <template v-else-if="bulkAction === 'add_tag' || bulkAction === 'remove_tag'">
                <Input v-model="bulkValue" class="w-40" list="bulk-tags" placeholder="Tên tag" aria-label="Tag" data-testid="bulk-value" @keydown.enter.prevent="bulkReady && applyBulk()" />
                <datalist id="bulk-tags"><option v-for="t in tags" :key="t" :value="t" /></datalist>
            </template>
            <Button size="sm" :disabled="!bulkReady" :loading="bulkBusy" :variant="bulkAction === 'delete' ? 'destructive' : 'default'" data-testid="bulk-apply" @click="applyBulk">Áp dụng</Button>
            <Button v-if="canExport" size="sm" variant="outline" :loading="exporting" @click="exportFile(true)"><Download /> Xuất các khách đã chọn</Button>
            <button class="ml-auto text-xs text-muted-foreground hover:text-foreground" @click="selected = new Set()">Bỏ chọn</button>
        </div>

        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 5" :key="i" class="h-10" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState
            v-else-if="!rows.length"
            :icon="UsersRound"
            :title="filters.q || activeExtra || filters.group || filters.view !== 'all' ? 'Không có khách phù hợp' : 'Chưa có khách hàng'"
            description="Khách được thêm tự động khi tạo đơn bán với khách mới, thêm thủ công, hoặc nhập từ file Excel."
        >
            <Button v-if="canImport && !filters.q" variant="outline" @click="importOpen = true"><Upload /> Nhập từ Excel</Button>
        </EmptyState>
        <ul v-else class="divide-y sm:hidden" :class="loading && 'opacity-60'" data-testid="customer-cards">
            <li v-for="c in rows" :key="c.id" class="flex items-start gap-3 px-4 py-3 text-sm">
                <input v-if="canManage" type="checkbox" class="mt-1 size-4" :checked="selected.has(c.id)" :aria-label="`Chọn ${c.name}`" @change="toggle(c.id)" />
                <RouterLink :to="`/customers/${c.id}`" class="min-w-0 flex-1">
                    <div class="flex items-center gap-1.5"><span class="truncate font-medium">{{ c.name }}</span><Badge v-if="c.status === 'inactive'" tone="muted">Ngừng</Badge><Badge v-if="c.group">{{ c.group.name }}</Badge></div>
                    <div class="text-xs text-muted-foreground">{{ c.code }}<template v-if="c.phone"> · {{ c.phone }}</template></div>
                    <div class="mt-1 flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                        <span>{{ formatNumber(c.orders_count) }} đơn · {{ formatMoney(c.total_spent) }}</span>
                        <span v-if="Number(c.debt) > 0" class="font-medium text-destructive">Nợ {{ formatMoney(c.debt) }}<template v-if="Number(c.overdue) > 0"> (quá hạn {{ formatMoney(c.overdue) }})</template></span>
                    </div>
                </RouterLink>
            </li>
        </ul>
        <div v-if="rows.length && !loadError" class="hidden overflow-x-auto sm:block" :class="loading && 'opacity-60'">
            <table class="w-full text-sm" data-testid="customer-table">
                <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th v-if="canManage" class="w-10 px-4 py-2.5"><input type="checkbox" class="size-4" :checked="allSelected" aria-label="Chọn tất cả trên trang" @change="toggleAll" /></th>
                        <th class="px-4 py-2.5 font-medium" :aria-sort="filters.sort === 'name' ? (sortMark('name') === '↑' ? 'ascending' : 'descending') : undefined"><button type="button" class="inline-flex items-center gap-1 uppercase hover:text-foreground" :data-testid="`sort-name`" @click="sortBy('name')">Khách hàng<span class="text-primary">{{ sortMark('name') }}</span></button></th>
                        <th v-if="show('phone')" class="px-4 py-2.5 font-medium">Điện thoại</th>
                        <th v-if="show('group')" class="px-4 py-2.5 font-medium">Nhóm</th>
                        <th v-if="show('assignee')" class="px-4 py-2.5 font-medium">Phụ trách</th>
                        <th v-if="show('tags')" class="px-4 py-2.5 font-medium">Tags</th>
                        <th v-if="show('orders')" class="px-4 py-2.5 font-medium text-right" :aria-sort="filters.sort === 'orders' ? (sortMark('orders') === '↑' ? 'ascending' : 'descending') : undefined"><button type="button" class="inline-flex items-center gap-1 uppercase hover:text-foreground" :data-testid="`sort-orders`" @click="sortBy('orders')">Số đơn<span class="text-primary">{{ sortMark('orders') }}</span></button></th>
                        <th v-if="show('spent')" class="px-4 py-2.5 font-medium text-right" :aria-sort="filters.sort === 'spent' ? (sortMark('spent') === '↑' ? 'ascending' : 'descending') : undefined"><button type="button" class="inline-flex items-center gap-1 uppercase hover:text-foreground" :data-testid="`sort-spent`" @click="sortBy('spent')">Tổng chi tiêu<span class="text-primary">{{ sortMark('spent') }}</span></button></th>
                        <th v-if="show('debt')" class="px-4 py-2.5 font-medium text-right" :aria-sort="filters.sort === 'debt' ? (sortMark('debt') === '↑' ? 'ascending' : 'descending') : undefined"><button type="button" class="inline-flex items-center gap-1 uppercase hover:text-foreground" :data-testid="`sort-debt`" @click="sortBy('debt')">Công nợ<span class="text-primary">{{ sortMark('debt') }}</span></button></th>
                        <th v-if="show('overdue')" class="px-4 py-2.5 font-medium text-right" :aria-sort="filters.sort === 'overdue' ? (sortMark('overdue') === '↑' ? 'ascending' : 'descending') : undefined"><button type="button" class="inline-flex items-center gap-1 uppercase hover:text-foreground" :data-testid="`sort-overdue`" @click="sortBy('overdue')">Nợ quá hạn<span class="text-primary">{{ sortMark('overdue') }}</span></button></th>
                        <th v-if="show('last_order')" class="px-4 py-2.5 font-medium" :aria-sort="filters.sort === 'recent' ? (sortMark('recent') === '↑' ? 'ascending' : 'descending') : undefined"><button type="button" class="inline-flex items-center gap-1 uppercase hover:text-foreground" :data-testid="`sort-recent`" @click="sortBy('recent')">Mua gần nhất<span class="text-primary">{{ sortMark('recent') }}</span></button></th>
                        <th v-if="show('birthday')" class="px-4 py-2.5 font-medium">Ngày sinh</th>
                        <th v-if="show('status')" class="px-4 py-2.5 font-medium">Trạng thái</th>
                        <th v-if="show('created')" class="px-4 py-2.5 font-medium">Ngày thêm</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="c in rows" :key="c.id" :class="cn('hover:bg-muted/30', selected.has(c.id) && 'bg-secondary/40')">
                        <td v-if="canManage" class="px-4 py-3"><input type="checkbox" class="size-4" :checked="selected.has(c.id)" :aria-label="`Chọn ${c.name}`" @change="toggle(c.id)" /></td>
                        <td class="px-4 py-3">
                            <RouterLink :to="`/customers/${c.id}`" class="font-medium hover:text-primary hover:underline">{{ c.name }}</RouterLink>
                            <Badge v-if="c.status === 'inactive'" tone="muted" class="ml-1.5">Ngừng</Badge>
                            <div class="font-mono text-xs text-muted-foreground">{{ c.code }}</div>
                        </td>
                        <td v-if="show('phone')" class="px-4 py-3 tabular-nums">{{ c.phone ?? '—' }}</td>
                        <td v-if="show('group')" class="px-4 py-3"><Badge v-if="c.group">{{ c.group.name }}</Badge><span v-else class="text-muted-foreground">—</span></td>
                        <td v-if="show('assignee')" class="px-4 py-3">{{ c.assignee?.name ?? '—' }}</td>
                        <td v-if="show('tags')" class="px-4 py-3">
                            <span v-for="t in c.tags" :key="t" class="mr-1 inline-block rounded-full bg-muted px-2 py-0.5 text-xs">{{ t }}</span>
                        </td>
                        <td v-if="show('orders')" class="px-4 py-3 text-right tabular-nums">{{ formatNumber(c.orders_count) }}</td>
                        <td v-if="show('spent')" class="px-4 py-3 text-right tabular-nums">{{ formatMoney(c.total_spent) }}</td>
                        <td v-if="show('debt')" class="px-4 py-3 text-right tabular-nums" :class="Number(c.debt) > 0 ? 'font-medium text-destructive' : 'text-muted-foreground'">{{ Number(c.debt) > 0 ? formatMoney(c.debt) : '—' }}</td>
                        <td v-if="show('overdue')" class="px-4 py-3 text-right tabular-nums" :class="Number(c.overdue) > 0 ? 'font-medium text-destructive' : 'text-muted-foreground'">{{ Number(c.overdue) > 0 ? formatMoney(c.overdue) : '—' }}</td>
                        <td v-if="show('last_order')" class="whitespace-nowrap px-4 py-3 text-muted-foreground">{{ formatDateTime(c.last_order_at) }}</td>
                        <td v-if="show('birthday')" class="whitespace-nowrap px-4 py-3">{{ c.birthday ? formatDate(c.birthday) : '—' }}</td>
                        <td v-if="show('status')" class="px-4 py-3"><Badge :tone="customerStatus[c.status]?.tone">{{ customerStatus[c.status]?.label }}</Badge></td>
                        <td v-if="show('created')" class="whitespace-nowrap px-4 py-3 text-muted-foreground">{{ formatDate(c.created_at) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((filters.page = p), load())" />
    </Card>
    <CustomerDialog v-model:open="dialog" @saved="(c) => $router.push(`/customers/${c.id}`)" />
    <CustomerImportDialog v-model:open="importOpen" @imported="load" @update:open="(v: boolean) => !v && watchImport()" />
</template>
