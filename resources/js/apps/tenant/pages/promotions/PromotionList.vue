<script setup lang="ts">
import { computed, onMounted, provide, ref } from 'vue';
import { ChartBar, ChevronRight, CircleCheck, CirclePlay, Info, Pause, Play, Plus, Search, Ticket, TicketPercent, Trash2, TrendingUp, Zap } from 'lucide-vue-next';
import CustomersFrame from '../customers/CustomersFrame.vue';
import ReportChip from '../reports/ReportChip.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import { btnPrimary, card, pill, runningPromotions } from '../customers/frame';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDate, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import { describe, promotionKinds, stateLabels, typeLabels, type PromotionData, type PromotionState } from './promotions';

/** Khuyến mãi (C4, mẫu 28/11): the shop's promotions for its own channels and the POS, with how often each was used. */
const tenant = useTenantStore();
const toast = useToast();
const canManage = computed(() => tenant.can('promotions.manage'));
const rows = ref<PromotionData[]>([]);
const channels = ref<{ id: string; name: string }[]>([]);
const loading = ref(true);
const loadError = ref<string | null>(null);
const state = ref<PromotionState | ''>('');
const q = ref('');
const kindFilter = ref('');
const channelFilter = ref('');
provide('soft-fields', true);

interface MonthStats { amount: string; orders: number; revenue: string; revenue_before: string; own_revenue: string; code_uses: number; top: { id: string | null; amount: string; uses: number }[] }
const month = ref<MonthStats | null>(null);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const [p, c] = await Promise.all([api.get('/promotions'), api.get('/sales-channels').catch(() => ({ data: { data: [] } }))]);
        rows.value = p.data.data;
        month.value = p.data.meta ?? null;
        channels.value = c.data.data;
        runningPromotions.value = rows.value.filter((r) => r.state === 'running').length;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

const kindOf = (p: PromotionData) => (p.type === 'order' && p.code ? 'code' : p.type);
const counts = computed(() => Object.fromEntries((Object.keys(stateLabels) as PromotionState[]).map((s) => [s, rows.value.filter((r) => r.state === s).length])));
const shown = computed(() => {
    const term = q.value.trim().toLowerCase();
    return rows.value.filter(
        (r) =>
            (!state.value || r.state === state.value) &&
            (!kindFilter.value || kindOf(r) === kindFilter.value) &&
            (!channelFilter.value || !r.channel_ids.length || r.channel_ids.includes(channelFilter.value)) &&
            (!term || r.name.toLowerCase().includes(term) || (r.code ?? '').toLowerCase().includes(term)),
    );
});
const states = computed(() => [{ value: '' as const, label: 'Tất cả', n: rows.value.length }, ...(['running', 'scheduled', 'paused', 'ended'] as PromotionState[]).map((k) => ({ value: k, label: stateLabels[k].label, n: counts.value[k] }))]);

const badge: Record<string, [string, string]> = {
    order: ['%', 'bg-blue-50 text-blue-700'],
    product: ['SP', 'bg-amber-50 text-amber-700'],
    gift: ['X+Y', 'bg-emerald-50 text-emerald-700'],
    code: ['MÃ', 'bg-violet-50 text-violet-700'],
};
const stateTone: Record<PromotionState, string> = { running: 'bg-emerald-50 text-emerald-700', scheduled: 'bg-blue-50 text-blue-700', paused: 'bg-amber-50 text-amber-700', ended: 'bg-slate-100 text-slate-500' };

