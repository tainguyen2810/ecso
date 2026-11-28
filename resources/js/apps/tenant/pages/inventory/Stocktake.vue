<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, ClipboardCheck, Package, ScanBarcode, Search } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Tabs from '../../components/Tabs.vue';
import Alert from '@/shared/ui/Alert.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import { useIdempotencyKey } from '@/shared/composables/useIdempotencyKey';
import { applyScan, stocktakeLines, variance, varianceReasons, type CountRow, type LineNotes } from './stocktake';
import type { InventoryDocument, Warehouse } from '@/shared/types';
import { useSoftPage } from '@/shared/composables/useSoftPage';

useSoftPage('Sản phẩm');

/**
 * Phiếu kiểm hàng like Sapo: count by scanning (+1 per scan) or typing, save the sheet as often as needed while
 * counting (a draft on the server, stock untouched), give a reason for each difference, then "Cân bằng kho": stock
 * becomes the count (approvers) or the sheet goes for approval (others).
 */
const route = useRoute();
const router = useRouter();
const toast = useToast();
const idempotency = useIdempotencyKey();
const tenant = useTenantStore();
const draftId = ref<string | null>(route.params.id ? String(route.params.id) : null);
const doc = ref<InventoryDocument | null>(null);

const warehouses = ref<Warehouse[]>([]);
const warehouse = ref('');
const rows = ref<CountRow[]>([]);
const counts = ref<Record<string, number | ''>>({});
const notes = ref<LineNotes>({});
const loading = ref(false);
const scan = ref('');
const filter = ref<'all' | 'uncounted' | 'match' | 'diff'>('all');
const q = ref('');
const zeroUncounted = ref(false);
const note = ref('');
const saving = ref<'save' | 'balance' | null>(null);
const lastScanned = ref<string | null>(null);
const canApprove = computed(() => tenant.can('inventory.adjust.approve'));

// An unsaved new sheet survives a reload on this device; a saved one lives on the server.
const storageKey = computed(() => `ecso.stocktake.${warehouse.value}`);
function persist() {
    if (draftId.value) return;
    try {
        localStorage.setItem(storageKey.value, JSON.stringify({ counts: counts.value, notes: notes.value }));
    } catch {
        /* storage unavailable: the count only lives on this page */
    }
}
function restore(): { counts: Record<string, number | ''>; notes: LineNotes } {
    try {
        const v = JSON.parse(localStorage.getItem(storageKey.value) ?? '{}');
        // Older versions stored the counts alone.
        return v.counts ? v : { counts: v, notes: {} };
    } catch {
        return { counts: {}, notes: {} };
    }
}

async function loadStock() {
    if (!warehouse.value) return;
    loading.value = true;
    try {
        const all: CountRow[] = [];
        for (let page = 1; page <= 20; page++) {
            const { data } = await api.get('/inventory/stock', { params: { warehouse: warehouse.value, per_page: 200, page } });
            all.push(
                ...data.data.map((r: { variant: { id: string; sku: string; name: string | null; barcode: string | null }; product: { name: string; image?: string | null }; on_hand: number; cost_price?: string | null }) => ({
                    id: r.variant.id,
                    sku: r.variant.sku,
                    barcode: r.variant.barcode,
                    name: `${r.product.name}${r.variant.name ? ' · ' + r.variant.name : ''}`,
                    on_hand: r.on_hand,
                    cost: r.cost_price == null ? null : Number(r.cost_price),
                    image: r.product.image ?? null,
                })),
            );
            if (page >= data.meta.last_page) break;
        }
        // A saved line is compared with the stock seen when it was counted, not with today's stock.
        const saved = new Map((doc.value?.lines ?? []).filter((l) => l.system_qty !== undefined).map((l) => [l.variant.id, l.system_qty!]));
        rows.value = all.map((r) => (saved.has(r.id) && saved.get(r.id) !== r.on_hand ? { ...r, on_hand: saved.get(r.id)!, on_hand_now: r.on_hand } : r));
        if (doc.value) {
            counts.value = Object.fromEntries((doc.value.lines ?? []).map((l) => [l.variant.id, l.quantity]));
            notes.value = Object.fromEntries((doc.value.lines ?? []).map((l) => [l.variant.id, { reason: l.reason, note: l.line_note }]));
        } else {
            ({ counts: counts.value, notes: notes.value } = restore());
        }
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        loading.value = false;
    }
}
onMounted(async () => {
    warehouses.value = ((await api.get('/warehouses')).data.data as Warehouse[]).filter((w) => w.status === 'active' && tenant.worksIn(w.id));
    if (draftId.value) {
        doc.value = (await api.get(`/inventory/adjustments/${draftId.value}`)).data.data;
        if (doc.value!.status !== 'draft') {
            await router.replace(`/inventory/adjustments/${draftId.value}`);
            return;
        }
        note.value = doc.value!.note ?? '';
        warehouse.value = doc.value!.warehouse?.id ?? '';
    } else {
        warehouse.value = (warehouses.value.find((w) => w.is_default) ?? warehouses.value[0])?.id ?? '';
    }
});
watch(warehouse, loadStock);
watch([counts, notes], persist, { deep: true });

