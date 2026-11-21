<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { BookOpen, FileSpreadsheet } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { BOOKS, dmy, periodLabel, type BookKey } from './accounting';
import { monthOptions } from '../purchasing/invoices';

/**
 * Kế toán, thuế › Sổ sách: các sổ của hộ kinh doanh (mẫu theo Thông tư 88/2021) lập từ đơn hàng, kho và phiếu chi.
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
    truncated?: boolean;
}

const route = useRoute();
const router = useRouter();
const toast = useToast();
const months = monthOptions(new Date(), 24);
const book = ref<BookKey>((BOOKS.find((b) => b.key === route.query.book)?.key ?? 's1') as BookKey);
const month = ref(typeof route.query.month === 'string' && /^\d{4}-\d{2}$/.test(route.query.month) ? route.query.month : months[0]!.value);
const data = ref<BookData | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const exporting = ref(false);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        data.value = (await api.get(`/accounting/books/${book.value}`, { params: { month: month.value } })).data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
        data.value = null;
    } finally {
        loading.value = false;
    }
}
watch([book, month], () => {
    router.replace({ query: { book: book.value, month: month.value } });
    load();
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

const who = computed(() => {
    const t = data.value?.taxpayer;
    return t ? [t.name, t.store, t.tax_code ? `MST ${t.tax_code}` : null].filter(Boolean).join(' · ') : '';
});
const periodText = computed(() => (book.value === 's4' ? `Năm ${month.value.slice(0, 4)}` : periodLabel(month.value)));
</script>

<template>
    <PageHeader title="Sổ sách kế toán" description="Tự lập từ đơn hàng, kho và phiếu chi. Không phải nhập lại.">
        <select v-model="month" class="h-9 rounded-md border bg-card px-2 text-sm" aria-label="Tháng">
            <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
        </select>
        <Button variant="outline" :loading="exporting" data-testid="book-export" @click="exportBook"><FileSpreadsheet /> Xuất Excel</Button>
    </PageHeader>

    <div class="grid grid-cols-[minmax(0,1fr)] items-start gap-4 lg:grid-cols-[280px_minmax(0,1fr)]">
        <Card class="p-2">
            <button
                v-for="b in BOOKS" :key="b.key" type="button"
                class="flex w-full items-start gap-2.5 rounded-lg px-3 py-2.5 text-left text-sm"
                :class="book === b.key ? 'bg-primary/10 font-medium text-primary' : 'hover:bg-muted'"
                @click="book = b.key"
            >
                <BookOpen class="mt-0.5 size-4 shrink-0" />
                <span>{{ b.title }}<span class="block text-xs font-normal text-muted-foreground">Mẫu {{ b.code }}</span></span>
            </button>
            <p class="border-t px-3 pb-1 pt-3 text-xs text-muted-foreground">Sổ tiền mặt, tiền gửi: xem <RouterLink to="/reports/cashbook" class="text-primary">Sổ quỹ</RouterLink>.</p>
        </Card>

        <Card class="min-w-0">
            <div class="border-b px-4 py-4 text-center">
                <div class="font-semibold uppercase">{{ BOOKS.find((b) => b.key === book)?.title }}</div>
                <div class="mt-1 text-xs text-muted-foreground">{{ who }} · {{ periodText }}</div>
            </div>
            <div v-if="loading && !data" class="space-y-3 p-4"><Skeleton v-for="i in 6" :key="i" class="h-9" /></div>
            <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
            <div v-else-if="data" class="overflow-x-auto">
                <!-- S1: doanh thu -->
                <table v-if="data.book === 's1'" class="w-full text-sm" data-testid="book-table">
                    <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
                        <tr><th class="px-4 py-2">Ngày</th><th class="px-4 py-2">Diễn giải</th><th class="px-4 py-2">Chứng từ</th><th class="px-4 py-2 text-right">Doanh thu tự khai</th><th class="px-4 py-2 text-right">Doanh thu sàn</th><th class="px-4 py-2 text-right">Thuế sàn đã khấu trừ</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(r, i) in data.rows" :key="i" class="border-t align-top">
                            <td class="whitespace-nowrap px-4 py-2.5">{{ dmy(r.day).slice(0, 5) }}</td>
                            <td class="px-4 py-2.5">{{ r.label }} <span class="text-muted-foreground">({{ r.count }} đơn)</span></td>
                            <td class="max-w-56 truncate px-4 py-2.5 font-mono text-xs text-muted-foreground" :title="r.orders.join(', ')">{{ r.orders.slice(0, 3).join(', ') }}{{ r.count > 3 ? '…' : '' }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums" :class="r.self < 0 && 'text-rose-700'">{{ r.self ? formatMoney(r.self) : '' }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums" :class="r.market < 0 && 'text-rose-700'">{{ r.market ? formatMoney(r.market) : '' }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ r.withheld ? formatMoney(r.withheld) : '' }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t font-semibold"><tr><td class="px-4 py-3" colspan="3">Cộng {{ periodText.toLowerCase() }}</td><td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(data.totals.self ?? 0) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(data.totals.market ?? 0) }}</td><td class="px-4 py-3 text-right tabular-nums text-muted-foreground">{{ formatMoney(data.totals.withheld ?? 0) }}</td></tr></tfoot>
                </table>

                <!-- S2: nhập xuất tồn -->
                <table v-else-if="data.book === 's2'" class="w-full text-sm" data-testid="book-table">
                    <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
                        <tr><th class="px-4 py-2">Mã hàng</th><th class="px-4 py-2">Tên hàng</th><th class="px-4 py-2 text-right">Tồn đầu</th><th class="px-4 py-2 text-right">Nhập</th><th class="px-4 py-2 text-right">Xuất</th><th class="px-4 py-2 text-right">Tồn cuối</th><th class="px-4 py-2 text-right">Giá trị tồn cuối</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in data.rows" :key="r.sku" class="border-t">
                            <td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs">{{ r.sku }}</td><td class="px-4 py-2.5">{{ r.name }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ formatNumber(r.opening) }}</td><td class="px-4 py-2.5 text-right tabular-nums">{{ formatNumber(r.in) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ formatNumber(r.out) }}</td><td class="px-4 py-2.5 text-right tabular-nums">{{ formatNumber(r.closing) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums">{{ formatMoney(r.closing_value) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t font-semibold"><tr><td class="px-4 py-3" colspan="2">Cộng</td><td class="px-4 py-3 text-right tabular-nums">{{ formatNumber(data.totals.opening ?? 0) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ formatNumber(data.totals.in ?? 0) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ formatNumber(data.totals.out ?? 0) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ formatNumber(data.totals.closing ?? 0) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(data.totals.closing_value ?? 0) }}</td></tr></tfoot>
                </table>

                <!-- S3: chi phí -->
                <table v-else-if="data.book === 's3'" class="w-full text-sm" data-testid="book-table">
                    <thead class="bg-muted/50 text-left text-xs text-muted-foreground"><tr><th class="px-4 py-2">Ngày</th><th class="px-4 py-2">Số phiếu</th><th class="px-4 py-2">Diễn giải</th><th class="px-4 py-2 text-right">Số tiền</th></tr></thead>
                    <tbody>
                        <tr v-for="r in data.rows" :key="r.id" class="border-t">
                            <td class="whitespace-nowrap px-4 py-2.5">{{ dmy(r.day).slice(0, 5) }}</td><td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs">{{ r.number }}</td>
                            <td class="px-4 py-2.5">{{ r.label }}</td><td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums">{{ formatMoney(r.amount) }}</td>
                        </tr>
                        <tr v-if="!data.rows.length"><td colspan="4" class="px-4 py-8 text-center text-muted-foreground">Chưa có phiếu chi tính vào chi phí trong tháng này.</td></tr>
                    </tbody>
                    <tfoot class="border-t font-semibold"><tr><td class="px-4 py-3" colspan="3">Cộng</td><td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(data.totals.amount ?? 0) }}</td></tr></tfoot>
                </table>

                <!-- S4: thuế -->
                <table v-else class="w-full text-sm" data-testid="book-table">
                    <thead class="bg-muted/50 text-left text-xs text-muted-foreground"><tr><th class="px-4 py-2">Kỳ</th><th class="px-4 py-2 text-right">Doanh thu tự khai</th><th class="px-4 py-2 text-right">Thuế GTGT</th><th class="px-4 py-2 text-right">Thuế TNCN</th><th class="px-4 py-2 text-right">Phải nộp</th><th class="px-4 py-2">Hạn nộp</th></tr></thead>
                    <tbody>
                        <tr v-for="r in data.rows" :key="r.period" class="border-t">
                            <td class="px-4 py-2.5"><RouterLink :to="{ path: '/accounting/declaration', query: { period: r.period } }" class="text-primary">{{ periodLabel(r.period) }}</RouterLink></td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums">{{ formatMoney(r.self) }}</td><td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums">{{ formatMoney(r.vat) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums">{{ formatMoney(r.pit) }}</td><td class="whitespace-nowrap px-4 py-2.5 text-right font-medium tabular-nums">{{ formatMoney(r.tax) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-muted-foreground">{{ dmy(r.deadline) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t font-semibold"><tr><td class="px-4 py-3">Cộng năm</td><td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(data.totals.self ?? 0) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(data.totals.vat ?? 0) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(data.totals.pit ?? 0) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(data.totals.tax ?? 0) }}</td><td></td></tr></tfoot>
                </table>
                <p v-if="data.truncated" class="border-t px-4 py-2 text-xs text-muted-foreground">Bảng chỉ hiện một phần, tổng cộng tính đủ. Xuất Excel để xem hết.</p>
            </div>
        </Card>
    </div>
</template>
