<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { ClipboardCheck, ScanBarcode, Search } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import InventoryNav from './InventoryNav.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { applyScan, stocktakeLines, variance, type CountRow } from './stocktake';
import type { Warehouse } from '@/shared/types';

const router = useRouter();
const toast = useToast();
const warehouses = ref<Warehouse[]>([]);
const warehouse = ref('');
const rows = ref<CountRow[]>([]);
const counts = ref<Record<string, number | ''>>({});
const loading = ref(false);
const scan = ref('');
const filter = ref<'all' | 'uncounted' | 'diff'>('all');
const q = ref('');
const zeroUncounted = ref(false);
const note = ref('');
const saving = ref(false);
const lastScanned = ref<string | null>(null);

const storageKey = computed(() => `ecso.stocktake.${warehouse.value}`);
function persist() {
    try {
        localStorage.setItem(storageKey.value, JSON.stringify(counts.value));
    } catch {
        /* storage unavailable: the count only lives on this page */
    }
}
function restore(): Record<string, number | ''> {
    try {
        return JSON.parse(localStorage.getItem(storageKey.value) ?? '{}');
    } catch {
        return {};
    }
}

async function load() {
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
        counts.value = restore();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        loading.value = false;
    }
}
onMounted(async () => {
    warehouses.value = ((await api.get('/warehouses')).data.data as Warehouse[]).filter((w) => w.status === 'active');
    warehouse.value = (warehouses.value.find((w) => w.is_default) ?? warehouses.value[0])?.id ?? '';
});
watch(warehouse, load);
watch(counts, persist, { deep: true });

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

const counted = computed(() => rows.value.filter((r) => counts.value[r.id] !== undefined && counts.value[r.id] !== '').length);
const diffs = computed(() => rows.value.filter((r) => (variance(r, counts.value) ?? 0) !== 0));
const shown = computed(() => {
    const term = q.value.trim().toLowerCase();
    return rows.value.filter((r) => {
        if (term && !r.sku.toLowerCase().includes(term) && !r.name.toLowerCase().includes(term)) return false;
        if (filter.value === 'uncounted') return counts.value[r.id] === undefined || counts.value[r.id] === '';
        if (filter.value === 'diff') return (variance(r, counts.value) ?? 0) !== 0;
        return true;
    });
});
const lines = computed(() => stocktakeLines(rows.value, counts.value, zeroUncounted.value));

async function submit() {
    saving.value = true;
    try {
        const { data } = await api.post('/inventory/adjustments', { warehouse_id: warehouse.value, reason: 'stocktake', note: note.value || `Kiểm kho ${new Date().toLocaleDateString('vi-VN')}`, lines: lines.value });
        try {
            localStorage.removeItem(storageKey.value);
        } catch {
            /* ignore */
        }
        toast.success(`Đã tạo phiếu kiểm kê ${data.data.number}. Tồn được cập nhật khi phiếu được duyệt.`);
        await router.push(`/inventory/adjustments/${data.data.id}`);
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        saving.value = false;
    }
}
function reset() {
    counts.value = {};
}
</script>

