<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowRight, ArrowUpRight, BookOpen, Calendar, Check, ChevronLeft, ChevronRight, FileSpreadsheet, FileText, Info, Landmark, Package, TrendingUp, Wallet, type LucideIcon } from 'lucide-vue-next';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import ChannelMark from '../reports/ChannelMark.vue';
import AccountingFrame from './AccountingFrame.vue';
import { BOOKS, BTN_PRIMARY, CARD, dmy, periodLabel, shiftPeriod, short, type BookKey, type BooksSummary } from './accounting';

/**
 * Kế toán, thuế › Sổ sách (mẫu 28/11): bốn sổ của hộ kinh doanh (Thông tư 88/2021) lập từ đơn hàng, kho và phiếu chi,
 * mỗi sổ một thẻ có con số của tháng; sổ đang chọn bên dưới theo mẫu, bên phải tóm tắt tháng.
 * S1 doanh thu theo ngày và kênh (tách doanh thu sàn), S2 nhập xuất tồn hàng hoá, S3 chi phí, S4 thuế phải nộp.
 */
interface BookData {
    book: BookKey;
    code: string;
    title: string;
    label: string;
    taxpayer: { name: string | null; store: string; tax_code: string | null; address: string | null };
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    rows: any[];
    totals: Record<string, number>;
    by_type?: { type: string; amount: number }[];
    truncated?: boolean;
}

const route = useRoute();
const router = useRouter();
const toast = useToast();
const tenant = useTenantStore();
const now = new Date();
const thisMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
const book = ref<BookKey>((BOOKS.find((b) => b.key === route.query.book)?.key ?? 's1') as BookKey);
const month = ref(typeof route.query.month === 'string' && /^\d{4}-\d{2}$/.test(route.query.month) ? route.query.month : thisMonth);
const data = ref<BookData | null>(null);
const summary = ref<BooksSummary | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const exporting = ref(false);
const showAll = ref(false);
const LIMIT = 30;

async function load() {
    loading.value = true;
    loadError.value = null;
    showAll.value = false;
    try {
        data.value = (await api.get(`/accounting/books/${book.value}`, { params: { month: month.value } })).data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
        data.value = null;
    } finally {
        loading.value = false;
    }
}
async function loadSummary() {
    summary.value = (await api.get('/accounting/books-summary', { params: { month: month.value } }).catch(() => null))?.data?.data ?? null;
}
watch(book, () => {
    router.replace({ query: { book: book.value, month: month.value } });
    load();
});
watch(month, () => {
    router.replace({ query: { book: book.value, month: month.value } });
    load();
    loadSummary();
}, { immediate: true });

async function exportBook() {
    exporting.value = true;
    try {
        await downloadFile(`/accounting/books/${book.value}?month=${month.value}&export=1`, `so-${book.value}-${month.value}.xlsx`);
    } catch (e) {
        toast.error((e as ApiError).message || 'Chưa xuất được sổ.');
    } finally {
        exporting.value = false;
    }
}

const canStock = computed(() => tenant.can('inventory.view'));
const cards = computed(() => {
    const s = summary.value;
    const out: { key: BookKey; code: string; title: string; icon: LucideIcon; tone: string; value: string; sub: string }[] = [
        { key: 's1', code: 'S1-HKD', title: 'Sổ doanh thu', icon: TrendingUp, tone: 'bg-blue-50 text-blue-600', value: s ? formatMoney(s.s1.total) : '…', sub: s ? `Tự khai ${short(s.s1.self)} · sàn ${short(s.s1.market)}` : '' },
        { key: 's2', code: 'S2-HKD', title: 'Sổ vật liệu, hàng hoá', icon: Package, tone: 'bg-violet-50 text-violet-600', value: s?.s2 ? `${formatNumber(s.s2.items)}${s.s2.truncated ? '+' : ''} mã hàng` : '…', sub: s?.s2 ? `Giá trị tồn cuối ${formatMoney(s.s2.closing_value)}` : '' },
        { key: 's3', code: 'S3-HKD', title: 'Sổ chi phí', icon: Wallet, tone: 'bg-amber-50 text-amber-600', value: s ? formatMoney(s.s3.amount) : '…', sub: s ? `${s.s3.vouchers} phiếu chi tính vào chi phí` : '' },
        { key: 's4', code: 'S4-HKD', title: 'Sổ thuế phải nộp', icon: Landmark, tone: 'bg-emerald-50 text-emerald-600', value: s ? formatMoney(s.s4.tax) : '…', sub: s ? `Cộng năm ${s.s4.year} · ${s.s4.periods} kỳ` : '' },
    ];
    return out.filter((c) => c.key !== 's2' || canStock.value);
});

