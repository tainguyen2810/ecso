<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ArrowRight, CalendarClock, CalendarRange, ChartColumn, Calculator, Check, FileCode, FileText, Info, Receipt, Settings2, ShieldCheck, Store, TriangleAlert } from 'lucide-vue-next';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import ReportKpi from '../reports/ReportKpi.vue';
import ReportChip from '../reports/ReportChip.vue';
import ChannelMark from '../reports/ChannelMark.vue';
import AccountingFrame from './AccountingFrame.vue';
import TaxSettingsDialog from './TaxSettingsDialog.vue';
import { BTN, BTN_PRIMARY, CARD, daysUntil, dmy, openInvoices, periodLabel, short, thresholdProgress, type Overview } from './accounting';

/**
 * Kế toán, thuế › Tổng quan thuế (mẫu 28/11): doanh thu năm của mọi kênh so với ngưỡng, thuế tạm tính kỳ đang chạy,
 * phần sàn đã khấu trừ, hoá đơn mua vào chờ xử lý; doanh thu theo kênh (tự khai tách khỏi sàn), thuế từng kỳ trong năm
 * và việc cần làm.
 */
const tenant = useTenantStore();
const thisYear = new Date().getFullYear();
const year = ref(thisYear);
const data = ref<Overview | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const settingsOpen = ref(false);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        data.value = (await api.get('/accounting/overview', { params: { year: year.value } })).data.data;
        openInvoices.value = data.value?.todo.invoices_open ?? null;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
function pickYear(y: number) {
    year.value = y;
    load();
}

const progress = computed(() => (data.value ? thresholdProgress(data.value.revenue, data.value.threshold) : { pct: 0, left: 0 }));
const channels = computed(() => data.value?.channels ?? []);
const millions = (n: number) => (n >= 1e9 ? `${(n / 1e9).toLocaleString('vi-VN', { maximumFractionDigits: 1 })} tỷ` : `${Math.round(n / 1e6)} triệu`);
const share = (n: number) => (data.value && data.value.revenue > 0 ? (n / data.value.revenue) * 100 : 0);
const pct = (n: number) => `${n.toLocaleString('vi-VN', { maximumFractionDigits: 1 })}%`;
const canInvoices = computed(() => tenant.can('purchasing.view'));
const missingTaxpayer = computed(() => !!data.value && (!data.value.settings.taxpayer_name || !data.value.current.taxpayer?.tax_code));
const todoCount = computed(() => (data.value ? 1 + (canInvoices.value && data.value.todo.invoices_open ? 1 : 0) + (missingTaxpayer.value ? 1 : 0) : 0));
const declareLeft = computed(() => (data.value ? daysUntil(data.value.todo.declare.deadline) : 0));
const periodMax = computed(() => Math.max(...(data.value?.periods ?? []).map((p) => p.self), 1));
const isCurrent = (p: string) => p === data.value?.current.period;
/** Ticks under the threshold bar: 0, quarters of the threshold. */
const ticks = computed(() => (data.value ? [0, 0.25, 0.5, 0.75, 1].map((f) => (f === 0 ? '0' : short(data.value!.threshold * f))) : []));
</script>