function onScan() {
    const code = scan.value.trim();
    if (!code) return;
    const hit = applyScan(rows.value, counts.value, code);
    if (hit) {
        lastScanned.value = hit;
        document.getElementById(`count-${hit}`)?.scrollIntoView({ block: 'center' });
    } else {
        toast.error(`Không tìm thấy mã ${code} trong kho này.`);
    }
    scan.value = '';
}

const isCounted = (r: CountRow) => counts.value[r.id] !== undefined && counts.value[r.id] !== '';
const counted = computed(() => rows.value.filter(isCounted).length);
const diffs = computed(() => rows.value.filter((r) => (variance(r, counts.value) ?? 0) !== 0));
const matches = computed(() => rows.value.filter((r) => variance(r, counts.value) === 0).length);
const tabs = computed(() => [
    { value: 'all', label: 'Tất cả', count: rows.value.length },
    { value: 'uncounted', label: 'Chưa kiểm', count: rows.value.length - counted.value },
    { value: 'match', label: 'Khớp', count: matches.value },
    { value: 'diff', label: 'Lệch', count: diffs.value.length },
]);
const shown = computed(() => {
    const term = q.value.trim().toLowerCase();
    return rows.value.filter((r) => {
        if (term && !r.sku.toLowerCase().includes(term) && !r.name.toLowerCase().includes(term)) return false;
        if (filter.value === 'uncounted') return !isCounted(r);
        if (filter.value === 'match') return variance(r, counts.value) === 0;
        if (filter.value === 'diff') return (variance(r, counts.value) ?? 0) !== 0;
        return true;
    });
});
const short = computed(() => diffs.value.filter((r) => variance(r, counts.value)! < 0).length);
/** Value of the variance at cost; null when costs are hidden. */
const diffValue = computed(() => (rows.value.some((r) => r.cost === null) ? null : diffs.value.reduce((sum, r) => sum + variance(r, counts.value)! * (r.cost ?? 0), 0)));
const lastRow = computed(() => rows.value.find((r) => r.id === lastScanned.value) ?? null);
const movedSince = computed(() => rows.value.filter((r) => r.on_hand_now !== undefined && isCounted(r)));
const lines = computed(() => stocktakeLines(rows.value, counts.value, zeroUncounted.value, notes.value));
function noteOf(id: string) {
    return (notes.value[id] ??= {});
}

function clearLocal() {
    try {
        localStorage.removeItem(storageKey.value);
    } catch {
        /* ignore */
    }
}