const who = computed(() => {
    const t = data.value?.taxpayer;
    return t ? [t.name, t.store, t.tax_code ? `MST ${t.tax_code}` : null].filter(Boolean).join(' · ') : '';
});
const periodText = computed(() => (book.value === 's4' ? `Năm ${month.value.slice(0, 4)}` : periodLabel(month.value)));
const rows = computed(() => (showAll.value ? data.value?.rows ?? [] : (data.value?.rows ?? []).slice(0, LIMIT)));
const hidden = computed(() => Math.max(0, (data.value?.rows.length ?? 0) - rows.value.length));
const canNext = computed(() => month.value < thisMonth);

/** S1: revenue per channel for the bar on the right. */
const byChannel = computed(() => {
    if (data.value?.book !== 's1') return [];
    const m = new Map<string, { label: string; mark: string; amount: number }>();
    for (const r of data.value.rows) {
        if (r.self + r.market <= 0) continue;
        const label = String(r.label);
        const cur = m.get(label) ?? { label, mark: r.mark, amount: 0 };
        cur.amount += r.self + r.market;
        m.set(label, cur);
    }
    const list = [...m.values()].sort((a, b) => b.amount - a.amount);
    const total = list.reduce((t, x) => t + x.amount, 0) || 1;
    return list.slice(0, 6).map((x, i) => ({ ...x, pct: (x.amount / total) * 100, color: ['bg-blue-500', 'bg-orange-500', 'bg-slate-800', 'bg-violet-500', 'bg-emerald-500', 'bg-amber-400'][i]! }));
});
const pct = (n: number) => `${n.toLocaleString('vi-VN', { maximumFractionDigits: 0 })}%`;
const th = 'whitespace-nowrap px-4 py-3 text-[12px] font-semibold uppercase tracking-wide text-slate-500 first:pl-5 last:pr-5 sm:first:pl-6 sm:last:pr-6';
const td = 'px-4 py-3 first:pl-5 last:pr-5 sm:first:pl-6 sm:last:pr-6';
</script>

