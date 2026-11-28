<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, provide, reactive, ref, watch } from 'vue';
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router';
import { Cake, Columns3, Crown, Download, Gem, HandCoins, Info, Moon, PhoneCall, Repeat, Search, SlidersHorizontal, TriangleAlert, Upload, UserPlus, UsersRound, X } from 'lucide-vue-next';
import ReportChip from '../reports/ReportChip.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import ChannelDots from './ChannelDots.vue';
import CustomerDialog from './CustomerDialog.vue';
import CustomerImportDialog from './CustomerImportDialog.vue';
import CustomersFrame from './CustomersFrame.vue';
import SoftPager from './SoftPager.vue';
import { avatarTone, btnOutline, btnPrimary, card, initials, pill } from './frame';
import { channelKinds, customerSources, customerStatus, customerTypes, genderLabels, months } from './labels';
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
import DateRangeInput from '@/shared/ui/DateRangeInput.vue';

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
const EXTRA = ['type', 'source', 'gender', 'birth_month', 'assigned', 'tier', 'channel', 'tag', 'spent_min', 'spent_max', 'orders_min', 'last_order_from', 'last_order_to', 'created_from', 'created_to'] as const;
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
// Nhân viên, hạng and kênh sit in the main row; the rest under "Bộ lọc khác".
const MORE = EXTRA.filter((k) => !['assigned', 'tier', 'channel'].includes(k));
const moreFilters = ref(MORE.some((k) => filters[k]));
const activeExtra = computed(() => MORE.filter((k) => filters[k]).length);
function clearExtra() {
    for (const k of MORE) filters[k] = '';
}
const thisMonth = String(new Date().getMonth() + 1);

// Columns the user chose to see, remembered on this device.
const COLUMNS = [
    { key: 'phone', label: 'Điện thoại' },
    { key: 'group', label: 'Nhóm' },
    { key: 'channels', label: 'Kênh mua' },
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
const DEFAULT_COLUMNS: ColumnKey[] = ['phone', 'group', 'channels', 'orders', 'spent', 'debt', 'last_order'];
const STORAGE_KEY = 'ecso.customers.columns.v2';
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
        if (key(query) !== key(route.query) && route.name === 'customers' && !leaving) router.replace({ query });
        const { data } = await api.get('/customers', { params: { ...apiParams(), page, per_page: perPage.value } });
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
        const active = !!job && ['pending_check', 'checking', 'queued', 'importing'].includes(job.status);
        importJob.value = active || job?.status === 'checked' || (job && was && was !== job.status) ? job : null;
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
    loadSummary();
    const [g, s, t, l] = await Promise.all([api.get('/customer-groups'), api.get('/customers/staff'), api.get('/customers/tags'), api.get('/loyalty').catch(() => null)]).catch(() => [null, null, null, null]);
    groups.value = g?.data.data ?? [];
    staff.value = s?.data.data ?? [];
    tags.value = t?.data.data ?? [];
    tiers.value = (l?.data.data.tiers ?? []).map((x: { id: string; name: string }) => ({ id: x.id, name: x.name }));
});

provide('soft-fields', true);
const perPage = ref(20);
watch(perPage, () => ((filters.page = 1), load()));
const tiers = ref<{ id: string; name: string }[]>([]);

