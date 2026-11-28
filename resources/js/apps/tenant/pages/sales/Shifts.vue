<script setup lang="ts">
import { computed, onMounted, provide, reactive, ref, watch } from 'vue';
import { BarChart3, Banknote, Clock, CreditCard, Lock, QrCode, Scale, Search, UsersRound, Wallet } from 'lucide-vue-next';
import ProductsHead from '../products/ui/ProductsHead.vue';
import LineTabs from '../products/ui/LineTabs.vue';
import NumberPager from '../products/ui/NumberPager.vue';
import StatusChip from '../products/ui/StatusChip.vue';
import { card, chip, chipBase, initials, row, shortMoney, th, thead } from '../products/ui/ui';
import Dialog from '@/shared/ui/Dialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import ShiftPanel from './ShiftPanel.vue';
import { differenceLabel } from './shift';
import type { CashShift } from '@/shared/types';
import DateRangeInput from '@/shared/ui/DateRangeInput.vue';

/** Ca bán hàng (mẫu 28/11): month figures, shifts with their cash difference, the open shift and differences per cashier. */
interface Summary {
    month: { collected: number; cash: number; transfer: number; shifts: number; orders: number; difference: number; mismatched: number; short: number; over: number; average: number; average_orders: number };
    open_count: number;
    open: CashShift | null;
    by_cashier: { name: string | null; shifts: number; difference: number }[];
}

provide('soft-cards', true);
const shifts = ref<CashShift[]>([]);
const loading = ref(true);
const canViewAll = ref(false);
const summary = ref<Summary | null>(null);
const filters = reactive({ tab: 'all', q: '', from: '', to: '' });
const meta = ref<{ current_page: number; last_page: number; total: number } | null>(null);
const page = ref(1);
const selected = ref<CashShift | null>(null);
const detailOpen = ref(false);

const tabs = computed(() => [
    { value: 'all', label: 'Tất cả' },
    { value: 'open', label: 'Đang mở', count: summary.value?.open_count ?? null },
    { value: 'mismatch', label: 'Có chênh lệch' },
    { value: 'closed', label: 'Đã đóng' },
]);