<template>
    <AccountingFrame page="Tổng quan thuế" title="Tổng quan thuế" description="Doanh thu so với ngưỡng, thuế phải nộp kỳ này và việc cần làm của hộ kinh doanh.">
        <div class="flex rounded-[12px] bg-slate-100 p-1 text-[14px]" role="group" aria-label="Năm">
            <button
                v-for="y in [thisYear, thisYear - 1, thisYear - 2]"
                :key="y"
                type="button"
                :class="cn('rounded-[9px] px-3.5 py-1.5 text-slate-500 hover:text-slate-900', year === y && 'bg-white font-semibold text-slate-900 shadow-sm')"
                @click="pickYear(y)"
            >
                {{ y }}
            </button>
        </div>
        <button v-if="tenant.can('settings.manage') && data" type="button" :class="BTN" data-testid="tax-settings" @click="settingsOpen = true"><Settings2 class="size-4" /> Thiết lập thuế</button>
        <RouterLink v-if="data" :to="{ path: '/accounting/declaration', query: { period: data.current.period } }" :class="BTN_PRIMARY"><FileText class="size-4" /> Xem tờ khai {{ periodLabel(data.current.period) }}</RouterLink>
    </AccountingFrame>

    <div v-if="loading && !data" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><Skeleton v-for="i in 4" :key="i" class="h-36" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <div v-else-if="data" :class="loading && 'opacity-60'" data-testid="tax-overview">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <ReportKpi primary :label="`Doanh thu năm ${data.year}`" tip="Doanh thu của mọi kênh: đơn kênh riêng đã giao và đơn sàn đã hoàn tất, trừ hàng trả lại." :icon="ChartColumn" :value="data.revenue">
                <template #chips><ReportChip tone="onblue">{{ pct(progress.pct) }} ngưỡng {{ millions(data.threshold) }}</ReportChip></template>
                Mọi kênh, từ 01/01{{ data.year === thisYear ? ' đến hôm nay' : ` đến 31/12/${data.year}` }}
            </ReportKpi>
            <ReportKpi :label="`Thuế tạm tính ${periodLabel(data.current.period)}`" tip="Thuế GTGT và TNCN trên phần doanh thu shop tự khai của kỳ đang chạy, theo tỷ lệ trong Thiết lập thuế." :icon="Calculator" tone="blue" plain :value="data.current.tax">
                <template #chips><ReportChip tone="plain"><CalendarClock class="size-3" />Hạn nộp {{ dmy(data.current.deadline) }}</ReportChip></template>
                GTGT {{ formatMoney(data.current.vat) }} · TNCN {{ formatMoney(data.current.pit) }}
            </ReportKpi>
            <ReportKpi label="Sàn đã khấu trừ" tip="Ước tính thuế sàn (Shopee, TikTok Shop…) đã khấu trừ và nộp thay trên doanh thu sàn của năm." :icon="ShieldCheck" tone="green" :value="data.withheld">
                <template #chips><ReportChip tone="up"><Check class="size-3" />Không phải khai lại</ReportChip></template>
                Doanh thu sàn {{ formatMoney(data.market) }}
            </ReportKpi>
            <ReportKpi v-if="canInvoices" label="Hoá đơn mua vào" tip="Hoá đơn điện tử nhà cung cấp xuất cho shop, chưa đưa vào phiếu nhập." :icon="Receipt" tone="amber" :value="null" :text="`${formatNumber(data.todo.invoices_open)} hoá đơn`">
                <template v-if="data.todo.invoices_open_vat" #chips><ReportChip tone="amber">VAT {{ formatMoney(data.todo.invoices_open_vat) }}</ReportChip></template>
                Chưa vào phiếu nhập<template v-if="data.todo.invoices_review"> · {{ data.todo.invoices_review }} cần xem lại</template>
            </ReportKpi>
            <ReportKpi v-else label="Phần shop tự khai" :icon="Store" tone="blue" plain :value="data.tax">Thuế năm {{ data.year }} trên {{ formatMoney(data.self) }} doanh thu</ReportKpi>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem] 2xl:grid-cols-[minmax(0,1fr)_26rem]">
            <div class="min-w-0 space-y-6">
                <section :class="cn(CARD, 'p-5 sm:p-6')" data-testid="tax-threshold">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-[16px] font-semibold text-slate-900">Ngưỡng doanh thu năm</h2>
                            <p class="mt-0.5 text-[13px] text-slate-500">Doanh thu từ {{ millions(data.threshold) }}/năm trở lên phải nộp thuế GTGT và TNCN.</p>
                        </div>
                        <div class="sm:text-right">
                            <ReportChip v-if="progress.left > 0" tone="amber"><TriangleAlert class="size-3" />Còn {{ formatMoney(progress.left) }} nữa chạm ngưỡng</ReportChip>
                            <ReportChip v-else tone="red"><TriangleAlert class="size-3" />Đã vượt ngưỡng {{ millions(data.threshold) }}</ReportChip>
                            <p v-if="data.reach_on" class="mt-1.5 text-[13px] text-slate-500">Theo đà bán hiện tại, dự kiến chạm ngưỡng khoảng <b class="text-slate-700">{{ dmy(data.reach_on) }}</b></p>
                        </div>
                    </div>
                    <div class="relative mt-11 h-3 rounded-full bg-slate-100" role="progressbar" :aria-valuenow="progress.pct" aria-valuemin="0" aria-valuemax="100">
                        <div class="h-full rounded-full bg-gradient-to-r from-blue-500 via-indigo-500 to-amber-500" :style="{ width: `${progress.pct}%` }" />
                        <span class="absolute -top-1.5 h-6 w-[3px] -translate-x-1/2 rounded-full bg-slate-900" :style="{ left: `${progress.pct}%` }" />
                        <span class="absolute -top-8 -translate-x-1/2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-0.5 text-[12px] font-semibold text-white" :style="{ left: `${Math.min(96, Math.max(4, progress.pct))}%` }">{{ short(data.revenue) }}</span>
                    </div>
                    <div class="mt-2 flex justify-between text-[12px] text-slate-400"><span v-for="t in ticks" :key="t">{{ t }}</span></div>
                </section>

                <section :class="CARD">
                    <header class="flex flex-wrap items-center gap-3.5 border-b border-slate-100 px-5 py-4 sm:px-6">
                        <span class="grid size-10 shrink-0 place-items-center rounded-[11px] bg-blue-50 text-blue-600"><Store class="size-5" :stroke-width="1.9" /></span>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-[16px] font-semibold text-slate-900">Doanh thu và thuế theo kênh</h2>
                            <p class="mt-0.5 text-[13px] text-slate-500">Năm {{ data.year }}, đơn đã giao và đơn sàn đã hoàn tất, trừ hàng trả lại.</p>
                        </div>
                        <RouterLink :to="{ path: '/accounting/books', query: { book: 's1' } }" class="inline-flex h-9 items-center gap-1.5 rounded-[10px] border border-slate-200 px-3 text-[14px] text-slate-700 hover:bg-slate-50">Sổ doanh thu <ArrowRight class="size-4" /></RouterLink>
                    </header>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[640px] text-[14px]" data-testid="tax-channels">
                            <thead class="bg-slate-50/80 text-left text-[12px] font-semibold uppercase tracking-wide text-slate-500">
                                <tr><th class="px-5 py-3 sm:px-6">Kênh</th><th class="px-4 py-3 text-right">Doanh thu</th><th class="px-4 py-3">Tỷ trọng</th><th class="px-4 py-3">Ai nộp thuế</th><th class="px-5 py-3 text-right sm:px-6">GTGT + TNCN</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="c in channels" :key="c.label + c.kind" class="border-t border-slate-100">
                                    <td class="px-5 py-3 sm:px-6"><span class="flex items-center gap-3 whitespace-nowrap font-medium text-slate-900"><ChannelMark :kind="c.mark" :name="c.label" />{{ c.label }}</span></td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(c.revenue) }}</td>
                                    <td class="px-4 py-3">
                                        <span class="flex items-center gap-2.5"><span class="h-1.5 w-24 rounded-full bg-slate-100"><span class="block h-full rounded-full" :class="c.kind === 'self' ? 'bg-blue-500' : 'bg-emerald-500'" :style="{ width: `${Math.min(100, share(c.revenue))}%` }" /></span><span class="text-[13px] tabular-nums text-slate-500">{{ pct(share(c.revenue)) }}</span></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <ReportChip v-if="c.kind === 'self'" tone="blue">Tự khai</ReportChip>
                                        <ReportChip v-else tone="up">Sàn đã khấu trừ</ReportChip>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3 text-right tabular-nums sm:px-6" :class="c.kind === 'market' ? 'text-slate-400 line-through' : 'font-semibold text-slate-900'">{{ formatMoney(c.tax) }}</td>
                                </tr>
                                <tr v-if="!channels.length"><td colspan="5" class="px-6 py-10 text-center text-slate-500">Chưa có doanh thu trong năm {{ data.year }}.</td></tr>
                            </tbody>
                            <tfoot v-if="channels.length" class="border-t border-slate-200">
                                <tr class="bg-blue-50/50"><td class="px-5 py-3.5 font-semibold sm:px-6">Phần shop tự khai, tự nộp</td><td class="whitespace-nowrap px-4 py-3.5 text-right font-semibold tabular-nums">{{ formatMoney(data.self) }}</td><td /><td /><td class="whitespace-nowrap px-5 py-3.5 text-right text-[16px] font-bold tabular-nums text-blue-700 sm:px-6">{{ formatMoney(data.tax) }}</td></tr>
                                <tr class="text-slate-500"><td class="px-5 py-3 sm:px-6">Sàn đã khấu trừ và nộp thay</td><td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(data.market) }}</td><td /><td /><td class="whitespace-nowrap px-5 py-3 text-right tabular-nums sm:px-6">{{ formatMoney(data.withheld) }}</td></tr>
                            </tfoot>
                        </table>
                    </div>
                </section>

                <section v-if="data.periods.length" :class="CARD" data-testid="tax-periods">
                    <header class="flex items-center gap-3.5 border-b border-slate-100 px-5 py-4 sm:px-6">
                        <span class="grid size-10 shrink-0 place-items-center rounded-[11px] bg-violet-50 text-violet-600"><CalendarRange class="size-5" :stroke-width="1.9" /></span>
                        <div class="min-w-0">
                            <h2 class="text-[16px] font-semibold text-slate-900">Thuế từng kỳ · {{ data.year }}</h2>
                            <p class="mt-0.5 text-[13px] text-slate-500">Như Sổ theo dõi thuế phải nộp (S4-HKD). Bấm một kỳ để mở tờ khai.</p>
                        </div>
                    </header>
                    <div class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6" :class="data.periods.length > 4 ? 'xl:grid-cols-4 2xl:grid-cols-6' : 'xl:grid-cols-4'">
                        <RouterLink
                            v-for="p in data.periods"
                            :key="p.period"
                            :to="{ path: '/accounting/declaration', query: { period: p.period } }"
                            :class="cn('rounded-[12px] border p-4 transition hover:border-blue-300', isCurrent(p.period) ? 'border-blue-200 bg-blue-50/40' : 'border-slate-200')"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-[15px] font-semibold text-slate-900">{{ periodLabel(p.period) }}</span>
                                <ReportChip :tone="isCurrent(p.period) ? 'blue' : 'plain'">{{ isCurrent(p.period) ? 'Đang tạm tính' : 'Đã hết kỳ' }}</ReportChip>
                            </div>
                            <div class="mt-4 h-2 rounded-full bg-slate-100"><div class="h-full rounded-full" :class="isCurrent(p.period) ? 'bg-blue-500' : 'bg-slate-400'" :style="{ width: `${Math.max(0, (p.self / periodMax) * 100)}%` }" /></div>
                            <p class="mt-3 text-[12px] text-slate-500">Doanh thu tự khai</p>
                            <p class="text-[16px] font-semibold tabular-nums text-slate-900">{{ short(p.self) }}</p>
                            <p class="mt-2 text-[12px] text-slate-500">Thuế phải nộp</p>
                            <p :class="cn('text-[16px] font-semibold tabular-nums', isCurrent(p.period) ? 'text-blue-700' : 'text-slate-900')">{{ formatMoney(p.tax) }}</p>
                            <p class="mt-2 text-[12px] text-slate-400">Hạn nộp {{ dmy(p.deadline) }}</p>
                        </RouterLink>
                    </div>
                </section>
            </div>

            <div class="space-y-6">
                <section :class="cn(CARD, 'p-5')" data-testid="tax-todo">
                    <div class="flex items-center justify-between"><h2 class="text-[16px] font-semibold text-slate-900">Việc cần làm</h2><ReportChip tone="red">{{ todoCount }} việc</ReportChip></div>
                    <div class="mt-4 space-y-3">
                        <RouterLink :to="{ path: '/accounting/declaration', query: { period: data.todo.declare.period } }" class="flex items-start gap-3.5 rounded-[12px] border border-slate-200 p-4 hover:border-blue-300">
                            <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-rose-50 text-rose-600"><CalendarClock class="size-[18px]" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-[14px] font-medium text-slate-900">Nộp tờ khai {{ periodLabel(data.todo.declare.period) }}</span>
                                <span class="mt-0.5 block text-[13px] text-slate-500">Hạn {{ dmy(data.todo.declare.deadline) }} · {{ declareLeft >= 0 ? `còn ${declareLeft} ngày` : 'đã qua hạn, nộp rồi thì bỏ qua' }}</span>
                            </span>
                            <span class="text-[13px] font-medium text-blue-600">Mở</span>
                        </RouterLink>
                        <RouterLink v-if="canInvoices && data.todo.invoices_open" to="/purchasing/invoices" class="flex items-start gap-3.5 rounded-[12px] border border-slate-200 p-4 hover:border-blue-300">
                            <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-blue-50 text-blue-600"><FileCode class="size-[18px]" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-[14px] font-medium text-slate-900">{{ data.todo.invoices_open }} hoá đơn mua vào chưa vào phiếu nhập</span>
                                <span v-if="data.todo.invoices_review" class="mt-0.5 block text-[13px] text-slate-500">{{ data.todo.invoices_review }} hoá đơn cần xem lại</span>
                            </span>
                            <span class="text-[13px] font-medium text-blue-600">Xem</span>
                        </RouterLink>
                        <div v-if="missingTaxpayer" class="flex items-start gap-3.5 rounded-[12px] border border-slate-200 p-4">
                            <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-amber-50 text-amber-600"><TriangleAlert class="size-[18px]" /></span>
                            <span class="min-w-0 flex-1 text-[13px] text-slate-500">
                                <span class="block text-[14px] font-medium text-slate-900">Tờ khai còn thiếu thông tin</span>
                                <template v-if="!data.settings.taxpayer_name">Tên chủ hộ: điền ở Thiết lập thuế. </template>
                                <template v-if="!data.current.taxpayer?.tax_code">Mã số thuế: điền ở <RouterLink to="/settings/store" class="text-blue-600 hover:underline">Cấu hình › Thông tin cửa hàng</RouterLink>.</template>
                            </span>
                        </div>
                    </div>
                </section>

                <section :class="cn(CARD, 'p-5')">
                    <div class="flex items-center justify-between gap-2"><h2 class="text-[16px] font-semibold text-slate-900">Thuế tạm tính {{ periodLabel(data.current.period) }}</h2><span class="whitespace-nowrap text-[12px] text-slate-500">{{ dmy(data.current.from).slice(0, 5) }} – {{ dmy(data.current.to).slice(0, 5) }}</span></div>
                    <dl class="mt-3 divide-y divide-slate-100 text-[14px]">
                        <div class="flex justify-between py-2.5"><dt class="text-slate-500">Doanh thu tự khai</dt><dd class="tabular-nums">{{ formatMoney(data.current.self) }}</dd></div>
                        <div class="flex justify-between py-2.5"><dt class="text-slate-500">Thuế GTGT</dt><dd class="tabular-nums">{{ formatMoney(data.current.vat) }}</dd></div>
                        <div class="flex justify-between py-2.5"><dt class="text-slate-500">Thuế TNCN</dt><dd class="tabular-nums">{{ formatMoney(data.current.pit) }}</dd></div>
                        <div class="flex items-center justify-between pt-3.5"><dt class="font-semibold text-slate-900">Phải nộp</dt><dd class="text-[22px] font-bold tabular-nums text-blue-700">{{ formatMoney(data.current.tax) }}</dd></div>
                    </dl>
                    <RouterLink :to="{ path: '/accounting/declaration', query: { period: data.current.period } }" :class="cn(BTN_PRIMARY, 'mt-4 w-full justify-center')"><FileText class="size-4" /> Xem tờ khai {{ periodLabel(data.current.period) }}</RouterLink>
                </section>

                <div class="flex gap-2.5 rounded-[12px] bg-slate-100/80 p-4 text-[13px] leading-relaxed text-slate-500">
                    <Info class="mt-0.5 size-4 shrink-0" />
                    <p>Số liệu tạm tính để tham khảo, lấy từ đơn đã giao (kênh riêng) và đơn sàn đã hoàn tất, trừ hàng trả lại. Thuế sàn đã khấu trừ là ước tính theo tỷ lệ của nhóm ngành. Tỷ lệ và ngưỡng sửa trong Thiết lập thuế; đối chiếu với cơ quan thuế hoặc kế toán trước khi nộp.</p>
                </div>
            </div>
        </div>

        <TaxSettingsDialog v-model:open="settingsOpen" :settings="data.settings" @saved="load" />
    </div>
</template>
