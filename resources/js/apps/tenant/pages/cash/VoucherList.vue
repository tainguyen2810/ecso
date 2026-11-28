<script setup lang="ts">
import { computed, onMounted, provide, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowDownLeft, ArrowLeftRight, ArrowRight, ArrowUpRight, ChevronLeft, ChevronRight, Clock, Download, Info, Minus, Plus, ReceiptText, Scale, Search } from 'lucide-vue-next';
import ReportChip from '../reports/ReportChip.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import { downloadCsv, presetRange, toCsv } from '../reports/range';
import CashHead from './CashHead.vue';
import QuickRange from './QuickRange.vue';
import VoucherDetail from './VoucherDetail.vue';
import VoucherDialog from './VoucherDialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { paymentMethods } from '../orders/labels';
import { directionLabels, partyKinds, sourceLabels, voucherCode, voucherStatuses, type Direction, type Fund, type VoucherRow, type VoucherType } from './voucher';

const tenant = useTenantStore();
const route = useRoute();
const router = useRouter();

// Filters live in the URL so a filtered list can be bookmarked or shared.
const qs = (k: string) => (typeof route.query[k] === 'string' ? (route.query[k] as string) : '');
const initial = presetRange('month');
const direction = ref(qs('direction') || 'all');
const status = ref(qs('status') || 'all');
const range = ref({ from: qs('from') || initial.from, to: qs('to') || initial.to });
const q = ref(qs('q'));
const type = ref(qs('type'));
const method = ref(qs('method'));
const partyKind = ref(qs('party_kind'));
const account = ref(qs('account'));
const funds = ref<Fund[]>([]);
const page = ref(Number(qs('page')) || 1);
const perPage = ref([10, 20, 50, 100].includes(Number(qs('per_page'))) ? Number(qs('per_page')) : 20);
provide('soft-cards', true);

const rows = ref<VoucherRow[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
interface Totals {
    count: number;
    in: number;
    out: number;
    count_in: number;
    count_out: number;
    transfers: number;
    in_by_method: { method: string; amount: number }[];
    out_by_type: { type: string; amount: number }[];
    previous: { from: string; to: string; in: number; out: number } | null;
}
const totals = ref<Totals>({ count: 0, in: 0, out: 0, count_in: 0, count_out: 0, transfers: 0, in_by_method: [], out_by_type: [], previous: null });
/** Payments waiting for approval (whatever the filters) and the amount from which a payment needs approval. */
const pending = ref(0);
const pendingAmount = ref(0);
const threshold = ref<string | null>(null);
const types = ref<VoucherType[]>([]);
const loading = ref(true);
const loadError = ref<string | null>(null);
const creating = ref<Direction | null>(null);
const creatingOpen = computed({ get: () => creating.value !== null, set: (v) => !v && (creating.value = null) });
const selected = ref<VoucherRow | null>(null);
const detailOpen = ref(false);
const exporting = ref(false);

const params = () => ({
    direction: direction.value === 'all' ? undefined : direction.value,
    status: status.value === 'all' ? undefined : status.value,
    from: range.value.from || undefined,
    to: range.value.to || undefined,
    q: q.value || undefined,
    type: type.value || undefined,
    method: method.value || undefined,
    party_kind: partyKind.value || undefined,
    account: account.value || undefined,
});

async function load() {
    loading.value = true;
    loadError.value = null;
    router.replace({ query: { ...params(), page: page.value > 1 ? page.value : undefined, per_page: perPage.value !== 20 ? perPage.value : undefined } });
    try {
        const { data } = await api.get('/cash-vouchers', { params: { ...params(), page: page.value, per_page: perPage.value } });
        rows.value = data.data;
        meta.value = data.meta;
        totals.value = data.totals;
        pending.value = data.pending ?? 0;
        pendingAmount.value = data.pending_amount ?? 0;
        threshold.value = data.approval_threshold ?? null;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    load();
    api.get('/cash-voucher-types').then(({ data }) => (types.value = data.data)).catch(() => {});
    api.get('/cash-accounts').then(({ data }) => (funds.value = data.data)).catch(() => {});
    // Links from the cashbook open a voucher directly: /cash?open=voucher:ULID
    const [source, id] = qs('open').split(':');
    if (source && id) {
        try {
            selected.value = (await api.get(`/cash-vouchers/${source}/${id}`)).data.data;
            detailOpen.value = true;
        } catch {
            /* the list is still shown */
        }
    }
});
let timer: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => ((page.value = 1), load()), 300);
});
watch([direction, status, type, method, partyKind, account, perPage, () => range.value.from, () => range.value.to], () => ((page.value = 1), load()));

