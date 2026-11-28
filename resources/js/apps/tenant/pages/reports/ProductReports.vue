<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { ArrowRight, Lock, Package, Search } from 'lucide-vue-next';
import ReportDetailHead from './ReportDetailHead.vue';
import SideCard from './SideCard.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDate, formatNumber } from '@/shared/lib/utils';
import { downloadCsv, toCsv } from './range';
import { useReportQuery } from './useReportQuery';

/**
 * Bán chạy, bán chậm (mẫu Tailwind 28/11): the three best sellers of the period as cards, the top list with how long
 * the stock lasts, and the slow movers (stock that has not sold) with the money tied up in them.
 */
interface VariantRef {
    id: string;
    sku: string;
    name: string;
}
interface BestRow {
    variant: VariantRef | null;
    units: number;
    orders: number;
    revenue: number;
    on_hand: number;
}
interface SlowRow {
    variant: VariantRef;
    on_hand: number;
    units_sold: number;
    last_sale_at: string | null;
    stock_value: number;
}

const { range, param } = useReportQuery();
const view = param('view', 'best');
const days = param('days', '30');
const best = ref<BestRow[] | null>(null);
const slow = ref<SlowRow[] | null>(null);
const slowLocked = ref<string | null>(null);
const error = ref<string | null>(null);
const q = ref('');

async function loadBest() {
    try {
        best.value = (await api.get('/reports/best-sellers', { params: range.value })).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    }
}
async function loadSlow() {
    slow.value = null;
    try {
        slow.value = (await api.get('/reports/slow-movers', { params: { days: days.value } })).data.data;
        slowLocked.value = null;
    } catch (e) {
        const err = e as ApiError;
        if (err.status === 403 && (err.body as { code?: string }).code === 'FEATURE_NOT_IN_PLAN') slowLocked.value = err.message;
        else error.value = err.message;
    }
}
onMounted(() => {
    loadBest();
    loadSlow();
});
watch(() => [range.value.from, range.value.to], loadBest);
watch(days, loadSlow);
function retry() {
    error.value = null;
    loadBest();
    loadSlow();
}

/** Days in the period, to turn units sold into a daily rate and the stock into "đủ bán khoảng N ngày". */
const periodDays = computed(() => Math.max(1, Math.round((Date.parse(range.value.to) - Date.parse(range.value.from)) / 86400000) + 1));
function lasts(r: BestRow): number | null {
    return r.units > 0 ? Math.round(r.on_hand / (r.units / periodDays.value)) : null;
}
const maxUnits = computed(() => Math.max(1, ...(best.value ?? []).map((r) => r.units)));
const shown = computed(() => {
    const s = q.value.trim().toLowerCase();
    return (best.value ?? []).map((r, i) => ({ ...r, rank: i + 1 })).filter((r) => !s || `${r.variant?.sku} ${r.variant?.name}`.toLowerCase().includes(s));
});
const podium = computed(() => (best.value ?? []).slice(0, 3));
const slowValue = computed(() => (slow.value ?? []).reduce((s, r) => s + Number(r.stock_value), 0));
const sinceDays = (iso: string | null) => (iso ? Math.max(0, Math.floor((Date.now() - Date.parse(iso)) / 86400000)) : null);
const shortMoney = (v: number) => (v >= 1e9 ? `${(v / 1e9).toLocaleString('vi-VN', { maximumFractionDigits: 1 })} tỷ` : v >= 1e6 ? `${(v / 1e6).toLocaleString('vi-VN', { maximumFractionDigits: 1 })} tr` : formatNumber(Math.round(v)));
const TINTS = ['bg-blue-50 text-blue-600', 'bg-slate-100 text-slate-600', 'bg-emerald-50 text-emerald-600', 'bg-violet-50 text-violet-600', 'bg-amber-50 text-amber-600'];
const MEDALS = ['bg-amber-400 text-white', 'bg-slate-300 text-white', 'bg-orange-400 text-white'];
const card = 'rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]';

