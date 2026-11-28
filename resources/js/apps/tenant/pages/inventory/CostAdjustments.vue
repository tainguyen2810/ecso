<script setup lang="ts">
import { computed, onMounted, provide, ref, watch } from 'vue';
import { ChevronDown, ChevronRight, FilePenLine, Info, Plus, Scale, Trash2, TrendingDown, TrendingUp, Wallet } from 'lucide-vue-next';
import ProductsHead from '../products/ui/ProductsHead.vue';
import HeadButton from '../products/ui/HeadButton.vue';
import SearchField from '../products/ui/SearchField.vue';
import NumberPager from '../products/ui/NumberPager.vue';
import ReportKpi from '../reports/ReportKpi.vue';
import ReportChip from '../reports/ReportChip.vue';
import SideCard from '../reports/SideCard.vue';
import { card, th, thead } from '../products/ui/ui';
import SkuPicker from '../../components/SkuPicker.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import { useIdempotencyKey } from '@/shared/composables/useIdempotencyKey';
import type { Variant } from '@/shared/types';

interface Line {
    variant: { id: string; sku: string; name: string };
    old_cost: string;
    new_cost: string;
    on_hand: number;
    value_change: string;
}
interface Doc {
    id: string;
    number: string;
    note: string | null;
    creator: string | null;
    created_at: string;
    lines: Line[];
}

interface Summary {
    all: number;
    month: { count: number; skus: number; decrease: number; increase: number; down_skus: number; up_skus: number };
    last_at: string | null;
}

/**
 * "Điều chỉnh giá vốn" (mẫu Tailwind 28/11): this month's sheets and how much they moved the stock value, the sheets
 * with their lines on expand, a quick start on the right (pick a SKU, the form opens with it).
 */
provide('soft-cards', true);
const tenant = useTenantStore();
const summary = ref<Summary | null>(null);
const toast = useToast();
const idempotency = useIdempotencyKey();
const canCreate = computed(() => tenant.can('inventory.adjust.approve'));

const docs = ref<Doc[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0, per_page: 20 });
const loading = ref(true);
const q = ref('');
const expanded = ref<Set<string>>(new Set());

const open = ref(false);
const note = ref('');
const rows = ref<{ variant: Variant; new_cost: string }[]>([]);
const saving = ref(false);
const error = ref<ApiError | null>(null);

async function load(page = 1) {
    loading.value = true;
    try {
        const { data } = await api.get('/inventory/cost-adjustments', { params: { q: q.value || undefined, page } });
        docs.value = data.data;
        meta.value = { per_page: 20, ...data.meta };
    } finally {
        loading.value = false;
    }
}
function loadSummary() {
    api.get('/inventory/cost-adjustments/summary').then(({ data }) => (summary.value = data.data)).catch(() => {});
}
onMounted(() => (load(), loadSummary()));
const net = computed(() => (summary.value ? summary.value.month.increase + summary.value.month.decrease : null));
/** Quick start: the SKU picked on the right opens the form with that line. */
function quick(v: Variant) {
    add(v);
    open.value = true;
}
let timer: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => load(), 250);
});

function add(v: Variant) {
    if (rows.value.some((r) => r.variant.id === v.id)) return;
    rows.value.push({ variant: v, new_cost: Number(v.cost_price) ? String(Math.round(Number(v.cost_price))) : '' });
}

const diff = (r: { variant: Variant; new_cost: string }) => (r.new_cost === '' ? 0 : Number(r.new_cost) - Number(r.variant.cost_price ?? 0));
const totalLines = (d: Doc) => d.lines.reduce((s, l) => s + Number(l.value_change), 0);