function where(p: PromotionData): string {
    if (!p.channel_ids.length) return 'Mọi kênh riêng';
    return p.channel_ids.map((id) => channels.value.find((c) => c.id === id)?.name ?? '?').join(', ');
}
function when(p: PromotionData): string {
    if (!p.starts_at && !p.ends_at) return 'Không thời hạn';
    return `${p.starts_at ? formatDate(p.starts_at) : '…'} – ${p.ends_at ? formatDate(p.ends_at) : '…'}`;
}
/** How far along its dates a promotion is (0..1), and the days left when it ends soon. */
function progress(p: PromotionData): { part: number; left: number | null } | null {
    if (!p.starts_at || !p.ends_at) return null;
    const start = new Date(p.starts_at).getTime();
    const end = new Date(p.ends_at).getTime();
    const now = Date.now();
    const part = end > start ? Math.min(1, Math.max(0, (now - start) / (end - start))) : 1;
    const left = Math.ceil((end - now) / 86400000);
    return { part, left: p.state === 'running' && left >= 0 && left <= 3 ? left : null };
}
const money = (v: string | number | null | undefined) => formatNumber(Math.round(Number(v ?? 0)));
const revenueChange = computed(() => {
    const now = Number(month.value?.revenue ?? 0);
    const before = Number(month.value?.revenue_before ?? 0);
    if (!before) return null;
    const d = ((now - before) * 100) / before;
    return { up: d >= 0, text: `${d >= 0 ? '+' : '−'}${Math.abs(Math.round(d * 10) / 10).toString().replace('.', ',')}% so với tháng trước` };
});
const share = computed(() => {
    const own = Number(month.value?.own_revenue ?? 0);
    return own && Number(month.value?.amount) ? `${(Math.round((Number(month.value?.amount ?? 0) * 1000) / own) / 10).toString().replace('.', ',')}% doanh thu kênh riêng` : null;
});
const exhausted = computed(() => rows.value.filter((r) => r.code && r.usage_limit && r.uses >= r.usage_limit).length);
const topCode = computed(() => [...rows.value].filter((r) => r.code).sort((a, b) => b.uses - a.uses)[0] ?? null);
const best = computed(() => {
    const list = (month.value?.top ?? []).map((t) => ({ ...t, promo: rows.value.find((r) => r.id === t.id) })).filter((t) => t.promo && Number(t.amount) > 0);
    const max = Math.max(1, ...list.map((t) => Number(t.amount)));
    return list.map((t) => ({ name: t.promo!.name, amount: Number(t.amount), width: (Number(t.amount) * 100) / max }));
});