async function load() {
    loading.value = true;
    try {
        const params = {
            status: filters.tab === 'open' || filters.tab === 'closed' ? filters.tab : undefined,
            mismatch: filters.tab === 'mismatch' ? 1 : undefined,
            q: filters.q || undefined,
            from: filters.from || undefined,
            to: filters.to || undefined,
            page: page.value,
        };
        const { data } = await api.get('/pos/shifts', { params });
        shifts.value = data.data;
        canViewAll.value = data.meta.can_view_all;
        summary.value = data.meta.summary ?? null;
        meta.value = data.meta;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    () => [filters.tab, filters.from, filters.to],
    () => ((page.value = 1), load()),
);
watch(
    () => filters.q,
    () => {
        clearTimeout(timer);
        timer = setTimeout(() => ((page.value = 1), load()), 300);
    },
);

async function show(s: CashShift) {
    const { data } = await api.get(`/pos/shifts/${s.id}`);
    selected.value = data.data;
    detailOpen.value = true;
}
function onChanged(s: CashShift) {
    selected.value = s;
    load();
}

const time = (iso: string | null) => (iso ? new Date(iso).toLocaleString('vi-VN', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).replace(',', '') : '—');
const diffTone = (s: CashShift) => (s.status === 'open' ? 'green' : s.difference === null ? 'slate' : Number(s.difference) === 0 ? 'green' : Number(s.difference) < 0 ? 'red' : 'amber');
const methodRows = [
    { key: 'cash', label: 'Tiền mặt', icon: Banknote, tone: 'bg-emerald-50 text-emerald-600' },
    { key: 'bank_transfer', label: 'Chuyển khoản', icon: QrCode, tone: 'bg-blue-50 text-blue-600' },
    { key: 'card', label: 'Thẻ', icon: CreditCard, tone: 'bg-violet-50 text-violet-600' },
    { key: 'ewallet', label: 'Ví điện tử', icon: Wallet, tone: 'bg-slate-100 text-slate-500' },
];
const signed = (n: number) => (n > 0 ? `+${formatNumber(n)}` : n < 0 ? `−${formatNumber(-n)}` : '0');
</script>

<template>
    <ProductsHead section="Đơn hàng" title="Ca bán hàng" :description="`${canViewAll ? 'Mọi ca của các thu ngân' : 'Các ca của bạn'}: tiền đầu ca, tiền thu, đếm két và chênh lệch khi đóng ca.`">
        <DateRangeInput v-model:from="filters.from" v-model:to="filters.to" clearable placeholder="Mọi ngày" aria-label="Khoảng ngày" class="[&_button]:h-11 [&_button]:rounded-[12px]" />
    </ProductsHead>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="shift-kpis">
        <div class="rounded-[14px] bg-gradient-to-br from-blue-600 to-indigo-600 p-5 text-white shadow-sm">
            <div class="flex items-start justify-between"><span class="text-[14px] text-blue-100">Đã thu trong tháng</span><span class="grid size-10 place-items-center rounded-[11px] bg-white/15"><Banknote class="size-5" /></span></div>
            <p class="mt-1 text-[30px] font-bold leading-none tabular-nums">{{ summary ? formatMoney(summary.month.collected) : '…' }}</p>
            <span class="mt-3 inline-block rounded-md bg-white/15 px-2 py-0.5 text-[12px]">{{ summary ? `${summary.month.shifts} ca · ${formatNumber(summary.month.orders)} đơn` : '' }}</span>
            <p class="mt-2 text-[13px] text-blue-100">{{ summary ? `Tiền mặt ${shortMoney(summary.month.cash)} · chuyển khoản ${shortMoney(summary.month.transfer)}` : '' }}</p>
        </div>
        <button type="button" :class="cn(card, 'p-5 text-left', filters.tab === 'open' && 'ring-2 ring-emerald-200')" @click="filters.tab = 'open'">
            <div class="flex items-start justify-between"><span class="text-[14px] text-slate-500">Ca đang mở</span><span class="grid size-10 place-items-center rounded-[11px] bg-emerald-50 text-emerald-600"><Clock class="size-5" /></span></div>
            <p class="mt-1 text-[30px] font-bold leading-none tabular-nums">{{ summary?.open_count ?? '…' }} <span class="text-[15px] font-medium text-slate-400">ca</span></p>
            <span v-if="summary?.open" :class="cn(chipBase, chip.green, 'mt-3')">{{ summary.open.warehouse?.name ?? summary.open.number }}</span>
            <p class="mt-2 text-[13px] text-slate-500">{{ summary?.open ? `${summary.open.cashier?.name} · từ ${time(summary.open.opened_at)}` : 'Không có ca nào đang mở' }}</p>
        </button>
        <button type="button" :class="cn(card, 'p-5 text-left', filters.tab === 'mismatch' && 'ring-2 ring-red-200')" @click="filters.tab = 'mismatch'">
            <div class="flex items-start justify-between"><span class="text-[14px] text-slate-500">Chênh lệch trong tháng</span><span class="grid size-10 place-items-center rounded-[11px] bg-red-50 text-red-600"><Scale class="size-5" /></span></div>
            <p :class="cn('mt-1 text-[30px] font-bold leading-none tabular-nums', (summary?.month.difference ?? 0) < 0 ? 'text-red-600' : (summary?.month.difference ?? 0) > 0 ? 'text-amber-600' : 'text-emerald-600')">{{ summary ? signed(summary.month.difference) : '…' }}<span class="text-[15px] font-medium text-slate-400"> ₫</span></p>
            <span :class="cn(chipBase, summary?.month.mismatched ? chip.red : chip.green, 'mt-3')">{{ summary?.month.mismatched ? `${summary.month.mismatched} ca lệch` : 'Mọi ca đều khớp' }}</span>
            <p class="mt-2 text-[13px] text-slate-500">{{ summary ? `Thiếu ${formatNumber(summary.month.short)} · thừa ${formatNumber(summary.month.over)}` : '' }}</p>
        </button>
        <div :class="cn(card, 'p-5')">
            <div class="flex items-start justify-between"><span class="text-[14px] text-slate-500">Trung bình mỗi ca</span><span class="grid size-10 place-items-center rounded-[11px] bg-slate-100 text-slate-600"><BarChart3 class="size-5" /></span></div>
            <p class="mt-1 text-[30px] font-bold leading-none tabular-nums">{{ summary ? formatMoney(summary.month.average) : '…' }}</p>
            <span :class="cn(chipBase, chip.slate, 'mt-3')">{{ summary ? `${formatNumber(summary.month.average_orders)} đơn/ca` : '' }}</span>
            <p class="mt-2 text-[13px] text-slate-500">Theo các ca đã đóng trong tháng</p>
        </div>
    </div>

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_400px]">
        <div :class="cn(card, 'min-w-0')">
            <LineTabs v-model="filters.tab" :tabs="tabs" />
            <div class="px-5 py-4">
                <div class="relative">
                    <Search class="pointer-events-none absolute left-3 top-3 size-4 text-slate-400" />
                    <input v-model="filters.q" class="h-10 w-full rounded-[10px] border border-slate-200 pl-9 pr-3 text-sm outline-none focus:border-blue-400" placeholder="Tìm mã ca, thu ngân…" data-testid="shift-search" />
                </div>
            </div>
            <div v-if="loading && !shifts.length" class="space-y-2 p-4"><Skeleton v-for="i in 4" :key="i" class="h-10" /></div>
            <EmptyState v-else-if="!shifts.length" :icon="Clock" title="Chưa có ca nào" description="Thu ngân mở ca ở màn hình Bán tại quầy." />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="shift-list">
                    <thead :class="thead">
                        <tr>
                            <th :class="th">Ca, quầy</th><th :class="th">Thu ngân</th><th :class="th">Mở / đóng</th>
                            <th :class="cn(th, 'text-right')">Số đơn</th><th :class="cn(th, 'text-right')">Đã thu</th>
                            <th :class="cn(th, 'text-right')">Tiền mặt hệ thống</th><th :class="cn(th, 'text-right')">Đếm thực tế</th><th :class="cn(th, 'text-right')">Chênh lệch</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in shifts" :key="s.id" :class="cn(row, 'cursor-pointer')" @click="show(s)">
                            <td class="px-4 py-3"><span class="font-mono text-[13px] font-semibold text-blue-700">{{ s.number }}</span><span class="block text-[12px] text-slate-500">{{ s.warehouse?.name ?? '—' }}</span></td>
                            <td class="px-4 py-3">
                                <span class="flex items-center gap-2.5"><span class="grid size-8 shrink-0 place-items-center rounded-full bg-slate-100 text-[11px] font-semibold text-slate-600">{{ initials(s.cashier?.name ?? '?') }}</span>{{ s.cashier?.name }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3"><span class="tabular-nums text-slate-800">{{ time(s.opened_at) }}</span><span class="block text-[12px] text-slate-500">{{ s.closed_at ? `Đóng ${time(s.closed_at)}` : 'Chưa đóng' }}</span></td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ s.totals.orders }}</td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ formatNumber(Number(s.totals.collected)) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ formatNumber(Number(s.expected_cash ?? s.totals.expected_cash)) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ s.counted_cash !== null ? formatNumber(Number(s.counted_cash)) : '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <StatusChip :tone="diffTone(s)">{{ s.status === 'open' ? '● Đang mở' : s.difference === null ? '—' : differenceLabel(Number(s.difference)) }}</StatusChip>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <NumberPager v-if="meta && shifts.length" :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" :per-page="20" :count="shifts.length" unit="ca" @go="(p) => ((page = p), load())" />
        </div>

        <aside class="space-y-6">
            <div v-if="summary?.open" :class="card" data-testid="shift-open">
                <div class="flex items-start justify-between border-b border-slate-100 px-5 py-4">
                    <div><h3 class="font-semibold text-slate-900">Ca đang mở</h3><p class="mt-0.5 text-[13px] text-slate-500">{{ [summary.open.number, summary.open.cashier?.name, summary.open.warehouse?.name].filter(Boolean).join(' · ') }}</p></div>
                    <span class="grid size-10 place-items-center rounded-[11px] bg-emerald-50 text-emerald-600"><Clock class="size-5" /></span>
                </div>
                <div class="grid grid-cols-2 gap-3 px-5 pt-4">
                    <div class="rounded-[12px] bg-slate-50 px-4 py-3"><p class="text-[12px] text-slate-500">Tiền đầu ca</p><p class="text-[18px] font-bold tabular-nums">{{ formatNumber(Number(summary.open.opening_cash)) }}</p></div>
                    <div class="rounded-[12px] bg-emerald-50 px-4 py-3"><p class="text-[12px] text-emerald-700">Tiền mặt nên có</p><p class="text-[18px] font-bold tabular-nums text-emerald-700">{{ formatNumber(Number(summary.open.totals.expected_cash)) }}</p></div>
                </div>
                <ul class="px-5 py-2">
                    <li v-for="m in methodRows" :key="m.key" class="flex items-center gap-3 py-2 text-[14px]">
                        <span :class="cn('grid size-8 place-items-center rounded-[9px]', m.tone)"><component :is="m.icon" class="size-4" /></span>
                        <span class="flex-1 text-slate-700">{{ m.label }}</span>
                        <span class="font-semibold tabular-nums">{{ formatNumber(Number(summary.open.totals.by_method[m.key] ?? 0)) }}</span>
                    </li>
                </ul>
                <div class="px-5 pb-5">
                    <button type="button" class="flex h-11 w-full items-center justify-center gap-2 rounded-[12px] bg-blue-600 text-[15px] font-medium text-white hover:bg-blue-700" data-testid="shift-close-open" @click="show(summary.open)"><Lock class="size-4" /> Đếm két và đóng ca</button>
                </div>
            </div>
            <div v-if="canViewAll" :class="card" data-testid="shift-by-cashier">
                <div class="flex items-start justify-between border-b border-slate-100 px-5 py-4">
                    <div><h3 class="font-semibold text-slate-900">Chênh lệch theo thu ngân</h3><p class="mt-0.5 text-[13px] text-slate-500">Tháng này</p></div>
                    <span class="grid size-10 place-items-center rounded-[11px] bg-slate-100 text-slate-600"><UsersRound class="size-5" /></span>
                </div>
                <p v-if="!summary?.by_cashier.length" class="px-5 py-8 text-center text-sm text-slate-400">Chưa có ca nào đóng trong tháng.</p>
                <div v-for="c in summary?.by_cashier ?? []" :key="c.name ?? ''" class="flex items-center gap-3 border-t border-slate-100 px-5 py-3 first-of-type:border-t-0">
                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-slate-100 text-[11px] font-semibold text-slate-600">{{ initials(c.name ?? '?') }}</span>
                    <span class="min-w-0 flex-1"><span class="block truncate text-[14px] font-medium">{{ c.name }}</span><span class="block text-[12px] text-slate-500">{{ c.shifts }} ca trong tháng</span></span>
                    <span :class="cn('font-semibold tabular-nums', c.difference < 0 ? 'text-red-600' : c.difference > 0 ? 'text-amber-600' : 'text-emerald-600')">{{ c.difference === 0 ? 'Khớp' : signed(c.difference) }}</span>
                </div>
            </div>
        </aside>
    </div>

    <Dialog v-model:open="detailOpen" :title="selected ? `Ca ${selected.number}` : 'Ca'" size="lg">
        <ShiftPanel v-if="selected" :shift="selected" @changed="onChanged" />
    </Dialog>
</template>
