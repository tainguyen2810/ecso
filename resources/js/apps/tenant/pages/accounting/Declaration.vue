<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowRight, CalendarClock, Check, ChevronLeft, ChevronRight, FileSpreadsheet, FileText, ShieldCheck, TriangleAlert } from 'lucide-vue-next';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import ReportChip from '../reports/ReportChip.vue';
import AccountingFrame from './AccountingFrame.vue';
import { BTN_PRIMARY, CARD, daysUntil, dmy, periodLabel, shiftPeriod, type Declaration } from './accounting';

/**
 * Kế toán, thuế › Tờ khai thuế (mẫu 28/11): số liệu tờ khai của hộ kinh doanh nộp thuế theo tỷ lệ % trên doanh thu
 * (mẫu 01/CNKD), theo nhóm ngành, chỉ phần tự khai, với hạn nộp và các bước nộp. Doanh thu sàn đã khấu trừ hiện riêng
 * để đối chiếu, không đưa vào tờ khai. Bên phải: số liệu lấy từ đâu và các kỳ trước trong năm.
 */
interface PastPeriod { period: string; tax: number; deadline: string }
const route = useRoute();
const router = useRouter();
const toast = useToast();
const period = ref<string | null>(typeof route.query.period === 'string' && /^\d{4}-(Q[1-4]|\d{2})$/.test(route.query.period) ? route.query.period : null);
const data = ref<Declaration | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const exporting = ref(false);
const exported = ref(false);
const past = ref<PastPeriod[]>([]);
let pastYear = '';

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        data.value = (await api.get('/accounting/declaration', { params: period.value ? { period: period.value } : {} })).data.data;
        if (!period.value && data.value) period.value = data.value.period;
        exported.value = false;
        loadPast();
    } catch (e) {
        loadError.value = (e as ApiError).message;
        data.value = null;
    } finally {
        loading.value = false;
    }
}
/** Periods of the same year, from the tax book (S4). */
async function loadPast() {
    const year = data.value?.period.slice(0, 4) ?? '';
    if (!year || year === pastYear) return;
    pastYear = year;
    const rows = (await api.get('/accounting/books/s4', { params: { month: `${year}-01` } }).catch(() => null))?.data?.data?.rows ?? [];
    past.value = (rows as PastPeriod[]).slice().reverse();
}
watch(period, (p, old) => {
    if (p && old !== undefined) router.replace({ query: { period: p } });
    if (!data.value || data.value.period !== p) load();
}, { immediate: true });

function go(by: number) {
    if (data.value) period.value = shiftPeriod(data.value.period, by);
}

async function exportFile() {
    if (!data.value) return;
    exporting.value = true;
    try {
        await downloadFile(`/accounting/declaration?period=${data.value.period}&export=1`, `to-khai-${data.value.period}.xlsx`);
        exported.value = true;
    } catch (e) {
        toast.error((e as ApiError).message || 'Chưa xuất được tờ khai.');
    } finally {
        exporting.value = false;
    }
}

const pct = (n: number) => `${String(n).replace('.', ',')}%`;
const left = computed(() => (data.value ? daysUntil(data.value.deadline) : 0));
/** The period has ended: figures are final, not an estimate. */
const ended = computed(() => !!data.value && daysUntil(data.value.to) < 0);
const canNext = computed(() => !!data.value && daysUntil(data.value.to) < 0);
const groups = computed(() => data.value?.groups.filter((x) => x.self !== 0) ?? []);
const others = computed(() => past.value.filter((p) => p.period !== data.value?.period));
const steps = computed(() => {
    const d = data.value;
    if (!d) return [];
    return [
        { title: ended.value ? 'Số liệu đã sẵn' : 'Đang tạm tính', sub: `Từ ${formatNumber(d.orders ?? 0)} đơn kênh riêng`, state: ended.value ? 'done' : 'now' },
        { title: 'Tải Excel', sub: 'Lưu số liệu kỳ này', state: exported.value ? 'done' : ended.value ? 'now' : 'todo' },
        { title: 'Nhập cổng thuế', sub: 'Tờ khai 01/CNKD', state: exported.value ? 'now' : 'todo' },
        { title: 'Nộp tiền thuế', sub: `Trước ${dmy(d.deadline)}`, state: 'todo' },
    ] as { title: string; sub: string; state: 'done' | 'now' | 'todo' }[];
});
const th = 'whitespace-nowrap px-4 py-3 text-[12px] font-semibold uppercase tracking-wide text-slate-500 first:pl-5 last:pr-5 sm:first:pl-6 sm:last:pr-6';
const td = 'px-4 py-3.5 first:pl-5 last:pr-5 sm:first:pl-6 sm:last:pr-6';
</script>

