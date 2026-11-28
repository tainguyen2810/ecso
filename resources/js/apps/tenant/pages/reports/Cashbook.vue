<script setup lang="ts">
import { computed, onMounted, provide, ref, watch } from 'vue';
import { ArrowDownLeft, ArrowUpRight, ChevronLeft, ChevronRight, CircleHelp, Download, Info, Landmark, PiggyBank, Plus, Search, Smartphone, Wallet } from 'lucide-vue-next';
import ReportDetailHead from './ReportDetailHead.vue';
import ReportKpi from './ReportKpi.vue';
import ReportChip from './ReportChip.vue';
import SideCard from './SideCard.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { cn, formatDate, formatDateTime, formatNumber } from '@/shared/lib/utils';
import { paymentMethods } from '../orders/labels';
import { downloadCsv, presetRange, toCsv } from './range';

/**
 * Sổ quỹ (mẫu Tailwind 28/11): opening, in, out and closing; the balance day by day; the funds with their balance;
 * then every entry with its running balance, 20 a page.
 */
import { useRoute, useRouter } from 'vue-router';
import type { Fund } from '../cash/voucher';

interface Entry {
    paid_at: string;
    type: 'payment' | 'refund' | 'supplier' | 'supplier_refund' | 'voucher' | 'voucher_reversal' | 'transfer_in' | 'transfer_out';
    label: string | null;
    method: string;
    amount: number;
    /** Null without finance.balances. */
    balance: number | null;
    document: { id: string; number: string; kind: 'order' | 'purchase' | 'voucher' | 'transfer' } | null;
    party: string | null;
    reference: string | null;
    created_by: string | null;
}
interface Book {
    opening: number | null;
    in: number;
    out: number;
    closing: number | null;
    by_method: { method: string; in: number; out: number }[];
    count: number;
    truncated: boolean;
    account: { id: string; name: string; kind: string } | null;
    rows: Entry[];
}

const types: Record<Entry['type'], string> = { payment: 'Thu tiền bán hàng', refund: 'Hoàn tiền khách', supplier: 'Chi trả nhà cung cấp', supplier_refund: 'Nhà cung cấp hoàn tiền', voucher: 'Phiếu thu chi', voucher_reversal: 'Hủy phiếu kỳ đã khóa (ghi đảo)', transfer_in: 'Chuyển quỹ đến', transfer_out: 'Chuyển quỹ đi' };
const label = (e: Entry) => ((e.type === 'voucher' || e.type === 'voucher_reversal') && e.label ? e.label : types[e.type]);
const methodName = (m: string) => (m === 'transfer' ? 'Chuyển quỹ' : (paymentMethods[m] ?? m));
const link = (d: NonNullable<Entry['document']>) => (d.kind === 'order' ? `/orders/${d.id}` : d.kind === 'purchase' ? `/purchasing/${d.id}` : `/cash?open=${d.kind}:${d.id}`);
provide('soft-cards', true);
const range = ref(presetRange('month'));
const route = useRoute();
const router = useRouter();
const method = ref('');
// The fund comes from the URL (?account=ULID), so a fund card links straight to its book.
const account = ref(typeof route.query.account === 'string' ? route.query.account : '');
const funds = ref<Fund[]>([]);
const fundOptions = computed(() => [{ value: '', label: 'Tất cả quỹ' }, ...funds.value.map((f) => ({ value: f.id, label: f.is_active ? f.name : `${f.name} (ngừng dùng)` }))]);
const data = ref<Book | null>(null);
const error = ref<string | null>(null);
const loading = ref(false);
const direction = ref<'all' | 'in' | 'out'>('all');
const search = ref('');
const glossary = ref(false);
const directions = { all: 'Tất cả', in: 'Thu', out: 'Chi' } as const;
const terms = [
    ['Tồn đầu kỳ', 'Tiền trong quỹ tính đến hết ngày trước ngày bắt đầu kỳ đang xem.'],
    ['Tổng thu', 'Tiền thu vào trong kỳ: khách trả tiền đơn hàng, thu nợ và các phiếu thu.'],
    ['Tổng chi', 'Tiền chi ra trong kỳ: hoàn tiền cho khách, trả tiền nhà cung cấp và các phiếu chi.'],
    ['Tồn cuối kỳ', 'Tồn đầu kỳ + Tổng thu − Tổng chi, tức tiền trong quỹ tính đến hết ngày cuối kỳ.'],
    ['Tồn quỹ từng dòng', 'Số dư ngay sau khoản thu hoặc chi đó, cộng dồn từ tồn đầu kỳ.'],
    ['Quỹ', 'Nơi tiền đang nằm: két tiền mặt, từng tài khoản ngân hàng, ví điện tử. Chọn một quỹ để xem sổ của riêng quỹ đó, có cả các lần chuyển quỹ.'],
    ['Chuyển quỹ', 'Tiền đổi chỗ giữa các quỹ, ví dụ nộp tiền mặt vào ngân hàng. Không phải thu hay chi nên sổ tất cả quỹ không tính.'],
    ['Hình thức', 'Cách khách trả hoặc mình trả. Lọc theo hình thức thì không tính số dư đầu của quỹ và các lần chuyển quỹ.'],
] as const;

