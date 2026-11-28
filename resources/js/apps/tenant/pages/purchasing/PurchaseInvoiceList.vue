<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { Ban, Calendar, Check, CircleCheck, Eye, FileCheck, FileCode, FileSpreadsheet, FileUp, Landmark, Mail, PackagePlus, Receipt, Search, ShieldCheck, TriangleAlert, Undo2 } from 'lucide-vue-next';
import Pagination from '../../components/Pagination.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import ReportKpi from '../reports/ReportKpi.vue';
import ReportChip from '../reports/ReportChip.vue';
import AccountingFrame from '../accounting/AccountingFrame.vue';
import { BTN, BTN_PRIMARY, CARD, openInvoices } from '../accounting/accounting';
import { useToast } from '@/shared/composables/useToast';
import { ISSUE_LABELS, monthOptions, type PurchaseInvoiceRow } from './invoices';

/**
 * Kế toán, thuế (và Nhập hàng) › Hoá đơn mua vào (mẫu 28/11): e-invoices suppliers issued to the shop. XML files (or a .zip) from the tax portal
 * or the supplier's email are uploaded, checked (issued to this shop, has the tax authority's code, adds up), then
 * turned into a purchase order with "Tạo phiếu nhập" or set aside with "Không đưa vào".
 */
type Tab = 'all' | 'open' | 'review' | 'linked' | 'ignored';
const router = useRouter();
const toast = useToast();

const rows = ref<PurchaseInvoiceRow[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0, counts: { all: 0, open: 0, review: 0, linked: 0, ignored: 0 }, summary: { invoices: 0, total: '0', vat: '0', deductible: 0, suppliers: 0, open_total: '0' } });
const loading = ref(true);
const loadError = ref<string | null>(null);
const page = ref(1);
const tab = ref<Tab>('all');
const months = monthOptions(new Date(), 12);
const month = ref(months[0]!.value);
const q = ref('');
const uploading = ref(false);
const exporting = ref(false);

const TABS: { key: Tab; label: string }[] = [
    { key: 'all', label: 'Tất cả' },
    { key: 'open', label: 'Chưa vào phiếu nhập' },
    { key: 'review', label: 'Cần xem lại' },
    { key: 'linked', label: 'Đã vào phiếu nhập' },
    { key: 'ignored', label: 'Không đưa vào' },
];

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get('/purchase-invoices', { params: { tab: tab.value, month: month.value || undefined, q: q.value || undefined, page: page.value } });
        rows.value = data.data;
        meta.value = data.meta;
        if (!q.value && !month.value) openInvoices.value = data.meta.counts.open;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

function go(t: Tab) {
    tab.value = t;
    page.value = 1;
    load();
}

let searchTimer: ReturnType<typeof setTimeout> | undefined;
function onSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => ((page.value = 1), load()), 300);
}

async function upload(e: Event) {
    const input = e.target as HTMLInputElement;
    if (!input.files?.length) return;
    const body = new FormData();
    [...input.files].slice(0, 50).forEach((f) => body.append('files[]', f));
    uploading.value = true;
    try {
        const { data } = await api.post('/purchase-invoices/import', body, { timeout: 120000 });
        const r = data.data as { added: number; duplicates: number; failed: string[] };
        const parts = [`Đã thêm ${r.added} hoá đơn`];
        if (r.duplicates) parts.push(`${r.duplicates} hoá đơn đã có từ trước`);
        if (r.failed.length) parts.push(`${r.failed.length} file không phải hoá đơn điện tử (${r.failed.slice(0, 3).join(', ')}${r.failed.length > 3 ? '…' : ''})`);
        (r.added ? toast.success : toast.info)(parts.join(', ') + '.');
        tab.value = r.added ? 'all' : tab.value;
        month.value = '';
        page.value = 1;
        await load();
    } catch (err) {
        const x = err as ApiError;
        toast.error(x.field('files.0') ?? x.message ?? 'Chưa tải được hoá đơn.');
    } finally {
        uploading.value = false;
        input.value = '';
    }
}

