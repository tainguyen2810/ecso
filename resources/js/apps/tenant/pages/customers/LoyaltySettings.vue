<script setup lang="ts">
import { computed, onMounted, provide, reactive, ref } from 'vue';
import { ArrowDown, ArrowUp, Check, ChartPie, CircleMinus, CirclePlus, Coins, Crown, History, Hourglass, Lightbulb, Plus, Trash2 } from 'lucide-vue-next';
import ReportChip from '../reports/ReportChip.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import CustomersFrame from './CustomersFrame.vue';
import { btnOutline, btnPrimary, card } from './frame';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDate, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';

/** Tích điểm và hạng khách (C5): how points are earned and spent, and the tiers by 12-month spending. */
const tenant = useTenantStore();
const toast = useToast();
const canManage = computed(() => tenant.can('promotions.manage'));

interface TierRow { id: string | null; name: string; min_spent: string | number; point_multiplier: string | number; discount_percent: string | number; customer_group_id: string; color: string; customers_count?: number }
const form = reactive({ enabled: false, earn_per: 100000 as string | number, point_value: 1000 as string | number, max_redeem_percent: 50 as string | number, expire_months: 12 as string | number, with_promotions: true });
const tiers = ref<TierRow[]>([]);
const groups = ref<{ id: string; name: string }[]>([]);
const loading = ref(true);
const saving = ref(false);
const error = ref<ApiError | null>(null);
const COLORS = ['#94a3b8', '#64748b', '#d97706', '#7c3aed', '#0ea5e9', '#e11d48'];

function fill(d: Record<string, unknown> & { tiers: Record<string, unknown>[] }) {
    Object.assign(form, { enabled: d.enabled, earn_per: d.earn_per, point_value: d.point_value, max_redeem_percent: d.max_redeem_percent, expire_months: d.expire_months, with_promotions: d.with_promotions });
    tiers.value = d.tiers.map((t) => ({
        id: t.id as string,
        name: t.name as string,
        min_spent: Number(t.min_spent),
        point_multiplier: Number(t.point_multiplier),
        discount_percent: Number(t.discount_percent),
        customer_group_id: (t.customer_group as { id: string } | null)?.id ?? '',
        color: (t.color as string | null) ?? COLORS[0]!,
        customers_count: t.customers_count as number,
    }));
}

provide('soft-fields', true);
interface Stats { held: number; holders: number; earned: number; earned_before: number; earn_orders: number; spent: number; spend_orders: number; expiring: number; expiring_customers: number }
interface Move { customer: { id: string; name: string } | null; up: boolean; note: string | null; at: string | null }
const stats = ref<Stats | null>(null);
const moves = ref<Move[]>([]);
const earnedChange = computed(() => {
    const s = stats.value;
    if (!s?.earned_before) return null;
    const d = ((s.earned - s.earned_before) * 100) / s.earned_before;
    return { up: d >= 0, text: `${d >= 0 ? '+' : '−'}${Math.abs(Math.round(d * 10) / 10).toString().replace('.', ',')}% so với tháng trước` };
});
const tierTotal = computed(() => tiers.value.reduce((a, t) => a + (t.customers_count ?? 0), 0));
const pct = (n: number) => `${(Math.round(n * 10) / 10).toString().replace('.', ',')}%`;
/** "Lên hạng Vàng (chi tiêu 12 tháng 20.000.000 ₫)" → "Lên hạng Vàng". */
const moveText = (m: Move) => (m.note ?? '').replace(/\s*\(.*\)\s*$/, '');

onMounted(async () => {
    try {
        const [l, g] = await Promise.all([api.get('/loyalty'), api.get('/customer-groups').catch(() => ({ data: { data: [] } }))]);
        fill(l.data.data);
        stats.value = l.data.meta?.stats ?? null;
        moves.value = l.data.meta?.moves ?? [];
        groups.value = g.data.data;
    } finally {
        loading.value = false;
    }
});

const groupOptions = computed(() => [{ value: '', label: 'Tạo nhóm mới khi có giảm' }, ...groups.value.map((g) => ({ value: g.id, label: g.name }))]);
function addTier() {
    const last = tiers.value[tiers.value.length - 1];
    tiers.value.push({ id: null, name: '', min_spent: last ? Number(last.min_spent) * 2 || 1000000 : 0, point_multiplier: 1, discount_percent: 0, customer_group_id: '', color: COLORS[tiers.value.length % COLORS.length]! });
}