async function toggle(p: PromotionData) {
    try {
        await api.put(`/promotions/${p.id}`, { status: p.status === 'active' ? 'paused' : 'active' });
        toast.success(p.status === 'active' ? 'Đã tạm dừng khuyến mãi.' : 'Đã bật lại khuyến mãi.');
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}

async function remove(p: PromotionData) {
    if (!window.confirm(`Xóa khuyến mãi ${p.name}?`)) return;
    try {
        await api.delete(`/promotions/${p.id}`);
        toast.success('Đã xóa khuyến mãi.');
        await load();
    } catch (e) {
        toast.error((e as ApiError).field('promotion') ?? (e as ApiError).message);
    }
}
</script>

<template>
    <CustomersFrame page="Khuyến mãi" description="Khuyến mãi cho kênh bán riêng và bán tại quầy. Đơn sàn Shopee, TikTok Shop theo khuyến mãi của sàn.">
        <button v-if="canManage" type="button" :class="btnPrimary" data-testid="add-promotion" @click="$router.push('/promotions/new')"><Plus class="size-4" /> Tạo khuyến mãi</button>
    </CustomersFrame>

    <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="promotion-kpis">
        <ReportKpi label="Tiền đã giảm cho khách" tip="Tổng tiền khuyến mãi các đơn tháng này đã nhận (không tính đơn hủy)." :icon="TicketPercent" primary :value="month ? Math.round(Number(month.amount)) : null">
            <template #chips><ReportChip tone="onblue">{{ formatNumber(month?.orders ?? 0) }} đơn có khuyến mãi</ReportChip></template>
            Tháng này<template v-if="share"> · chiếm {{ share }}</template>
        </ReportKpi>
        <ReportKpi label="Đang chạy" :icon="CirclePlay" tone="green" :value="counts.running ?? 0" unit="khuyến mãi">
            <template #chips>
                <ReportChip v-if="counts.scheduled" tone="blue">{{ counts.scheduled }} sắp chạy</ReportChip>
                <ReportChip v-if="counts.paused" tone="amber">{{ counts.paused }} tạm dừng</ReportChip>
            </template>
            Đơn nhận khuyến mãi có lợi nhất cho khách
        </ReportKpi>
        <ReportKpi label="Doanh thu từ đơn có khuyến mãi" tip="Tổng tiền các đơn tháng này có nhận ít nhất một khuyến mãi." :icon="TrendingUp" tone="violet" plain :value="month ? Math.round(Number(month.revenue)) : null">
            <template v-if="revenueChange" #chips><ReportChip :tone="revenueChange.up ? 'up' : 'down'">{{ revenueChange.text }}</ReportChip></template>
            <template v-if="month?.orders">Giá trị trung bình {{ money(Number(month.revenue) / month.orders) }} ₫/đơn</template>
            <template v-else>Chưa có đơn dùng khuyến mãi tháng này</template>
        </ReportKpi>
        <ReportKpi label="Mã giảm giá đã dùng" tip="Số lần khách dùng mã giảm giá trong tháng này." :icon="Ticket" tone="amber" :value="month?.code_uses ?? null" unit="lượt">
            <template v-if="exhausted" #chips><ReportChip tone="red">{{ exhausted }} mã đã hết lượt</ReportChip></template>
            <template v-if="topCode && topCode.uses">{{ topCode.code }} dùng nhiều nhất: {{ formatNumber(topCode.uses) }} lượt</template>
            <template v-else>Tạo mã để khách đọc khi mua</template>
        </ReportKpi>
    </div>

    <div class="grid items-start gap-5 2xl:grid-cols-[minmax(0,1fr)_380px]">
        <section :class="cn(card, 'min-w-0 overflow-hidden')">
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3.5">
                <div class="flex overflow-x-auto rounded-[12px] bg-slate-100 p-1 text-[14px]" role="group" aria-label="Trạng thái" data-testid="promotion-states">
                    <button v-for="t in states" :key="t.value" type="button" :class="pill(state === t.value)" :aria-pressed="state === t.value" @click="state = t.value">
                        {{ t.label }} <span class="tabular-nums text-slate-400">{{ t.n }}</span>
                    </button>
                </div>
                <div class="relative min-w-40 flex-1">
                    <Search class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input v-model="q" class="h-11 w-full rounded-[10px] border border-slate-200 bg-white pl-10 pr-3 text-[15px] outline-none placeholder:text-slate-400 focus:border-blue-400" placeholder="Tên hoặc mã giảm giá" aria-label="Tìm khuyến mãi" />
                </div>
                <Select v-model="kindFilter" class="w-full sm:w-44" aria-label="Loại" :options="[{ value: '', label: 'Mọi loại' }, ...promotionKinds.map((k) => ({ value: k.key, label: k.title }))]" />
                <Select v-if="channels.length" v-model="channelFilter" class="w-full sm:w-40" aria-label="Kênh" :options="[{ value: '', label: 'Mọi kênh' }, ...channels.map((c) => ({ value: c.id, label: c.name }))]" />
            </div>
            <div v-if="loading && !rows.length" class="space-y-3 p-5"><Skeleton v-for="i in 4" :key="i" class="h-12" /></div>
            <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
            <EmptyState v-else-if="!rows.length" :icon="TicketPercent" title="Chưa có khuyến mãi" description="Tạo giảm giá theo đơn, theo sản phẩm, mua X tặng Y hoặc mã giảm giá. Đơn tạo tay và bán tại quầy tự áp khi đủ điều kiện.">
                <button v-if="canManage" type="button" :class="btnPrimary" @click="$router.push('/promotions/new')"><Plus class="size-4" /> Tạo khuyến mãi</button>
            </EmptyState>
            <EmptyState v-else-if="!shown.length" :icon="Search" title="Không có khuyến mãi phù hợp" description="Đổi trạng thái hoặc bộ lọc để xem khuyến mãi khác." />
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[960px]" data-testid="promotion-table">
                    <thead class="bg-slate-50/70 text-left text-[12px] font-semibold uppercase tracking-wider text-slate-400">
                        <tr>
                            <th class="whitespace-nowrap py-3 pl-5 pr-4 font-semibold">Khuyến mãi</th>
                            <th class="whitespace-nowrap px-4 py-3 font-semibold">Áp dụng</th>
                            <th class="whitespace-nowrap px-4 py-3 font-semibold">Thời gian</th>
                            <th class="whitespace-nowrap px-4 py-3 text-right font-semibold">Đã dùng</th>
                            <th class="whitespace-nowrap px-4 py-3 text-right font-semibold">Đã giảm</th>
                            <th class="whitespace-nowrap px-4 py-3 font-semibold">Trạng thái</th>
                            <th v-if="canManage" class="px-3 py-3" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in shown" :key="p.id" :class="cn('border-t border-slate-100 hover:bg-slate-50/70', p.state === 'ended' && 'opacity-60')">
                            <td class="py-3.5 pl-5 pr-4">
                                <div class="flex items-center gap-3">
                                    <span :class="cn('grid h-10 w-11 shrink-0 place-items-center rounded-[10px] text-[12px] font-bold', badge[kindOf(p)]![1])" :title="p.code ? 'Mã giảm giá' : typeLabels[p.type]">{{ badge[kindOf(p)]![0] }}</span>
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <RouterLink v-if="canManage" :to="`/promotions/${p.id}`" class="text-[14px] font-semibold text-blue-700 hover:underline">{{ p.name }}</RouterLink>
                                            <span v-else class="text-[14px] font-semibold text-slate-900">{{ p.name }}</span>
                                            <span v-if="p.code" class="rounded-md border border-dashed border-violet-300 bg-violet-50 px-2 py-0.5 font-mono text-[12px] font-semibold text-violet-700">{{ p.code }}</span>
                                        </div>
                                        <p class="max-w-[420px] truncate text-[12px] text-slate-500">{{ describe(p) }}<template v-if="p.per_customer_limit"> · Mỗi khách {{ p.per_customer_limit }} lần</template></p>
                                    </div>
                                </div>
                            </td>
                            <td class="max-w-[220px] px-4 py-3.5 text-[14px] text-slate-600"><span class="line-clamp-2">{{ where(p) }}</span></td>
                            <td class="whitespace-nowrap px-4 py-3.5">
                                <p class="text-[14px] text-slate-700">{{ when(p) }}</p>
                                <template v-if="progress(p)">
                                    <div class="mt-1.5 h-1 w-36 rounded-full bg-slate-100">
                                        <div :class="cn('h-1 rounded-full', p.state === 'ended' ? 'bg-slate-300' : progress(p)!.left !== null ? 'bg-amber-500' : 'bg-blue-500')" :style="{ width: `${progress(p)!.part * 100}%` }" />
                                    </div>
                                    <p v-if="progress(p)!.left !== null" class="mt-1 text-[12px] font-medium text-amber-600">{{ progress(p)!.left ? `Còn ${progress(p)!.left} ngày` : 'Kết thúc hôm nay' }}</p>
                                </template>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3.5 text-right">
                                <p class="text-[14px] font-semibold tabular-nums text-slate-900">{{ formatNumber(p.uses) }}</p>
                                <p v-if="p.usage_limit" :class="cn('text-[12px]', p.uses >= p.usage_limit ? 'font-medium text-red-600' : 'text-slate-500')">
                                    {{ p.uses >= p.usage_limit ? 'Hết lượt' : `còn ${p.usage_limit - p.uses}/${p.usage_limit} lượt` }}
                                </p>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3.5 text-right text-[14px] font-semibold tabular-nums text-slate-900">{{ money(p.amount) }}</td>
                            <td class="px-4 py-3.5"><span :class="cn('inline-flex whitespace-nowrap rounded-md px-2 py-0.5 text-[12px] font-medium', stateTone[p.state])">{{ stateLabels[p.state].label }}</span></td>
                            <td v-if="canManage" class="whitespace-nowrap px-3 py-3.5 text-right">
                                <button
                                    v-if="p.state !== 'ended'"
                                    type="button"
                                    class="inline-grid size-8 place-items-center rounded-[8px] text-slate-500 hover:bg-slate-100 hover:text-slate-900"
                                    :aria-label="p.status === 'active' ? `Tạm dừng ${p.name}` : `Bật ${p.name}`"
                                    :title="p.status === 'active' ? 'Tạm dừng' : 'Bật lại'"
                                    @click="toggle(p)"
                                >
                                    <Pause v-if="p.status === 'active'" class="size-4" /><Play v-else class="size-4" />
                                </button>
                                <button
                                    type="button"
                                    class="inline-grid size-8 place-items-center rounded-[8px] text-slate-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-40 disabled:hover:bg-transparent"
                                    :aria-label="`Xóa ${p.name}`"
                                    :disabled="p.uses > 0"
                                    :title="p.uses > 0 ? 'Đã có đơn dùng, chỉ tạm dừng được' : 'Xóa'"
                                    @click="remove(p)"
                                >
                                    <Trash2 class="size-4" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <aside class="grid gap-5 md:grid-cols-2 2xl:grid-cols-1">
            <section v-if="canManage" :class="cn(card, 'p-5')">
                <div class="mb-4 flex items-center justify-between"><h3 class="text-[16px] font-semibold text-slate-900">Tạo nhanh</h3><Zap class="size-4 text-slate-400" /></div>
                <div class="grid gap-2.5">
                    <RouterLink v-for="k in promotionKinds" :key="k.key" :to="{ path: '/promotions/new', query: { kind: k.key } }" class="flex items-center gap-3 rounded-[12px] border border-slate-200 px-3.5 py-3 hover:border-blue-300">
                        <span :class="cn('grid h-10 w-11 shrink-0 place-items-center rounded-[10px] text-[12px] font-bold', badge[k.key]![1])">{{ badge[k.key]![0] }}</span>
                        <span class="min-w-0 flex-1"><span class="block text-[14px] font-semibold text-slate-800">{{ k.title }}</span><span class="block text-[12px] text-slate-500">{{ k.hint }}</span></span>
                        <ChevronRight class="size-4 text-slate-300" />
                    </RouterLink>
                </div>
            </section>
            <section v-if="best.length" :class="cn(card, 'p-5')">
                <div class="mb-4 flex items-center justify-between"><h3 class="text-[16px] font-semibold text-slate-900">Giảm nhiều nhất tháng này</h3><ChartBar class="size-4 text-slate-400" /></div>
                <div class="space-y-3.5">
                    <div v-for="b in best" :key="b.name">
                        <div class="flex justify-between gap-3 text-[13px]"><span class="truncate text-slate-700">{{ b.name }}</span><span class="font-semibold tabular-nums text-slate-900">{{ money(b.amount) }}</span></div>
                        <div class="mt-1.5 h-1.5 rounded-full bg-slate-100"><div class="h-1.5 rounded-full bg-blue-500" :style="{ width: `${b.width}%` }" /></div>
                    </div>
                </div>
            </section>
            <section :class="cn(card, 'p-5')">
                <div class="mb-4 flex items-center justify-between"><h3 class="text-[16px] font-semibold text-slate-900">Cách áp dụng</h3><Info class="size-4 text-slate-400" /></div>
                <ul class="space-y-2.5 text-[13px] text-slate-600">
                    <li class="flex gap-2"><CircleCheck class="mt-0.5 size-4 shrink-0 text-emerald-500" /><span>Mỗi đơn nhận <b>1 khuyến mãi giảm tiền</b> tự động, cái có lợi nhất cho khách.</span></li>
                    <li class="flex gap-2"><CircleCheck class="mt-0.5 size-4 shrink-0 text-emerald-500" /><span>Cộng thêm mọi <b>quà tặng</b> đủ điều kiện và <b>1 mã giảm giá</b> khách đọc.</span></li>
                    <li class="flex gap-2"><CircleCheck class="mt-0.5 size-4 shrink-0 text-emerald-500" /><span>Khuyến mãi đã có đơn dùng chỉ tạm dừng được, không xoá.</span></li>
                    <li class="flex gap-2"><CircleCheck class="mt-0.5 size-4 shrink-0 text-emerald-500" /><span>Đơn sàn Shopee, TikTok Shop theo khuyến mãi của sàn.</span></li>
                </ul>
            </section>
        </aside>
    </div>
</template>