<template>
    <PageHeader title="Kho hàng" description="Kiểm kho: quét hoặc nhập số đếm thực tế, xem chênh lệch, rồi tạo phiếu kiểm kê để duyệt." />
    <InventoryNav />
    <div class="grid gap-5 lg:grid-cols-3">
        <Card class="lg:col-span-2">
            <div class="flex flex-wrap items-center gap-2 border-b p-3">
                <div class="relative min-w-60 flex-1">
                    <ScanBarcode class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                    <Input v-model="scan" class="pl-8" placeholder="Quét mã vạch / gõ SKU rồi Enter: mỗi lần quét +1" data-testid="stocktake-scan" @keydown.enter.prevent="onScan" />
                </div>
                <div class="relative w-48">
                    <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                    <Input v-model="q" class="pl-8" placeholder="Lọc SKU, tên…" />
                </div>
                <Select v-model="filter" class="w-36" :options="[{ value: 'all', label: 'Tất cả' }, { value: 'uncounted', label: 'Chưa đếm' }, { value: 'diff', label: 'Có chênh lệch' }]" aria-label="Lọc" />
            </div>
            <div v-if="loading" class="space-y-3 p-4"><Skeleton v-for="i in 6" :key="i" class="h-9" /></div>
            <EmptyState v-else-if="!rows.length" :icon="ClipboardCheck" title="Kho chưa có SKU" description="Chọn kho khác hoặc tạo sản phẩm trước." />
            <div v-else class="max-h-[60vh] overflow-auto">
                <table class="w-full text-sm" data-testid="stocktake-table">
                    <thead class="sticky top-0 border-b bg-muted text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr><th class="px-4 py-2 font-medium">SKU</th><th class="px-4 py-2 text-right font-medium">Hệ thống</th><th class="px-4 py-2 text-right font-medium">Đếm được</th><th class="px-4 py-2 text-right font-medium">Chênh lệch</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="r in shown" :key="r.id" :class="lastScanned === r.id ? 'bg-primary/5' : undefined">
                            <td class="px-4 py-2"><p class="font-mono text-xs font-medium">{{ r.sku }}</p><p class="max-w-72 truncate text-xs text-muted-foreground">{{ r.name }}</p></td>
                            <td class="px-4 py-2 text-right tabular-nums text-muted-foreground">{{ formatNumber(r.on_hand) }}</td>
                            <td class="px-4 py-2 text-right">
                                <input :id="`count-${r.id}`" v-model.number="counts[r.id]" type="number" min="0" class="h-8 w-20 rounded-md border border-input bg-card px-2 text-right tabular-nums" :data-testid="`count-${r.sku}`" />
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                <span v-if="variance(r, counts) === null" class="text-muted-foreground">—</span>
                                <span v-else :class="cn(variance(r, counts)! > 0 && 'text-success', variance(r, counts)! < 0 && 'font-medium text-destructive')">{{ variance(r, counts)! > 0 ? '+' : '' }}{{ variance(r, counts) }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
        <div class="space-y-5">
            <Card title="Phiên kiểm kho">
                <div class="space-y-3 p-5 text-sm">
                    <Select v-model="warehouse" :options="warehouses.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))" aria-label="Kho" />
                    <div class="flex justify-between"><span class="text-muted-foreground">Đã đếm</span><span class="tabular-nums" data-testid="stocktake-progress">{{ formatNumber(counted) }} / {{ formatNumber(rows.length) }} SKU</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">Có chênh lệch</span><span class="tabular-nums" :class="diffs.length > 0 ? 'font-medium text-destructive' : undefined">{{ formatNumber(diffs.length) }} SKU</span></div>
                    <label class="flex items-start gap-2 text-xs"><input v-model="zeroUncounted" type="checkbox" class="mt-0.5 size-4" /> SKU chưa đếm coi như 0 (kiểm toàn bộ kho)</label>
                    <Input v-model="note" placeholder="Ghi chú phiếu (người kiểm, khu vực…)" />
                    <Button class="w-full" :disabled="!lines.length || lines.length > 500" :loading="saving" data-testid="stocktake-submit" @click="submit"><ClipboardCheck /> Tạo phiếu kiểm kê ({{ lines.length }})</Button>
                    <p v-if="lines.length > 500" class="text-xs text-destructive">Tối đa 500 SKU mỗi phiếu. Lọc theo khu vực và tạo nhiều phiếu.</p>
                    <button type="button" class="text-xs text-muted-foreground hover:text-destructive" @click="reset">Xóa số đếm</button>
                    <p class="text-xs text-muted-foreground">Số đếm được lưu trên máy này khi tải lại trang. Phiếu chờ duyệt; khi duyệt, tồn được đặt bằng số đếm. Nên duyệt ngay sau khi đếm để không lệch với đơn bán trong lúc chờ.</p>
                </div>
            </Card>
        </div>
    </div>
</template>
