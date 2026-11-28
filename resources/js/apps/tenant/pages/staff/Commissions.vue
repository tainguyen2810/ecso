<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { HandCoins, Info, MessageCircle, Plus, Store, Target, Trophy, Wrench } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import ReportChip from '../reports/ReportChip.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import SideCard from '../reports/SideCard.vue';
import FrameButton from './FrameButton.vue';
import StaffFrame from './StaffFrame.vue';
import { monthLabel, RULE_KINDS, ruleValue, thisMonth, type CommissionMonth, type CommissionRule, type RuleKind } from './staff';

/**
 * Nhân viên › Hoa hồng: the ways commission is paid (set once, switched on and off) and each member's figures of the
 * month so far. Returns come off by themselves. A member sees only their own figures.
 */
const route = useRoute();
const router = useRouter();
const toast = useToast();
const month = ref(typeof route.query.month === 'string' && /^\d{4}-\d{2}$/.test(route.query.month) ? route.query.month : thisMonth());
const data = ref<CommissionMonth | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const members = ref<{ id: string; name: string }[]>([]);
const categories = ref<{ id: string; name: string; parent?: { name: string } | null }[]>([]);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        data.value = (await api.get('/staff/commissions', { params: { month: month.value } })).data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
watch(month, (m) => {
    router.replace({ query: { month: m } });
    load();
});
onMounted(load);

const icons = { counter: Store, online: MessageCircle, category_unit: Wrench, store_bonus: Trophy } as const;
const who = (r: CommissionRule) => (r.user_ids === null ? 'Tất cả' : r.user_ids.map((id) => members.value.find((m) => m.id === id)?.name ?? '…').join(', ') || 'Chưa chọn ai');
const best = computed(() => Math.max(1, ...(data.value?.members ?? []).map((m) => m.commission)));

