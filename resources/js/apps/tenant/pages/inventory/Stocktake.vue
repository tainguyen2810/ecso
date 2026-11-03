<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ClipboardCheck, ScanBarcode, Search } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Tabs from '../../components/Tabs.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import { applyScan, stocktakeLines, variance, varianceReasons, type CountRow, type LineNotes } from './stocktake';
import type { InventoryDocument, Warehouse } from '@/shared/types';

/**
 * Phiếu kiểm hàng like Sapo: count by scanning (+1 per scan) or typing, save the sheet as often as needed while
 * counting (a draft on the server, stock untouched), give a reason for each difference, then "Cân bằng kho": stock
 * becomes the count (approvers) or the sheet goes for approval (others).
 */
const route = useRoute();
const router = useRouter();
const toast = useToast();
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
            all.push(...data.data.map((r: { variant: { id: string; sku: string; name: string | null; barcode: string | null }; product: { name: string }; on_hand: number }) => ({ id: r.variant.id, sku: r.variant.sku, barcode: r.variant.barcode, name: `${r.product.name}${r.variant.name ? ' · ' + r.variant.name : ''}`, on_hand: r.on_hand })));
            if (page >= data.meta.last_page) break;
        }
        rows.value = all;
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
    warehouses.value = ((await api.get('/warehouses')).data.data as Warehouse[]).filter((w) => w.status === 'active');
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
        doc.value = (await api.post('/inventory/adjustments', { ...body, warehouse_id: warehouse.value, reason: 'stocktake', draft: true })).data.data;
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
    <PageHeader :title="doc ? `Phiếu kiểm hàng ${doc.number}` : 'Tạo phiếu kiểm hàng'">
        <template #links>
            <Badge tone="warning">Đang kiểm</Badge>
            <span class="text-xs text-muted-foreground">{{ doc ? `Tạo ${formatDateTime(doc.created_at)} · ${doc.created_by}` : 'Chưa lưu' }} · chưa thay đổi tồn kho</span>
        </template>
        <RouterLink to="/inventory/stocktake"><Button variant="outline">Thoát</Button></RouterLink>
        <Button variant="outline" :disabled="!lines.length || lines.length > 500" :loading="saving === 'save'" data-testid="stocktake-save" @click="saveSheet">Lưu phiếu kiểm</Button>
        <Button :disabled="!lines.length || lines.length > 500" :loading="saving === 'balance'" data-testid="stocktake-submit" @click="balance">
            <ClipboardCheck /> {{ canApprove ? 'Cân bằng kho' : 'Gửi duyệt' }} ({{ lines.length }})
        </Button>
    </PageHeader>

    <div class="mb-5 grid gap-5 lg:grid-cols-[1fr_22rem]">
        <Card :caps="false" title="Thông tin phiếu">
            <div class="grid gap-3 p-5 sm:grid-cols-2">
                <FormField label="Kho kiểm">
                    <Select v-model="warehouse" :disabled="!!draftId" :options="warehouses.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))" aria-label="Kho" />
                </FormField>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div><p class="text-xs text-muted-foreground">Đã kiểm</p><p class="mt-1 font-semibold tabular-nums" data-testid="stocktake-progress">{{ formatNumber(counted) }} / {{ formatNumber(rows.length) }} SKU</p></div>
                    <div><p class="text-xs text-muted-foreground">Lệch</p><p class="mt-1 font-semibold tabular-nums" :class="diffs.length > 0 && 'text-destructive'">{{ formatNumber(diffs.length) }} SKU</p></div>
                </div>
            </div>
        </Card>
        <Card :caps="false" title="Thông tin bổ sung">
            <div class="space-y-3 p-5 text-sm">
                <FormField label="Ghi chú" for="st-note"><Input id="st-note" v-model="note" placeholder="Người kiểm, khu vực, kệ…" /></FormField>
                <label class="flex items-start gap-2 text-xs"><input v-model="zeroUncounted" type="checkbox" class="mt-0.5 size-4" /> SKU chưa kiểm coi như 0 (kiểm toàn bộ kho)</label>
            </div>
        </Card>
    </div>

    <Card>
        <div class="flex flex-wrap items-center justify-between gap-2 border-b px-3">
            <Tabs v-model="filter" :tabs="tabs" variant="underline" data-testid="stocktake-tabs" />
            <button type="button" class="px-2 text-xs text-muted-foreground hover:text-destructive" @click="reset">Xóa số đếm</button>
        </div>
        <div class="flex flex-wrap items-center gap-2 border-b p-3">
            <div class="relative min-w-60 flex-1">
                <ScanBarcode class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="scan" class="pl-8" placeholder="Quét mã vạch / gõ SKU rồi Enter: mỗi lần quét +1" data-testid="stocktake-scan" @keydown.enter.prevent="onScan" />
            </div>
            <div class="relative w-56">
                <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="q" class="pl-8" placeholder="Lọc SKU, tên…" />
            </div>
        </div>
        <div v-if="loading" class="space-y-3 p-4"><Skeleton v-for="i in 6" :key="i" class="h-9" /></div>
        <EmptyState v-else-if="!rows.length" :icon="ClipboardCheck" title="Kho chưa có SKU" description="Chọn kho khác hoặc tạo sản phẩm trước." />
        <div v-else class="max-h-[65vh] overflow-auto">
            <table class="w-full text-sm" data-testid="stocktake-table">
                <thead class="sticky top-0 z-10 border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="w-12 px-4 py-2 font-medium">STT</th>
                        <th class="px-3 py-2 font-medium">Tên sản phẩm</th>
                        <th class="px-3 py-2 text-right font-medium">Tồn hệ thống</th>
                        <th class="px-3 py-2 text-right font-medium">Tồn thực tế</th>
                        <th class="px-3 py-2 text-right font-medium">Lệch</th>
                        <th class="px-3 py-2 font-medium">Lý do</th>
                        <th class="px-3 py-2 font-medium">Ghi chú</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="(r, i) in shown" :key="r.id" :class="cn(lastScanned === r.id && 'bg-primary/5', (variance(r, counts) ?? 0) !== 0 && 'bg-amber-50/40')">
                        <td class="px-4 py-2 text-muted-foreground">{{ i + 1 }}</td>
                        <td class="px-3 py-2"><p class="max-w-80 truncate font-medium text-primary">{{ r.name }}</p><p class="font-mono text-xs text-muted-foreground">{{ r.sku }}</p></td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ formatNumber(r.on_hand) }}</td>
                        <td class="px-3 py-2 text-right">
                            <input :id="`count-${r.id}`" v-model.number="counts[r.id]" type="number" min="0" placeholder="Chưa kiểm" class="h-8 w-24 rounded-md border border-input bg-card px-2 text-right tabular-nums placeholder:text-xs" :data-testid="`count-${r.sku}`" />
                        </td>
                        <td class="px-3 py-2 text-right font-semibold tabular-nums">
                            <span v-if="variance(r, counts) === null" class="font-normal text-muted-foreground">—</span>
                            <span v-else-if="variance(r, counts) === 0" class="text-success">Khớp</span>
                            <span v-else :class="variance(r, counts)! > 0 ? 'text-success' : 'text-destructive'">{{ variance(r, counts)! > 0 ? '+' : '' }}{{ variance(r, counts) }}</span>
                        </td>
                        <td class="px-3 py-2">
                            <select v-if="(variance(r, counts) ?? 0) !== 0" v-model="noteOf(r.id).reason" class="h-8 w-36 rounded-md border border-input bg-card px-1 text-sm" aria-label="Lý do lệch" :data-testid="`reason-${r.sku}`">
                                <option :value="undefined">Chọn lý do…</option>
                                <option v-for="(label, key) in varianceReasons" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </td>
                        <td class="px-3 py-2">
                            <input v-if="(variance(r, counts) ?? 0) !== 0" v-model="noteOf(r.id).note" class="h-8 w-full min-w-40 rounded-md border border-input bg-card px-2 text-sm" placeholder="Ghi chú" :data-testid="`note-${r.sku}`" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="lines.length > 500" class="border-t px-4 py-2 text-xs text-destructive">Tối đa 500 SKU mỗi phiếu. Lọc theo khu vực và tạo nhiều phiếu.</p>
    </Card>
</template>