// Headline figures and the five quick groups (mẫu 28/11).
interface Summary {
    total: number;
    active: number;
    inactive: number;
    new_this_month: number;
    returning: { count: number; percent: number | null };
    returning_before: { count: number; percent: number | null };
    debt: { amount: string; count: number };
    overdue: { amount: string; count: number };
    segments: Record<'follow_up' | 'birthday' | 'lapsed' | 'frequent' | 'high_value', number>;
}
const summary = ref<Summary | null>(null);
async function loadSummary() {
    try {
        summary.value = (await api.get('/customers/summary')).data.data;
    } catch {
        summary.value = null;
    }
}
const returningChange = computed(() => {
    const now = summary.value?.returning.percent;
    const before = summary.value?.returning_before.percent;
    if (now == null || before == null) return null;
    const d = Math.round((now - before) * 10) / 10;
    if (!d) return null;
    return { up: d >= 0, text: `${d >= 0 ? '+' : '−'}${String(Math.abs(d)).replace('.', ',')} điểm so với tháng trước` };
});
const quick = computed(() => [
    { key: 'follow_up', label: 'Cần liên hệ', hint: 'Việc nhắc đến hạn hoặc đã trễ', icon: PhoneCall, tone: 'bg-red-50 text-red-500' },
    { key: 'birthday', label: 'Sinh nhật tháng này', hint: 'Gửi lời chúc, tặng mã', icon: Cake, tone: 'bg-violet-50 text-violet-600' },
    { key: 'lapsed', label: 'Lâu không mua', hint: '90 ngày nay chưa có đơn', icon: Moon, tone: 'bg-amber-50 text-amber-600' },
    { key: 'frequent', label: 'Mua thường xuyên', hint: 'Từ 3 đơn trong 180 ngày', icon: Repeat, tone: 'bg-emerald-50 text-emerald-600' },
    { key: 'high_value', label: 'Giá trị cao', hint: '10% khách chi tiêu nhiều nhất', icon: Gem, tone: 'bg-blue-50 text-blue-600' },
] as const);
const quickOn = (key: string) => (key === 'birthday' ? filters.birth_month === thisMonth : filters.segment === key);
function toggleQuick(key: string) {
    if (key === 'birthday') {
        filters.birth_month = filters.birth_month === thisMonth ? '' : thisMonth;
        return;
    }
    filters.segment = filters.segment === key ? '' : key;
}
const views = computed(() => [
    { value: 'all', label: 'Tất cả', n: summary.value?.total },
    { value: 'active', label: 'Đang giao dịch', n: undefined },
    { value: 'inactive', label: 'Ngừng giao dịch', n: undefined },
    { value: 'debt', label: 'Đang nợ', n: summary.value?.debt.count },
    { value: 'overdue', label: 'Nợ quá hạn', n: summary.value?.overdue.count },
]);
const money = (v: string | number | null | undefined) => formatNumber(Math.round(Number(v ?? 0)));
/** "Hôm nay", "Hôm qua", "3 ngày trước", … for the last purchase. */
function ago(iso: string | null): string {
    if (!iso) return '—';
    const days = Math.floor((new Date().setHours(0, 0, 0, 0) - new Date(iso).setHours(0, 0, 0, 0)) / 86400000);
    if (days <= 0) return 'Hôm nay';
    if (days === 1) return 'Hôm qua';
    if (days < 30) return `${days} ngày trước`;
    if (days < 365) return `${Math.floor(days / 30)} tháng trước`;
    return formatDate(iso);
}
const sortCols = [
    { key: 'orders', sort: 'orders', label: 'Số đơn', right: true },
    { key: 'spent', sort: 'spent', label: 'Tổng chi tiêu', right: true },
    { key: 'debt', sort: 'debt', label: 'Công nợ', right: true },
    { key: 'overdue', sort: 'overdue', label: 'Nợ quá hạn', right: true },
    { key: 'last_order', sort: 'recent', label: 'Mua gần nhất', right: false },
] as const;
const groupTone = (name: string) => cn('inline-flex whitespace-nowrap rounded-md px-2 py-0.5 text-[12px] font-medium', avatarTone(name).replace('-100', '-50'));