/** Saves the sheet (a draft) and returns its id. */
async function save(): Promise<string> {
    const body = { note: note.value || `Kiểm hàng ${new Date().toLocaleDateString('vi-VN')}`, lines: lines.value };
    if (draftId.value) {
        doc.value = (await api.put(`/inventory/adjustments/${draftId.value}`, body)).data.data;
    } else {
        doc.value = (await api.post('/inventory/adjustments', { ...body, warehouse_id: warehouse.value, reason: 'stocktake', draft: true }, idempotency.config())).data.data;
        idempotency.renew();
        draftId.value = doc.value!.id;
        clearLocal();
        await router.replace(`/inventory/stocktake/${draftId.value}`);
    }
    return draftId.value!;
}
async function saveSheet() {
    saving.value = 'save';
    try {
        await save();
        toast.success(`Đã lưu phiếu kiểm ${doc.value!.number}. Tồn kho chưa thay đổi.`);
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        saving.value = null;
    }
}
async function balance() {
    saving.value = 'balance';
    try {
        const id = await save();
        const { data } = await api.post(`/inventory/adjustments/${id}/balance`);
        toast.success(data.data.status === 'approved' ? `Đã cân bằng kho theo phiếu ${data.data.number}.` : `Đã gửi duyệt phiếu ${data.data.number}. Tồn được cập nhật khi phiếu được duyệt.`);
        await router.push(`/inventory/adjustments/${id}`);
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        saving.value = null;
    }
}
function reset() {
    counts.value = {};
    notes.value = {};
}
</script>