async function submit() {
    saving.value = true;
    error.value = null;
    try {
        const { data } = await api.post('/inventory/cost-adjustments', {
            note: note.value || null,
            lines: rows.value.map((r) => ({ variant_id: r.variant.id, new_cost: r.new_cost === '' ? null : Number(r.new_cost) })),
        }, idempotency.config());
        idempotency.renew();
        toast.success(`Đã tạo phiếu ${data.data.number}, giá vốn đã cập nhật`);
        open.value = false;
        rows.value = [];
        note.value = '';
        expanded.value = new Set([data.data.id]);
        await load();
        loadSummary();
    } catch (e) {
        error.value = e as ApiError;
        toast.error(error.value.body.errors ? 'Vui lòng kiểm tra các dòng được đánh dấu.' : error.value.message);
    } finally {
        saving.value = false;
    }
}

function toggle(id: string) {
    const s = new Set(expanded.value);
    s.has(id) ? s.delete(id) : s.add(id);
    expanded.value = s;
}
</script>

<template>
    <ProductsHead title="Điều chỉnh giá vốn" description="Sửa giá vốn bình quân khi giá nhập ghi sai hoặc nhà cung cấp chiết khấu sau. Số lượng tồn không đổi.">
        <HeadButton v-if="canCreate" :icon="Plus" primary data-testid="new-cost-adjustment" @click="open = true">Tạo phiếu điều chỉnh</HeadButton>
    </ProductsHead>

    <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="cost-kpis">
        <ReportKpi label="Phiếu tháng này" :icon="FilePenLine" primary :value="summary?.month.count ?? null" unit="phiếu">
            <template #chips><ReportChip tone="onblue">{{ formatNumber(summary?.month.skus ?? 0) }} SKU</ReportChip></template>
            Giá vốn mới áp dụng ngay khi tạo phiếu
        </ReportKpi>
        <ReportKpi label="Giá trị tồn giảm" tip="Tổng (giá vốn mới − giá vốn cũ) × tồn lúc điều chỉnh, của các dòng giảm giá vốn trong tháng." :icon="TrendingDown" tone="green" :value="summary ? Math.round(summary.month.decrease) : null">
            <template #chips><ReportChip tone="up">{{ formatNumber(summary?.month.down_skus ?? 0) }} SKU giảm giá vốn</ReportChip></template>
            Thường do nhà cung cấp chiết khấu sau
        </ReportKpi>
        <ReportKpi label="Giá trị tồn tăng" tip="Tổng chênh lệch của các dòng tăng giá vốn trong tháng." :icon="TrendingUp" tone="red" :value="summary ? Math.round(summary.month.increase) : null">
            <template #chips><ReportChip tone="red">{{ formatNumber(summary?.month.up_skus ?? 0) }} SKU tăng giá vốn</ReportChip></template>
            Thường do giá nhập ghi thiếu phí vận chuyển
        </ReportKpi>
        <ReportKpi label="Chênh lệch ròng" tip="Tăng cộng giảm: giá trị tồn kho thay đổi bao nhiêu vì các phiếu trong tháng." :icon="Scale" tone="slate" :value="net === null ? null : Math.round(net)">
            <template #chips><ReportChip>{{ formatNumber(summary?.all ?? 0) }} phiếu từ trước đến nay</ReportChip></template>
            {{ summary?.last_at ? `Phiếu gần nhất ${formatDateTime(summary.last_at)}` : 'Chưa có phiếu nào' }}
        </ReportKpi>
    </div>

    <div class="grid gap-5 2xl:grid-cols-[minmax(0,1fr)_400px]">
        <section :class="cn(card, 'min-w-0 overflow-hidden')">
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3">
                <SearchField v-model="q" placeholder="Số phiếu hoặc SKU" />
            </div>
            <div v-if="loading && !docs.length" class="space-y-3 p-5"><Skeleton v-for="i in 4" :key="i" class="h-12" /></div>
            <EmptyState v-else-if="!docs.length" :icon="Wallet" title="Chưa có phiếu điều chỉnh giá vốn" description="Mỗi phiếu ghi lại giá vốn cũ và mới của từng SKU, ai làm và lúc nào." />
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-[14px]" data-testid="cost-adjustment-table">
                    <thead :class="thead">
                        <tr>
                            <th :class="cn(th, 'w-10')"></th>
                            <th :class="th">Số phiếu</th>
                            <th :class="th">Ghi chú</th>
                            <th :class="th">Người tạo</th>
                            <th :class="cn(th, 'text-right')">Số SKU</th>
                            <th :class="cn(th, 'text-right')">Chênh lệch giá trị tồn</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="d in docs" :key="d.id">
                            <tr class="cursor-pointer border-t border-slate-100 hover:bg-slate-50/70" @click="toggle(d.id)">
                                <td class="py-3.5 pl-4 text-slate-400"><ChevronDown v-if="expanded.has(d.id)" class="size-4" /><ChevronRight v-else class="size-4" /></td>
                                <td class="px-4 py-3.5">
                                    <span class="font-mono text-[13px] font-semibold text-blue-700">{{ d.number }}</span>
                                    <p class="text-[12px] text-slate-500">{{ formatDateTime(d.created_at) }}</p>
                                </td>
                                <td class="max-w-[28rem] truncate px-4 py-3.5 text-slate-700">{{ d.note || '—' }}</td>
                                <td class="px-4 py-3.5">
                                    <p class="text-slate-800">{{ d.creator ?? '—' }}</p>
                                    <p class="text-[12px] text-slate-500">Người tạo</p>
                                </td>
                                <td class="px-4 py-3.5 text-right tabular-nums text-slate-600">{{ d.lines.length }} SKU</td>
                                <td :class="cn('px-4 py-3.5 text-right font-semibold tabular-nums', totalLines(d) < 0 ? 'text-emerald-600' : totalLines(d) > 0 ? 'text-red-600' : 'text-slate-500')">{{ totalLines(d) > 0 ? '+' : '' }}{{ formatNumber(Math.round(totalLines(d))) }}</td>
                            </tr>
                            <tr v-if="expanded.has(d.id)">
                                <td colspan="6" class="px-4 pb-4">
                                    <div class="overflow-hidden rounded-[12px] border border-slate-200">
                                        <table class="w-full text-[13px]">
                                            <thead class="bg-slate-50/70 text-[11px] uppercase tracking-wider text-slate-400">
                                                <tr>
                                                    <th class="px-4 py-2.5 text-left font-semibold">SKU</th>
                                                    <th class="px-4 py-2.5 text-right font-semibold">Tồn lúc điều chỉnh</th>
                                                    <th class="px-4 py-2.5 text-right font-semibold">Giá vốn cũ</th>
                                                    <th class="px-4 py-2.5 text-right font-semibold">Giá vốn mới</th>
                                                    <th class="px-4 py-2.5 text-right font-semibold">Chênh lệch giá trị</th>
                                                </tr>
                                            </thead>
                                            <tr v-for="l in d.lines" :key="l.variant.id" class="border-t border-slate-100">
                                                <td class="px-4 py-2.5"><p class="font-mono text-[12px] font-semibold text-slate-800">{{ l.variant.sku }}</p><p class="text-[12px] text-slate-500">{{ l.variant.name }}</p></td>
                                                <td class="px-4 py-2.5 text-right tabular-nums">{{ formatNumber(l.on_hand) }}</td>
                                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-400 line-through">{{ formatNumber(Math.round(Number(l.old_cost))) }}</td>
                                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900">{{ formatNumber(Math.round(Number(l.new_cost))) }}</td>
                                                <td :class="cn('px-4 py-2.5 text-right tabular-nums', Number(l.value_change) < 0 ? 'text-emerald-600' : Number(l.value_change) > 0 ? 'text-red-600' : 'text-slate-500')">{{ Number(l.value_change) > 0 ? '+' : '' }}{{ formatNumber(Math.round(Number(l.value_change))) }}</td>
                                            </tr>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <NumberPager :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" :per-page="meta.per_page" :count="docs.length" unit="phiếu" @go="load" />
        </section>

        <aside class="grid content-start gap-5 md:grid-cols-2 2xl:grid-cols-1">
            <SideCard v-if="canCreate" title="Tạo nhanh" description="Chọn SKU, nhập giá vốn mới rồi áp dụng">
                <template #actions><span class="grid size-10 place-items-center rounded-[10px] bg-blue-50 text-blue-600"><FilePenLine class="size-5" /></span></template>
                <div class="p-5"><SkuPicker stocked placeholder="Tìm SKU cần sửa giá vốn" @pick="quick" /></div>
            </SideCard>
            <section :class="cn(card, 'p-5')">
                <h3 class="flex items-center gap-2 text-[15px] font-semibold text-slate-900"><Info class="size-4 text-slate-400" /> Ảnh hưởng thế nào</h3>
                <p class="mt-2 text-[14px] leading-relaxed text-slate-600">Lãi gộp các đơn bán sau thời điểm điều chỉnh tính theo giá vốn mới. Đơn đã bán trước đó giữ nguyên. Lần nhập sau sẽ tính bình quân từ giá mới.</p>
            </section>
        </aside>
    </div>

    <Dialog v-model:open="open" title="Tạo phiếu điều chỉnh giá vốn" description="Giá vốn mới áp dụng ngay khi tạo phiếu." size="xl">
        <div class="space-y-4">
            <SkuPicker stocked placeholder="Quét hoặc tìm SKU cần điều chỉnh" :exclude="rows.map((r) => r.variant.id!)" @pick="add" />
            <p v-if="error?.field('lines')" class="text-sm text-destructive">{{ error.field('lines') }}</p>
            <table v-if="rows.length" class="w-full text-sm" data-testid="cost-lines">
                <thead class="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="py-2 font-medium">SKU</th>
                        <th class="py-2 text-right font-medium">Giá vốn hiện tại</th>
                        <th class="py-2 text-right font-medium">Giá vốn mới</th>
                        <th class="py-2 text-right font-medium">Chênh lệch</th>
                        <th class="w-10" />
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="(r, i) in rows" :key="r.variant.id">
                        <td class="py-2">
                            <p class="font-mono text-xs font-medium">{{ r.variant.sku }}</p>
                            <p class="text-xs text-muted-foreground">{{ r.variant.product?.name }}<span v-if="r.variant.name"> · {{ r.variant.name }}</span></p>
                        </td>
                        <td class="py-2 text-right tabular-nums">{{ formatMoney(r.variant.cost_price ?? 0) }}</td>
                        <td class="py-2 text-right">
                            <input v-model="r.new_cost" type="number" min="0" required :data-testid="`new-cost-${i}`" aria-label="Giá vốn mới" :class="cn('h-8 w-32 rounded-md border border-input bg-card px-2 text-right tabular-nums', error?.field(`lines.${i}.new_cost`) && 'border-destructive')" />
                        </td>
                        <td :class="cn('py-2 text-right tabular-nums', diff(r) < 0 && 'text-destructive', diff(r) > 0 && 'text-success')">{{ diff(r) > 0 ? '+' : '' }}{{ formatMoney(diff(r)) }}</td>
                        <td class="text-right"><Button variant="ghost" size="icon" class="size-8" title="Bỏ dòng" @click="rows.splice(i, 1)"><Trash2 class="text-muted-foreground" /></Button></td>
                    </tr>
                </tbody>
            </table>
            <FormField label="Ghi chú" for="cad-note">
                <Input id="cad-note" v-model="note" maxlength="1000" placeholder="Ví dụ: NCC chiết khấu 5% sau khi nhập" />
            </FormField>
        </div>
        <template #footer>
            <Button variant="outline" @click="open = false">Hủy</Button>
            <Button :disabled="!rows.length || rows.some((r) => r.new_cost === '')" :loading="saving" data-testid="submit-cost-adjustment" @click="submit">Tạo phiếu và áp dụng</Button>
        </template>
    </Dialog>
</template>