function exportCsv() {
    if (view.value === 'slow') {
        downloadCsv(
            `ban-cham-${days.value}-ngay.csv`,
            toCsv(['SKU', 'Sản phẩm', 'Tồn', 'Đã bán', 'Bán lần cuối', 'Giá trị tồn'], (slow.value ?? []).map((r) => [r.variant.sku, r.variant.name, r.on_hand, r.units_sold, r.last_sale_at, r.stock_value])),
        );
        return;
    }
    downloadCsv(
        `ban-chay-${range.value.from}-${range.value.to}.csv`,
        toCsv(['#', 'SKU', 'Sản phẩm', 'Đã bán', 'Đơn', 'Doanh thu', 'Tồn', 'Đủ bán (ngày)'], (best.value ?? []).map((r, i) => [i + 1, r.variant?.sku ?? '', r.variant?.name ?? '', r.units, r.orders, r.revenue, r.on_hand, lasts(r)])),
    );
}
</script>

<template>
    <div class="mx-auto max-w-[1440px] pt-2">
        <ReportDetailHead
            title="Bán chạy, bán chậm"
            description="Sản phẩm bán chạy trong kỳ và hàng tồn lâu không bán được."
            :period="view === 'slow' ? 'none' : 'range'"
            :from="range.from"
            :to="range.to"
            :can-export="view === 'slow' ? Boolean(slow?.length) : Boolean(best?.length)"
            @update:range="(r) => (range = r)"
            @export="exportCsv"
        />

        <div class="mb-5 flex flex-wrap items-center gap-3">
            <div class="flex rounded-[12px] bg-slate-100 p-1 text-[14px]" role="tablist">
                <button
                    v-for="[k, l] in [['best', 'Bán chạy'], ['slow', 'Bán chậm']]"
                    :key="k"
                    type="button"
                    role="tab"
                    :aria-selected="view === k"
                    :class="cn('rounded-[9px] px-4 py-1.5', view === k ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-900')"
                    :data-testid="`products-view-${k}`"
                    @click="view = k!"
                >
                    {{ l }}
                </button>
            </div>
            <select
                v-if="view === 'slow' && !slowLocked"
                v-model="days"
                class="ml-auto h-11 rounded-[10px] border border-slate-200 bg-white px-3.5 text-[15px] text-slate-700"
                aria-label="Không bán trong"
            >
                <option value="30">Không bán 30 ngày</option>
                <option value="60">Không bán 60 ngày</option>
                <option value="90">Không bán 90 ngày</option>
            </select>
        </div>

        <ErrorState v-if="error" :message="error" @retry="retry" />

        <div v-else-if="view === 'best'" class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
            <div class="min-w-0 space-y-5">
                <div v-if="!best" class="grid gap-4 md:grid-cols-3"><Skeleton v-for="i in 3" :key="i" class="h-56 rounded-[14px]" /></div>
                <div v-else-if="podium.length" class="grid gap-4 md:grid-cols-3" data-testid="best-podium">
                    <RouterLink v-for="(r, i) in podium" :key="r.variant?.id ?? i" :to="r.variant ? `/inventory/sku/${r.variant.id}` : ''" :class="cn(card, 'block p-5 hover:border-blue-300')">
                        <div class="flex items-start justify-between">
                            <span :class="cn('grid size-12 place-items-center rounded-[12px]', TINTS[i])"><Package class="size-6" :stroke-width="1.8" /></span>
                            <span :class="cn('grid size-8 place-items-center rounded-full text-[14px] font-bold', MEDALS[i])">{{ i + 1 }}</span>
                        </div>
                        <p class="mt-4 line-clamp-2 font-semibold text-slate-900">{{ r.variant?.name ?? 'Sản phẩm đã xóa' }}</p>
                        <p class="font-mono text-[12px] text-slate-400">{{ r.variant?.sku }}</p>
                        <div class="mt-4 grid grid-cols-3 gap-2 rounded-[10px] bg-slate-50 px-3 py-2.5">
                            <div><p class="text-[11px] text-slate-400">Đã bán</p><p class="whitespace-nowrap text-[17px] font-bold tabular-nums text-slate-900">{{ formatNumber(r.units) }}</p></div>
                            <div><p class="text-[11px] text-slate-400">Doanh thu</p><p class="whitespace-nowrap text-[17px] font-bold tabular-nums text-slate-900">{{ shortMoney(r.revenue) }}</p></div>
                            <div><p class="text-[11px] text-slate-400">Còn tồn</p><p class="whitespace-nowrap text-[17px] font-bold tabular-nums text-slate-900">{{ formatNumber(r.on_hand) }}</p></div>
                        </div>
                        <p :class="cn('mt-3 text-[13px]', lasts(r) !== null && lasts(r)! < 14 ? 'text-red-600' : 'text-emerald-600')">
                            {{ lasts(r) === null ? '' : lasts(r) === 0 ? 'Đã hết hàng, cần nhập thêm' : `Đủ bán khoảng ${formatNumber(lasts(r)!)} ngày` }}
                        </p>
                    </RouterLink>
                </div>

                <section :class="cn(card, 'overflow-hidden')" data-testid="best-sellers">
                    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                        <div>
                            <h3 class="text-[16px] font-semibold text-slate-900">Top sản phẩm bán chạy</h3>
                            <p class="mt-0.5 text-[13px] text-slate-500">Tính theo số lượng đã xuất kho trong kỳ (đơn đã bàn giao trở đi).</p>
                        </div>
                        <div class="relative w-full sm:w-64">
                            <Search class="absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                            <input v-model="q" placeholder="Tìm SKU, tên" class="h-10 w-full rounded-[10px] border border-slate-200 pl-10 pr-3 text-[14px] outline-none focus:border-blue-400" aria-label="Tìm sản phẩm" />
                        </div>
                    </header>
                    <div v-if="!best" class="space-y-3 p-5"><Skeleton v-for="i in 5" :key="i" class="h-8" /></div>
                    <EmptyState v-else-if="!best.length" :icon="Package" title="Chưa có hàng xuất kho trong kỳ" description="Chọn kỳ khác hoặc chờ đơn được bàn giao cho vận chuyển." />
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-[14px]">
                            <thead class="border-b border-slate-100 text-left text-[12px] font-semibold uppercase tracking-wider text-slate-400">
                                <tr>
                                    <th class="w-12 px-5 py-3.5">#</th>
                                    <th class="px-4 py-3.5">Sản phẩm</th>
                                    <th class="px-4 py-3.5">Đã bán</th>
                                    <th class="px-4 py-3.5 text-right">Đơn</th>
                                    <th class="px-4 py-3.5 text-right">Doanh thu</th>
                                    <th class="px-4 py-3.5 text-right">Tồn</th>
                                    <th class="px-5 py-3.5 text-right">Đủ bán</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700">
                                <tr v-for="r in shown" :key="r.variant?.id ?? r.rank" class="hover:bg-slate-50/70">
                                    <td class="px-5 py-3.5">
                                        <span :class="cn('grid size-7 place-items-center rounded-md text-[13px] font-semibold', r.rank <= 3 ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-500')">{{ r.rank }}</span>
                                    </td>
                                    <td class="min-w-56 px-4 py-3.5">
                                        <div class="flex items-center gap-3">
                                            <span :class="cn('grid size-10 shrink-0 place-items-center rounded-[10px]', TINTS[(r.rank - 1) % TINTS.length])"><Package class="size-5" :stroke-width="1.8" /></span>
                                            <div class="min-w-0">
                                                <RouterLink v-if="r.variant" :to="`/inventory/sku/${r.variant.id}`" class="font-medium text-slate-900 hover:text-blue-700 hover:underline">{{ r.variant.name }}</RouterLink>
                                                <span v-else class="text-slate-400">Sản phẩm đã xóa</span>
                                                <p class="font-mono text-[12px] text-slate-400">{{ r.variant?.sku }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="min-w-36 px-4 py-3.5">
                                        <div class="flex items-center gap-3">
                                            <span class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100"><span class="block h-full rounded-full bg-blue-600" :style="{ width: `${(r.units / maxUnits) * 100}%` }" /></span>
                                            <b class="w-10 text-right tabular-nums text-slate-900">{{ formatNumber(r.units) }}</b>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3.5 text-right tabular-nums">{{ formatNumber(r.orders) }}</td>
                                    <td class="px-4 py-3.5 text-right font-semibold tabular-nums text-slate-900">{{ formatNumber(Math.round(r.revenue)) }}</td>
                                    <td class="px-4 py-3.5 text-right tabular-nums">{{ formatNumber(r.on_hand) }}</td>
                                    <td class="px-5 py-3.5 text-right">
                                        <span
                                            v-if="lasts(r) !== null"
                                            :class="cn('whitespace-nowrap rounded-md px-2 py-0.5 text-[13px] font-medium', lasts(r)! < 14 ? 'bg-red-50 text-red-600' : lasts(r)! < 30 ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700')"
                                        >
                                            {{ lasts(r) === 0 ? 'Hết hàng' : `${formatNumber(lasts(r)!)} ngày` }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <div class="space-y-5">
                <SideCard title="Hàng tồn bán chậm" :description="`Không bán ${days} ngày, đang giữ vốn`" :badge="slow?.length ? `${formatNumber(slow.length)} SKU` : null" data-testid="slow-movers">
                    <EmptyState v-if="slowLocked" :icon="Lock" title="Cần gói có Báo cáo nâng cao" :description="slowLocked">
                        <RouterLink to="/subscription" class="text-sm font-medium text-blue-700 hover:underline">Xem các gói</RouterLink>
                    </EmptyState>
                    <div v-else-if="!slow" class="space-y-3 p-5"><Skeleton v-for="i in 4" :key="i" class="h-10" /></div>
                    <p v-else-if="!slow.length" class="px-5 py-6 text-[14px] text-slate-500">Không có hàng tồn bán chậm.</p>
                    <template v-else>
                        <ul class="divide-y divide-slate-100 px-5">
                            <li v-for="r in slow.slice(0, 5)" :key="r.variant.id" class="flex items-center gap-3 py-3">
                                <span class="grid size-10 shrink-0 place-items-center rounded-[10px] bg-slate-100 text-slate-500"><Package class="size-5" :stroke-width="1.8" /></span>
                                <div class="min-w-0 flex-1">
                                    <RouterLink :to="`/inventory/sku/${r.variant.id}`" class="block truncate text-[14px] font-medium text-slate-900 hover:text-blue-700">{{ r.variant.name }}</RouterLink>
                                    <p class="text-[12px] text-slate-500">Tồn {{ formatNumber(r.on_hand) }} · vốn {{ formatNumber(Math.round(r.stock_value)) }} đ</p>
                                </div>
                                <span class="whitespace-nowrap rounded-md bg-amber-50 px-2 py-0.5 text-[12px] font-medium text-amber-700">
                                    {{ sinceDays(r.last_sale_at) === null ? 'Chưa từng bán' : `${sinceDays(r.last_sale_at)} ngày` }}
                                </span>
                            </li>
                        </ul>
                        <button type="button" class="flex w-full items-center gap-1 border-t border-slate-100 px-5 py-3.5 text-[14px] font-medium text-blue-700 hover:underline" @click="view = 'slow'">
                            Xem tất cả hàng bán chậm <ArrowRight class="size-4" />
                        </button>
                    </template>
                </SideCard>
                <div v-if="slow?.length" :class="cn(card, 'p-5')">
                    <p class="text-[14px] text-slate-500">Vốn nằm ở hàng bán chậm</p>
                    <p class="mt-1 text-[28px] font-bold tabular-nums tracking-tight text-amber-600">{{ formatNumber(Math.round(slowValue)) }}<span class="ml-1 text-[16px] font-semibold text-slate-400">đ</span></p>
                    <p class="mt-1.5 text-[13px] text-slate-500">{{ formatNumber(slow.length) }} SKU trên {{ days }} ngày không bán. Có thể giảm giá hoặc làm combo.</p>
                </div>
            </div>
        </div>

        <section v-else :class="cn(card, 'overflow-hidden')" data-testid="slow-movers">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div>
                    <h3 class="text-[16px] font-semibold text-slate-900">Hàng bán chậm</h3>
                    <p class="mt-0.5 text-[13px] text-slate-500">SKU còn tồn nhưng không bán được trong {{ days }} ngày qua, xếp theo giá trị tồn.</p>
                </div>
                <p v-if="slow?.length" class="text-[14px] text-slate-500">
                    Vốn đang nằm: <b class="text-amber-600">{{ formatNumber(Math.round(slowValue)) }} đ</b>
                </p>
            </header>
            <EmptyState v-if="slowLocked" :icon="Lock" title="Cần gói có Báo cáo nâng cao" :description="slowLocked">
                <RouterLink to="/subscription" class="text-sm font-medium text-blue-700 hover:underline">Xem các gói</RouterLink>
            </EmptyState>
            <div v-else-if="!slow" class="space-y-3 p-5"><Skeleton v-for="i in 5" :key="i" class="h-8" /></div>
            <EmptyState v-else-if="!slow.length" :icon="Package" title="Không có hàng tồn bán chậm" description="Mọi SKU còn tồn đều có bán trong khoảng thời gian này." />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-[14px]">
                    <thead class="border-b border-slate-100 text-left text-[12px] font-semibold uppercase tracking-wider text-slate-400">
                        <tr>
                            <th class="px-5 py-3.5">Sản phẩm</th>
                            <th class="px-4 py-3.5 text-right">Tồn</th>
                            <th class="px-4 py-3.5 text-right">Đã bán</th>
                            <th class="px-4 py-3.5">Bán lần cuối</th>
                            <th class="px-4 py-3.5">Không bán</th>
                            <th class="px-5 py-3.5 text-right">Giá trị tồn</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        <tr v-for="r in slow" :key="r.variant.id" class="hover:bg-slate-50/70">
                            <td class="min-w-60 px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-[10px] bg-slate-100 text-slate-500"><Package class="size-5" :stroke-width="1.8" /></span>
                                    <div class="min-w-0">
                                        <RouterLink :to="`/inventory/sku/${r.variant.id}`" class="font-medium text-slate-900 hover:text-blue-700 hover:underline">{{ r.variant.name }}</RouterLink>
                                        <p class="font-mono text-[12px] text-slate-400">{{ r.variant.sku }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3.5 text-right tabular-nums">{{ formatNumber(r.on_hand) }}</td>
                            <td class="px-4 py-3.5 text-right tabular-nums">{{ formatNumber(r.units_sold) }}</td>
                            <td class="whitespace-nowrap px-4 py-3.5 text-slate-500">{{ r.last_sale_at ? formatDate(r.last_sale_at) : 'Chưa từng bán' }}</td>
                            <td class="px-4 py-3.5">
                                <span class="whitespace-nowrap rounded-md bg-amber-50 px-2 py-0.5 text-[13px] font-medium text-amber-700">{{ sinceDays(r.last_sale_at) === null ? 'Chưa từng bán' : `${sinceDays(r.last_sale_at)} ngày` }}</span>
                            </td>
                            <td class="px-5 py-3.5 text-right font-semibold tabular-nums text-slate-900">{{ formatNumber(Math.round(r.stock_value)) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t border-slate-200 bg-slate-50 font-semibold text-slate-900">
                        <tr>
                            <td class="px-5 py-4">Tổng {{ formatNumber(slow.length) }} SKU</td>
                            <td class="px-4 py-4 text-right tabular-nums">{{ formatNumber(slow.reduce((s, r) => s + r.on_hand, 0)) }}</td>
                            <td colspan="3" />
                            <td class="px-5 py-4 text-right tabular-nums text-amber-600">{{ formatNumber(Math.round(slowValue)) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    </div>
</template>
