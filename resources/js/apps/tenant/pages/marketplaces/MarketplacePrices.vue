<script setup lang="ts">
import { computed, onMounted, provide, ref, watch } from 'vue';
import { ArrowDown, ArrowUp, Check, CheckCheck, Download, Lock, LockOpen, Pencil, Search } from 'lucide-vue-next';
import ChannelHead from './ChannelHead.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import { btn, card, pill, th } from './channel';
import Select from '@/shared/ui/Select.vue';
import Pagination from '../../components/Pagination.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import Input from '@/shared/ui/Input.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDate, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import type { PriceBoardCell, PriceBoardRow } from '@/shared/types';
import { BadgePercent, Clock, FileSpreadsheet, TrendingDown } from 'lucide-vue-next';

/** Bảng giá sàn: price now vs price from the fee table on each marketplace shop; apply, lock, price file. */
provide('soft-cards', true);
const toast = useToast();
const tenant = useTenantStore();
const canEdit = computed(() => tenant.can('marketplace.connect'));

const tab = ref<'pending' | 'loss' | 'locked' | 'all'>('pending');
const q = ref('');
const rows = ref<PriceBoardRow[]>([]);
const accounts = ref<{ id: string; shop_name: string; marketplace: string; by_api: boolean }[]>([]);
const counts = ref({ pending: 0, loss: 0, locked: 0, all: 0 });
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const rulesUpdatedAt = ref<string | null>(null);
const loading = ref(true);
const busy = ref<string | null>(null);
const selected = ref<Set<string>>(new Set());

async function load(page = 1) {
    loading.value = true;
    try {
        const { data } = await api.get('/marketplace-pricing/board', { params: { tab: tab.value, q: q.value || undefined, page } });
        rows.value = data.data;
        accounts.value = data.accounts;
        counts.value = data.counts;
        meta.value = data.meta;
        rulesUpdatedAt.value = data.rules_updated_at;
        selected.value = new Set();
    } finally {
        loading.value = false;
    }
}
onMounted(() => {
    load();
    api.get('/marketplace-pricing/rules').then(({ data }) => (rules.value = data.data)).catch(() => undefined);
});
watch(tab, () => load());
let timer: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => load(), 300);
});

const actionable = (c?: PriceBoardCell) => !!c && (c.status === 'pending' || c.status === 'loss' || c.status === 'failed');
const exportable = computed(() => rows.value.some((r) => Object.values(r.cells).some((c) => c.status === 'export')));