async function ignore(r: PurchaseInvoiceRow) {
    try {
        await api.post(`/purchase-invoices/${r.id}/ignore`, {});
        toast.success(`Đã để hoá đơn ${r.number} ra ngoài phiếu nhập.`);
        load();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}

async function restore(r: PurchaseInvoiceRow) {
    await api.post(`/purchase-invoices/${r.id}/restore`, {});
    load();
}

async function exportList() {
    exporting.value = true;
    try {
        await downloadFile(`/purchase-invoices/export${month.value ? `?month=${month.value}` : ''}`, 'hoa-don-mua-vao.xlsx');
    } catch (e) {
        toast.error((e as ApiError).message || 'Chưa xuất được bảng kê.');
    } finally {
        exporting.value = false;
    }
}

const monthLabel = computed(() => months.find((m) => m.value === month.value)?.label ?? 'Tất cả');
const date = (d: string | null) => (d ? d.split('-').reverse().slice(0, 2).join('/') : '—');
/** Two letters of the supplier's name for its badge, e.g. "Công ty TNHH Phong Vũ" → "PV". */
function initials(name: string | null): string {
    const words = (name ?? '').replace(/^(công ty|cty|cửa hàng|chi nhánh)\s+(tnhh|cp|cổ phần)?\s*(mtv|một thành viên)?\s*/i, '').split(/\s+/).filter(Boolean);
    return ((words[0]?.[0] ?? '?') + (words[1]?.[0] ?? '')).toUpperCase();
}
const TONES = ['bg-blue-50 text-blue-600', 'bg-violet-50 text-violet-600', 'bg-amber-50 text-amber-600', 'bg-emerald-50 text-emerald-600', 'bg-pink-50 text-pink-600', 'bg-sky-50 text-sky-600'];
const tone = (key: string | null) => TONES[[...(key ?? '')].reduce((t, c) => t + c.charCodeAt(0), 0) % TONES.length]!;
const th = 'whitespace-nowrap px-4 py-3 text-[12px] font-semibold uppercase tracking-wide text-slate-500 first:pl-5 last:pr-5 sm:first:pl-6 sm:last:pr-6';
const td = 'px-4 py-3 first:pl-5 last:pr-5 sm:first:pl-6 sm:last:pr-6';
</script>

<template>
    <AccountingFrame page="Hoá đơn mua vào" title="Hoá đơn mua vào" description="Hoá đơn điện tử nhà cung cấp xuất cho cửa hàng: kiểm tra, đưa vào phiếu nhập, xuất bảng kê thuế đầu vào.">
        <button type="button" :class="BTN" :disabled="exporting" @click="exportList"><FileSpreadsheet class="size-4" /> {{ exporting ? 'Đang xuất…' : 'Xuất bảng kê' }}</button>
        <label class="inline-flex cursor-pointer" data-testid="invoice-upload">
            <span :class="cn(BTN_PRIMARY, uploading && 'pointer-events-none opacity-60')"><FileUp class="size-4" />{{ uploading ? 'Đang đọc…' : 'Tải hoá đơn XML' }}</span>
            <input type="file" accept=".xml,.zip,text/xml,application/zip" multiple class="sr-only" data-testid="invoice-input" @change="upload" />
        </label>
    </AccountingFrame>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <ReportKpi primary :label="`Hoá đơn · ${monthLabel}`" tip="Hoá đơn đã tải lên, trừ hoá đơn để ra ngoài (Không đưa vào)." :icon="Receipt" :value="meta.summary.invoices" unit="hoá đơn">
            <template v-if="meta.summary.suppliers" #chips><ReportChip tone="onblue">{{ formatNumber(meta.summary.suppliers) }} nhà cung cấp</ReportChip></template>
            Tổng tiền {{ formatMoney(Number(meta.summary.total)) }}
        </ReportKpi>
        <ReportKpi label="Thuế GTGT đầu vào hợp lệ" tip="Thuế GTGT của hoá đơn GTGT hợp lệ: đúng người mua, có mã của cơ quan thuế, cộng đúng." :icon="FileCheck" tone="green" :value="Number(meta.summary.vat)">
            <template #chips><ReportChip tone="up"><Check class="size-3" />{{ formatNumber(meta.summary.deductible) }} hợp lệ</ReportChip></template>
            Hoá đơn từ 5 triệu cần trả bằng chuyển khoản
        </ReportKpi>
        <button type="button" class="text-left" @click="go('open')">
            <ReportKpi label="Chưa vào phiếu nhập" :icon="PackagePlus" tone="blue" plain :value="meta.counts.open" unit="hoá đơn" :class="tab === 'open' && 'ring-2 ring-blue-500/40'">
                <template v-if="Number(meta.summary.open_total)" #chips><ReportChip>{{ formatMoney(Number(meta.summary.open_total)) }}</ReportChip></template>
                Bấm Tạo phiếu nhập để nhập kho
            </ReportKpi>
        </button>
        <button type="button" class="text-left" @click="go('review')">
            <ReportKpi label="Cần xem lại" :icon="TriangleAlert" :tone="meta.counts.review ? 'red' : 'slate'" :value="meta.counts.review" unit="hoá đơn" :class="tab === 'review' && 'ring-2 ring-red-500/30'">
                <template v-if="meta.counts.review" #chips><ReportChip tone="red">Chưa khấu trừ được</ReportChip></template>
                Sai người mua, thiếu mã CQT hoặc cộng sai
            </ReportKpi>
        </button>
    </div>

    <section :class="cn(CARD, 'mt-6 overflow-hidden')">
        <div class="flex flex-wrap items-center gap-x-6 border-b border-slate-100 px-5 sm:px-6">
            <div class="flex min-w-0 max-w-full gap-5 overflow-x-auto 2xl:flex-1">
                <button
                    v-for="t in TABS" :key="t.key" type="button"
                    :class="cn('-mb-px flex shrink-0 items-center gap-2 border-b-2 px-1 py-4 text-[15px]', tab === t.key ? 'border-blue-600 font-semibold text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-900')"
                    @click="go(t.key)"
                >
                    {{ t.label }} <span :class="cn('rounded-full px-2 text-[12px] font-semibold tabular-nums', tab === t.key ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-500')">{{ meta.counts[t.key] }}</span>
                </button>
            </div>
            <div class="flex w-full gap-3 py-3 sm:ml-auto sm:w-auto">
                <div class="relative flex-1 sm:w-72">
                    <Search class="pointer-events-none absolute left-3 top-3 size-4 text-slate-400" />
                    <input v-model="q" type="search" placeholder="NCC, MST, số hoá đơn" class="h-10 w-full rounded-[10px] border border-slate-200 bg-white pl-9 pr-3 text-[14px] outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100" @input="onSearch" />
                </div>
                <div class="relative">
                    <Calendar class="pointer-events-none absolute left-3 top-3 size-4 text-slate-400" />
                    <select v-model="month" class="h-10 appearance-none rounded-[10px] border border-slate-200 bg-white pl-9 pr-8 text-[14px] outline-none focus:border-blue-400" aria-label="Tháng" @change="(page = 1), load()">
                        <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
                        <option value="">Tất cả tháng</option>
                    </select>
                </div>
            </div>
        </div>

        <div v-if="loading && !rows.length" class="space-y-3 p-5"><Skeleton v-for="i in 5" :key="i" class="h-10" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState
            v-else-if="!rows.length"
            :icon="FileCode"
            :title="meta.counts.all || tab !== 'all' ? 'Không có hoá đơn nào ở đây' : 'Chưa có hoá đơn mua vào'"
            description="Tải file XML hoá đơn (hoặc file .zip nhiều hoá đơn) tải từ hoadondientu.gdt.gov.vn hoặc email nhà cung cấp gửi."
        />
        <div v-else class="overflow-x-auto" :class="loading && 'opacity-60'">
            <table class="w-full text-[14px]" data-testid="invoice-table">
                <thead class="hidden bg-slate-50/80 text-left md:table-header-group">
                    <tr>
                        <th :class="th">Nhà cung cấp</th><th :class="th">Ký hiệu · số</th><th :class="th">Ngày</th>
                        <th :class="cn(th, 'text-right')">Tổng tiền</th><th :class="cn(th, 'text-right')">Thuế GTGT</th>
                        <th :class="th">Kiểm tra</th><th :class="cn(th, 'text-right')">Phiếu nhập</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.id" :class="cn('border-t border-slate-100 max-md:grid max-md:grid-cols-2 max-md:gap-x-3 max-md:py-2', r.blocked && r.status !== 'ignored' ? 'bg-rose-50/40' : r.status === 'ignored' ? 'opacity-60' : '')">
                        <td :class="cn(td, 'max-md:col-span-2 max-md:pb-1')">
                            <span class="flex items-center gap-3">
                                <span :class="cn('grid size-9 shrink-0 place-items-center rounded-[10px] text-[13px] font-semibold', tone(r.seller.tax_code))">{{ initials(r.seller.name) }}</span>
                                <span class="min-w-0">
                                    <span class="block font-medium text-slate-900">{{ r.seller.name || '—' }}</span>
                                    <span class="block text-[12px] text-slate-500">MST {{ r.seller.tax_code || '—' }}<template v-if="r.kind === 'sale'"> · Hoá đơn bán hàng</template></span>
                                </span>
                            </span>
                        </td>
                        <td :class="cn(td, 'whitespace-nowrap font-mono text-[13px] max-md:py-1')">{{ r.series }} · {{ r.number }}</td>
                        <td :class="cn(td, 'tabular-nums text-slate-500 max-md:py-1 max-md:text-right')">{{ date(r.issued_on) }}</td>
                        <td :class="cn(td, 'whitespace-nowrap text-right font-medium tabular-nums max-md:py-1 max-md:text-left')">{{ formatMoney(Number(r.total)) }}</td>
                        <td :class="cn(td, 'whitespace-nowrap text-right tabular-nums text-slate-500 max-md:py-1')"><span class="md:hidden">VAT </span>{{ formatMoney(Number(r.vat_amount)) }}</td>
                        <td :class="cn(td, 'max-md:py-1')">
                            <ReportChip v-if="!r.issues.length" tone="up">Hợp lệ</ReportChip>
                            <span v-else class="flex flex-wrap gap-1.5">
                                <ReportChip v-for="i in r.issues" :key="i" :tone="ISSUE_LABELS[i]?.blocking ? 'red' : 'amber'">{{ ISSUE_LABELS[i]?.label ?? i }}</ReportChip>
                            </span>
                        </td>
                        <td :class="cn(td, 'max-md:py-1')">
                            <span v-if="r.purchase_order" class="flex items-center justify-end gap-1.5 max-md:justify-end">
                                <CircleCheck class="size-4 text-emerald-500" />
                                <RouterLink :to="`/purchasing/${r.purchase_order.id}`" class="font-mono text-[13px] font-medium text-blue-600 hover:underline">{{ r.purchase_order.number }}</RouterLink>
                            </span>
                            <span v-else-if="r.status === 'ignored'" class="flex items-center justify-end gap-2 text-[13px] text-slate-400">
                                Không đưa vào
                                <button type="button" class="inline-flex items-center gap-1 font-medium text-blue-600" @click="restore(r)"><Undo2 class="size-3.5" />Đưa lại</button>
                            </span>
                            <span v-else class="flex items-center justify-end gap-1.5">
                                <button
                                    type="button"
                                    :class="cn('inline-flex h-9 items-center gap-1.5 whitespace-nowrap rounded-[10px] px-3 text-[13px] font-medium', r.blocked ? 'border border-slate-200 text-slate-700 hover:bg-slate-50' : 'bg-blue-50 text-blue-700 hover:bg-blue-100')"
                                    data-testid="invoice-make-po"
                                    @click="router.push({ path: '/purchasing/scan', query: { invoice: r.id } })"
                                >
                                    <component :is="r.blocked ? Eye : PackagePlus" class="size-4" /> {{ r.blocked ? 'Xem lại' : 'Tạo phiếu nhập' }}
                                </button>
                                <button type="button" class="grid size-9 place-items-center rounded-[10px] text-slate-400 hover:bg-slate-100 hover:text-slate-600" title="Không đưa vào phiếu nhập (chi phí, hoá đơn sai)" aria-label="Không đưa vào" @click="ignore(r)"><Ban class="size-4" /></button>
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((page = p), load())" />
    </section>

    <p class="mb-3 mt-6 text-[13px] font-semibold tracking-wider text-slate-400">LẤY FILE HOÁ ĐƠN Ở ĐÂU?</p>
    <div class="grid gap-4 md:grid-cols-3">
        <div :class="cn(CARD, 'flex gap-3.5 p-4')">
            <span class="grid size-10 shrink-0 place-items-center rounded-[11px] bg-blue-50 text-blue-600"><Landmark class="size-5" :stroke-width="1.9" /></span>
            <div><p class="text-[15px] font-semibold text-slate-900">Cổng hoá đơn điện tử</p><p class="mt-0.5 text-[13px] leading-relaxed text-slate-500">hoadondientu.gdt.gov.vn › Tra cứu hoá đơn › Hoá đơn mua vào: chọn tháng, tải XML (nhiều hoá đơn thì được file .zip).</p></div>
        </div>
        <div :class="cn(CARD, 'flex gap-3.5 p-4')">
            <span class="grid size-10 shrink-0 place-items-center rounded-[11px] bg-violet-50 text-violet-600"><Mail class="size-5" :stroke-width="1.9" /></span>
            <div><p class="text-[15px] font-semibold text-slate-900">Email nhà cung cấp</p><p class="mt-0.5 text-[13px] leading-relaxed text-slate-500">Tải file .xml đính kèm trong email hoá đơn rồi bấm Tải hoá đơn XML.</p></div>
        </div>
        <div :class="cn(CARD, 'flex gap-3.5 p-4')">
            <span class="grid size-10 shrink-0 place-items-center rounded-[11px] bg-emerald-50 text-emerald-600"><ShieldCheck class="size-5" :stroke-width="1.9" /></span>
            <div><p class="text-[15px] font-semibold text-slate-900">Tải lại không sợ trùng</p><p class="mt-0.5 text-[13px] leading-relaxed text-slate-500">Hoá đơn trùng được bỏ qua, tải lại cả tháng cũng không sao. Hoá đơn từ 5 triệu đồng cần trả bằng chuyển khoản mới được khấu trừ thuế.</p></div>
        </div>
    </div>
</template>