async function toggle(r: CommissionRule) {
    try {
        await api.put(`/staff/commission-rules/${r.id}`, { is_active: !r.is_active });
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}

// Add or edit a rule.
const open = ref(false);
const editingId = ref<string | null>(null);
const form = ref({ name: '', kind: 'counter' as RuleKind, rate: 1, amount: 50000, category_id: '', threshold: 500000000, everyone: true, user_ids: [] as string[] });
const saving = ref(false);
async function ensureLists() {
    if (!members.value.length) members.value = (await api.get('/staff/members').catch(() => null))?.data?.data ?? [];
    if (!categories.value.length) categories.value = (await api.get('/categories').catch(() => null))?.data?.data ?? [];
}
watch(() => data.value?.manage, (m) => m && ensureLists());
function edit(r?: CommissionRule) {
    ensureLists();
    editingId.value = r?.id ?? null;
    form.value = r
        ? { name: r.name, kind: r.kind, rate: r.rate, amount: r.amount, category_id: r.category_id ?? '', threshold: r.threshold, everyone: r.user_ids === null, user_ids: r.user_ids ?? [] }
        : { name: '', kind: 'counter', rate: 1, amount: 50000, category_id: '', threshold: 500000000, everyone: true, user_ids: [] };
    open.value = true;
}
async function save() {
    saving.value = true;
    try {
        const f = form.value;
        const body = { name: f.name || RULE_KINDS[f.kind].label, kind: f.kind, rate: f.rate, amount: f.amount, category_id: f.category_id || null, threshold: f.threshold, user_ids: f.everyone ? null : f.user_ids };
        if (editingId.value) await api.put(`/staff/commission-rules/${editingId.value}`, body);
        else await api.post('/staff/commission-rules', body);
        toast.success('Đã lưu cách tính hoa hồng.');
        open.value = false;
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        saving.value = false;
    }
}
async function remove() {
    if (!editingId.value) return;
    try {
        await api.delete(`/staff/commission-rules/${editingId.value}`);
        open.value = false;
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}

// Headline figures, ranking and the weekly bars.
const sortBy = ref<'commission' | 'sales'>('commission');
const ranked = computed(() => [...(data.value?.members ?? [])].sort((a, b) => b[sortBy.value] - a[sortBy.value]));
const total = computed(() => (data.value?.members ?? []).reduce((s, m) => s + m.commission, 0));
const activeRules = computed(() => (data.value?.rules ?? []).filter((r) => r.is_active));
const withTarget = computed(() => (data.value?.members ?? []).filter((m) => m.target_pct !== null));
const reached = computed(() => withTarget.value.filter((m) => (m.target_pct ?? 0) >= 100).sort((a, b) => (b.target_pct ?? 0) - (a.target_pct ?? 0)));
const bonus = computed(() => (data.value?.rules ?? []).find((r) => r.kind === 'store_bonus' && r.is_active) ?? null);
const share = (part: number) => (data.value?.store_revenue ? Math.round((part / data.value.store_revenue) * 100) : 0);
const weekMax = computed(() => Math.max(1, ...(data.value?.weeks ?? []).map((w) => w.amount)));
const short = (n: number) => (Math.abs(n) >= 1e6 ? `${String(Math.round(n / 1e5) / 10).replace('.', ',')} tr` : formatNumber(n));
const first = (name: string) => name.trim().split(/\s+/).pop() ?? name;
const initials = (name: string) => name.trim().split(/\s+/).slice(-2).map((w) => w[0]).join('').toUpperCase();
const medal = ['bg-amber-100 text-amber-700', 'bg-slate-200 text-slate-600', 'bg-orange-100 text-orange-700'];
</script>

<template>
    <StaffFrame v-model:month="month" page="commissions" title="Hoa hồng" description="Tự tính từ đơn đã giao của kênh riêng (quầy, chat, web). Hàng khách trả tự trừ lại.">
        <template #actions>
            <FrameButton v-if="data?.manage" primary data-testid="rule-add" @click="edit()"><Plus /> Thêm cách tính</FrameButton>
        </template>
    </StaffFrame>

    <div v-if="loading && !data" class="space-y-5"><div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4"><Skeleton v-for="i in 4" :key="i" class="h-36" /></div><Skeleton class="h-72" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <template v-else-if="data">
        <div class="mb-5 grid gap-5 sm:grid-cols-2 xl:grid-cols-4" data-testid="commission-kpis">
            <ReportKpi primary :label="data.manage ? 'Hoa hồng tạm tính' : 'Hoa hồng của tôi'" :icon="HandCoins" :value="total" tip="Cộng hoa hồng theo các cách tính đang bật, trên đơn đã giao trong tháng. Đơn khách trả tự trừ lại.">
                <template #chips><ReportChip tone="onblue">{{ month === thisMonth() ? 'đến hôm nay' : 'cả tháng' }}</ReportChip></template>
                {{ data.manage ? `${data.members.length} nhân viên · ${activeRules.length} cách tính đang bật` : monthLabel(month) }}
            </ReportKpi>
            <ReportKpi v-if="data.manage" label="Doanh số kênh riêng" :icon="Store" tone="green" :value="data.store_revenue" tip="Tiền hàng (sau giảm giá, chưa phí ship) của đơn quầy, chat, web đã giao trong tháng, trừ hàng khách trả. Đơn sàn không tính.">
                <template #chips><ReportChip>Quầy {{ share(data.store_counter) }}%</ReportChip><ReportChip>Chat, web {{ share(data.store_online) }}%</ReportChip></template>
                Đơn đã giao, trừ hàng khách trả
            </ReportKpi>
            <ReportKpi v-else label="Doanh số của tôi" :icon="Store" tone="green" :value="data.members[0]?.sales ?? 0">Đơn đã giao, trừ hàng khách trả</ReportKpi>
            <ReportKpi label="Đạt mục tiêu" :icon="Target" tone="violet" plain :value="null" :text="withTarget.length ? `${reached.length}/${withTarget.length}` : '—'" tip="Nhân viên có doanh số tháng từ mục tiêu trở lên. Mục tiêu đặt trong Hồ sơ lương (trang Bảng lương).">
                <template #chips>
                    <ReportChip v-for="m in reached.slice(0, 2)" :key="m.id" tone="up">{{ first(m.name) }} {{ m.target_pct }}%</ReportChip>
                    <ReportChip v-if="!withTarget.length">Chưa đặt mục tiêu</ReportChip>
                </template>
                Mục tiêu đặt trong Hồ sơ lương
            </ReportKpi>
            <ReportKpi v-if="bonus" label="Thưởng vượt mức" :icon="Trophy" tone="amber" :value="null" :text="`+${String(bonus.rate).replace('.', ',')}%`" tip="Cộng thêm % doanh số mỗi người khi doanh số tháng của cửa hàng vượt mức.">
                <template #chips><ReportChip :tone="data.store_revenue > bonus.threshold ? 'up' : 'amber'">{{ data.store_revenue > bonus.threshold ? `Đã vượt mức ${short(bonus.threshold)}` : `Còn thiếu ${short(bonus.threshold - data.store_revenue)}` }}</ReportChip></template>
                Khi doanh số cửa hàng vượt {{ formatMoney(bonus.threshold) }}
            </ReportKpi>
            <ReportKpi v-else label="Thưởng vượt mức" :icon="Trophy" tone="amber" :value="null" text="Chưa bật">Thêm cách tính "Thưởng vượt mức" để thưởng khi cửa hàng vượt doanh số</ReportKpi>
        </div>

        <div :class="cn('grid items-start gap-5', data.manage && 'xl:grid-cols-[minmax(0,1fr)_25rem]')">
            <section class="min-w-0 overflow-hidden rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4 sm:px-6">
                    <div>
                        <h2 class="text-[16px] font-semibold text-slate-900">{{ data.manage ? 'Hoa hồng từng nhân viên' : 'Hoa hồng của tôi' }}</h2>
                        <p class="mt-0.5 text-[13px] text-slate-500">Tạm tính {{ monthLabel(month).toLowerCase() }}{{ month === thisMonth() ? ' đến hôm nay' : '' }}.{{ data.manage ? ' Nhân viên tự xem số của mình ở trang này.' : '' }}</p>
                    </div>
                    <div v-if="data.members.length > 1" class="flex rounded-[12px] bg-slate-100 p-1 text-[14px]" role="group" aria-label="Xếp theo">
                        <button type="button" :class="cn('rounded-[9px] px-3.5 py-1.5 text-slate-500', sortBy === 'commission' && 'bg-white font-semibold text-slate-900 shadow-sm')" @click="sortBy = 'commission'">Theo hoa hồng</button>
                        <button type="button" :class="cn('rounded-[9px] px-3.5 py-1.5 text-slate-500', sortBy === 'sales' && 'bg-white font-semibold text-slate-900 shadow-sm')" @click="sortBy = 'sales'">Theo doanh số</button>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[860px] table-fixed" data-testid="commission-members">
                        <colgroup><col class="w-[64px]" /><col class="w-[220px]" /><col /><col class="w-[130px]" /><col class="w-[180px]" /><col class="w-[130px]" /></colgroup>
                        <thead class="border-b border-slate-100 bg-slate-50/60 text-left text-[12px] uppercase tracking-wider text-slate-400">
                            <tr><th class="py-3 pl-5 font-semibold sm:pl-6">#</th><th class="py-3 pr-4 font-semibold">Nhân viên</th><th class="py-3 pr-4 font-semibold">Chi tiết theo cách tính</th><th class="py-3 pr-4 text-right font-semibold">Doanh số</th><th class="py-3 pr-4 font-semibold">Mục tiêu</th><th class="py-3 pr-5 text-right font-semibold sm:pr-6">Hoa hồng</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="(m, i) in ranked" :key="m.id" class="border-b border-slate-100 align-top last:border-0">
                                <td class="py-4 pl-5 pr-2 sm:pl-6"><span :class="cn('grid size-7 place-items-center rounded-full text-[12px] font-bold', i < 3 && m[sortBy] > 0 ? medal[i] : 'text-slate-400')">{{ i + 1 }}</span></td>
                                <td class="py-4 pr-4">
                                    <div class="flex items-center gap-3">
                                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-blue-50 text-[12px] font-semibold text-blue-600">{{ initials(m.name) }}</span>
                                        <div class="min-w-0"><p class="truncate text-[14px] font-medium text-slate-900">{{ m.name }}</p><p class="truncate text-[12px] text-slate-500">{{ m.title }}</p></div>
                                    </div>
                                </td>
                                <td class="py-4 pr-4">
                                    <div v-if="m.items.length" class="flex flex-wrap gap-1.5">
                                        <span v-for="it in m.items" :key="it.rule" class="inline-flex items-center gap-1.5 rounded-[8px] bg-slate-50 px-2.5 py-1 text-[12px] text-slate-600">
                                            {{ it.name }} <span class="text-slate-400">·</span> {{ it.unit === 'units' ? `${formatNumber(it.base)} sản phẩm` : formatNumber(it.base) }}
                                            <b class="font-semibold tabular-nums text-slate-800">{{ formatNumber(it.amount) }}</b>
                                        </span>
                                    </div>
                                    <span v-else class="text-[13px] text-slate-400">Chưa có đơn tính hoa hồng</span>
                                </td>
                                <td class="py-4 pr-4 text-right text-[14px] tabular-nums text-slate-700">{{ m.sales ? formatNumber(m.sales) : '—' }}</td>
                                <td class="py-4 pr-4">
                                    <div class="flex items-center justify-between text-[12px]"><span class="text-slate-500">{{ m.target_pct !== null ? 'Mục tiêu' : 'Chưa đặt mục tiêu' }}</span><span v-if="m.target_pct !== null" :class="cn('font-semibold tabular-nums', m.target_pct >= 100 ? 'text-emerald-600' : 'text-slate-700')">{{ m.target_pct }}%</span></div>
                                    <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100">
                                        <div :class="cn('h-full rounded-full', m.target_pct === null ? 'bg-slate-300' : m.target_pct >= 100 ? 'bg-emerald-500' : 'bg-blue-500')" :style="{ width: `${Math.min(100, m.target_pct ?? Math.round((m.commission / best) * 100))}%` }" />
                                    </div>
                                </td>
                                <td class="py-4 pr-5 text-right text-[16px] font-bold tabular-nums text-emerald-600 sm:pr-6">{{ formatNumber(m.commission) }}</td>
                            </tr>
                            <tr v-if="!data.members.length"><td colspan="6" class="px-6 py-10 text-center text-[14px] text-slate-500">Chưa có nhân viên.</td></tr>
                        </tbody>
                        <tfoot v-if="data.members.length > 1" class="border-t border-slate-200 bg-slate-50/60">
                            <tr><td /><td class="py-3.5 text-[14px] font-semibold text-slate-900">Tổng {{ data.members.length }} nhân viên</td><td /><td class="py-3.5 pr-4 text-right text-[14px] font-semibold tabular-nums">{{ formatNumber(data.members.reduce((s, m) => s + m.sales, 0)) }}</td><td /><td class="py-3.5 pr-5 text-right text-[16px] font-bold tabular-nums text-emerald-600 sm:pr-6">{{ formatNumber(total) }}</td></tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <div v-if="data.manage" class="space-y-5">
                <SideCard title="Cách tính hoa hồng" :description="`${activeRules.length} đang bật · bấm để sửa`">
                    <template #actions><FrameButton sm @click="edit()"><Plus /> Thêm</FrameButton></template>
                    <ul class="px-5" data-testid="rules">
                        <li v-for="r in data.rules" :key="r.id" :class="cn('flex items-center gap-3 border-b border-slate-100 py-3.5 last:border-0', !r.is_active && 'opacity-60')">
                            <button type="button" class="flex min-w-0 flex-1 items-center gap-3 text-left" :title="`Sửa ${r.name}`" @click="edit(r)">
                                <span class="grid size-10 shrink-0 place-items-center rounded-[11px] bg-blue-50 text-blue-600"><component :is="icons[r.kind]" class="size-5" /></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[14px] font-medium text-slate-900">{{ r.name }}</span>
                                    <span class="block truncate text-[12px] text-slate-500">{{ ruleValue(r, formatMoney) }}<template v-if="r.category"> · {{ r.category }}</template></span>
                                    <span class="mt-0.5 block truncate text-[12px] text-slate-500">Áp cho: <span class="text-slate-700">{{ who(r) }}</span></span>
                                </span>
                            </button>
                            <button type="button" role="switch" :aria-checked="r.is_active" :aria-label="r.is_active ? `Tắt ${r.name}` : `Bật ${r.name}`" :class="cn('relative h-6 w-11 shrink-0 rounded-full transition', r.is_active ? 'bg-blue-600' : 'bg-slate-200')" @click="toggle(r)">
                                <span :class="cn('absolute top-0.5 size-5 rounded-full bg-white shadow transition-all', r.is_active ? 'left-[22px]' : 'left-0.5')" />
                            </button>
                        </li>
                        <li v-if="!data.rules.length" class="py-8 text-center text-[13px] text-slate-500">Chưa có cách tính nào. Bấm Thêm, VD: 1% doanh số bán tại quầy.</li>
                    </ul>
                </SideCard>
                <div class="flex gap-3 rounded-[14px] border border-blue-100 bg-blue-50/60 p-4 text-[13px] text-slate-600">
                    <Info class="mt-0.5 size-4 shrink-0 text-blue-600" />
                    <p>Chỉ tính đơn đã giao của kênh riêng (quầy, chat, web); đơn tại quầy tính khi bán xong. Khách trả hàng tự trừ lại. Đơn sàn không tính hoa hồng.</p>
                </div>
                <SideCard v-if="data.weeks.length" title="Doanh số theo tuần" description="Kênh riêng của cả cửa hàng, cơ sở tính hoa hồng">
                    <div class="px-5 pb-3" data-testid="commission-weeks">
                        <div class="flex h-[150px] items-end gap-4 pt-4">
                            <div v-for="w in data.weeks" :key="w.label" class="flex h-full flex-1 flex-col items-center justify-end gap-1.5">
                                <span class="whitespace-nowrap text-[11px] font-medium text-slate-500">{{ short(w.amount) }}</span>
                                <div class="w-full max-w-10 rounded-t-[6px] bg-emerald-500" :style="{ height: `${Math.max(2, Math.round((Math.max(0, w.amount) / weekMax) * 110))}px` }" />
                            </div>
                        </div>
                        <div class="mt-2 flex gap-4 border-t border-slate-100 pt-2">
                            <span v-for="w in data.weeks" :key="w.label" class="flex-1 text-center text-[11px] text-slate-400" :title="`${w.from} – ${w.to}`">{{ w.label }}</span>
                        </div>
                    </div>
                </SideCard>
            </div>
        </div>
    </template>

    <Dialog v-model:open="open" :title="editingId ? 'Sửa cách tính hoa hồng' : 'Thêm cách tính hoa hồng'" size="md">
        <div class="space-y-4 text-sm">
            <label class="block"><span class="mb-1 block font-medium">Kiểu tính</span>
                <Select v-model="form.kind" :options="(Object.keys(RULE_KINDS) as RuleKind[]).map((k) => ({ value: k, label: RULE_KINDS[k].label }))" />
                <span class="mt-1 block text-xs text-muted-foreground">{{ RULE_KINDS[form.kind].hint }}</span>
            </label>
            <label class="block"><span class="mb-1 block font-medium">Tên</span><Input v-model="form.name" :placeholder="RULE_KINDS[form.kind].label" maxlength="100" /></label>
            <div class="grid grid-cols-2 gap-3">
                <label v-if="form.kind !== 'category_unit'" class="block"><span class="mb-1 block font-medium">Tỷ lệ (%)</span><Input v-model.number="form.rate" type="number" step="0.1" min="0" max="100" /></label>
                <template v-else>
                    <label class="block"><span class="mb-1 block font-medium">Tiền mỗi sản phẩm (đ)</span><Input v-model.number="form.amount" type="number" min="0" step="1000" /></label>
                    <label class="block"><span class="mb-1 block font-medium">Danh mục</span>
                        <Select v-model="form.category_id" :options="[{ value: '', label: 'Chọn danh mục' }, ...categories.map((c) => ({ value: c.id, label: c.parent ? `${c.parent.name} › ${c.name}` : c.name }))]" />
                    </label>
                </template>
                <label v-if="form.kind === 'store_bonus'" class="block"><span class="mb-1 block font-medium">Khi doanh số tháng vượt (đ)</span><Input v-model.number="form.threshold" type="number" min="0" step="1000000" /></label>
            </div>
            <div>
                <label class="flex items-center gap-2 font-medium"><input v-model="form.everyone" type="checkbox" class="size-4" /> Áp cho tất cả nhân viên</label>
                <div v-if="!form.everyone" class="mt-2 grid max-h-40 gap-1.5 overflow-y-auto rounded-lg border p-2 sm:grid-cols-2">
                    <label v-for="m in members" :key="m.id" class="flex items-center gap-2"><input v-model="form.user_ids" type="checkbox" :value="m.id" class="size-4" /> {{ m.name }}</label>
                </div>
            </div>
        </div>
        <template #footer>
            <Button v-if="editingId" variant="ghost" class="mr-auto text-rose-700" @click="remove">Xoá</Button>
            <Button variant="outline" @click="open = false">Huỷ</Button>
            <Button :loading="saving" data-testid="rule-save" @click="save">Lưu</Button>
        </template>
    </Dialog>
</template>