const methodLabel = (v: VoucherRow) => (v.source === 'transfer' ? 'Chuyển quỹ' : (paymentMethods[v.method] ?? v.method));
const fundOptions = computed(() => [{ value: '', label: 'Mọi quỹ' }, ...funds.value.map((f) => ({ value: f.id, label: f.is_active ? f.name : `${f.name} (ngừng dùng)` }))]);

const typeOptions = computed(() => [
    { value: '', label: 'Mọi loại phiếu' },
    { value: 'order_payment', label: 'Tự động: thu tiền đơn, hoàn tiền' },
    { value: 'supplier_payment', label: 'Tự động: trả nhà cung cấp' },
    { value: 'shift_movement', label: 'Tự động: thu, chi tiền mặt trong ca' },
    { value: 'transfer', label: 'Chuyển quỹ' },
    ...types.value
        .filter((t) => direction.value === 'all' || t.direction === direction.value)
        .map((t) => ({ value: t.id, label: `${directionLabels[t.direction]}: ${t.name}${t.is_active ? '' : ' (ngừng dùng)'}` })),
]);

function openRow(v: VoucherRow) {
    selected.value = v;
    detailOpen.value = true;
}

function onChanged(v: VoucherRow) {
    selected.value = v;
    load();
}

async function exportCsv() {
    exporting.value = true;
    try {
        const all: VoucherRow[] = [];
        for (let p = 1; p <= 50; p++) {
            const { data } = await api.get('/cash-vouchers', { params: { ...params(), page: p, per_page: 100 } });
            all.push(...data.data);
            if (p >= data.meta.last_page) break;
        }
        downloadCsv(
            `phieu-thu-chi-${range.value.from}-${range.value.to}.csv`,
            toCsv(
                ['Mã phiếu', 'Loại', 'Loại phiếu', 'Ngày ghi nhận', 'Đối tượng', 'Tên', 'Hình thức', 'Quỹ', 'Thu', 'Chi', 'Chuyển quỹ', 'Chứng từ gốc', 'Tham chiếu', 'Diễn giải', 'Tính KQKD', 'Trạng thái', 'Người lập'],
                all.map((v) => [
                    voucherCode(v),
                    directionLabels[v.direction],
                    v.type.name,
                    formatDateTime(v.paid_at),
                    partyKinds[v.party.kind],
                    v.party.name,
                    methodLabel(v),
                    v.account?.name ?? '',
                    v.direction === 'receipt' ? v.amount : null,
                    v.direction === 'payment' ? v.amount : null,
                    v.direction === 'transfer' ? v.amount : null,
                    v.document?.number ?? '',
                    v.reference,
                    v.note,
                    v.source === 'voucher' ? (v.counts_in_pnl ? 'Có' : 'Không') : '',
                    v.voided_at ? `${voucherStatuses[v.status]?.label ?? 'Đã hủy'}: ${v.void_reason}` : voucherStatuses[v.status]?.label ?? 'Hoàn thành',
                    v.created_by,
                ]),
            ),
        );
    } finally {
        exporting.value = false;
    }
}

const money = (n: number) => formatNumber(Math.round(n));
/** Change against the period of the same length just before, as "+12,4%" (null when there is nothing to compare). */
function change(now: number, before: number | undefined): { text: string; up: boolean } | null {
    if (before === undefined || before <= 0) return null;
    const pct = ((now - before) / before) * 100;
    return { text: `${pct >= 0 ? '+' : '−'}${Math.abs(pct).toLocaleString('vi-VN', { maximumFractionDigits: 1 })}%`, up: pct >= 0 };
}
const inChange = computed(() => change(totals.value.in, totals.value.previous?.in));
const outChange = computed(() => change(totals.value.out, totals.value.previous?.out));
const methodName = (m: string) => paymentMethods[m] ?? m;