async function run(key: string, fn: () => Promise<string | void>) {
    busy.value = key;
    try {
        const msg = await fn();
        if (msg) toast.success(msg);
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}
const apply = (ids: string[] | null) => run(ids?.length === 1 ? `apply-${ids[0]}` : 'apply', async () => {
    const { data } = await api.post('/marketplace-pricing/board/apply', ids ? { listing_ids: ids } : { all: true });
    await load(meta.value.current_page);
    const d = data.data;
    const parts = [`Đã áp dụng ${formatNumber(d.applied)} giá.`];
    if (d.pushed) parts.push(`${formatNumber(d.pushed)} giá đang được đẩy lên sàn.`);
    if (d.exported) parts.push(`${formatNumber(d.exported)} giá đang chờ trong file giá để tải lên sàn.`);
    return parts.join(' ');
});

/** Prices that would go straight to a marketplace: shown first, sent only after "Đẩy giá lên sàn". */
type Preview = { name: string; shop: string; marketplace: string; current: number | null; next: number };
const confirmOpen = ref(false);
const confirmIds = ref<string[] | null>(null);
const preview = ref<Preview[]>([]);
const apiShops = computed(() => new Set(accounts.value.filter((a) => a.by_api).map((a) => a.id)));
function askApply(ids: string[] | null) {
    const list: Preview[] = [];
    for (const r of rows.value) {
        for (const [accountId, c] of Object.entries(r.cells)) {
            if (!actionable(c) || c.new === null || !apiShops.value.has(accountId)) continue;
            if (ids && !ids.includes(c.listing_id)) continue;
            const a = accounts.value.find((x) => x.id === accountId)!;
            list.push({ name: [r.name, r.variant_name].filter(Boolean).join(' · '), shop: a.shop_name, marketplace: a.marketplace, current: c.current, next: c.new });
        }
    }
    if (!list.length) return apply(ids);
    preview.value = list;
    confirmIds.value = ids;
    confirmOpen.value = true;
}
async function confirmApply() {
    confirmOpen.value = false;
    await apply(confirmIds.value);
}
const previewMore = computed(() => (ids: string[] | null) => !ids && counts.value.pending > rows.value.length);
const lock = (ids: string[], locked: boolean) => run(`lock-${ids[0]}`, async () => {
    await api.post('/marketplace-pricing/board/lock', { listing_ids: ids, locked });
    await load(meta.value.current_page);
    return locked ? 'Đã khóa giá, EzChat Bán hàng không đổi giá này nữa.' : 'Đã mở khóa giá.';
});
function exportFile(accountId: string) {
    window.location.href = `/api/v1/marketplace-pricing/board/export?account=${accountId}`;
}
const diffText = (c: PriceBoardCell) => (c.current !== null && c.new !== null ? c.new - c.current : null);
const tabs = computed(() => [
    { value: 'pending' as const, label: 'Chờ duyệt', count: counts.value.pending },
    { value: 'loss' as const, label: 'Lỗ sau phí', count: counts.value.loss },
    { value: 'locked' as const, label: 'Đang khoá', count: counts.value.locked },
    { value: 'all' as const, label: 'Tất cả', count: counts.value.all },
]);

/** One line per product and shop (mẫu 28/11), from the rows the board returns per variant. */
const shop = ref('');
const lines = computed(() =>
    rows.value.flatMap((row) =>
        accounts.value
            .filter((a) => row.cells[a.id] && (!shop.value || shop.value === a.id))
            .filter((a) => tab.value === 'all' || (tab.value === 'locked' ? row.cells[a.id].locked : tab.value === 'loss' ? row.cells[a.id].below_margin || row.cells[a.id].status === 'loss' : actionable(row.cells[a.id])))
            .map((account) => ({ row, account, cell: row.cells[account.id] })),
    ),
);
function toggleCell(id: string) {
    const next = new Set(selected.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    selected.value = next;
}
const outcome = (c: PriceBoardCell) => (c.new !== null && !c.locked ? c.new_outcome : c.current_outcome) ?? c.new_outcome;
function marginText(c: PriceBoardCell) {
    const m = outcome(c)?.margin_pct;
    return m === null || m === undefined ? '—' : `${(Math.round(m * 10) / 10).toLocaleString('vi-VN')}%`;
}
function marginTone(c: PriceBoardCell) {
    const m = outcome(c)?.margin_pct;
    if (m === null || m === undefined) return 'text-slate-400';
    if (m < 0) return 'text-red-600';
    if (c.min_margin_pct !== null && m < c.min_margin_pct) return 'text-amber-600';
    return 'text-emerald-600';
}

/** Fee table of each shop, from Cấu hình › Chính sách giá. */
type FeeRule = { commission_pct: number | null; payment_pct: number | null; service_pct: number | null; fixed_fee: number | null; rounding: number; min_margin_pct: number; auto_apply: boolean };
const rules = ref<{ account: { id: string; shop_name: string; marketplace: string; marketplace_name: string }; saved: boolean; shop: FeeRule }[]>([]);
const pctText = (v: number) => `${(Math.round(v * 10) / 10).toLocaleString('vi-VN')}%`;
const totalPct = (r: FeeRule) => (r.commission_pct ?? 0) + (r.payment_pct ?? 0) + (r.service_pct ?? 0);
function feeParts(r: FeeRule) {
    return [
        { label: 'Phí hoa hồng', v: r.commission_pct },
        { label: 'Phí thanh toán', v: r.payment_pct },
        { label: 'Phí dịch vụ', v: r.service_pct },
    ]
        .filter((f) => f.v)
        .map((f) => ({ label: f.label, value: pctText(f.v!) }))
        .concat(r.fixed_fee ? [{ label: 'Phí cố định mỗi đơn', value: formatMoney(r.fixed_fee) }] : []);
}
const feeLine = computed(() => rules.value.map((r) => `${r.account.shop_name} ${pctText(totalPct(r.shop))}`).join(' · '));
const uniq = <T,>(xs: T[]) => [...new Set(xs)];
const ruleSummary = computed(() => {
    const m = uniq(rules.value.map((r) => r.shop.min_margin_pct));
    const ro = uniq(rules.value.map((r) => r.shop.rounding));
    const au = uniq(rules.value.map((r) => r.shop.auto_apply));
    return {
        margin: m.length === 1 ? pctText(m[0]) : `${pctText(Math.min(...m))} – ${pctText(Math.max(...m))}`,
        rounding: ro.length === 1 ? (ro[0] <= 1 ? 'Không làm tròn' : `lên ${formatMoney(ro[0])}`) : 'Theo từng gian hàng',
        auto: au.length === 1 ? (au[0] ? 'Bật' : 'Tắt') : 'Theo từng gian hàng',
    };
});
</script>

<template>
    <ChannelHead title="Bảng giá sàn">
        <template #default>
            <button v-for="a in accounts" v-show="exportable" :key="a.id" type="button" :class="btn.outline" :data-testid="`export-${a.id}`" @click="exportFile(a.id)"><Download class="size-4" /> File giá {{ a.shop_name }}</button>
            <button v-if="canEdit && selected.size" type="button" :class="btn.outline" :disabled="busy?.startsWith('lock')" @click="lock([...selected], true)"><Lock class="size-4" /> Khoá giá đã chọn</button>
            <button v-if="canEdit" type="button" :class="btn.primary" :disabled="busy === 'apply' || !counts.pending" data-testid="apply-prices" @click="askApply(selected.size ? [...selected] : null)">
                <CheckCheck class="size-4" /> {{ selected.size ? `Áp dụng đã chọn (${selected.size})` : `Áp dụng tất cả (${counts.pending})` }}
            </button>
        </template>
    </ChannelHead>
    <p class="-mt-4 mb-6 text-[15px] text-slate-500">Giá niêm yết từng sàn tính từ giá gốc và <RouterLink to="/settings/pricing" class="text-blue-600 hover:underline">bảng phí</RouterLink>. Duyệt rồi áp dụng, hoặc xuất file giá để tải lên Seller Center.</p>

    <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <ReportKpi label="Chờ duyệt giá mới" :icon="Clock" tone="blue" plain :value="counts.pending" unit="">Giá gốc hoặc bảng phí vừa đổi</ReportKpi>
        <ReportKpi label="Lỗ hoặc dưới lãi tối thiểu" :icon="TrendingDown" tone="red" :value="counts.loss" unit="">Sau phí sàn, lãi dưới mức tối thiểu</ReportKpi>
        <ReportKpi label="Đang khoá giá" :icon="Lock" tone="slate" plain :value="counts.locked" unit="">Giữ nguyên giá sàn, không tự đổi</ReportKpi>
        <ReportKpi label="Bảng phí cập nhật" :icon="BadgePercent" tone="violet" plain :value="null" :text="rulesUpdatedAt ? formatDate(rulesUpdatedAt) : '—'" unit="">{{ feeLine || 'Khai ở Cấu hình › Chính sách giá' }}</ReportKpi>
    </div>

    <div class="grid gap-5 2xl:grid-cols-[minmax(0,1fr)_400px]">
        <div :class="[card, 'min-w-0 overflow-hidden']">
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4">
                <div class="flex max-w-full flex-wrap gap-1 rounded-[12px] bg-slate-100 p-1 text-[14px]">
                    <button v-for="t in tabs" :key="t.value" type="button" :class="pill(tab === t.value)" @click="tab = t.value">
                        {{ t.label }} <span :class="['rounded-md px-1.5 text-[12px] tabular-nums', tab === t.value ? 'bg-blue-50 text-blue-700' : 'bg-white/70 text-slate-500']">{{ formatNumber(t.count) }}</span>
                    </button>
                </div>
                <div class="ml-auto flex flex-wrap items-center gap-3">
                    <Select v-if="accounts.length > 1" v-model="shop" class="h-10 w-48" :options="[{ value: '', label: 'Tất cả gian hàng' }, ...accounts.map((a) => ({ value: a.id, label: a.shop_name }))]" />
                    <div class="relative w-full sm:w-72"><Search class="absolute left-3 top-3 size-4 text-slate-400" /><Input v-model="q" placeholder="Tìm SKU, tên sản phẩm…" class="h-10 pl-9" /></div>
                </div>
            </div>
            <div v-if="selected.size" class="flex flex-wrap items-center gap-3 border-b border-slate-100 bg-blue-50/40 px-4 py-2.5 text-[14px]">
                <span class="font-medium text-slate-800">{{ selected.size }} giá đã chọn</span>
                <button type="button" class="text-slate-500 hover:text-slate-900" @click="selected = new Set()">Bỏ chọn</button>
                <button v-if="canEdit" type="button" :class="[btn.smallPrimary, 'ml-auto']" :disabled="busy === 'apply'" @click="askApply([...selected])"><Check class="size-4" /> Áp dụng {{ selected.size }} giá</button>
            </div>
            <div v-if="loading && !rows.length" class="space-y-2 p-4"><Skeleton v-for="i in 5" :key="i" class="h-12" /></div>
            <EmptyState v-else-if="!accounts.length" :icon="FileSpreadsheet" title="Chưa có bảng phí" description="Khai bảng phí cho từng gian hàng ở Cấu hình → Thuế và chính sách giá." />
            <EmptyState v-else-if="!lines.length" :icon="FileSpreadsheet" :title="tab === 'pending' ? 'Không có giá cần duyệt' : 'Không có sản phẩm'" description="Giá trên sàn đang khớp với bảng phí." />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-[14px]" data-testid="price-board">
                    <thead class="border-b border-slate-100 text-left">
                        <tr>
                            <th v-if="canEdit" :class="[th, 'w-10']" />
                            <th :class="th">Sản phẩm</th>
                            <th :class="th">Gian hàng</th>
                            <th :class="[th, 'text-right']">Giá gốc</th>
                            <th :class="[th, 'text-right']">Giá vốn</th>
                            <th :class="[th, 'text-right']">Đang bán</th>
                            <th :class="[th, 'text-right']">Giá mới</th>
                            <th :class="[th, 'text-right']" title="Giá bán trừ phí sàn và giá vốn">Lãi sau phí</th>
                            <th :class="th" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="l in lines" :key="l.cell.listing_id" :class="l.cell.below_margin ? 'bg-red-50/40' : selected.has(l.cell.listing_id) ? 'bg-blue-50/30' : ''">
                            <td v-if="canEdit" class="px-4 py-3"><input type="checkbox" class="size-4 accent-blue-600" :checked="selected.has(l.cell.listing_id)" :aria-label="l.row.name ?? l.row.sku" @change="toggleCell(l.cell.listing_id)" /></td>
                            <td class="max-w-72 px-4 py-3">
                                <RouterLink v-if="l.row.product_id" :to="`/products/${l.row.product_id}`" class="block truncate font-medium text-slate-900 hover:text-blue-600">{{ l.row.name }}</RouterLink>
                                <span v-else class="block truncate font-medium text-slate-900">{{ l.row.name }}</span>
                                <span class="block truncate text-[12px] text-slate-500">{{ l.row.variant_name ? `${l.row.variant_name} · ` : '' }}<span class="font-mono">{{ l.row.sku }}</span></span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3"><span class="inline-flex items-center gap-2 text-slate-700"><MarketplaceLogo :marketplace="l.account.marketplace" :name="l.account.shop_name" size="sm" />{{ l.account.shop_name }}</span></td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(l.row.base) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-slate-500">{{ l.row.cost ? formatMoney(l.row.cost) : '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                                {{ l.cell.current !== null ? formatMoney(l.cell.current) : '—' }}
                                <span v-if="l.cell.below_margin && l.cell.current_outcome" class="block text-[12px] text-red-600">thu về {{ formatMoney(Math.round(l.cell.current_outcome.net)) }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                                <template v-if="l.cell.locked">
                                    <span class="text-slate-500">giữ {{ formatMoney(l.cell.current) }}</span>
                                    <span class="block text-[12px] text-slate-400">tính ra {{ formatMoney(l.cell.new) }}</span>
                                </template>
                                <template v-else-if="l.cell.new !== null">
                                    <span class="font-semibold text-slate-900">{{ formatMoney(l.cell.new) }}</span>
                                    <span v-if="l.cell.status === 'export'" class="block text-[12px] text-blue-600">trong file giá</span>
                                    <span v-else-if="l.cell.status === 'pushing'" class="block text-[12px] text-blue-600">đang đẩy lên sàn…</span>
                                    <span v-else-if="l.cell.status === 'failed'" class="block max-w-56 whitespace-normal text-[12px] text-red-600" :title="l.cell.push_error ?? ''">sàn chưa nhận: {{ l.cell.push_error }}</span>
                                    <span v-else-if="l.cell.raised && actionable(l.cell)" class="block text-[12px] text-amber-600">nâng lên lãi tối thiểu {{ l.cell.min_margin_pct }}%</span>
                                    <span v-else-if="actionable(l.cell) && diffText(l.cell) !== null" :class="['flex items-center justify-end gap-0.5 text-[12px]', diffText(l.cell)! >= 0 ? 'text-blue-600' : 'text-slate-500']"><component :is="diffText(l.cell)! >= 0 ? ArrowUp : ArrowDown" class="size-3" />{{ formatMoney(Math.abs(diffText(l.cell)!)) }}</span>
                                    <span v-else class="block text-[12px] text-slate-400">không đổi</span>
                                </template>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                                <span :class="['font-semibold', marginTone(l.cell)]">{{ outcome(l.cell)?.profit != null ? formatMoney(Math.round(outcome(l.cell)!.profit!)) : '—' }}</span>
                                <span v-if="outcome(l.cell)?.margin_pct != null" class="block text-[12px] text-slate-500">{{ marginText(l.cell) }} trên giá vốn</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <div v-if="canEdit" class="inline-flex items-center gap-1">
                                    <button v-if="actionable(l.cell)" type="button" :class="[btn.small, 'border-blue-100 bg-blue-50 text-blue-700']" :disabled="busy === `apply-${l.cell.listing_id}`" @click="askApply([l.cell.listing_id])">Áp dụng</button>
                                    <button v-if="l.cell.locked" type="button" class="grid size-8 place-items-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900" title="Mở khoá giá" aria-label="Mở khoá giá" @click="lock([l.cell.listing_id], false)"><LockOpen class="size-4" /></button>
                                    <button v-else type="button" class="grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-900" title="Khoá giá: phần mềm không đổi giá SKU này trên sàn" aria-label="Khoá giá" @click="lock([l.cell.listing_id], true)"><Lock class="size-4" /></button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination v-if="rows.length" :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="load" />
            <p class="border-t border-slate-100 px-5 py-3 text-[12px] text-slate-500">
                <template v-if="accounts.some((a) => a.by_api)"><b>Áp dụng</b> đẩy giá mới thẳng lên Shopee và TikTok Shop sau khi bạn xem lại và xác nhận. Giá đổi là giá niêm yết; chương trình giảm giá đang chạy trên sàn vẫn giữ nguyên. </template>
                <template v-if="accounts.some((a) => !a.by_api)">Sàn chưa đổi giá qua API được thì <b>Áp dụng</b> ghi giá mới vào <b>file giá</b> (Excel: mã sản phẩm, mã phân loại, SKU, giá mới) để bạn tải lên công cụ cập nhật hàng loạt của sàn. Khi sàn hiện đúng giá mới, phần mềm tự đánh dấu xong.</template>
            </p>
        </div>

        <aside class="grid content-start gap-5 md:grid-cols-2 2xl:grid-cols-1">
            <div :class="[card, 'p-5']" data-testid="price-fees">
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-[17px] font-semibold text-slate-900">Bảng phí từng gian hàng</h2>
                        <p class="text-[13px] text-slate-500">Giá mới = giá gốc cộng phí để giữ lãi mong muốn</p>
                    </div>
                    <RouterLink to="/settings/pricing" :class="btn.small"><Pencil class="size-3.5" /> Sửa</RouterLink>
                </div>
                <p v-if="!rules.length" class="text-[14px] text-slate-500">Chưa có gian hàng nào cần bảng phí.</p>
                <div v-for="r in rules" :key="r.account.id" class="mb-3 rounded-[12px] border border-slate-200 p-4 last:mb-0">
                    <div class="flex items-center gap-3">
                        <MarketplaceLogo :marketplace="r.account.marketplace" :name="r.account.shop_name" size="md" />
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-semibold text-slate-900">{{ r.account.shop_name }}</div>
                            <div class="text-[12px] text-slate-500">{{ r.account.marketplace_name }}<span v-if="!r.saved" class="text-amber-600"> · số tham khảo</span></div>
                        </div>
                        <span class="text-[17px] font-bold tabular-nums text-slate-900">{{ pctText(totalPct(r.shop)) }}</span>
                    </div>
                    <dl class="mt-3 space-y-1.5 border-t border-slate-100 pt-3 text-[13px]">
                        <div v-for="f in feeParts(r.shop)" :key="f.label" class="flex justify-between"><dt class="text-slate-500">{{ f.label }}</dt><dd class="tabular-nums text-slate-800">{{ f.value }}</dd></div>
                    </dl>
                </div>
            </div>
            <div v-if="rules.length" :class="[card, 'p-5']" data-testid="price-rules">
                <h2 class="mb-2 text-[17px] font-semibold text-slate-900">Quy tắc làm giá</h2>
                <dl class="divide-y divide-slate-100 text-[14px]">
                    <div class="flex justify-between py-2.5"><dt class="text-slate-500">Lãi tối thiểu sau phí</dt><dd class="font-semibold text-slate-900">{{ ruleSummary.margin }}</dd></div>
                    <div class="flex justify-between py-2.5"><dt class="text-slate-500">Làm tròn giá</dt><dd class="font-semibold text-slate-900">{{ ruleSummary.rounding }}</dd></div>
                    <div class="flex justify-between py-2.5"><dt class="text-slate-500">Giá khuyến mãi trên sàn</dt><dd class="font-semibold text-slate-900">Giữ nguyên</dd></div>
                    <div class="flex justify-between py-2.5"><dt class="text-slate-500">Tự áp dụng giá mới</dt><dd class="font-semibold text-slate-900">{{ ruleSummary.auto }}</dd></div>
                </dl>
            </div>
        </aside>
    </div>

    <Dialog v-model:open="confirmOpen" title="Đẩy giá lên sàn" :description="`${formatNumber(preview.length)} giá sẽ đổi ngay trên sàn. Xem lại trước khi đẩy.`" size="lg">
        <table class="w-full text-sm" data-testid="price-push-preview">
            <thead class="text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                <tr><th class="py-1.5 font-medium">Sản phẩm</th><th class="py-1.5 font-medium">Shop</th><th class="py-1.5 text-right font-medium">Đang bán</th><th class="py-1.5 text-right font-medium">Giá mới</th></tr>
            </thead>
            <tbody class="divide-y">
                <tr v-for="(p, i) in preview.slice(0, 50)" :key="i">
                    <td class="py-2 pr-3">{{ p.name }}</td>
                    <td class="py-2 pr-3"><span class="inline-flex items-center gap-1.5 whitespace-nowrap"><MarketplaceLogo :marketplace="p.marketplace" :name="p.shop" size="sm" />{{ p.shop }}</span></td>
                    <td class="whitespace-nowrap py-2 text-right tabular-nums text-muted-foreground">{{ p.current !== null ? formatMoney(p.current) : '—' }}</td>
                    <td class="whitespace-nowrap py-2 text-right font-semibold tabular-nums">{{ formatMoney(p.next) }}</td>
                </tr>
            </tbody>
        </table>
        <p v-if="preview.length > 50 || previewMore(confirmIds)" class="mt-2 text-xs text-muted-foreground">Bảng chỉ hiện các giá ở trang này; "Áp dụng tất cả" đẩy mọi giá đang chờ duyệt.</p>
        <template #footer>
            <Button variant="outline" @click="confirmOpen = false">Để sau</Button>
            <Button data-testid="confirm-price-push" @click="confirmApply">Đẩy giá lên sàn</Button>
        </template>
    </Dialog>
</template>