<template>
    <AccountingFrame page="Sổ sách" title="Sổ sách kế toán" description="Bốn sổ của hộ kinh doanh theo Thông tư 88/2021, tự lập từ đơn hàng, kho và phiếu chi. Không phải nhập lại.">
        <div class="flex h-11 items-center rounded-[12px] border border-slate-200 bg-white text-[15px]">
            <button type="button" class="grid size-11 place-items-center text-slate-500 hover:text-slate-900" aria-label="Tháng trước" @click="month = shiftPeriod(month, -1)"><ChevronLeft class="size-4" /></button>
            <span class="flex min-w-36 items-center justify-center gap-2 px-1 font-medium" data-testid="book-month"><Calendar class="size-4 text-slate-400" />{{ periodLabel(month) }}</span>
            <button type="button" class="grid size-11 place-items-center text-slate-500 hover:text-slate-900 disabled:text-slate-300" aria-label="Tháng sau" :disabled="!canNext" @click="month = shiftPeriod(month, 1)"><ChevronRight class="size-4" /></button>
        </div>
        <button type="button" :class="BTN_PRIMARY" :disabled="exporting" data-testid="book-export" @click="exportBook"><FileSpreadsheet class="size-4" /> {{ exporting ? 'Đang xuất…' : 'Xuất Excel' }}</button>
    </AccountingFrame>

    <div class="grid gap-4 sm:grid-cols-2" :class="cards.length === 4 ? 'xl:grid-cols-4' : 'xl:grid-cols-3'" role="tablist" aria-label="Sổ">
        <button
            v-for="c in cards"
            :key="c.key"
            type="button"
            role="tab"
            :aria-selected="book === c.key"
            :class="cn('rounded-[14px] border bg-white p-5 text-left shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition', book === c.key ? 'border-blue-500 ring-4 ring-blue-500/10' : 'border-slate-200/80 hover:border-slate-300')"
            :data-testid="`book-card-${c.key}`"
            @click="book = c.key"
        >
            <span class="flex items-center gap-3">
                <span :class="cn('grid size-10 shrink-0 place-items-center rounded-[11px]', c.tone)"><component :is="c.icon" class="size-5" :stroke-width="1.9" /></span>
                <span class="min-w-0 flex-1">
                    <span class="block text-[12px] font-semibold text-slate-400">Mẫu {{ c.code }}</span>
                    <span class="block truncate text-[15px] font-semibold text-slate-900">{{ c.title }}</span>
                </span>
                <span v-if="book === c.key" class="grid size-6 shrink-0 place-items-center rounded-full bg-blue-600 text-white"><Check class="size-3.5" /></span>
            </span>
            <span class="mt-4 block whitespace-nowrap text-[22px] font-bold tabular-nums text-slate-900">{{ c.value }}</span>
            <span class="block truncate text-[13px] text-slate-500">{{ c.sub }}&nbsp;</span>
        </button>
    </div>

    <div class="mt-6 grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_23rem] 2xl:grid-cols-[minmax(0,1fr)_25rem]">
        <section :class="cn(CARD, 'min-w-0 overflow-hidden')">
            <div class="border-b border-slate-100 px-5 py-5 text-center">
                <p class="text-[12px] font-semibold tracking-wider text-slate-400">MẪU {{ BOOKS.find((b) => b.key === book)?.code }} · THÔNG TƯ 88/2021</p>
                <h2 class="mt-1 text-[18px] font-bold uppercase tracking-tight text-slate-900">{{ BOOKS.find((b) => b.key === book)?.title }}</h2>
                <p class="mt-1 text-[13px] text-slate-500">{{ who }}{{ who ? ' · ' : '' }}{{ periodText }}</p>
            </div>
            <div v-if="loading && !data" class="space-y-3 p-5"><Skeleton v-for="i in 6" :key="i" class="h-9" /></div>
            <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
            <div v-else-if="data" class="overflow-x-auto" :class="loading && 'opacity-60'">
                <!-- S1: doanh thu -->
                <table v-if="data.book === 's1'" class="w-full min-w-[720px] text-[14px]" data-testid="book-table">
                    <thead class="bg-slate-50/80 text-left"><tr><th :class="th">Ngày</th><th :class="th">Diễn giải</th><th :class="th">Chứng từ</th><th :class="cn(th, 'text-right')">Doanh thu tự khai</th><th :class="cn(th, 'text-right')">Doanh thu sàn</th><th :class="cn(th, 'text-right')">Thuế sàn đã khấu trừ</th></tr></thead>
                    <tbody>
                        <tr v-for="(r, i) in rows" :key="i" class="border-t border-slate-100">
                            <td :class="cn(td, 'whitespace-nowrap tabular-nums text-slate-500')">{{ dmy(r.day).slice(0, 5) }}</td>
                            <td :class="td"><span class="flex items-center gap-3"><ChannelMark :kind="r.mark" :name="r.label" size="sm" /><span>{{ r.label }} <span class="whitespace-nowrap text-slate-400">· {{ r.count }} đơn</span></span></span></td>
                            <td :class="cn(td, 'max-w-56 truncate font-mono text-[12px] text-slate-500')" :title="r.orders.join(', ')">{{ r.orders.slice(0, 3).join(', ') }}{{ r.count > 3 ? '…' : '' }}</td>
                            <td :class="cn(td, 'whitespace-nowrap text-right tabular-nums', r.self < 0 && 'text-rose-600')">{{ r.self ? formatMoney(r.self) : '' }}</td>
                            <td :class="cn(td, 'whitespace-nowrap text-right tabular-nums', r.market < 0 && 'text-rose-600')">{{ r.market ? formatMoney(r.market) : '' }}</td>
                            <td :class="cn(td, 'whitespace-nowrap text-right tabular-nums text-slate-400')">{{ r.withheld ? formatMoney(r.withheld) : '' }}</td>
                        </tr>
                        <tr v-if="!data.rows.length"><td colspan="6" class="px-6 py-10 text-center text-slate-500">Chưa có doanh thu trong tháng này.</td></tr>
                    </tbody>
                    <tfoot class="border-t border-slate-200 bg-blue-50/50"><tr><td :class="cn(td, 'font-semibold')" colspan="3">Cộng {{ periodText.toLowerCase() }}</td><td :class="cn(td, 'whitespace-nowrap text-right text-[15px] font-bold tabular-nums text-blue-700')">{{ formatMoney(data.totals.self ?? 0) }}</td><td :class="cn(td, 'whitespace-nowrap text-right font-semibold tabular-nums')">{{ formatMoney(data.totals.market ?? 0) }}</td><td :class="cn(td, 'whitespace-nowrap text-right font-semibold tabular-nums text-slate-500')">{{ formatMoney(data.totals.withheld ?? 0) }}</td></tr></tfoot>
                </table>

                <!-- S2: nhập xuất tồn -->
                <table v-else-if="data.book === 's2'" class="w-full min-w-[720px] text-[14px]" data-testid="book-table">
                    <thead class="bg-slate-50/80 text-left"><tr><th :class="th">Mã hàng</th><th :class="th">Tên hàng</th><th :class="cn(th, 'text-right')">Tồn đầu</th><th :class="cn(th, 'text-right')">Nhập</th><th :class="cn(th, 'text-right')">Xuất</th><th :class="cn(th, 'text-right')">Tồn cuối</th><th :class="cn(th, 'text-right')">Giá trị tồn cuối</th></tr></thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.sku" class="border-t border-slate-100">
                            <td :class="cn(td, 'whitespace-nowrap font-mono text-[12px]')">{{ r.sku }}</td><td :class="td">{{ r.name }}</td>
                            <td :class="cn(td, 'text-right tabular-nums')">{{ formatNumber(r.opening) }}</td><td :class="cn(td, 'text-right tabular-nums text-emerald-700')">{{ r.in ? formatNumber(r.in) : '' }}</td>
                            <td :class="cn(td, 'text-right tabular-nums text-rose-600')">{{ r.out ? formatNumber(r.out) : '' }}</td><td :class="cn(td, 'text-right font-medium tabular-nums')">{{ formatNumber(r.closing) }}</td>
                            <td :class="cn(td, 'whitespace-nowrap text-right tabular-nums')">{{ formatMoney(r.closing_value) }}</td>
                        </tr>
                        <tr v-if="!data.rows.length"><td colspan="7" class="px-6 py-10 text-center text-slate-500">Chưa có hàng hoá phát sinh trong tháng này.</td></tr>
                    </tbody>
                    <tfoot class="border-t border-slate-200 bg-blue-50/50"><tr><td :class="cn(td, 'font-semibold')" colspan="2">Cộng</td><td :class="cn(td, 'text-right font-semibold tabular-nums')">{{ formatNumber(data.totals.opening ?? 0) }}</td><td :class="cn(td, 'text-right font-semibold tabular-nums')">{{ formatNumber(data.totals.in ?? 0) }}</td><td :class="cn(td, 'text-right font-semibold tabular-nums')">{{ formatNumber(data.totals.out ?? 0) }}</td><td :class="cn(td, 'text-right font-semibold tabular-nums')">{{ formatNumber(data.totals.closing ?? 0) }}</td><td :class="cn(td, 'whitespace-nowrap text-right font-bold tabular-nums text-blue-700')">{{ formatMoney(data.totals.closing_value ?? 0) }}</td></tr></tfoot>
                </table>

                <!-- S3: chi phí -->
                <table v-else-if="data.book === 's3'" class="w-full min-w-[720px] text-[14px]" data-testid="book-table">
                    <thead class="bg-slate-50/80 text-left"><tr><th :class="th">Ngày</th><th :class="th">Số phiếu</th><th :class="th">Diễn giải</th><th :class="cn(th, 'text-right')">Số tiền</th></tr></thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.id" class="border-t border-slate-100">
                            <td :class="cn(td, 'whitespace-nowrap tabular-nums text-slate-500')">{{ dmy(r.day).slice(0, 5) }}</td><td :class="cn(td, 'whitespace-nowrap font-mono text-[12px]')">{{ r.number }}</td>
                            <td :class="td">{{ r.label }}</td><td :class="cn(td, 'whitespace-nowrap text-right tabular-nums')">{{ formatMoney(r.amount) }}</td>
                        </tr>
                        <tr v-if="!data.rows.length"><td colspan="4" class="px-6 py-10 text-center text-slate-500">Chưa có phiếu chi tính vào chi phí trong tháng này.</td></tr>
                    </tbody>
                    <tfoot class="border-t border-slate-200 bg-blue-50/50"><tr><td :class="cn(td, 'font-semibold')" colspan="3">Cộng {{ periodText.toLowerCase() }}</td><td :class="cn(td, 'whitespace-nowrap text-right text-[15px] font-bold tabular-nums text-blue-700')">{{ formatMoney(data.totals.amount ?? 0) }}</td></tr></tfoot>
                </table>

                <!-- S4: thuế -->
                <table v-else class="w-full min-w-[720px] text-[14px]" data-testid="book-table">
                    <thead class="bg-slate-50/80 text-left"><tr><th :class="th">Kỳ</th><th :class="cn(th, 'text-right')">Doanh thu tự khai</th><th :class="cn(th, 'text-right')">Thuế GTGT</th><th :class="cn(th, 'text-right')">Thuế TNCN</th><th :class="cn(th, 'text-right')">Phải nộp</th><th :class="th">Hạn nộp</th></tr></thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.period" class="border-t border-slate-100">
                            <td :class="td"><RouterLink :to="{ path: '/accounting/declaration', query: { period: r.period } }" class="font-medium text-blue-600 hover:underline">{{ periodLabel(r.period) }}</RouterLink></td>
                            <td :class="cn(td, 'whitespace-nowrap text-right tabular-nums')">{{ formatMoney(r.self) }}</td><td :class="cn(td, 'whitespace-nowrap text-right tabular-nums')">{{ formatMoney(r.vat) }}</td>
                            <td :class="cn(td, 'whitespace-nowrap text-right tabular-nums')">{{ formatMoney(r.pit) }}</td><td :class="cn(td, 'whitespace-nowrap text-right font-semibold tabular-nums')">{{ formatMoney(r.tax) }}</td>
                            <td :class="cn(td, 'whitespace-nowrap text-slate-500')">{{ dmy(r.deadline) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t border-slate-200 bg-blue-50/50"><tr><td :class="cn(td, 'font-semibold')">Cộng năm</td><td :class="cn(td, 'whitespace-nowrap text-right font-semibold tabular-nums')">{{ formatMoney(data.totals.self ?? 0) }}</td><td :class="cn(td, 'whitespace-nowrap text-right font-semibold tabular-nums')">{{ formatMoney(data.totals.vat ?? 0) }}</td><td :class="cn(td, 'whitespace-nowrap text-right font-semibold tabular-nums')">{{ formatMoney(data.totals.pit ?? 0) }}</td><td :class="cn(td, 'whitespace-nowrap text-right text-[15px] font-bold tabular-nums text-blue-700')">{{ formatMoney(data.totals.tax ?? 0) }}</td><td /></tr></tfoot>
                </table>
                <div v-if="hidden || data.truncated" class="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-[13px] text-slate-500 sm:px-6">
                    <span>{{ data.truncated ? 'Bảng chỉ hiện một phần, tổng cộng tính đủ. Xuất Excel để xem hết.' : `Hiện ${rows.length} / ${data.rows.length} dòng · tổng cộng tính đủ` }}</span>
                    <button v-if="hidden" type="button" class="font-medium text-blue-600 hover:underline" @click="showAll = true">Xem hết</button>
                </div>
            </div>
        </section>

        <div class="space-y-6">
            <section v-if="summary" :class="cn(CARD, 'p-5')" data-testid="book-summary">
                <h2 class="text-[16px] font-semibold text-slate-900">Tóm tắt {{ periodLabel(month).toLowerCase() }}</h2>
                <dl class="mt-2 divide-y divide-slate-100 text-[14px]">
                    <div class="flex justify-between py-2.5"><dt class="text-slate-500">Doanh thu tự khai</dt><dd class="tabular-nums">{{ formatMoney(summary.s1.self) }}</dd></div>
                    <div class="flex justify-between py-2.5"><dt class="text-slate-500">Doanh thu sàn</dt><dd class="tabular-nums">{{ formatMoney(summary.s1.market) }}</dd></div>
                    <div class="flex justify-between py-2.5"><dt class="text-slate-500">Sàn đã khấu trừ</dt><dd class="tabular-nums text-slate-500">{{ formatMoney(summary.s1.withheld) }}</dd></div>
                    <div class="flex justify-between py-2.5"><dt class="text-slate-500">Chi phí</dt><dd class="tabular-nums">{{ formatMoney(summary.s3.amount) }}</dd></div>
                    <div v-if="summary.s2" class="flex justify-between py-2.5"><dt class="text-slate-500">Giá trị hàng tồn cuối</dt><dd class="tabular-nums">{{ formatMoney(summary.s2.closing_value) }}</dd></div>
                </dl>
                <div v-if="byChannel.length" class="mt-3 rounded-[12px] bg-slate-50 p-4">
                    <p class="text-[12px] text-slate-500">Doanh thu theo kênh</p>
                    <div class="mt-2.5 flex h-2.5 overflow-hidden rounded-full bg-slate-200"><span v-for="c in byChannel" :key="c.label" :class="c.color" :style="{ width: `${c.pct}%` }" /></div>
                    <div class="mt-3 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[12px] text-slate-600">
                        <span v-for="c in byChannel" :key="c.label" class="flex min-w-0 items-center gap-1.5"><span :class="cn('size-2 shrink-0 rounded-full', c.color)" /><span class="truncate">{{ c.label }}</span><span class="tabular-nums text-slate-400">{{ pct(c.pct) }}</span></span>
                    </div>
                </div>
                <div v-if="data?.book === 's3' && data.by_type?.length" class="mt-3 rounded-[12px] bg-slate-50 p-4">
                    <p class="text-[12px] text-slate-500">Chi phí theo loại</p>
                    <div v-for="t in data.by_type.slice(0, 6)" :key="t.type" class="mt-2 flex justify-between text-[13px]"><span class="truncate text-slate-600">{{ t.type }}</span><span class="tabular-nums">{{ formatMoney(t.amount) }}</span></div>
                </div>
            </section>

            <section :class="cn(CARD, 'p-5')">
                <h2 class="text-[16px] font-semibold text-slate-900">Sổ khác</h2>
                <div class="mt-3 space-y-2.5">
                    <RouterLink to="/reports/cashbook" class="flex items-center gap-3 rounded-[12px] border border-slate-200 p-3.5 hover:border-blue-300">
                        <span class="grid size-9 place-items-center rounded-[10px] bg-blue-50 text-blue-600"><BookOpen class="size-[18px]" /></span>
                        <span class="flex-1"><span class="block text-[14px] font-medium text-slate-900">Sổ quỹ tiền mặt, tiền gửi</span><span class="block text-[12px] text-slate-500">Mở ở Báo cáo › Tài chính</span></span>
                        <ArrowUpRight class="size-4 text-slate-400" />
                    </RouterLink>
                    <RouterLink to="/accounting/declaration" class="flex items-center gap-3 rounded-[12px] border border-slate-200 p-3.5 hover:border-blue-300">
                        <span class="grid size-9 place-items-center rounded-[10px] bg-emerald-50 text-emerald-600"><FileText class="size-[18px]" /></span>
                        <span class="flex-1"><span class="block text-[14px] font-medium text-slate-900">Tờ khai thuế</span><span class="block text-[12px] text-slate-500">Lấy số từ sổ S1</span></span>
                        <ArrowRight class="size-4 text-slate-400" />
                    </RouterLink>
                </div>
            </section>

            <div class="flex gap-2.5 rounded-[12px] bg-slate-100/80 p-4 text-[13px] leading-relaxed text-slate-500">
                <Info class="mt-0.5 size-4 shrink-0" />
                <p>Sổ tự lập từ đơn hàng, kho và phiếu chi. Doanh thu sàn ghi riêng vì sàn đã khấu trừ thuế. In sổ: bấm Xuất Excel rồi in từ Excel.</p>
            </div>
        </div>
    </div>
</template>