let timer: ReturnType<typeof setTimeout> | undefined;
// Typing a name then clicking a result at once: the search must not fire while the next page loads and pull the user back.
let leaving = false;
onBeforeRouteLeave(() => {
    leaving = true;
    clearTimeout(timer);
});
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
        if (action === 'set_status' || action === 'delete') loadSummary();
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
    <CustomersFrame page="Danh sách khách hàng" description="Danh bạ khách mua qua mọi kênh: nhóm, hạng, người phụ trách, lịch sử mua và công nợ.">
        <button v-if="canExport" type="button" :class="btnOutline" :disabled="exporting" data-testid="customer-export" @click="exportFile(false)"><Download class="size-4" /> {{ exporting ? 'Đang xuất…' : 'Xuất Excel' }}</button>
        <button v-if="canImport" type="button" :class="btnOutline" data-testid="customer-import" @click="importOpen = true"><Upload class="size-4" /> Nhập file</button>
        <button v-if="canManage" type="button" :class="btnPrimary" data-testid="add-customer" @click="dialog = true"><UserPlus class="size-4" /> Thêm khách</button>
    </CustomersFrame>

    <div
        v-if="importJob"
        :class="cn('mb-5 flex items-center gap-3 rounded-[14px] border px-5 py-3.5 text-[14px]', importJob.status === 'failed' ? 'border-red-200 bg-red-50 text-red-700' : 'border-blue-200 bg-blue-50 text-blue-900')"
        data-testid="customer-import-banner"
    >
        <span :class="cn('grid size-9 shrink-0 place-items-center rounded-[10px]', importJob.status === 'failed' ? 'bg-red-100 text-red-600' : 'bg-blue-100 text-blue-600')"><Upload class="size-[18px]" /></span>
        <p class="min-w-0 flex-1">
            <template v-if="importJob.status === 'pending_check' || importJob.status === 'checking'">Đang kiểm tra tệp <b>{{ importJob.file_name }}</b> (chạy ngầm). Kiểm tra xong, mở "Nhập file" để bấm nhập.</template>
            <template v-else-if="importJob.status === 'checked'">
                Tệp <b>{{ importJob.file_name }}</b> đã kiểm tra xong: {{ formatNumber(importJob.total) }} dòng. <button type="button" class="font-medium underline" @click="importOpen = true">Mở để nhập</button>
            </template>
            <template v-else-if="importJob.status === 'queued' || importJob.status === 'importing'">
                Đang nhập tệp <b>{{ importJob.file_name }}</b>: {{ formatNumber(importJob.processed) }} / {{ formatNumber(importJob.total) }} dòng. Khách mới chỉ hiện khi đã nhập xong toàn bộ.
            </template>
            <template v-else-if="importJob.status === 'done'">Đã nhập xong tệp <b>{{ importJob.file_name }}</b>: thêm {{ formatNumber(importJob.created) }} khách, cập nhật {{ formatNumber(importJob.updated) }} khách.</template>
            <template v-else>Không nhập được tệp <b>{{ importJob.file_name }}</b>: {{ importJob.message }} Mở "Nhập file" để xem chi tiết.</template>
        </p>
    </div>

    <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="customer-kpis">
        <ReportKpi label="Tổng khách hàng" tip="Mọi thẻ khách của cửa hàng, gồm cả khách ngừng giao dịch." :icon="UsersRound" primary :value="summary?.total ?? null" unit="khách">
            <template v-if="summary?.new_this_month" #chips><ReportChip tone="onblue">+{{ formatNumber(summary.new_this_month) }} khách mới tháng này</ReportChip></template>
            {{ formatNumber(summary?.active ?? 0) }} đang giao dịch · {{ formatNumber(summary?.inactive ?? 0) }} ngừng giao dịch
        </ReportKpi>
        <ReportKpi label="Khách quay lại mua" tip="Trong số khách có mua 12 tháng qua, phần trăm khách mua từ 2 đơn trở lên. Không tính đơn hủy, đơn hoàn." :icon="Repeat" tone="green" :value="null" :text="summary?.returning.percent != null ? `${String(summary.returning.percent).replace('.', ',')}%` : '—'">
            <template #chips>
                <ReportChip tone="up">{{ formatNumber(summary?.returning.count ?? 0) }} khách</ReportChip>
                <ReportChip v-if="returningChange" :tone="returningChange.up ? 'up' : 'plain'">{{ returningChange.text }}</ReportChip>
            </template>
            Mua từ 2 đơn trở lên trong 12 tháng
        </ReportKpi>
        <ReportKpi label="Khách đang nợ" tip="Tổng công nợ khách còn phải trả. Chỉ tính đơn kênh bán riêng và bán tại quầy." :icon="HandCoins" tone="amber" :value="summary ? Math.round(Number(summary.debt.amount)) : null">
            <template #chips><ReportChip tone="amber">{{ formatNumber(summary?.debt.count ?? 0) }} khách</ReportChip></template>
            Chỉ tính đơn kênh bán riêng và bán tại quầy
        </ReportKpi>
        <ReportKpi label="Nợ quá hạn" tip="Phần công nợ đã quá hạn thanh toán của khách hoặc nhóm khách." :icon="TriangleAlert" tone="red" :value="summary ? Math.round(Number(summary.overdue.amount)) : null">
            <template #chips><ReportChip tone="red">{{ formatNumber(summary?.overdue.count ?? 0) }} khách</ReportChip></template>
            Quá hạn thanh toán của khách, nhóm khách
        </ReportKpi>
    </div>

    <div class="mb-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5" data-testid="customer-quick">
        <button
            v-for="q in quick"
            :key="q.key"
            type="button"
            :class="cn('flex items-center gap-3 rounded-[14px] border bg-white px-4 py-3.5 text-left shadow-[0_1px_2px_rgba(15,23,42,0.04)] hover:border-blue-300', quickOn(q.key) ? 'border-blue-300 ring-2 ring-blue-100' : 'border-slate-200/80')"
            :aria-pressed="quickOn(q.key)"
            @click="toggleQuick(q.key)"
        >
            <span :class="cn('grid size-10 shrink-0 place-items-center rounded-[11px]', q.tone)"><component :is="q.icon" class="size-5" /></span>
            <span class="min-w-0 flex-1">
                <span class="block text-[14px] font-medium text-slate-700">{{ q.label }}</span>
                <span class="block truncate text-[12px] text-slate-400">{{ q.hint }}</span>
            </span>
            <span class="text-[22px] font-bold tabular-nums text-slate-900">{{ summary ? formatNumber(summary.segments[q.key]) : '—' }}</span>
        </button>
    </div>

    <section :class="cn(card, 'overflow-hidden')">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 pb-3 pt-4">
            <div class="flex overflow-x-auto rounded-[12px] bg-slate-100 p-1 text-[14px]" role="group" aria-label="Xem" data-testid="customer-view">
                <button v-for="t in views" :key="t.value" type="button" :class="pill(filters.view === t.value)" :aria-pressed="filters.view === t.value" @click="filters.view = t.value">
                    {{ t.label }}<span v-if="t.n" class="ml-1 tabular-nums text-slate-400">{{ formatNumber(t.n) }}</span>
                </button>
            </div>
            <div class="flex flex-wrap items-center gap-3 xl:ml-auto">
                <Select
                    v-model="filters.sort"
                    class="w-full sm:w-56"
                    aria-label="Sắp xếp"
                    :options="[
                        { value: 'newest', label: 'Sắp xếp: Mới thêm' },
                        { value: 'recent', label: 'Sắp xếp: Mua gần đây' },
                        { value: 'spent', label: 'Sắp xếp: Chi tiêu nhiều' },
                        { value: 'orders', label: 'Sắp xếp: Nhiều đơn nhất' },
                        { value: 'debt', label: 'Sắp xếp: Nợ nhiều' },
                        { value: 'overdue', label: 'Sắp xếp: Quá hạn nhiều' },
                        { value: 'name', label: 'Sắp xếp: Tên A → Z' },
                    ]"
                />
                <div class="relative">
                    <button type="button" class="inline-flex h-11 items-center gap-2 rounded-[12px] border border-slate-200 px-3.5 text-[14px] text-slate-600 hover:border-blue-300 hover:text-blue-700" aria-label="Chọn cột hiển thị" data-testid="column-picker" @click="columnsOpen = !columnsOpen" @keydown.escape="columnsOpen = false">
                        <Columns3 class="size-4" /> Cột
                    </button>
                    <div v-if="columnsOpen" class="fixed inset-0 z-40" @click="columnsOpen = false" />
                    <div v-if="columnsOpen" class="absolute right-0 z-50 mt-1 w-56 rounded-[12px] border border-slate-200 bg-white p-2 text-sm shadow-lg" @keydown.escape="columnsOpen = false">
                        <p class="px-2 pb-1 text-xs text-slate-500">Cột hiển thị</p>
                        <label v-for="c in COLUMNS" :key="c.key" class="flex cursor-pointer items-center gap-2 rounded-[8px] px-2 py-1.5 hover:bg-slate-50">
                            <input type="checkbox" class="size-4" :checked="show(c.key)" @change="toggleColumn(c.key)" /> {{ c.label }}
                        </label>
                        <button type="button" class="mt-1 w-full rounded-[8px] px-2 py-1.5 text-left text-xs text-blue-700 hover:bg-slate-50" @click="resetColumns">Về mặc định</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3">
            <div class="relative min-w-60 flex-1">
                <Search class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                <input v-model="filters.q" class="h-11 w-full rounded-[10px] border border-slate-200 bg-white pl-10 pr-3 text-[15px] outline-none placeholder:text-slate-400 focus:border-blue-400" placeholder="Tên, mã khách, email, số điện thoại (đủ số hoặc 4 số cuối)" aria-label="Tìm khách" data-testid="customer-q" />
            </div>
            <Select v-model="filters.group" class="w-full sm:w-44" aria-label="Nhóm" :options="[{ value: '', label: 'Mọi nhóm' }, ...groups.map((g) => ({ value: g.id, label: g.name })), { value: 'none', label: 'Chưa xếp nhóm' }]" />
            <Select v-if="tiers.length" v-model="filters.tier" class="w-full sm:w-40" aria-label="Hạng" :options="[{ value: '', label: 'Mọi hạng' }, ...tiers.map((t) => ({ value: t.id, label: t.name })), { value: 'none', label: 'Chưa có hạng' }]" />
            <Select v-model="filters.channel" class="w-full sm:w-44" aria-label="Kênh mua" :options="[{ value: '', label: 'Mọi kênh' }, ...Object.entries(channelKinds).map(([value, label]) => ({ value, label }))]" />
            <Select v-model="filters.assigned" class="w-full sm:w-44" aria-label="Nhân viên phụ trách" :options="[{ value: '', label: 'Mọi nhân viên' }, ...staff.map((s) => ({ value: s.id, label: s.name })), { value: 'none', label: 'Chưa giao' }]" />
            <button
                type="button"
                :class="cn('inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-[12px] border px-3.5 text-[14px]', moreFilters ? 'border-blue-300 bg-blue-50 text-blue-700' : 'border-slate-200 text-slate-600 hover:border-blue-300 hover:text-blue-700')"
                data-testid="more-filters"
                @click="moreFilters = !moreFilters"
            >
                <SlidersHorizontal class="size-4" /> Bộ lọc khác<span v-if="activeExtra" class="rounded-full bg-blue-600 px-1.5 text-[12px] font-semibold text-white">{{ activeExtra }}</span>
            </button>
        </div>
        <div v-if="moreFilters" class="flex flex-wrap items-end gap-3 border-b border-slate-100 bg-slate-50/60 px-5 py-3.5 text-sm" data-testid="extra-filters">
            <label class="grid gap-1">
                <span class="text-[12px] text-slate-500">Loại khách</span>
                <Select v-model="filters.type" class="w-36" aria-label="Loại khách" :options="[{ value: '', label: 'Tất cả' }, ...Object.entries(customerTypes).map(([value, label]) => ({ value, label }))]" />
            </label>
            <label class="grid gap-1">
                <span class="text-[12px] text-slate-500">Nguồn khách</span>
                <Select v-model="filters.source" class="w-40" aria-label="Nguồn khách" :options="[{ value: '', label: 'Tất cả' }, ...Object.entries(customerSources).map(([value, label]) => ({ value, label }))]" />
            </label>
            <label class="grid gap-1">
                <span class="text-[12px] text-slate-500">Giới tính</span>
                <Select v-model="filters.gender" class="w-32" aria-label="Giới tính" :options="[{ value: '', label: 'Tất cả' }, ...Object.entries(genderLabels).map(([value, label]) => ({ value, label }))]" />
            </label>
            <label class="grid gap-1">
                <span class="text-[12px] text-slate-500">Tháng sinh</span>
                <Select v-model="filters.birth_month" class="w-36" aria-label="Tháng sinh" :options="[{ value: '', label: 'Mọi tháng' }, ...months]" />
            </label>
            <label class="grid gap-1">
                <span class="text-[12px] text-slate-500">Tag</span>
                <Select v-model="filters.tag" class="w-40" aria-label="Tag" :options="[{ value: '', label: 'Mọi tag' }, ...tags.map((t) => ({ value: t, label: t }))]" />
            </label>
            <label class="grid gap-1">
                <span class="text-[12px] text-slate-500">Tổng chi tiêu từ</span>
                <Input v-model="filters.spent_min" type="number" min="0" step="1000" class="w-36" placeholder="0" aria-label="Chi tiêu từ" />
            </label>
            <label class="grid gap-1">
                <span class="text-[12px] text-slate-500">đến</span>
                <Input v-model="filters.spent_max" type="number" min="0" step="1000" class="w-36" placeholder="Không giới hạn" aria-label="Chi tiêu đến" />
            </label>
            <label class="grid gap-1">
                <span class="text-[12px] text-slate-500">Số đơn tối thiểu</span>
                <Input v-model="filters.orders_min" type="number" min="0" class="w-28" aria-label="Số đơn tối thiểu" />
            </label>
            <div class="grid gap-1">
                <span class="text-[12px] text-slate-500">Mua lần cuối</span>
                <DateRangeInput v-model:from="filters.last_order_from" v-model:to="filters.last_order_to" clearable placeholder="Mọi ngày" aria-label="Mua lần cuối" />
            </div>
            <div class="grid gap-1">
                <span class="text-[12px] text-slate-500">Ngày thêm</span>
                <DateRangeInput v-model:from="filters.created_from" v-model:to="filters.created_to" clearable placeholder="Mọi ngày" aria-label="Ngày thêm khách" />
            </div>
            <button v-if="activeExtra" type="button" class="inline-flex h-11 items-center gap-1.5 px-2 text-[13px] font-medium text-slate-500 hover:text-slate-900" @click="clearExtra"><X class="size-4" /> Xóa bộ lọc</button>
        </div>

        <div v-if="selected.size" class="flex flex-wrap items-center gap-3 border-b border-blue-100 bg-blue-50/60 px-5 py-2.5 text-sm" data-testid="bulk-bar">
            <span class="text-[14px] font-semibold text-blue-800">Đã chọn {{ selected.size }} khách</span>
            <Select v-model="bulkAction" class="w-56" aria-label="Thao tác hàng loạt" :options="BULK_ACTIONS" data-testid="bulk-action" />
            <Select v-if="bulkAction === 'set_group'" v-model="bulkValue" class="w-44" aria-label="Nhóm mới" :options="[{ value: '', label: 'Bỏ khỏi nhóm' }, ...groups.map((g) => ({ value: g.id, label: g.name }))]" />
            <Select v-else-if="bulkAction === 'assign'" v-model="bulkValue" class="w-44" aria-label="Nhân viên" :options="[{ value: '', label: 'Bỏ phụ trách' }, ...staff.map((s) => ({ value: s.id, label: s.name }))]" />
            <Select v-else-if="bulkAction === 'set_status'" v-model="bulkValue" class="w-44" aria-label="Trạng thái" :options="[{ value: '', label: 'Chọn…' }, { value: 'active', label: 'Đang giao dịch' }, { value: 'inactive', label: 'Ngừng giao dịch' }]" />
            <template v-else-if="bulkAction === 'add_tag' || bulkAction === 'remove_tag'">
                <Input v-model="bulkValue" class="w-40" list="bulk-tags" placeholder="Tên tag" aria-label="Tag" data-testid="bulk-value" @keydown.enter.prevent="bulkReady && applyBulk()" />
                <datalist id="bulk-tags"><option v-for="t in tags" :key="t" :value="t" /></datalist>
            </template>
            <button
                type="button"
                :class="cn('inline-flex h-10 items-center rounded-[10px] px-4 text-[14px] font-medium text-white disabled:opacity-50', bulkAction === 'delete' ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700')"
                :disabled="!bulkReady || bulkBusy"
                data-testid="bulk-apply"
                @click="applyBulk"
            >
                {{ bulkBusy ? 'Đang áp dụng…' : 'Áp dụng' }}
            </button>
            <button v-if="canExport" type="button" class="inline-flex h-10 items-center gap-1.5 rounded-[10px] border border-slate-200 bg-white px-3 text-[14px] text-slate-600 hover:text-blue-700" :disabled="exporting" @click="exportFile(true)"><Download class="size-4" /> Xuất các khách đã chọn</button>
            <button type="button" class="ml-auto text-[13px] text-slate-500 hover:text-slate-900" @click="selected = new Set()">Bỏ chọn</button>
        </div>

        <div v-if="loading && !rows.length" class="space-y-3 p-5"><Skeleton v-for="i in 6" :key="i" class="h-12" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState
            v-else-if="!rows.length"
            :icon="UsersRound"
            :title="filters.q || activeExtra || filters.group || filters.segment || filters.tier || filters.channel || filters.assigned || filters.view !== 'all' ? 'Không có khách phù hợp' : 'Chưa có khách hàng'"
            description="Khách được thêm tự động khi tạo đơn bán với khách mới, thêm thủ công, hoặc nhập từ file Excel."
        >
            <button v-if="canImport && !filters.q" type="button" :class="btnOutline" @click="importOpen = true"><Upload class="size-4" /> Nhập từ Excel</button>
        </EmptyState>
        <ul v-else class="divide-y divide-slate-100 md:hidden" :class="loading && 'opacity-60'" data-testid="customer-cards">
            <li v-for="c in rows" :key="c.id" class="flex items-start gap-3 px-4 py-3.5 text-sm">
                <input v-if="canManage" type="checkbox" class="mt-3 size-4" :checked="selected.has(c.id)" :aria-label="`Chọn ${c.name}`" @change="toggle(c.id)" />
                <span :class="cn('grid size-10 shrink-0 place-items-center rounded-full text-[13px] font-semibold', avatarTone(c.name))">{{ initials(c.name) }}</span>
                <RouterLink :to="`/customers/${c.id}`" class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="truncate font-semibold text-slate-900">{{ c.name }}</span>
                        <span v-if="c.tier" class="inline-flex items-center gap-1 rounded-md bg-white px-1.5 py-0.5 text-[11px] font-semibold ring-1 ring-slate-200" :style="{ color: c.tier.color ?? undefined }"><Crown class="size-3" />{{ c.tier.name }}</span>
                    </div>
                    <div class="text-[12px] text-slate-500">{{ c.code }}<template v-if="c.phone"> · {{ c.phone }}</template><template v-if="c.group"> · {{ c.group.name }}</template></div>
                    <div class="mt-1 flex flex-wrap gap-x-3 text-[12px] text-slate-600">
                        <span>{{ formatNumber(c.orders_count) }} đơn · {{ money(c.total_spent) }} ₫</span>
                        <span v-if="Number(c.debt) > 0" class="font-medium text-red-600">Nợ {{ money(c.debt) }}<template v-if="Number(c.overdue) > 0"> (quá hạn {{ money(c.overdue) }})</template></span>
                    </div>
                </RouterLink>
            </li>
        </ul>
        <div v-if="rows.length && !loadError" class="hidden overflow-x-auto md:block" :class="loading && 'opacity-60'">
            <table class="w-full" data-testid="customer-table">
                <thead class="bg-slate-50/70 text-left text-[12px] font-semibold uppercase tracking-wider text-slate-400">
                    <tr>
                        <th v-if="canManage" class="w-12 py-3 pl-5"><input type="checkbox" class="size-4 align-middle" :checked="allSelected" aria-label="Chọn tất cả trên trang" @change="toggleAll" /></th>
                        <th class="whitespace-nowrap px-4 py-3 font-semibold" :aria-sort="filters.sort === 'name' ? (sortMark('name') === '↑' ? 'ascending' : 'descending') : undefined">
                            <button type="button" class="inline-flex items-center gap-1 uppercase hover:text-slate-700" data-testid="sort-name" @click="sortBy('name')">Khách hàng<span class="text-blue-600">{{ sortMark('name') }}</span></button>
                        </th>
                        <th v-if="show('phone')" class="whitespace-nowrap px-4 py-3 font-semibold">Điện thoại</th>
                        <th v-if="show('group')" class="whitespace-nowrap px-4 py-3 font-semibold">Nhóm</th>
                        <th v-if="show('channels')" class="whitespace-nowrap px-4 py-3 font-semibold">Kênh mua</th>
                        <th v-if="show('assignee')" class="whitespace-nowrap px-4 py-3 font-semibold">Phụ trách</th>
                        <th v-if="show('tags')" class="whitespace-nowrap px-4 py-3 font-semibold">Tags</th>
                        <th v-for="col in sortCols.filter((x) => show(x.key))" :key="col.key" :class="cn('whitespace-nowrap px-4 py-3 font-semibold', col.right && 'text-right')" :aria-sort="filters.sort === col.sort ? (sortMark(col.sort) === '↑' ? 'ascending' : 'descending') : undefined">
                            <button type="button" class="inline-flex items-center gap-1 uppercase hover:text-slate-700" :data-testid="`sort-${col.sort}`" @click="sortBy(col.sort)">{{ col.label }}<span class="text-blue-600">{{ sortMark(col.sort) }}</span></button>
                        </th>
                        <th v-if="show('birthday')" class="whitespace-nowrap px-4 py-3 font-semibold">Ngày sinh</th>
                        <th v-if="show('status')" class="whitespace-nowrap px-4 py-3 font-semibold">Trạng thái</th>
                        <th v-if="show('created')" class="whitespace-nowrap px-4 py-3 font-semibold">Ngày thêm</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in rows" :key="c.id" :class="cn('border-t border-slate-100 hover:bg-slate-50/70', selected.has(c.id) && 'bg-blue-50/40')">
                        <td v-if="canManage" class="w-12 py-3.5 pl-5"><input type="checkbox" class="size-4 align-middle" :checked="selected.has(c.id)" :aria-label="`Chọn ${c.name}`" @change="toggle(c.id)" /></td>
                        <td class="px-4 py-3.5">
                            <div class="flex items-center gap-3">
                                <span :class="cn('grid size-10 shrink-0 place-items-center rounded-full text-[13px] font-semibold', avatarTone(c.name))">{{ initials(c.name) }}</span>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <RouterLink :to="`/customers/${c.id}`" class="max-w-[280px] truncate text-[14px] font-semibold text-slate-900 hover:text-blue-700 hover:underline">{{ c.name }}</RouterLink>
                                        <span v-if="c.tier" class="inline-flex shrink-0 items-center gap-1 rounded-md bg-white px-1.5 py-0.5 text-[11px] font-semibold ring-1 ring-slate-200" :style="{ color: c.tier.color ?? undefined }"><Crown class="size-3" />{{ c.tier.name }}</span>
                                        <span v-if="c.status === 'inactive'" class="shrink-0 rounded-md bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-500">Ngừng</span>
                                    </div>
                                    <div class="mt-0.5 flex items-center gap-1.5">
                                        <span class="font-mono text-[12px] text-slate-400">{{ c.code }}</span>
                                        <span v-for="t in (show('tags') ? [] : c.tags.slice(0, 2))" :key="t" class="truncate rounded bg-slate-100 px-1.5 py-px text-[11px] text-slate-500">{{ t }}</span>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td v-if="show('phone')" class="whitespace-nowrap px-4 py-3.5 text-[14px] tabular-nums text-slate-700">{{ c.phone ?? '—' }}</td>
                        <td v-if="show('group')" class="px-4 py-3.5"><span v-if="c.group" :class="groupTone(c.group.name)">{{ c.group.name }}</span><span v-else class="text-slate-300">—</span></td>
                        <td v-if="show('channels')" class="px-4 py-3.5"><ChannelDots :channels="c.channels" /></td>
                        <td v-if="show('assignee')" class="whitespace-nowrap px-4 py-3.5 text-[14px] text-slate-600">{{ c.assignee?.name ?? '—' }}</td>
                        <td v-if="show('tags')" class="px-4 py-3.5">
                            <span v-for="t in c.tags" :key="t" class="mr-1 inline-block rounded bg-slate-100 px-1.5 py-0.5 text-[12px] text-slate-600">{{ t }}</span>
                        </td>
                        <td v-if="show('orders')" class="px-4 py-3.5 text-right text-[14px] tabular-nums text-slate-700">{{ formatNumber(c.orders_count) }}</td>
                        <td v-if="show('spent')" class="whitespace-nowrap px-4 py-3.5 text-right text-[14px] font-semibold tabular-nums text-slate-900">{{ money(c.total_spent) }}</td>
                        <td v-if="show('debt')" class="whitespace-nowrap px-4 py-3.5 text-right">
                            <p v-if="Number(c.debt) > 0" class="text-[14px] font-semibold tabular-nums text-slate-900">{{ money(c.debt) }}</p>
                            <p v-else class="text-slate-300">—</p>
                            <p v-if="!show('overdue') && Number(c.overdue) > 0" class="text-[12px] font-medium text-red-600">quá hạn {{ money(c.overdue) }}</p>
                        </td>
                        <td v-if="show('overdue')" class="whitespace-nowrap px-4 py-3.5 text-right text-[14px] tabular-nums" :class="Number(c.overdue) > 0 ? 'font-semibold text-red-600' : 'text-slate-300'">{{ Number(c.overdue) > 0 ? money(c.overdue) : '—' }}</td>
                        <td v-if="show('last_order')" class="whitespace-nowrap px-4 py-3.5 text-[14px] text-slate-500" :title="formatDateTime(c.last_order_at)">{{ ago(c.last_order_at) }}</td>
                        <td v-if="show('birthday')" class="whitespace-nowrap px-4 py-3.5 text-[14px] text-slate-600">{{ c.birthday ? formatDate(c.birthday) : '—' }}</td>
                        <td v-if="show('status')" class="px-4 py-3.5">
                            <span :class="cn('inline-flex whitespace-nowrap rounded-md px-2 py-0.5 text-[12px] font-medium', c.status === 'inactive' ? 'bg-slate-100 text-slate-500' : 'bg-emerald-50 text-emerald-700')">{{ customerStatus[c.status]?.label }}</span>
                        </td>
                        <td v-if="show('created')" class="whitespace-nowrap px-4 py-3.5 text-[14px] text-slate-500">{{ formatDate(c.created_at) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <SoftPager v-model:per-page="perPage" :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" :count="rows.length" unit="khách" @go="(p) => ((filters.page = p), load())" />
    </section>
    <p class="mt-4 flex items-center gap-1.5 text-[13px] text-slate-500"><Info class="size-3.5 shrink-0" /> Bấm tên khách để mở hồ sơ: đơn đã mua, công nợ, điểm, việc cần nhắc. Khách được thêm tự động khi tạo đơn với khách mới.</p>
    <CustomerDialog v-model:open="dialog" @saved="(c) => $router.push(`/customers/${c.id}`)" />
    <CustomerImportDialog v-model:open="importOpen" @imported="(load(), loadSummary())" @update:open="(v: boolean) => !v && watchImport()" />
</template>