// Tabs and search only narrow the list; the totals and running balance stay those of the whole book.
const rows = computed(() => {
    const q = search.value.trim().toLowerCase();
    return (data.value?.rows ?? []).filter(
        (e) =>
            (direction.value === 'all' || (direction.value === 'in' ? e.amount > 0 : e.amount < 0)) &&
            (!q || [e.document?.number, e.party, e.reference, e.created_by].some((v) => v?.toLowerCase().includes(q))),
    );
});

async function load() {
    loading.value = true;
    error.value = null;
    try {
        router.replace({ query: { ...route.query, account: account.value || undefined } });
        data.value = (await api.get('/reports/cashbook', { params: { ...range.value, method: method.value || undefined, account: account.value || undefined } })).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(() => {
    load();
    api.get('/cash-accounts').then(({ data }) => (funds.value = data.data)).catch(() => {});
});
watch([range, method, account], load, { deep: true });

// Every row of the period, written by the server (the screen lists the newest rows only).
const exporting = ref(false);
async function exportExcel() {
    exporting.value = true;
    try {
        const params = new URLSearchParams({ ...range.value, ...(method.value ? { method: method.value } : {}), ...(account.value ? { account: account.value } : {}) });
        await downloadFile(`/reports/cashbook/export?${params.toString()}`, `so-quy-${range.value.from}-${range.value.to}.xlsx`);
    } finally {
        exporting.value = false;
    }
}

function exportCsv() {
    if (!data.value) return;
    downloadCsv(
        `so-quy-${data.value.account?.name || method.value || 'tat-ca'}-${range.value.from}-${range.value.to}.csv`,
        toCsv(
            ['Thời gian', 'Loại', 'Hình thức', 'Chứng từ', 'Đối tượng', 'Tham chiếu', 'Thu', 'Chi', 'Tồn quỹ', 'Người lập'],
            [...data.value.rows].reverse().map((e) => [formatDateTime(e.paid_at), label(e), methodName(e.method), e.document?.number ?? '', e.party, e.reference, e.amount > 0 ? e.amount : null, e.amount < 0 ? -e.amount : null, e.balance, e.created_by]),
        ),
    );
}

const card = 'rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]';
const ins = computed(() => (data.value?.rows ?? []).filter((e) => e.amount > 0).length);
const outs = computed(() => (data.value?.rows ?? []).filter((e) => e.amount < 0).length);

/** Closing balance of each day of the period, carried over days with no entry. Needs the balances (finance.balances). */
const days = computed(() => {
    const d = data.value;
    if (!d || d.opening === null || !d.rows.length || d.rows.some((e) => e.balance === null)) return [];
    const last = new Map<string, number>();
    // Rows come newest first: the first one seen for a day is its closing balance.
    for (const e of d.rows) {
        const day = e.paid_at.slice(0, 10);
        if (!last.has(day)) last.set(day, Number(e.balance));
    }
    const out: { day: string; balance: number }[] = [];
    let run = Number(d.truncated ? d.rows[d.rows.length - 1]!.balance! - d.rows[d.rows.length - 1]!.amount : d.opening);
    const start = new Date(`${range.value.from}T00:00:00`);
    const end = new Date(`${range.value.to}T00:00:00`);
    for (let t = start; t <= end; t = new Date(t.getTime() + 86400000)) {
        const key = `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`;
        if (last.has(key)) run = last.get(key)!;
        out.push({ day: key, balance: run });
    }
    return out;
});
const CW = 900;
const CH = 250;
const chart = computed(() => {
    const pts = days.value;
    if (pts.length < 2) return null;
    const vals = pts.map((p) => p.balance);
    let lo = Math.min(...vals);
    let hi = Math.max(...vals);
    const pad = (hi - lo) * 0.15 || Math.abs(hi) * 0.1 || 1;
    lo -= pad;
    hi += pad;
    const x = (i: number) => (i / (pts.length - 1)) * CW;
    const y = (v: number) => 10 + ((hi - v) / (hi - lo)) * CH;
    const line = pts.map((p, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(p.balance).toFixed(1)}`).join(' ');
    const step = Math.max(1, Math.ceil(pts.length / 7));
    return {
        line,
        area: `${line} L${CW},${CH + 10} L0,${CH + 10} Z`,
        ticks: pts.map((p, i) => ({ i, x: x(i), label: `${p.day.slice(8, 10)}/${p.day.slice(5, 7)}` })).filter((t) => t.i % step === 0),
        end: { x: x(pts.length - 1), y: y(pts[pts.length - 1]!.balance) },
    };
});

const fundIcon = (k: string) => (k === 'cash' ? Wallet : k === 'bank' ? Landmark : Smartphone);
const fundTint = (k: string) => (k === 'cash' ? 'bg-emerald-50 text-emerald-600' : k === 'bank' ? 'bg-blue-50 text-blue-600' : 'bg-violet-50 text-violet-600');
const activeFunds = computed(() => funds.value.filter((f) => f.is_active));

/** 20 entries a page. */
const pageSize = ref(20);
const page = ref(1);
watch(() => [rows.value, pageSize.value], () => (page.value = 1));
const pages = computed(() => Math.max(1, Math.ceil(rows.value.length / pageSize.value)));
const shown = computed(() => rows.value.slice((page.value - 1) * pageSize.value, page.value * pageSize.value));
const pageList = computed<(number | '…')[]>(() => {
    const n = pages.value;
    const p = page.value;
    if (n <= 7) return Array.from({ length: n }, (_, i) => i + 1);
    const set = [...new Set([1, 2, p - 1, p, p + 1, n - 1, n].filter((x) => x >= 1 && x <= n))].sort((a, b) => a - b);
    return set.flatMap((x, i) => (i && x - set[i - 1]! > 1 ? (['…', x] as const) : [x]));
});
const when = (iso: string) => {
    const d = new Date(iso);
    const p2 = (n: number) => String(n).padStart(2, '0');
    return `${p2(d.getDate())}/${p2(d.getMonth() + 1)} ${p2(d.getHours())}:${p2(d.getMinutes())}`;
};
const money = (v: number) => formatNumber(Math.round(v));
</script>

<template>
    <div class="pt-2">
        <ReportDetailHead
            title="Sổ quỹ"
            description="Tiền thu từ khách, hoàn tiền, chi trả nhà cung cấp và các phiếu thu chi; tồn quỹ đầu và cuối kỳ của từng quỹ."
            :from="range.from"
            :to="range.to"
            :exporting="exporting"
            :can-export="Boolean(data)"
            export-label="Xuất Excel"
            export-testid="cashbook-excel"
            @update:range="(r) => (range = r)"
            @export="exportExcel"
        >
            <template #actions>
                <button
                    type="button"
                    class="inline-flex h-11 items-center gap-2 rounded-[12px] border border-slate-200 bg-white px-4 text-[15px] font-medium text-slate-700 hover:border-blue-300 hover:text-blue-700"
                    data-testid="cashbook-glossary"
                    @click="glossary = true"
                >
                    <CircleHelp class="size-4" /> Thuật ngữ
                </button>
            </template>
        </ReportDetailHead>

        <ErrorState v-if="error" :message="error" @retry="load" />
        <div v-else-if="!data" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><Skeleton v-for="i in 4" :key="i" class="h-[132px] rounded-[14px]" /></div>
        <template v-else>
            <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="cashbook-kpis">
                <ReportKpi label="Tồn đầu kỳ" tip="Tiền trong quỹ tính đến hết ngày trước ngày bắt đầu kỳ." :icon="Wallet" tone="slate" :value="data.opening === null ? null : Math.round(data.opening)" :text="data.opening === null ? '•••' : undefined">
                    <template #chips><ReportChip>{{ formatDate(range.from) }}</ReportChip></template>
                    {{ data.account ? data.account.name : 'Tổng các quỹ đầu kỳ' }}
                </ReportKpi>
                <ReportKpi label="Tổng thu" tip="Khách trả tiền đơn, thu nợ và các phiếu thu trong kỳ." :icon="ArrowDownLeft" tone="green" :value="Math.round(data.in)">
                    <template #chips><ReportChip tone="up">{{ formatNumber(ins) }} khoản</ReportChip></template>
                    <template v-if="data.by_method.length">{{ data.by_method.filter((m) => m.in).map((m) => `${methodName(m.method)} ${money(m.in)}`).slice(0, 2).join(' · ') }}</template>
                </ReportKpi>
                <ReportKpi label="Tổng chi" tip="Hoàn tiền khách, trả nhà cung cấp và các phiếu chi trong kỳ." :icon="ArrowUpRight" tone="red" :value="Math.round(data.out)">
                    <template #chips><ReportChip tone="red">{{ formatNumber(outs) }} khoản</ReportChip></template>
                    <template v-if="data.by_method.length">{{ data.by_method.filter((m) => m.out).map((m) => `${methodName(m.method)} ${money(m.out)}`).slice(0, 2).join(' · ') }}</template>
                </ReportKpi>
                <ReportKpi label="Tồn cuối kỳ" tip="Tồn đầu kỳ + tổng thu − tổng chi." :icon="PiggyBank" primary :value="data.closing === null ? null : Math.round(data.closing)" :text="data.closing === null ? '•••' : undefined">
                    <template v-if="data.closing !== null && data.opening !== null" #chips>
                        <ReportChip tone="onblue">{{ data.closing >= data.opening ? '+' : '−' }}{{ money(Math.abs(data.closing - data.opening)) }} đ</ReportChip>
                        <span class="text-blue-100">trong kỳ</span>
                    </template>
                    <span data-testid="cashbook-closing">Tồn quỹ đến hết {{ formatDate(range.to) }}</span>
                </ReportKpi>
            </div>

            <div class="mb-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
                <section :class="card">
                    <header class="border-b border-slate-100 px-6 py-4">
                        <h3 class="text-[16px] font-semibold text-slate-900">Tồn quỹ theo ngày</h3>
                        <p class="mt-0.5 text-[13px] text-slate-500">{{ data.account ? data.account.name : 'Tổng tiền các quỹ' }} cuối mỗi ngày</p>
                    </header>
                    <div v-if="chart" class="px-6 py-5">
                        <svg :viewBox="`0 0 ${CW} ${CH + 40}`" class="h-auto w-full" role="img" aria-label="Tồn quỹ theo ngày">
                            <defs>
                                <linearGradient id="cb-fill" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#2563eb" stop-opacity="0.16" /><stop offset="1" stop-color="#2563eb" stop-opacity="0" /></linearGradient>
                            </defs>
                            <line v-for="g in 4" :key="g" x1="0" :x2="CW" :y1="10 + (g - 1) * (CH / 3)" :y2="10 + (g - 1) * (CH / 3)" stroke="#e2e8f0" stroke-dasharray="3 4" />
                            <path :d="chart.area" fill="url(#cb-fill)" />
                            <path :d="chart.line" fill="none" stroke="#2563eb" stroke-width="2.5" stroke-linejoin="round" />
                            <circle :cx="chart.end.x" :cy="chart.end.y" r="4.5" fill="#2563eb" stroke="#fff" stroke-width="2" />
                            <text v-for="t in chart.ticks" :key="t.i" :x="t.x" :y="CH + 34" :text-anchor="t.i === 0 ? 'start' : 'middle'" class="fill-slate-400 text-[12px]">{{ t.label }}</text>
                        </svg>
                    </div>
                    <p v-else class="px-6 py-10 text-center text-[14px] text-slate-500">{{ data.opening === null ? 'Cần quyền xem số dư quỹ để vẽ biểu đồ.' : 'Chưa đủ số liệu để vẽ biểu đồ.' }}</p>
                </section>

                <SideCard title="Các quỹ" description="Số dư hiện tại">
                    <template #actions><RouterLink to="/cash/accounts" class="text-[13px] font-medium text-blue-700 hover:underline">Quản lý</RouterLink></template>
                    <ul class="divide-y divide-slate-100 px-5">
                        <li v-for="f in activeFunds" :key="f.id">
                            <button type="button" :class="cn('flex w-full items-center gap-3 py-3 text-left', account === f.id && 'text-blue-700')" @click="account = account === f.id ? '' : f.id">
                                <span :class="cn('grid size-10 shrink-0 place-items-center rounded-[10px]', fundTint(f.kind))"><component :is="fundIcon(f.kind)" class="size-5" /></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[14px] font-medium">{{ f.name }}</span>
                                    <span class="block text-[12px] text-slate-500">{{ f.account_number ? `•••• ${f.account_number.slice(-4)}` : (f.bank_name ?? (f.kind === 'cash' ? 'Tiền mặt' : '')) }}</span>
                                </span>
                                <b class="tabular-nums text-slate-900">{{ f.balance === null ? '•••' : money(f.balance) }}</b>
                            </button>
                        </li>
                        <li v-if="!activeFunds.length" class="py-4 text-[14px] text-slate-500">Chưa có quỹ nào.</li>
                    </ul>
                </SideCard>
            </div>

            <section :class="cn(card, 'overflow-hidden')">
                <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-4">
                    <div class="flex rounded-[12px] bg-slate-100 p-1 text-[14px]" role="tablist">
                        <button
                            v-for="(l, key) in directions"
                            :key="key"
                            type="button"
                            role="tab"
                            :aria-selected="direction === key"
                            :data-testid="`cashbook-tab-${key}`"
                            :class="cn('rounded-[9px] px-3.5 py-1.5', direction === key ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-900')"
                            @click="direction = key"
                        >
                            {{ l }}
                        </button>
                    </div>
                    <div class="relative w-full sm:w-60">
                        <Search class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="search"
                            class="h-11 w-full rounded-[10px] border border-slate-200 bg-white pl-10 pr-3 text-[15px] outline-none placeholder:text-slate-400 focus:border-blue-400"
                            placeholder="Tìm chứng từ, đối tượng"
                            aria-label="Tìm trong sổ quỹ"
                            data-testid="cashbook-search"
                        />
                    </div>
                    <Select v-model="account" class="w-40" :options="fundOptions" aria-label="Quỹ" data-testid="cashbook-account" />
                    <Select v-model="method" class="w-44" :options="[{ value: '', label: 'Mọi hình thức' }, ...Object.entries(paymentMethods).map(([value, l]) => ({ value, label: value === 'cash' ? 'Tiền mặt (sổ quỹ tiền mặt)' : l }))]" aria-label="Hình thức" data-testid="cashbook-method" />
                    <div class="ml-auto flex gap-2">
                        <button type="button" class="inline-flex h-11 items-center gap-2 rounded-[12px] border border-slate-200 px-3.5 text-[14px] text-slate-600 hover:border-blue-300 hover:text-blue-700" @click="exportCsv"><Download class="size-4" /> CSV</button>
                        <RouterLink to="/cash" class="inline-flex h-11 items-center gap-2 rounded-[12px] border border-blue-200 bg-blue-50 px-4 text-[14px] font-medium text-blue-700 hover:bg-blue-100"><Plus class="size-4" /> Lập phiếu thu chi</RouterLink>
                    </div>
                </div>
                <p v-if="data.truncated" class="border-b border-amber-100 bg-amber-50 px-5 py-2.5 text-[13px] text-amber-800" data-testid="cashbook-truncated">
                    Kỳ này có {{ formatNumber(data.count) }} dòng, chỉ hiện {{ formatNumber(data.rows.length) }} dòng mới nhất. Tồn đầu kỳ, tổng thu chi và tồn cuối kỳ vẫn tính đủ cả kỳ. Chọn khoảng ngày ngắn hơn để xem hết.
                </p>
                <EmptyState v-if="!data.rows.length" :icon="Wallet" title="Không có phiếu thu chi" description="Chưa có khoản thu, hoàn hay chi nào trong kỳ này." />
                <EmptyState v-else-if="!rows.length" :icon="Search" title="Không có dòng phù hợp" description="Thử đổi tab hoặc từ khóa tìm kiếm." />
                <template v-else>
                    <div class="overflow-x-auto" :class="loading && 'opacity-60'">
                        <table class="w-full text-[14px]" data-testid="cashbook-table">
                            <thead class="border-b border-slate-100 text-left text-[12px] font-semibold uppercase tracking-wider text-slate-400">
                                <tr>
                                    <th class="px-5 py-3.5">Thời gian</th>
                                    <th class="px-4 py-3.5">Nội dung</th>
                                    <th class="px-4 py-3.5">Hình thức</th>
                                    <th class="px-4 py-3.5 text-right">Thu</th>
                                    <th class="px-4 py-3.5 text-right">Chi</th>
                                    <th class="px-5 py-3.5 text-right">Tồn quỹ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700">
                                <tr v-for="(e, i) in shown" :key="i" class="hover:bg-slate-50/70">
                                    <td class="whitespace-nowrap px-5 py-3.5 text-slate-500">{{ when(e.paid_at) }}<div v-if="e.created_by" class="text-[12px] text-slate-400">{{ e.created_by }}</div></td>
                                    <td class="min-w-72 px-4 py-3.5">
                                        <div class="flex items-center gap-3">
                                            <span :class="cn('grid size-8 shrink-0 place-items-center rounded-full', e.amount > 0 ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-500')">
                                                <ArrowDownLeft v-if="e.amount > 0" class="size-4" /><ArrowUpRight v-else class="size-4" />
                                            </span>
                                            <div class="min-w-0">
                                                <p class="font-medium text-slate-900">
                                                    {{ label(e) }}<template v-if="e.type.startsWith('transfer')"> {{ e.type === 'transfer_in' ? 'từ' : 'sang' }} {{ e.party }}</template>
                                                </p>
                                                <p class="text-[13px] text-slate-500">
                                                    <RouterLink v-if="e.document" :to="link(e.document)" class="font-mono text-[12px] text-blue-700 hover:underline">{{ e.document.number }}</RouterLink>
                                                    <span v-else>Thanh toán chung</span>
                                                    <template v-if="e.party && !e.type.startsWith('transfer')"> · {{ e.party }}</template>
                                                    <template v-if="e.reference"> · {{ e.reference }}</template>
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3.5"><span class="whitespace-nowrap rounded-md bg-slate-100 px-2 py-0.5 text-[13px] text-slate-600">{{ methodName(e.method) }}</span></td>
                                    <td class="whitespace-nowrap px-4 py-3.5 text-right font-semibold tabular-nums text-emerald-600">{{ e.amount > 0 ? `+${money(e.amount)}` : '' }}</td>
                                    <td class="whitespace-nowrap px-4 py-3.5 text-right font-semibold tabular-nums text-red-600">{{ e.amount < 0 ? `−${money(-e.amount)}` : '' }}</td>
                                    <td class="whitespace-nowrap px-5 py-3.5 text-right tabular-nums text-slate-900">{{ e.balance === null ? '' : money(e.balance) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4 text-[14px] text-slate-500">
                        <span>Hiển thị {{ formatNumber((page - 1) * pageSize + 1) }}–{{ formatNumber(Math.min(page * pageSize, rows.length)) }} trong {{ formatNumber(rows.length) }} khoản</span>
                        <div class="flex items-center gap-1.5">
                            <template v-if="pages > 1">
                                <button type="button" class="grid size-9 place-items-center rounded-[9px] hover:bg-slate-100 disabled:opacity-40" :disabled="page === 1" aria-label="Trang trước" @click="page--"><ChevronLeft class="size-4" /></button>
                                <template v-for="(p, i) in pageList" :key="i">
                                    <span v-if="p === '…'" class="px-1">…</span>
                                    <button v-else type="button" :class="cn('grid h-9 min-w-9 place-items-center rounded-[9px] px-2 tabular-nums', p === page ? 'bg-blue-600 font-semibold text-white' : 'text-slate-600 hover:bg-slate-100')" @click="page = p">{{ p }}</button>
                                </template>
                                <button type="button" class="grid size-9 place-items-center rounded-[9px] hover:bg-slate-100 disabled:opacity-40" :disabled="page === pages" aria-label="Trang sau" @click="page++"><ChevronRight class="size-4" /></button>
                            </template>
                            <select v-model.number="pageSize" class="ml-2 h-10 rounded-[10px] border border-slate-200 bg-white px-3 text-[14px] text-slate-700" aria-label="Số dòng mỗi trang">
                                <option :value="20">20 dòng</option>
                                <option :value="50">50 dòng</option>
                                <option :value="100">100 dòng</option>
                            </select>
                        </div>
                    </div>
                </template>
                <p class="flex items-start gap-2 border-t border-slate-100 px-5 py-3 text-[13px] text-slate-500"><Info class="mt-0.5 size-4 shrink-0 text-slate-400" /> Tiền sàn chuyển về (payout) chưa có ở đây vì cần dữ liệu đối soát từ API sàn. Phiếu đã hủy không được tính.</p>
            </section>
        </template>
        <Dialog v-model:open="glossary" title="Giải thích thuật ngữ" size="lg">
            <dl class="divide-y text-sm">
                <div v-for="[term, meaning] in terms" :key="term" class="grid gap-1 py-2.5 sm:grid-cols-[10rem_1fr]">
                    <dt class="font-medium">{{ term }}</dt>
                    <dd class="text-muted-foreground">{{ meaning }}</dd>
                </div>
            </dl>
        </Dialog>
    </div>
</template>