const directionTabs = [
    { value: 'all', label: 'Tất cả' },
    { value: 'receipt', label: 'Phiếu thu' },
    { value: 'payment', label: 'Phiếu chi' },
];
const statusTabs = computed(() => [
    { value: 'all', label: 'Mọi trạng thái' },
    { value: 'active', label: 'Hoàn thành' },
    ...(threshold.value || pending.value ? [{ value: 'pending', label: pending.value ? `Chờ duyệt (${pending.value})` : 'Chờ duyệt' }] : []),
    { value: 'voided', label: 'Đã hủy' },
]);
const statusTone: Record<string, string> = { success: 'bg-emerald-50 text-emerald-700', warning: 'bg-amber-50 text-amber-700', danger: 'bg-red-50 text-red-600', muted: 'bg-slate-100 text-slate-600' };
const rowIcon = (v: VoucherRow) =>
    v.direction === 'transfer' ? [ArrowLeftRight, 'bg-blue-50 text-blue-600'] : v.direction === 'receipt' ? [ArrowDownLeft, 'bg-emerald-50 text-emerald-600'] : [ArrowUpRight, 'bg-red-50 text-red-500'];

/** Page numbers around the current one: 1 … 4 5 6 … 12. */
const pages = computed(() => {
    const last = meta.value.last_page;
    const cur = meta.value.current_page;
    const set = new Set([1, last, cur - 1, cur, cur + 1].filter((p) => p >= 1 && p <= last));
    const out: (number | '…')[] = [];
    [...set].sort((a, b) => a - b).forEach((p, i, all) => {
        if (i && p - all[i - 1] > 1) out.push('…');
        out.push(p);
    });
    return out;
});
const shown = computed(() => ({ from: (meta.value.current_page - 1) * perPage.value + 1, to: (meta.value.current_page - 1) * perPage.value + rows.value.length }));
const go = (p: number) => ((page.value = p), load());
const card = 'rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]';
const pill = (on: boolean) => cn('whitespace-nowrap rounded-[9px] px-3.5 py-1.5', on ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-900');
</script>

<template>
    <CashHead title="Phiếu thu, phiếu chi" description="Mọi khoản tiền vào, ra: phiếu lập tay và phiếu tự động từ đơn hàng, trả nhà cung cấp.">
        <template v-if="tenant.can('finance.vouchers')">
            <button type="button" class="inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-[12px] border border-emerald-200 bg-emerald-50 px-4 text-[15px] font-medium text-emerald-700 hover:bg-emerald-100" data-testid="new-receipt" @click="creating = 'receipt'"><Plus class="size-4" /> Lập phiếu thu</button>
            <button type="button" class="inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-[12px] border border-red-200 bg-red-50 px-4 text-[15px] font-medium text-red-600 hover:bg-red-100" data-testid="new-payment" @click="creating = 'payment'"><Minus class="size-4" /> Lập phiếu chi</button>
        </template>
    </CashHead>

    <div v-if="pending && tenant.can('finance.approve') && status !== 'pending'" class="mb-5 flex flex-wrap items-center gap-3 rounded-[14px] border border-amber-200 bg-amber-50 px-5 py-3.5" data-testid="pending-banner">
        <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-amber-100 text-amber-600"><Clock class="size-[18px]" /></span>
        <p class="min-w-0 flex-1 text-[14px] text-amber-800">
            <b>{{ pending }} phiếu chi</b> đang chờ bạn duyệt, tổng <b>{{ money(pendingAmount) }} đ</b><template v-if="threshold"> (phiếu từ {{ money(Number(threshold)) }} đ trở lên cần duyệt)</template>.
        </p>
        <button type="button" class="inline-flex h-9 items-center gap-1.5 rounded-[10px] bg-amber-500 px-4 text-[14px] font-medium text-white hover:bg-amber-600" @click="status = 'pending'">Xem và duyệt <ArrowRight class="size-4" /></button>
    </div>

    <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="voucher-totals">
        <ReportKpi label="Tổng thu" tip="Phiếu thu hoàn thành trong kỳ và bộ lọc đang chọn, gồm cả tiền khách trả đơn." :icon="ArrowDownLeft" tone="green" :value="Math.round(totals.in)">
            <template #chips>
                <ReportChip tone="up">{{ formatNumber(totals.count_in) }} phiếu</ReportChip>
                <ReportChip v-if="inChange" :tone="inChange.up ? 'up' : 'plain'">{{ inChange.text }} so với kỳ trước</ReportChip>
            </template>
            <template v-if="totals.in_by_method.length">{{ totals.in_by_method.slice(0, 2).map((m) => `${methodName(m.method)} ${money(m.amount)}`).join(' · ') }}</template>
        </ReportKpi>
        <ReportKpi label="Tổng chi" tip="Phiếu chi hoàn thành trong kỳ và bộ lọc đang chọn, gồm cả trả nhà cung cấp và hoàn tiền khách." :icon="ArrowUpRight" tone="red" :value="Math.round(totals.out)">
            <template #chips>
                <ReportChip tone="red">{{ formatNumber(totals.count_out) }} phiếu</ReportChip>
                <ReportChip v-if="outChange" :tone="outChange.up ? 'red' : 'plain'">{{ outChange.text }} so với kỳ trước</ReportChip>
            </template>
            <template v-if="totals.out_by_type.length">{{ totals.out_by_type.slice(0, 2).map((t) => `${t.type} ${money(t.amount)}`).join(' · ') }}</template>
        </ReportKpi>
        <ReportKpi label="Chênh lệch thu − chi" tip="Tổng thu trừ tổng chi. Chuyển quỹ chỉ đổi chỗ tiền nên không tính." :icon="Scale" primary :value="Math.round(totals.in - totals.out)">
            <template #chips><ReportChip tone="onblue">{{ formatNumber(totals.count) }} phiếu hoàn thành</ReportChip></template>
            {{ totals.transfers ? `Không tính ${formatNumber(totals.transfers)} lần chuyển quỹ và phiếu đã hủy` : 'Không tính phiếu đã hủy, chờ duyệt' }}
        </ReportKpi>
        <ReportKpi label="Chờ duyệt" tip="Phiếu chi từ mức cần duyệt trở lên, đang chờ chủ cửa hàng duyệt. Chưa tính vào tổng chi." :icon="Clock" tone="amber" :value="pending" unit="phiếu">
            <template #chips><ReportChip tone="amber">{{ money(pendingAmount) }} đ</ReportChip></template>
            {{ threshold ? `Phiếu chi từ ${money(Number(threshold))} đ cần duyệt` : 'Chưa đặt mức cần duyệt phiếu chi' }}
        </ReportKpi>
    </div>

    <section :class="cn(card, 'overflow-hidden')">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 pb-3 pt-4">
            <div class="flex rounded-[12px] bg-slate-100 p-1 text-[14px]" role="group" aria-label="Loại phiếu" data-testid="voucher-direction">
                <button v-for="t in directionTabs" :key="t.value" type="button" :class="pill(direction === t.value)" :aria-pressed="direction === t.value" @click="direction = t.value">{{ t.label }}</button>
            </div>
            <div class="flex rounded-[12px] bg-slate-100 p-1 text-[14px]" role="group" aria-label="Trạng thái" data-testid="voucher-status">
                <button v-for="t in statusTabs" :key="t.value" type="button" :class="pill(status === t.value)" :aria-pressed="status === t.value" @click="status = t.value">{{ t.label }}</button>
            </div>
            <QuickRange v-model:from="range.from" v-model:to="range.to" class="xl:ml-auto" />
        </div>
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3">
            <div class="relative min-w-60 flex-1">
                <Search class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                <input v-model="q" class="h-11 w-full rounded-[10px] border border-slate-200 bg-white pl-10 pr-3 text-[15px] outline-none placeholder:text-slate-400 focus:border-blue-400" placeholder="Mã phiếu, người nộp/nhận, mã đơn, tham chiếu" aria-label="Tìm phiếu" data-testid="voucher-q" />
            </div>
            <Select v-model="type" class="w-full sm:w-56" :options="typeOptions" aria-label="Loại phiếu" data-testid="voucher-type-filter" />
            <Select v-model="partyKind" class="w-full sm:w-44" :options="[{ value: '', label: 'Mọi đối tượng' }, ...Object.entries(partyKinds).map(([value, label]) => ({ value, label }))]" aria-label="Đối tượng" />
            <Select v-model="account" class="w-full sm:w-48" :options="fundOptions" aria-label="Quỹ" data-testid="voucher-account-filter" />
            <Select v-model="method" class="w-full sm:w-44" :options="[{ value: '', label: 'Mọi hình thức' }, ...Object.entries(paymentMethods).map(([value, label]) => ({ value, label }))]" aria-label="Hình thức" />
            <button type="button" class="inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-[12px] border border-slate-200 px-3.5 text-[14px] text-slate-600 hover:border-blue-300 hover:text-blue-700 disabled:opacity-50" :disabled="!meta.total || exporting" @click="exportCsv">
                <Download class="size-4" /> {{ exporting ? 'Đang xuất…' : 'Xuất CSV' }}
            </button>
        </div>

        <div v-if="loading && !rows.length" class="space-y-3 p-5"><Skeleton v-for="i in 6" :key="i" class="h-12" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState v-else-if="!rows.length" :icon="ReceiptText" title="Không có phiếu nào" description="Đổi khoảng ngày hoặc bộ lọc, hoặc lập phiếu thu, phiếu chi mới." />
        <div v-else class="overflow-x-auto 2xl:overflow-visible" :class="loading && 'opacity-60'">
            <table class="w-full min-w-[1080px]" data-testid="voucher-table">
                <thead class="bg-slate-50/70 text-left text-[12px] font-semibold uppercase tracking-wider text-slate-400">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Mã phiếu</th>
                        <th class="px-4 py-3 font-semibold">Ngày ghi nhận</th>
                        <th class="px-4 py-3 font-semibold">Loại phiếu</th>
                        <th class="px-4 py-3 font-semibold">Người nộp / nhận</th>
                        <th class="px-4 py-3 font-semibold">Hình thức, quỹ</th>
                        <th class="px-4 py-3 text-right font-semibold">Thu</th>
                        <th class="px-4 py-3 text-right font-semibold">Chi</th>
                        <th class="px-4 py-3 font-semibold">Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="v in rows" :key="`${v.source}-${v.id}`" class="cursor-pointer border-t border-slate-100 hover:bg-slate-50/70" @click="openRow(v)">
                        <td class="px-4 py-3.5">
                            <div class="flex items-center gap-3">
                                <span :class="cn('grid size-8 shrink-0 place-items-center rounded-[9px]', rowIcon(v)[1])"><component :is="rowIcon(v)[0]" class="size-4" /></span>
                                <div class="min-w-0">
                                    <p :class="cn('font-mono text-[13px] font-semibold', v.voided_at ? 'text-slate-400 line-through' : v.number ? 'text-slate-900' : 'text-blue-700')">{{ v.number ?? v.document?.number ?? 'Tự động' }}</p>
                                    <p class="text-[12px] text-slate-400">
                                        {{ v.source === 'voucher' ? 'Lập tay' : sourceLabels[v.source] }}<span v-if="v.imported" class="ml-1 rounded bg-blue-50 px-1 text-blue-600">Sapo</span>
                                    </p>
                                </div>
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3.5">
                            <p class="text-[14px] text-slate-800">{{ formatDateTime(v.paid_at) }}</p>
                            <p class="text-[12px] text-slate-400">{{ v.created_by }}</p>
                        </td>
                        <td class="px-4 py-3.5">
                            <p class="text-[14px] font-medium text-slate-800">{{ v.type.name }}</p>
                            <p v-if="v.note" class="max-w-[260px] truncate text-[12px] text-slate-500">{{ v.note }}</p>
                        </td>
                        <td class="px-4 py-3.5">
                            <p class="max-w-[240px] truncate text-[14px] text-slate-800">{{ v.party.name }}</p>
                            <p class="text-[12px] text-slate-500">{{ v.source === 'transfer' ? 'Chuyển quỹ' : partyKinds[v.party.kind] }}</p>
                        </td>
                        <td class="max-w-[240px] px-4 py-3.5">
                            <span v-if="v.source === 'transfer'" class="text-[13px] text-slate-500">Giữa hai quỹ</span>
                            <template v-else>
                                <span class="rounded-md bg-slate-100 px-2 py-0.5 text-[12px] text-slate-600">{{ methodLabel(v) }}</span>
                                <p v-if="v.account || v.reference" class="mt-1 truncate text-[12px] text-slate-500">{{ [v.account?.name, v.number ? v.reference : null].filter(Boolean).join(' · ') }}</p>
                            </template>
                        </td>
                        <td v-if="v.direction === 'transfer'" colspan="2" class="px-4 py-3.5 text-right text-[14px] tabular-nums text-blue-700" title="Chuyển giữa các quỹ, không tính thu hay chi">⇄ {{ money(v.amount) }}</td>
                        <template v-else>
                            <td :class="cn('whitespace-nowrap px-4 py-3.5 text-right text-[14px] font-semibold tabular-nums', v.voided_at || v.status === 'pending' ? 'text-slate-400' : 'text-emerald-600', v.voided_at && 'line-through')">{{ v.direction === 'receipt' ? `+${money(v.amount)}` : '' }}</td>
                            <td :class="cn('whitespace-nowrap px-4 py-3.5 text-right text-[14px] font-semibold tabular-nums', v.voided_at || v.status === 'pending' ? 'text-slate-400' : 'text-red-600', v.voided_at && 'line-through')">{{ v.direction === 'payment' ? `−${money(v.amount)}` : '' }}</td>
                        </template>
                        <td class="px-4 py-3.5">
                            <span :class="cn('inline-flex whitespace-nowrap rounded-md px-2 py-0.5 text-[12px] font-medium', statusTone[voucherStatuses[v.status]?.tone ?? 'success'])">{{ voucherStatuses[v.status]?.label ?? 'Hoàn thành' }}</span>
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t border-slate-200 bg-slate-50/70">
                        <td colspan="5" class="px-4 py-3 text-[14px] font-semibold text-slate-700">Tổng {{ formatNumber(totals.count) }} phiếu hoàn thành (đang lọc)</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-[14px] font-bold tabular-nums text-emerald-600">+{{ money(totals.in) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-[14px] font-bold tabular-nums text-red-600">−{{ money(totals.out) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div v-if="meta.total" class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-3.5 text-[13px] text-slate-500">
            <span>Hiển thị {{ formatNumber(shown.from) }}–{{ formatNumber(shown.to) }} trong {{ formatNumber(meta.total) }} phiếu</span>
            <div class="flex items-center gap-1">
                <button type="button" class="grid size-8 place-items-center rounded-[8px] text-slate-600 hover:bg-slate-100 disabled:text-slate-300" :disabled="meta.current_page <= 1" aria-label="Trang trước" @click="go(meta.current_page - 1)"><ChevronLeft class="size-4" /></button>
                <template v-for="(p, i) in pages" :key="i">
                    <span v-if="p === '…'" class="grid size-8 place-items-center">…</span>
                    <button v-else type="button" :class="cn('grid size-8 place-items-center rounded-[8px] tabular-nums', p === meta.current_page ? 'bg-blue-600 font-semibold text-white' : 'text-slate-600 hover:bg-slate-100')" @click="go(p)">{{ p }}</button>
                </template>
                <button type="button" class="grid size-8 place-items-center rounded-[8px] text-slate-600 hover:bg-slate-100 disabled:text-slate-300" :disabled="meta.current_page >= meta.last_page" aria-label="Trang sau" @click="go(meta.current_page + 1)"><ChevronRight class="size-4" /></button>
                <select v-model.number="perPage" class="ml-3 h-9 rounded-[10px] border border-slate-200 bg-white px-2.5 text-[13px] text-slate-700" aria-label="Số dòng mỗi trang">
                    <option v-for="n in [10, 20, 50, 100]" :key="n" :value="n">{{ n }} dòng</option>
                </select>
            </div>
        </div>
    </section>
    <p class="mt-4 flex items-center gap-1.5 text-[13px] text-slate-500"><Info class="size-3.5 shrink-0" /> Bấm một dòng để xem chi tiết, in phiếu, duyệt hoặc hủy. Phiếu tự động sửa ở chứng từ gốc (đơn hàng, phiếu nhập).</p>

    <VoucherDialog v-model:open="creatingOpen" :direction="creating ?? 'receipt'" @saved="load" />
    <VoucherDetail v-model:open="detailOpen" :voucher="selected" @changed="onChanged" />
</template>
