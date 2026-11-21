<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { Ban, FileCode2, FileSpreadsheet, FileUp, PackagePlus, Receipt, RotateCcw, Search, TriangleAlert, Undo2 } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Pagination from '../../components/Pagination.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import KpiCard from '@/shared/ui/KpiCard.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { formatMoney } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { ISSUE_LABELS, monthOptions, type PurchaseInvoiceRow } from './invoices';

/**
 * Nhập hàng › Hoá đơn mua vào: e-invoices suppliers issued to the shop. XML files (or a .zip) from the tax portal
 * or the supplier's email are uploaded, checked (issued to this shop, has the tax authority's code, adds up), then
 * turned into a purchase order with "Tạo phiếu nhập" or set aside with "Không đưa vào".
 */
type Tab = 'all' | 'open' | 'review' | 'linked' | 'ignored';
const router = useRouter();
const toast = useToast();

const rows = ref<PurchaseInvoiceRow[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0, counts: { all: 0, open: 0, review: 0, linked: 0, ignored: 0 }, summary: { invoices: 0, total: '0', vat: '0' } });
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
</script>

<template>
    <PageHeader title="Hoá đơn mua vào" description="Hoá đơn điện tử nhà cung cấp xuất cho cửa hàng: kiểm tra, đưa vào phiếu nhập, xuất bảng kê thuế đầu vào." :back="{ to: '/purchasing', label: 'Nhập hàng' }">
        <Button variant="outline" :loading="exporting" @click="exportList"><FileSpreadsheet /> Xuất bảng kê</Button>
        <label class="inline-flex cursor-pointer" data-testid="invoice-upload">
            <span class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground shadow-xs hover:bg-primary/90" :class="uploading && 'pointer-events-none opacity-60'">
                <FileUp class="size-4" />{{ uploading ? 'Đang đọc…' : 'Tải hoá đơn XML' }}
            </span>
            <input type="file" accept=".xml,.zip,text/xml,application/zip" multiple class="sr-only" data-testid="invoice-input" @change="upload" />
        </label>
    </PageHeader>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <KpiCard :label="`Hoá đơn · ${monthLabel}`" :value="`${meta.summary.invoices} hoá đơn`" :icon="Receipt" />
        <KpiCard label="Thuế GTGT đầu vào hợp lệ" :value="formatMoney(Number(meta.summary.vat))" :icon="FileCode2" tone="green" />
        <button type="button" class="text-left" @click="go('open')"><KpiCard label="Chưa vào phiếu nhập" :value="`${meta.counts.open} hoá đơn`" :icon="PackagePlus" tone="blue" :active="tab === 'open'" /></button>
        <button type="button" class="text-left" @click="go('review')"><KpiCard label="Cần xem lại" :value="`${meta.counts.review} hoá đơn`" :icon="TriangleAlert" tone="red" :active="tab === 'review'" /></button>
    </div>

    <Card>
        <div class="flex flex-wrap items-center gap-x-2 border-b px-3">
            <div class="flex min-w-0 max-w-full gap-1 overflow-x-auto 2xl:flex-1">
                <button
                    v-for="t in TABS" :key="t.key" type="button"
                    class="-mb-px flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-3 text-sm"
                    :class="tab === t.key ? 'border-primary font-medium text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'"
                    @click="go(t.key)"
                >
                    {{ t.label }} <span class="rounded-full bg-muted px-1.5 text-xs tabular-nums">{{ meta.counts[t.key] }}</span>
                </button>
            </div>
            <div class="flex w-full gap-2 py-2 sm:ml-auto sm:w-auto">
                <div class="relative flex-1 sm:w-56">
                    <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                    <input v-model="q" type="search" placeholder="NCC, MST, số hoá đơn" class="h-9 w-full rounded-md border bg-card pl-8 pr-2 text-sm" @input="onSearch" />
                </div>
                <select v-model="month" class="h-9 rounded-md border bg-card px-2 text-sm" aria-label="Tháng" @change="(page = 1), load()">
                    <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
                    <option value="">Tất cả tháng</option>
                </select>
            </div>
        </div>

        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 5" :key="i" class="h-10" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState
            v-else-if="!rows.length"
            :icon="FileCode2"
            :title="meta.counts.all || tab !== 'all' ? 'Không có hoá đơn nào ở đây' : 'Chưa có hoá đơn mua vào'"
            description="Tải file XML hoá đơn (hoặc file .zip nhiều hoá đơn) tải từ hoadondientu.gdt.gov.vn hoặc email nhà cung cấp gửi."
        />
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="invoice-table">
                <thead class="hidden border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground md:table-header-group">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Nhà cung cấp</th><th class="px-4 py-2.5 font-medium">Số hoá đơn</th><th class="px-4 py-2.5 font-medium">Ngày</th>
                        <th class="px-4 py-2.5 text-right font-medium">Tổng tiền</th><th class="px-4 py-2.5 text-right font-medium">Thuế GTGT</th>
                        <th class="px-4 py-2.5 font-medium">Kiểm tra</th><th class="px-4 py-2.5 font-medium">Phiếu nhập</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="r in rows" :key="r.id" class="max-md:grid max-md:grid-cols-2 max-md:gap-x-3 max-md:py-2" :class="r.blocked && r.status !== 'ignored' ? 'bg-rose-50/40' : r.status === 'ignored' ? 'opacity-60' : ''">
                        <td class="px-4 py-3 max-md:col-span-2 max-md:pb-1">
                            <div class="font-medium">{{ r.seller.name || '—' }}</div>
                            <div class="text-xs text-muted-foreground">MST {{ r.seller.tax_code || '—' }}<template v-if="r.kind === 'sale'"> · Hoá đơn bán hàng</template></div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 font-mono text-xs max-md:py-1">{{ r.series }} {{ r.number }}</td>
                        <td class="px-4 py-3 text-muted-foreground max-md:py-1 max-md:text-right">{{ date(r.issued_on) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums max-md:py-1 max-md:text-left">{{ formatMoney(Number(r.total)) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-muted-foreground max-md:py-1">VAT {{ formatMoney(Number(r.vat_amount)) }}</td>
                        <td class="px-4 py-3 max-md:py-1">
                            <span v-if="!r.issues.length" class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Hợp lệ</span>
                            <div v-else class="flex flex-wrap gap-1">
                                <span v-for="i in r.issues" :key="i" class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium" :class="ISSUE_LABELS[i]?.blocking ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-800'">{{ ISSUE_LABELS[i]?.label ?? i }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 max-md:py-1 max-md:text-right">
                            <RouterLink v-if="r.purchase_order" :to="`/purchasing/${r.purchase_order.id}`" class="font-mono text-xs font-medium text-primary">{{ r.purchase_order.number }}</RouterLink>
                            <div v-else-if="r.status === 'ignored'" class="flex items-center gap-2 text-xs text-muted-foreground max-md:justify-end">
                                Không đưa vào
                                <button type="button" class="inline-flex items-center gap-1 font-medium text-primary" @click="restore(r)"><Undo2 class="size-3" />Đưa lại</button>
                            </div>
                            <div v-else class="flex items-center gap-1 max-md:justify-end">
                                <Button size="sm" variant="outline" data-testid="invoice-make-po" @click="router.push({ path: '/purchasing/scan', query: { invoice: r.id } })"><PackagePlus /> Tạo phiếu nhập</Button>
                                <Button size="sm" variant="ghost" :title="'Không đưa vào phiếu nhập (chi phí, hoá đơn sai)'" aria-label="Không đưa vào" @click="ignore(r)"><Ban /></Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((page = p), load())" />
    </Card>

    <Card class="mt-4 p-4 text-sm text-muted-foreground">
        <div class="mb-2 flex items-center gap-2 font-medium text-foreground"><RotateCcw class="size-4" />Lấy file hoá đơn ở đâu?</div>
        <ul class="list-disc space-y-1 pl-5">
            <li>Cổng hoá đơn điện tử hoadondientu.gdt.gov.vn › Tra cứu hoá đơn › Hoá đơn mua vào: chọn tháng, tải XML (tải nhiều hoá đơn thì được file .zip).</li>
            <li>Email nhà cung cấp gửi kèm hoá đơn: tải file .xml đính kèm.</li>
            <li>Hoá đơn trùng được bỏ qua, tải lại cả tháng cũng không sao. Hoá đơn từ 5 triệu đồng cần trả bằng chuyển khoản mới được khấu trừ thuế.</li>
        </ul>
    </Card>
</template>