<template>
    <PageHeader :title="doc ? `Phiếu kiểm hàng ${doc.number}` : 'Kiểm hàng mới'">
        <template #links>
            <Badge tone="warning">Đang đếm</Badge>
            <span class="text-xs text-muted-foreground">{{ doc ? `Tạo ${formatDateTime(doc.created_at)} · ${doc.created_by}` : 'Chưa lưu' }} · chưa thay đổi tồn kho</span>
        </template>
        <RouterLink to="/inventory/stocktake"><Button variant="outline"><ArrowLeft /> Thoát</Button></RouterLink>
    </PageHeader>

    <!-- How far the count is, at a glance. -->
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" data-testid="stocktake-kpis">
        <div class="rounded-xl border bg-card p-4">
            <p class="text-sm text-muted-foreground">Đã đếm</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums" data-testid="stocktake-progress">{{ formatNumber(counted) }} / {{ formatNumber(rows.length) }} SKU</p>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${rows.length ? Math.round((counted / rows.length) * 100) : 0}%` }" /></div>
        </div>
        <div class="rounded-xl border bg-card p-4">
            <p class="text-sm text-muted-foreground">Khớp</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-emerald-600">{{ formatNumber(matches) }}</p>
            <p class="mt-0.5 text-xs text-muted-foreground">không lệch</p>
        </div>
        <div class="rounded-xl border bg-card p-4">
            <p class="text-sm text-muted-foreground">Lệch</p>
            <p :class="cn('mt-1 text-2xl font-semibold tabular-nums', diffs.length ? 'text-amber-600' : '')">{{ formatNumber(diffs.length) }}</p>
            <p class="mt-0.5 text-xs text-muted-foreground">{{ formatNumber(short) }} thiếu · {{ formatNumber(diffs.length - short) }} thừa</p>
        </div>
        <div class="rounded-xl border bg-card p-4">
            <p class="text-sm text-muted-foreground">Giá trị lệch</p>
            <p :class="cn('mt-1 text-2xl font-semibold tabular-nums', (diffValue ?? 0) < 0 ? 'text-destructive' : (diffValue ?? 0) > 0 ? 'text-emerald-600' : '')">{{ diffValue === null ? '—' : (diffValue > 0 ? '+' : '') + formatMoney(diffValue) }}</p>
            <p class="mt-0.5 text-xs text-muted-foreground">{{ diffValue === null ? 'cần quyền xem giá vốn' : 'theo giá vốn' }}</p>
        </div>
    </div>

    <!-- The scanner: every scan adds 1. -->
    <div class="relative mb-4">
        <ScanBarcode class="pointer-events-none absolute left-4 top-1/2 size-6 -translate-y-1/2 text-primary" :stroke-width="1.75" />
        <Input v-model="scan" class="h-14 rounded-xl border-2 border-primary/60 bg-white pl-14 text-base shadow-sm focus-visible:border-primary sm:pr-72" placeholder="Quét từng món trên kệ, mỗi lần quét cộng 1 (hoặc gõ SKU rồi Enter)" data-testid="stocktake-scan" @keydown.enter.prevent="onScan" />
        <span v-if="lastRow" class="absolute right-3 top-1/2 hidden max-w-72 -translate-y-1/2 items-center gap-1 rounded-lg bg-emerald-50 px-2.5 py-1 text-sm font-medium text-emerald-700 sm:flex">
            <span class="shrink-0">Vừa quét:</span><span class="truncate">{{ lastRow.name }}</span><span class="shrink-0 font-semibold">→ {{ counts[lastRow.id] }}</span>
        </span>
    </div>

    <Card>
        <div class="flex flex-wrap items-center gap-2 border-b px-3 py-2">
            <Tabs v-model="filter" :tabs="tabs" variant="pill" data-testid="stocktake-tabs" />
            <div class="relative ml-auto w-56">
                <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="q" class="pl-8" placeholder="Lọc SKU, tên…" />
            </div>
            <button type="button" class="px-2 text-xs text-muted-foreground hover:text-destructive" @click="reset">Xóa số đếm</button>
        </div>
        <Alert v-if="movedSince.length" tone="amber" class="m-3" data-testid="stocktake-moved">
            {{ formatNumber(movedSince.length) }} SKU đã có nhập hoặc xuất sau khi đếm. Khi cân bằng, EzChat Bán hàng chỉ cộng hoặc trừ phần lệch lúc đếm và giữ nguyên các giao dịch đó.
        </Alert>
        <div v-if="loading" class="space-y-3 p-4"><Skeleton v-for="i in 6" :key="i" class="h-9" /></div>
        <EmptyState v-else-if="!rows.length" :icon="ClipboardCheck" title="Kho chưa có SKU" description="Chọn kho khác hoặc tạo sản phẩm trước." />
        <div v-else class="max-h-[60vh] overflow-auto">
            <table class="w-full text-sm" data-testid="stocktake-table">
                <thead class="sticky top-0 z-10 border-b bg-slate-50 text-left text-xs text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Sản phẩm</th>
                        <th class="px-3 py-2.5 text-right font-medium" title="Tồn hệ thống lúc đếm">Phần mềm ghi</th>
                        <th class="px-3 py-2.5 text-right font-medium">Đếm được</th>
                        <th class="px-3 py-2.5 text-right font-medium">Lệch</th>
                        <th class="px-3 py-2.5 font-medium">Lý do</th>
                        <th class="px-3 py-2.5 font-medium">Ghi chú</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="r in shown" :key="r.id" :class="cn(lastScanned === r.id && 'bg-emerald-50/60')">
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-3">
                                <img v-if="r.image" :src="r.image" alt="" class="size-10 shrink-0 rounded-lg border object-cover" />
                                <span v-else class="grid size-10 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-400"><Package class="size-4" /></span>
                                <div class="min-w-0"><p class="max-w-80 truncate font-medium">{{ r.name }}</p><p class="font-mono text-xs text-muted-foreground">{{ r.sku }}</p></div>
                            </div>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ formatNumber(r.on_hand) }}
                            <p v-if="r.on_hand_now !== undefined && isCounted(r)" class="text-[11px] text-amber-600" title="Tồn hiện tại khác tồn lúc đếm">Hiện tại {{ formatNumber(r.on_hand_now) }}</p>
                        </td>
                        <td class="px-3 py-2 text-right">
                            <input
                                :id="`count-${r.id}`"
                                v-model.number="counts[r.id]"
                                type="number"
                                min="0"
                                placeholder="Chưa đếm"
                                :class="cn('h-10 w-24 rounded-[8px] border border-slate-200 bg-white px-2.5 focus:border-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-100 text-right font-medium tabular-nums placeholder:text-xs placeholder:font-normal', (variance(r, counts) ?? 0) < 0 ? 'border-red-300 bg-red-50/40' : (variance(r, counts) ?? 0) > 0 ? 'border-amber-300 bg-amber-50/40' : variance(r, counts) === 0 ? 'border-emerald-300' : 'border-input')"
                                :data-testid="`count-${r.sku}`"
                            />
                        </td>
                        <td class="px-3 py-2 text-right font-semibold tabular-nums">
                            <span v-if="variance(r, counts) === null" class="font-normal text-muted-foreground">—</span>
                            <span v-else-if="variance(r, counts) === 0" class="text-success">Khớp</span>
                            <span v-else :class="variance(r, counts)! > 0 ? 'text-amber-600' : 'text-destructive'">{{ variance(r, counts)! > 0 ? '+' : '' }}{{ variance(r, counts) }}</span>
                        </td>
                        <td class="px-3 py-2">
                            <select v-if="(variance(r, counts) ?? 0) !== 0" v-model="noteOf(r.id).reason" class="h-9 w-36 rounded-[8px] border border-slate-200 bg-white focus:border-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-100 px-2 text-sm" aria-label="Lý do lệch" :data-testid="`reason-${r.sku}`">
                                <option :value="undefined">Chọn lý do…</option>
                                <option v-for="(label, key) in varianceReasons" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </td>
                        <td class="px-3 py-2">
                            <input v-if="(variance(r, counts) ?? 0) !== 0" v-model="noteOf(r.id).note" class="h-9 w-full min-w-40 rounded-[8px] border border-slate-200 bg-white focus:border-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-100 px-2.5 text-sm" placeholder="Ghi chú" :data-testid="`note-${r.sku}`" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="lines.length > 500" class="border-t px-4 py-2 text-xs text-destructive">Tối đa 500 SKU mỗi phiếu. Lọc theo khu vực và tạo nhiều phiếu.</p>
    </Card>

    <!-- Sheet details and the two actions, kept at the bottom of the screen. -->
    <div class="sticky bottom-0 z-10 mt-4 flex flex-wrap items-center gap-3 rounded-xl border bg-card/95 p-3 shadow-[0_-4px_16px_rgba(15,23,42,0.06)] backdrop-blur">
        <Select v-model="warehouse" class="w-48" :disabled="!!draftId" :options="warehouses.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))" aria-label="Kho" />
        <Input v-model="note" class="w-56" placeholder="Ghi chú: người kiểm, kệ…" aria-label="Ghi chú phiếu" />
        <label class="flex items-center gap-2 text-xs text-muted-foreground"><input v-model="zeroUncounted" type="checkbox" class="size-4" /> SKU chưa đếm coi như 0</label>
        <p class="hidden flex-1 text-xs text-muted-foreground 2xl:block">Cân bằng kho: tồn trong phần mềm đổi theo số đếm. {{ zeroUncounted ? 'Hàng chưa đếm về 0.' : 'Hàng chưa đếm giữ nguyên.' }}</p>
        <div class="ml-auto flex gap-2">
            <Button variant="outline" :disabled="!lines.length || lines.length > 500" :loading="saving === 'save'" data-testid="stocktake-save" @click="saveSheet">Lưu, đếm tiếp sau</Button>
            <Button :disabled="!lines.length || lines.length > 500" :loading="saving === 'balance'" data-testid="stocktake-submit" @click="balance">
                <ClipboardCheck /> {{ canApprove ? 'Cân bằng kho' : 'Gửi duyệt' }} ({{ lines.length }})
            </Button>
        </div>
    </div>
</template>