<template>
    <AccountingFrame page="Tờ khai thuế" title="Tờ khai thuế" description="Số liệu đã điền sẵn từ đơn hàng. Anh/chị đối chiếu rồi nhập vào cổng thuế hoặc gửi kế toán.">
        <div class="flex h-11 items-center rounded-[12px] border border-slate-200 bg-white text-[15px]">
            <button type="button" class="grid size-11 place-items-center text-slate-500 hover:text-slate-900" aria-label="Kỳ trước" @click="go(-1)"><ChevronLeft class="size-4" /></button>
            <span class="min-w-32 px-1 text-center font-semibold" data-testid="declaration-period">{{ data ? periodLabel(data.period) : '…' }}</span>
            <button type="button" class="grid size-11 place-items-center text-slate-500 hover:text-slate-900 disabled:text-slate-300" aria-label="Kỳ sau" :disabled="!canNext" @click="go(1)"><ChevronRight class="size-4" /></button>
        </div>
        <button type="button" :class="BTN_PRIMARY" :disabled="exporting || !data" data-testid="declaration-export" @click="exportFile"><FileSpreadsheet class="size-4" /> {{ exporting ? 'Đang xuất…' : 'Tải Excel' }}</button>
    </AccountingFrame>

    <div v-if="loading && !data" class="space-y-4"><Skeleton class="h-24" /><Skeleton class="h-96" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <div v-else-if="data" :class="loading && 'opacity-60'">
        <section :class="cn(CARD, 'flex flex-col gap-5 px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:gap-8')" data-testid="declaration-steps">
            <div class="shrink-0 lg:border-r lg:border-slate-100 lg:pr-8">
                <p class="text-[13px] text-slate-500">Hạn nộp</p>
                <p class="text-[22px] font-bold text-slate-900">{{ dmy(data.deadline) }}</p>
                <ReportChip v-if="left >= 0" tone="blue"><CalendarClock class="size-3" />Còn {{ left }} ngày</ReportChip>
                <ReportChip v-else tone="red"><CalendarClock class="size-3" />Đã qua hạn</ReportChip>
            </div>
            <ol class="grid flex-1 gap-4 sm:grid-cols-2 xl:flex xl:items-center xl:gap-0">
                <li v-for="(s, i) in steps" :key="s.title" class="flex items-center xl:flex-1">
                    <span class="flex items-center gap-3">
                        <span v-if="s.state === 'done'" class="grid size-9 shrink-0 place-items-center rounded-full bg-emerald-500 text-white"><Check class="size-4" /></span>
                        <span v-else-if="s.state === 'now'" class="grid size-9 shrink-0 place-items-center rounded-full bg-blue-600 text-[14px] font-semibold text-white ring-4 ring-blue-100">{{ i + 1 }}</span>
                        <span v-else class="grid size-9 shrink-0 place-items-center rounded-full border-2 border-slate-200 bg-white text-[14px] font-semibold text-slate-400">{{ i + 1 }}</span>
                        <span>
                            <span :class="cn('block text-[14px] font-semibold', s.state === 'todo' ? 'text-slate-400' : 'text-slate-900')">{{ s.title }}</span>
                            <span class="block text-[12px] text-slate-500">{{ s.sub }}</span>
                        </span>
                    </span>
                    <span v-if="i < steps.length - 1" :class="cn('mx-4 hidden h-0.5 flex-1 rounded-full xl:block', s.state === 'done' ? 'bg-emerald-400' : 'bg-slate-200')" />
                </li>
            </ol>
        </section>

        <div class="mt-6 grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_23rem] 2xl:grid-cols-[minmax(0,1fr)_25rem]">
            <section :class="cn(CARD, 'min-w-0 overflow-hidden')">
                <div class="border-b border-slate-100 bg-gradient-to-b from-slate-50 to-white px-5 py-5 text-center">
                    <p class="text-[12px] font-semibold tracking-wider text-slate-400">MẪU 01/CNKD</p>
                    <h2 class="mt-1 text-[18px] font-bold uppercase tracking-tight text-slate-900">Tờ khai thuế đối với hộ kinh doanh, cá nhân kinh doanh</h2>
                    <p class="mt-1 text-[13px] text-slate-500">Kỳ tính thuế: {{ data.label }} (từ {{ dmy(data.from) }} đến {{ dmy(data.to) }})<template v-if="!ended"> · đang tạm tính</template></p>
                </div>
                <dl class="grid gap-x-10 gap-y-1 border-b border-slate-100 px-5 py-4 text-[14px] sm:grid-cols-2 sm:px-6">
                    <div class="flex gap-2 py-1"><dt class="w-32 shrink-0 text-slate-500">Người nộp thuế</dt><dd class="font-medium">{{ data.taxpayer?.name || '—' }}</dd></div>
                    <div class="flex gap-2 py-1">
                        <dt class="w-32 shrink-0 text-slate-500">Mã số thuế</dt>
                        <dd v-if="data.taxpayer?.tax_code" class="font-mono font-medium">{{ data.taxpayer.tax_code }}</dd>
                        <dd v-else class="flex items-center gap-1 text-amber-600"><TriangleAlert class="size-3.5" />Chưa có, điền ở <RouterLink to="/settings/store" class="underline">Cấu hình</RouterLink></dd>
                    </div>
                    <div class="flex gap-2 py-1"><dt class="w-32 shrink-0 text-slate-500">Tên cửa hàng</dt><dd class="font-medium">{{ data.taxpayer?.store || '—' }}</dd></div>
                    <div class="flex gap-2 py-1"><dt class="w-32 shrink-0 text-slate-500">Điện thoại</dt><dd class="font-medium">{{ data.taxpayer?.phone || '—' }}</dd></div>
                    <div class="flex gap-2 py-1 sm:col-span-2"><dt class="w-32 shrink-0 text-slate-500">Địa chỉ</dt><dd class="font-medium">{{ data.taxpayer?.address || '—' }}</dd></div>
                </dl>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-[14px]" data-testid="declaration-table">
                        <thead class="bg-slate-50/80 text-left"><tr><th :class="th">Nhóm ngành nghề</th><th :class="cn(th, 'text-right')">Doanh thu</th><th :class="cn(th, 'text-right')">Tỷ lệ GTGT</th><th :class="cn(th, 'text-right')">Thuế GTGT</th><th :class="cn(th, 'text-right')">Tỷ lệ TNCN</th><th :class="cn(th, 'text-right')">Thuế TNCN</th></tr></thead>
                        <tbody>
                            <tr v-for="g in groups" :key="g.group" class="border-t border-slate-100">
                                <td :class="cn(td, 'min-w-60')">{{ g.label }}</td>
                                <td :class="cn(td, 'whitespace-nowrap text-right tabular-nums')">{{ formatMoney(g.self) }}</td>
                                <td :class="cn(td, 'text-right tabular-nums text-slate-500')">{{ pct(g.vat) }}</td>
                                <td :class="cn(td, 'whitespace-nowrap text-right tabular-nums')">{{ formatMoney(g.vat_amount) }}</td>
                                <td :class="cn(td, 'text-right tabular-nums text-slate-500')">{{ pct(g.pit) }}</td>
                                <td :class="cn(td, 'whitespace-nowrap text-right tabular-nums')">{{ formatMoney(g.pit_amount) }}</td>
                            </tr>
                            <tr v-if="!groups.length"><td colspan="6" class="px-6 py-10 text-center text-slate-500">Kỳ này chưa có doanh thu tự khai.</td></tr>
                        </tbody>
                        <tfoot class="border-t border-slate-200">
                            <tr>
                                <td :class="cn(td, 'font-semibold')">Tổng cộng</td>
                                <td :class="cn(td, 'whitespace-nowrap text-right font-semibold tabular-nums')">{{ formatMoney(data.self) }}</td>
                                <td />
                                <td :class="cn(td, 'whitespace-nowrap text-right font-semibold tabular-nums')">{{ formatMoney(data.vat) }}</td>
                                <td />
                                <td :class="cn(td, 'whitespace-nowrap text-right font-semibold tabular-nums')">{{ formatMoney(data.pit) }}</td>
                            </tr>
                            <tr class="bg-blue-600 text-white">
                                <td class="px-5 py-4 text-[15px] font-semibold sm:px-6" colspan="5">Tổng số thuế phải nộp (GTGT + TNCN)</td>
                                <td class="whitespace-nowrap px-5 py-4 text-right text-[20px] font-bold tabular-nums sm:px-6" data-testid="declaration-tax">{{ formatMoney(data.tax) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div v-if="data.market" class="m-5 flex gap-3 rounded-[12px] border border-emerald-200 bg-emerald-50 p-4 text-[14px] text-emerald-900 sm:m-6">
                    <ShieldCheck class="size-5 shrink-0 text-emerald-600" />
                    <p><b>Không đưa vào tờ khai:</b> doanh thu sàn {{ formatMoney(data.market) }}. Sàn đã khấu trừ và nộp thay khoảng {{ formatMoney(data.withheld) }} (ước tính theo tỷ lệ nhóm ngành).</p>
                </div>
            </section>

            <div class="space-y-6">
                <section :class="cn(CARD, 'p-5')">
                    <h2 class="text-[16px] font-semibold text-slate-900">Số liệu lấy từ đâu</h2>
                    <dl class="mt-2 divide-y divide-slate-100 text-[14px]">
                        <div class="flex justify-between py-2.5"><dt class="text-slate-500">Đơn kênh riêng đã giao</dt><dd class="tabular-nums">{{ formatNumber(data.orders ?? 0) }} đơn</dd></div>
                        <div class="flex justify-between py-2.5"><dt class="text-slate-500">Hàng trả lại đã trừ</dt><dd :class="cn('tabular-nums', (data.returns ?? 0) > 0 && 'text-rose-600')">{{ (data.returns ?? 0) > 0 ? '−' : '' }}{{ formatMoney(data.returns ?? 0) }}</dd></div>
                        <div class="flex justify-between py-2.5"><dt class="text-slate-500">Doanh thu sàn (tách riêng)</dt><dd class="tabular-nums">{{ formatMoney(data.market) }}</dd></div>
                    </dl>
                    <RouterLink :to="{ path: '/accounting/books', query: { book: 's1', month: data.from.slice(0, 7) } }" class="mt-3 inline-flex items-center gap-1.5 text-[14px] font-medium text-blue-600 hover:underline">Xem chi tiết trong Sổ doanh thu (S1) <ArrowRight class="size-4" /></RouterLink>
                </section>

                <section v-if="others.length" :class="cn(CARD, 'p-5')" data-testid="declaration-past">
                    <h2 class="text-[16px] font-semibold text-slate-900">Các kỳ khác năm {{ data.period.slice(0, 4) }}</h2>
                    <div class="mt-1 divide-y divide-slate-100">
                        <button v-for="p in others" :key="p.period" type="button" class="flex w-full items-center gap-3 py-3 text-left hover:text-blue-600" @click="period = p.period">
                            <span class="grid size-9 place-items-center rounded-[10px] bg-slate-100 text-slate-600"><FileText class="size-[18px]" /></span>
                            <span class="flex-1"><span class="block text-[14px] font-medium">{{ periodLabel(p.period) }}</span><span class="block text-[12px] text-slate-500">Hạn {{ dmy(p.deadline) }}</span></span>
                            <span class="text-[14px] font-semibold tabular-nums">{{ formatMoney(p.tax) }}</span>
                            <ChevronRight class="size-4 text-slate-300" />
                        </button>
                    </div>
                </section>

                <section :class="cn(CARD, 'p-5')">
                    <h2 class="text-[16px] font-semibold text-slate-900">Nộp ở đâu</h2>
                    <ol class="mt-3 space-y-2.5 text-[14px] text-slate-600">
                        <li class="flex gap-2.5"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-blue-50 text-[12px] font-semibold text-blue-700">1</span>Bấm Tải Excel để lưu số liệu kỳ này.</li>
                        <li class="flex gap-2.5"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-blue-50 text-[12px] font-semibold text-blue-700">2</span>Đăng nhập cổng thuế điện tử hoặc app eTax Mobile, chọn tờ khai 01/CNKD, kỳ {{ data.label }}.</li>
                        <li class="flex gap-2.5"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-blue-50 text-[12px] font-semibold text-blue-700">3</span>Nhập doanh thu và thuế theo từng nhóm ngành như bảng bên, nộp tờ khai và nộp tiền trước hạn.</li>
                    </ol>
                    <p class="mt-3 text-[12px] text-slate-400">Số liệu tạm tính để tham khảo. Tỷ lệ thuế theo Thiết lập thuế; đối chiếu với cơ quan thuế hoặc kế toán trước khi nộp.</p>
                </section>
            </div>
        </div>
    </div>
</template>