async function save() {
    saving.value = true;
    error.value = null;
    try {
        const body = {
            ...Object.fromEntries(Object.entries(form).map(([k, v]) => [k, typeof v === 'boolean' ? v : Number(v)])),
            tiers: [...tiers.value].sort((a, b) => Number(a.min_spent) - Number(b.min_spent)).map((t) => ({
                id: t.id, name: t.name.trim(), min_spent: Number(t.min_spent), point_multiplier: Number(t.point_multiplier), discount_percent: Number(t.discount_percent),
                customer_group_id: t.customer_group_id || null, color: t.color,
            })),
        };
        fill((await api.put('/loyalty', body)).data.data);
        groups.value = (await api.get('/customer-groups')).data.data;
        toast.success('Đã lưu tích điểm và hạng khách.');
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        saving.value = false;
    }
}

const example = computed(() => {
    const per = Number(form.earn_per) || 1;
    const points = Math.floor(1000000 / per);
    return `Đơn 1.000.000 ₫ được ${points} điểm, dùng lại được ${formatMoney(points * (Number(form.point_value) || 0))}.`;
});
</script>

<template>
    <CustomersFrame page="Tích điểm và hạng" description="Áp dụng cho đơn kênh bán riêng và bán tại quầy có khách hàng. Điểm cộng khi đơn hoàn tất, trừ lại khi trả hàng hoặc huỷ.">
        <button v-if="canManage" type="button" :class="btnPrimary" :disabled="saving" data-testid="loyalty-save" @click="save"><Check class="size-4" /> {{ saving ? 'Đang lưu…' : 'Lưu thay đổi' }}</button>
    </CustomersFrame>

    <div v-if="loading" class="space-y-4"><Skeleton v-for="i in 3" :key="i" class="h-32 rounded-[14px]" /></div>
    <template v-else>
        <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="loyalty-kpis">
            <ReportKpi label="Điểm khách đang giữ" tip="Tổng điểm khách còn trong tài khoản, quy ra tiền theo giá trị 1 điểm hiện tại." :icon="Coins" primary :value="stats?.held ?? 0" unit="điểm">
                <template #chips><ReportChip tone="onblue">≈ {{ formatMoney((stats?.held ?? 0) * (Number(form.point_value) || 0)) }}</ReportChip></template>
                {{ formatNumber(stats?.holders ?? 0) }} khách có điểm
            </ReportKpi>
            <ReportKpi label="Điểm cộng tháng này" tip="Điểm khách được cộng khi đơn hoàn tất trong tháng này." :icon="CirclePlus" tone="green" :value="stats?.earned ?? 0" unit="điểm">
                <template v-if="earnedChange" #chips><ReportChip :tone="earnedChange.up ? 'up' : 'down'">{{ earnedChange.text }}</ReportChip></template>
                Từ {{ formatNumber(stats?.earn_orders ?? 0) }} đơn hoàn tất
            </ReportKpi>
            <ReportKpi label="Điểm đã dùng tháng này" tip="Điểm khách dùng để giảm tiền đơn trong tháng này (đã trừ điểm trả lại khi hủy đơn)." :icon="CircleMinus" tone="violet" :value="stats?.spent ?? 0" unit="điểm">
                <template #chips><ReportChip tone="plain">= {{ formatMoney((stats?.spent ?? 0) * (Number(form.point_value) || 0)) }} đã giảm</ReportChip></template>
                {{ formatNumber(stats?.spend_orders ?? 0) }} đơn dùng điểm
            </ReportKpi>
            <ReportKpi label="Sắp hết hạn" tip="Điểm sẽ hết hạn trong 30 ngày tới nếu khách không dùng (tính như lúc trừ điểm hết hạn mỗi đêm)." :icon="Hourglass" tone="amber" :value="stats?.expiring ?? 0" unit="điểm">
                <template #chips><ReportChip tone="amber">trong 30 ngày</ReportChip></template>
                {{ Number(form.expire_months) ? `${formatNumber(stats?.expiring_customers ?? 0)} khách · nhắc khách dùng trước khi mất` : 'Điểm đang đặt không hết hạn' }}
            </ReportKpi>
        </div>

        <div class="grid items-start gap-5 2xl:grid-cols-[minmax(0,1fr)_400px]">
            <fieldset :disabled="!canManage" class="min-w-0 space-y-5">
                <section :class="cn(card, 'p-6')">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-[17px] font-semibold text-slate-900">Tích điểm</h3>
                            <p class="text-[13px] text-slate-500">Cách khách được cộng điểm và dùng điểm</p>
                        </div>
                        <label class="flex cursor-pointer items-center gap-3 text-[14px] font-medium text-slate-700">
                            {{ form.enabled ? 'Đang bật' : 'Đang tắt' }}
                            <input
                                v-model="form.enabled"
                                type="checkbox"
                                class="relative h-6 w-11 shrink-0 cursor-pointer appearance-none rounded-full bg-slate-300 transition before:absolute before:left-0.5 before:top-0.5 before:size-5 before:rounded-full before:bg-white before:shadow before:transition checked:bg-blue-600 checked:before:translate-x-5"
                                aria-label="Bật tích điểm"
                                data-testid="loyalty-enabled"
                            />
                        </label>
                    </div>
                    <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <FormField label="Mỗi … đồng được 1 điểm" for="l-per" hint="Tính trên tiền khách trả, không gồm phí giao" :error="error?.field('earn_per')"><Input id="l-per" v-model="form.earn_per" type="number" min="1000" data-testid="loyalty-earn-per" /></FormField>
                        <FormField label="1 điểm đổi được (₫)" for="l-value" :error="error?.field('point_value')"><Input id="l-value" v-model="form.point_value" type="number" min="0" /></FormField>
                        <FormField label="Dùng điểm tối đa (% giá trị đơn)" for="l-max" :error="error?.field('max_redeem_percent')"><Input id="l-max" v-model="form.max_redeem_percent" type="number" min="0" max="100" /></FormField>
                        <FormField label="Điểm hết hạn sau (tháng)" for="l-exp" hint="0 = không hết hạn" :error="error?.field('expire_months')"><Input id="l-exp" v-model="form.expire_months" type="number" min="0" max="120" /></FormField>
                    </div>
                    <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-[12px] bg-slate-50 px-4 py-3">
                        <label class="flex items-center gap-2.5 text-[14px] text-slate-700"><input v-model="form.with_promotions" type="checkbox" class="size-4" /> Cho dùng điểm cùng lúc với khuyến mãi</label>
                        <p class="flex items-center gap-1.5 text-[13px] text-slate-600"><Lightbulb class="size-4 shrink-0 text-amber-500" /><span>{{ example }}</span></p>
                    </div>
                </section>

                <section>
                    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="text-[17px] font-semibold text-slate-900">Hạng khách</h3>
                            <p class="text-[13px] text-slate-500">
                                Xét theo tổng chi tiêu 12 tháng. Lên hạng ngay khi đủ, xét xuống hạng mỗi đêm. Mỗi hạng gắn với một nhóm khách, nên giảm giá và chính sách giá của nhóm áp dụng cho hạng. Khách đã được xếp vào nhóm khác bằng tay (VD khách sỉ) giữ nguyên nhóm.
                            </p>
                        </div>
                        <button v-if="tiers.length < 10" type="button" :class="cn(btnOutline, 'h-10 text-[14px]')" data-testid="tier-add" @click="addTier"><Plus class="size-4" /> Thêm hạng</button>
                    </div>
                    <div v-if="!tiers.length" :class="cn(card, 'px-6 py-10 text-center text-[14px] text-slate-500')">Chưa có hạng nào. Bấm "Thêm hạng" để tạo hạng đầu tiên, ví dụ Thành viên từ 0 ₫.</div>
                    <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="tier-table">
                        <div v-for="(t, i) in tiers" :key="t.id ?? `new-${i}`" class="relative overflow-hidden rounded-[14px] border border-slate-200/80 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
                            <div class="absolute inset-x-0 top-0 h-1" :style="{ background: t.color }" />
                            <div class="flex items-center gap-2.5">
                                <label class="relative grid size-10 shrink-0 cursor-pointer place-items-center rounded-[11px] text-white" :style="{ background: t.color }" title="Đổi màu hạng">
                                    <Crown class="size-5" />
                                    <input v-model="t.color" type="color" class="absolute inset-0 cursor-pointer opacity-0" aria-label="Màu hạng" />
                                </label>
                                <div class="min-w-0 flex-1">
                                    <input v-model="t.name" class="w-full rounded-[8px] border border-transparent px-1.5 py-0.5 text-[16px] font-semibold text-slate-900 outline-none hover:border-slate-200 focus:border-blue-400" placeholder="Tên hạng, VD: Vàng" aria-label="Tên hạng" :data-testid="`tier-name-${i}`" />
                                    <p class="px-1.5 text-[12px] text-slate-500">Hạng {{ i + 1 }}</p>
                                </div>
                                <button type="button" class="grid size-8 place-items-center rounded-[8px] text-slate-400 hover:bg-red-50 hover:text-red-600" :aria-label="`Bỏ hạng ${t.name}`" @click="tiers.splice(i, 1)"><Trash2 class="size-4" /></button>
                            </div>
                            <label class="mt-4 block text-[12px] text-slate-500">Chi tiêu 12 tháng từ (₫)</label>
                            <input v-model="t.min_spent" type="number" min="0" class="mt-1 h-10 w-full rounded-[10px] border border-slate-200 px-3 text-[18px] font-bold tabular-nums text-slate-900 outline-none focus:border-blue-400" aria-label="Chi tiêu 12 tháng từ" :data-testid="`tier-min-${i}`" />
                            <div class="mt-3 grid grid-cols-2 gap-2 text-[13px]">
                                <label class="rounded-[10px] bg-slate-50 px-3 py-2">
                                    <span class="text-slate-500">Giảm thêm (%)</span>
                                    <input v-model="t.discount_percent" type="number" min="0" max="100" step="0.5" class="w-full bg-transparent font-semibold text-slate-900 outline-none" aria-label="Giảm thêm" />
                                </label>
                                <label class="rounded-[10px] bg-slate-50 px-3 py-2">
                                    <span class="text-slate-500">Nhân điểm (×)</span>
                                    <input v-model="t.point_multiplier" type="number" min="0" step="0.1" class="w-full bg-transparent font-semibold text-slate-900 outline-none" aria-label="Nhân điểm" />
                                </label>
                            </div>
                            <div class="mt-3 border-t border-slate-100 pt-3">
                                <Select v-model="t.customer_group_id" class="!h-9 !text-[13px]" :options="groupOptions" aria-label="Nhóm khách" />
                                <div class="mt-2 flex items-center justify-between text-[13px]">
                                    <span class="text-slate-500">Khách trong hạng</span>
                                    <RouterLink v-if="t.id" :to="{ path: '/customers', query: { tier: t.id } }" class="font-semibold text-blue-700 hover:underline">{{ formatNumber(t.customers_count ?? 0) }} khách</RouterLink>
                                    <span v-else class="text-slate-400">Chưa lưu</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <p v-if="error?.field('tiers') || Object.keys(error?.body.errors ?? {}).some((k) => k.startsWith('tiers.'))" class="mt-3 text-sm text-red-600">
                        {{ error?.field('tiers') ?? Object.values(error?.body.errors ?? {})[0]?.[0] }}
                    </p>
                </section>
            </fieldset>

            <aside class="grid gap-5 md:grid-cols-2 2xl:grid-cols-1">
                <section :class="cn(card, 'p-5')">
                    <div class="mb-4 flex items-center justify-between"><h3 class="text-[16px] font-semibold text-slate-900">Khách theo hạng</h3><ChartPie class="size-4 text-slate-400" /></div>
                    <template v-if="tierTotal">
                        <div class="flex h-3 overflow-hidden rounded-full bg-slate-100">
                            <div v-for="(t, i) in tiers" :key="i" :style="{ width: `${((t.customers_count ?? 0) * 100) / tierTotal}%`, background: t.color }" />
                        </div>
                        <div class="mt-4 space-y-2.5">
                            <div v-for="(t, i) in tiers" :key="i" class="flex items-center gap-2.5 text-[13px]">
                                <span class="size-2.5 shrink-0 rounded-full" :style="{ background: t.color }" />
                                <span class="min-w-0 flex-1 truncate text-slate-700">{{ t.name || 'Chưa đặt tên' }}</span>
                                <span class="tabular-nums text-slate-500">{{ pct(((t.customers_count ?? 0) * 100) / tierTotal) }}</span>
                                <span class="w-14 text-right font-semibold tabular-nums text-slate-900">{{ formatNumber(t.customers_count ?? 0) }}</span>
                            </div>
                        </div>
                    </template>
                    <p v-else class="text-[13px] text-slate-500">Chưa có khách nào được xếp hạng. Hạng được xét khi đơn hoàn tất và mỗi đêm.</p>
                </section>
                <section :class="cn(card, 'p-5')">
                    <div class="mb-2 flex items-center justify-between"><h3 class="text-[16px] font-semibold text-slate-900">Lên, xuống hạng gần đây</h3><History class="size-4 text-slate-400" /></div>
                    <div v-if="moves.length" class="divide-y divide-slate-100">
                        <div v-for="(m, i) in moves" :key="i" class="flex items-center gap-3 py-2.5 text-[13px]">
                            <span :class="cn('grid size-7 shrink-0 place-items-center rounded-full', m.up ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-500')"><ArrowUp v-if="m.up" class="size-3.5" /><ArrowDown v-else class="size-3.5" /></span>
                            <div class="min-w-0 flex-1">
                                <RouterLink v-if="m.customer" :to="`/customers/${m.customer.id}`" class="block truncate font-medium text-slate-800 hover:text-blue-700">{{ m.customer.name }}</RouterLink>
                                <p class="truncate text-slate-500">{{ moveText(m) }}</p>
                            </div>
                            <span class="shrink-0 text-slate-400">{{ formatDate(m.at) }}</span>
                        </div>
                    </div>
                    <p v-else class="py-2 text-[13px] text-slate-500">Chưa có khách nào lên, xuống hạng.</p>
                </section>
            </aside>
        </div>
    </template>
</template>
