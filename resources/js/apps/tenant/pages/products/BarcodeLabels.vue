<script setup lang="ts">
import { computed, onMounted, reactive, ref, shallowRef, watch } from 'vue';
import { useRoute } from 'vue-router';
import { Download, ImageOff, Printer, Tags, X } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import SkuPicker from '../../components/SkuPicker.vue';
import { barcodeSvg } from './barcode';
import LabelPreview from './LabelPreview.vue';
import { approxMetrics, parseFontMetrics, type FontMetrics } from './fontMetrics';
import { codeWarning, defaultOptions, expandLabels, isRoll, labelCode, loadLabelFont, MM, paginate, paperGroups, papers, printLabels, type LabelItem, type LabelOptions, type Paper, type PaperKey } from './labels';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import type { Variant, Warehouse } from '@/shared/types';

type PriceList = 'retail' | 'wholesale';
type Row = LabelItem & { id: string; image: string | null; retail: string | number | null; wholesale: string | number | null; onHand: number | null; error: string | null; warning: string | null };

const route = useRoute();
const toast = useToast();
const tenant = useTenantStore();
const rows = ref<Row[]>([]);
const busy = ref(false);
const bulkQty = ref('');
const warehouses = ref<Warehouse[]>([]);
const warehouseId = ref('');
const priceList = ref<PriceList>('retail');

/** Saved per browser: the printer setup (paper, fields, calibration) rarely changes. */
const STORAGE_KEY = 'ecso.labels.v2';
interface Saved extends Partial<LabelOptions> {
    showStore?: boolean;
    priceList?: PriceList;
}
function saved(): Saved {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}');
    } catch {
        return {};
    }
}
const initial = saved();
const options = reactive<LabelOptions>({ ...defaultOptions, ...initial, storeName: '' });
if (!(options.paper in papers)) options.paper = defaultOptions.paper;
const showStore = ref(initial.showStore ?? false);
if (initial.priceList) priceList.value = initial.priceList;
watch([options, showStore, priceList], () => {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({ ...options, storeName: undefined, showStore: showStore.value, priceList: priceList.value }));
    } catch {
        // Private mode: keep the options for this visit only.
    }
});

const paper = computed<Paper>(() => papers[options.paper]);
const roll = computed(() => isRoll(paper.value));
const printOptions = computed<LabelOptions>(() => ({ ...options, storeName: showStore.value ? (tenant.current?.name ?? '') : '' }));

function toRow(v: Variant, quantity = 1): Row {
    const row: Row = {
        id: v.id!,
        sku: v.sku,
        barcode: v.barcode,
        productName: v.product?.name ?? '',
        variantName: v.name,
        unit: v.product?.unit ?? null,
        image: v.product?.image ?? null,
        retail: v.ref_price,
        wholesale: v.wholesale_price ?? null,
        price: null,
        onHand: v.stock ? v.stock.on_hand : null,
        quantity,
        error: null,
        warning: null,
    };
    row.price = priceList.value === 'wholesale' ? (row.wholesale ?? row.retail) : row.retail;
    const code = labelCode(row);
    try {
        barcodeSvg(code);
        row.warning = codeWarning(code);
    } catch (e) {
        row.error = (e as Error).message;
    }
    return row;
}

watch(priceList, (list) => rows.value.forEach((r) => (r.price = list === 'wholesale' ? (r.wholesale ?? r.retail) : r.retail)));

function add(v: Variant) {
    const existing = rows.value.find((r) => r.id === v.id);
    if (existing) existing.quantity++;
    else rows.value.push(toRow(v));
}

async function fetchVariants(params: Record<string, unknown>): Promise<Variant[]> {
    const { data } = await api.get('/variants', { params: { ...params, warehouse: warehouseId.value || undefined } });
    return data.data as Variant[];
}

/** Branch changed: reload the stock column for the rows already picked. */
watch(warehouseId, async () => {
    if (!rows.value.length) return;
    try {
        const fresh = new Map((await fetchVariants({ ids: rows.value.map((r) => r.id) })).map((v) => [v.id, v]));
        rows.value.forEach((r) => (r.onHand = fresh.get(r.id)?.stock?.on_hand ?? null));
    } catch (e) {
        toast.error((e as ApiError).message);
    }
});

onMounted(async () => {
    try {
        const { data } = await api.get('/warehouses');
        warehouses.value = (data.data as Warehouse[]).filter((w) => w.purpose !== 'defective' && w.status === 'active');
        warehouseId.value = warehouses.value.find((w) => w.is_default)?.id ?? warehouses.value[0]?.id ?? '';
    } catch {
        // Stock column stays empty; printing does not need it.
    }
    const list = (key: string) => String(route.query[key] ?? '').split(',').filter(Boolean);
    // ?lines=variant:qty,… (from a stock receipt or purchase order): one label per unit received.
    const perLine = new Map(list('lines').map((l) => l.split(':')).map(([id, q]) => [id!, Math.max(0, Math.floor(Number(q) || 0))]));
    const ids = [...list('variants'), ...perLine.keys()];
    const products = list('products');
    if (!ids.length && !products.length) return;
    try {
        const variants = await fetchVariants({ ids: ids.length ? ids : undefined, products: products.length ? products : undefined });
        const qty = Math.max(1, Number(route.query.qty ?? 1) || 1);
        rows.value = variants.map((v) => toRow(v, perLine.get(v.id!) ?? qty));
    } catch (e) {
        toast.error((e as ApiError).message);
    }
});

const printable = computed(() => rows.value.filter((r) => !r.error));
const labels = computed(() => expandLabels(printable.value));
const pages = computed(() => paginate(paper.value, printOptions.value, labels.value));
const errors = computed(() => rows.value.filter((r) => r.error).length);

function applyBulk() {
    const n = Math.max(0, Math.floor(Number(bulkQty.value)));
    if (!Number.isFinite(n)) return;
    rows.value.forEach((r) => (r.quantity = n));
}

function fromStock() {
    rows.value.forEach((r) => (r.quantity = Math.max(0, r.onHand ?? 0)));
}

async function print(mode: 'open' | 'download') {
    if (!labels.value.length) return;
    busy.value = true;
    try {
        await printLabels(printable.value, printOptions.value, metrics.value, mode);
    } catch (e) {
        toast.error((e as Error).message);
    } finally {
        busy.value = false;
    }
}

/** Real font widths, so the preview and the PDF wrap names exactly like Sapo (Arial metrics). */
const metrics = shallowRef<FontMetrics>(approxMetrics);
const FONT = 'EcsoLabelSans';
onMounted(async () => {
    try {
        const font = await loadLabelFont();
        metrics.value = parseFontMetrics(font.regular);
        for (const [data, weight] of [[font.regular, '400'], [font.bold, '700']] as const) {
            const face = new FontFace(FONT, data, { weight });
            await face.load();
            document.fonts.add(face);
        }
    } catch {
        // Preview falls back to approximate widths; printing loads the font again and reports its own error.
    }
});

// Preview: the first page at scale (px per pt), drawn from the same parts as the PDF.
const scale = computed(() => (roll.value ? 420 / (paper.value.page[0] * MM) : 300 / (paper.value.page[1] * MM)));
const previewPage = computed(() => {
    const first = pages.value[0];
    if (first) return first;
    const sample: LabelItem = { sku: 'SP-0001', barcode: null, productName: 'Tên sản phẩm', variantName: null, price: 100000, unit: 'Cái', quantity: 1 };
    return paginate(paper.value, printOptions.value, [sample])[0]!;
});
const zoomScale = computed(() => 220 / (paper.value.width * MM));
const zoomLabel = computed(() => previewPage.value[0]!);

const fields: { key: 'showName' | 'showPrice' | 'priceCurrency' | 'priceUnit'; label: string }[] = [
    { key: 'showName', label: 'Tên sản phẩm' },
    { key: 'showPrice', label: 'Giá bán' },
    { key: 'priceCurrency', label: 'Giá kèm đơn vị tiền VND' },
    { key: 'priceUnit', label: 'Giá kèm đơn vị tính' },
];
</script>

<template>
    <PageHeader title="In tem mã vạch" description="In tem dán sản phẩm đúng khổ giấy tem cuộn hoặc giấy A4, A5. Khi in chọn tỷ lệ 100% (Actual size), không chọn Fit to page." />

    <Card>
        <div class="flex flex-wrap items-center gap-2 border-b px-4 py-3">
            <Select v-model="warehouseId" class="w-full sm:w-48" :options="warehouses.map((w) => ({ value: w.id, label: w.name }))" aria-label="Chi nhánh" data-testid="label-warehouse" />
            <Select
                :model-value="priceList"
                class="w-full sm:w-40"
                :options="[
                    { value: 'retail', label: 'Giá bán lẻ' },
                    { value: 'wholesale', label: 'Giá bán buôn' },
                ]"
                aria-label="Bảng giá"
                data-testid="label-price-list"
                @update:model-value="(v?: string) => (priceList = (v ?? 'retail') as PriceList)"
            />
            <SkuPicker class="min-w-64 flex-1" :warehouse="warehouseId" placeholder="Tìm theo tên, mã SKU, hoặc quét mã Barcode…" @pick="add" />
        </div>
        <EmptyState v-if="!rows.length" :icon="Tags" title="Chưa chọn sản phẩm" description="Chọn sản phẩm ở danh sách rồi bấm In tem, hoặc tìm, quét mã vạch ở ô trên." />
        <template v-else>
            <div class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="label-table">
                    <thead class="border-b bg-slate-50 text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="w-12 px-4 py-2 font-medium">STT</th>
                            <th class="w-14 px-2 py-2 font-medium">Ảnh</th>
                            <th class="px-4 py-2 font-medium">Mã barcode</th>
                            <th class="px-4 py-2 font-medium">Tên sản phẩm</th>
                            <th class="px-4 py-2 text-right font-medium">Tồn kho</th>
                            <th class="px-4 py-2 text-right font-medium">Số lượng</th>
                            <th class="px-4 py-2 text-right font-medium">Đơn giá</th>
                            <th class="w-10 px-2" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(r, i) in rows" :key="r.id">
                            <td class="px-4 py-2 tabular-nums">{{ i + 1 }}</td>
                            <td class="px-2 py-2">
                                <img v-if="r.image" :src="r.image" alt="" class="size-9 rounded border object-cover" />
                                <div v-else class="grid size-9 place-items-center rounded border bg-muted/50"><ImageOff class="size-4 text-muted-foreground" /></div>
                            </td>
                            <td class="px-4 py-2">
                                <span class="font-mono text-xs text-primary">{{ labelCode(r) }}</span>
                                <p v-if="r.error" class="text-xs text-destructive">{{ r.error }} Sửa mã vạch của SKU để in.</p>
                                <p v-else-if="r.warning" class="text-xs text-amber-600">{{ r.warning }}</p>
                            </td>
                            <td class="px-4 py-2">
                                <p>{{ [r.productName, r.variantName].filter(Boolean).join(' - ') }}</p>
                                <p class="text-xs text-muted-foreground"><span class="font-mono">{{ r.sku }}</span><span v-if="r.unit"> · ĐVT: {{ r.unit }}</span></p>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ r.onHand === null ? '—' : formatNumber(r.onHand) }}</td>
                            <td class="px-4 py-2 text-right">
                                <input v-model.number="r.quantity" type="number" min="0" max="1000" :disabled="!!r.error" :data-testid="`label-qty-${i}`" class="h-8 w-20 rounded-md border border-input bg-card px-2 text-right tabular-nums" />
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ formatNumber(r.price) }}</td>
                            <td class="px-2 text-right">
                                <Button variant="ghost" size="icon" class="size-8" title="Bỏ sản phẩm" @click="rows.splice(i, 1)"><X class="text-muted-foreground" /></Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="flex flex-wrap items-center gap-2 border-t px-4 py-3 text-sm text-muted-foreground">
                Số tem cho tất cả dòng:
                <Input v-model="bulkQty" type="number" min="0" class="h-8 w-20" aria-label="Số tem cho tất cả" />
                <Button variant="outline" size="sm" :disabled="bulkQty === ''" @click="applyBulk">Áp dụng</Button>
                <Button variant="outline" size="sm" :disabled="!warehouseId" data-testid="label-from-stock" @click="fromStock">Lấy theo tồn kho chi nhánh</Button>
                <span class="ml-auto">Tổng {{ formatNumber(labels.length) }} tem</span>
            </div>
        </template>
    </Card>

    <div class="mt-5 grid gap-5 lg:grid-cols-[1fr_380px]">
        <Card title="Thiết lập mẫu in tem">
            <div class="grid gap-4 p-5 md:grid-cols-[220px_1fr]">
                <div class="space-y-3 text-sm">
                    <label class="flex items-center gap-2"><input v-model="options.showName" type="checkbox" class="size-4" /> Tên sản phẩm</label>
                    <label class="flex items-center gap-2 text-muted-foreground"><input type="checkbox" class="size-4" checked disabled /> Mã Barcode</label>
                    <label v-for="f in fields.slice(1)" :key="f.key" class="flex items-center gap-2"><input v-model="options[f.key]" type="checkbox" class="size-4" :data-testid="`label-opt-${f.key}`" /> {{ f.label }}</label>
                    <label class="flex items-center gap-2"><input v-model="options.wide" type="checkbox" class="size-4" data-testid="label-opt-wide" /> Khổ rộng</label>
                    <label class="flex items-center gap-2"><input v-model="showStore" type="checkbox" class="size-4" data-testid="label-opt-store" /> Tên cửa hàng</label>
                </div>
                <div class="flex flex-col items-center justify-center gap-3 rounded-lg bg-muted/50 p-4">
                    <div class="flex flex-wrap items-center justify-center gap-4">
                        <div class="relative bg-white shadow-sm" :style="{ width: `${paper.page[0] * MM * scale}px`, height: `${paper.page[1] * MM * scale}px` }" data-testid="label-preview">
                            <div v-for="(l, i) in previewPage" :key="i" class="absolute outline-dashed outline-1 outline-slate-300" :style="{ left: `${l.slot.x * MM * scale}px`, top: `${l.slot.y * MM * scale}px` }">
                                <LabelPreview :item="l.item" :options="printOptions" :paper="paper" :metrics="metrics" :scale="scale" :font-family="FONT" />
                            </div>
                        </div>
                        <div v-if="!roll" class="text-center">
                            <LabelPreview class="border border-dashed border-slate-300 shadow-sm" :item="zoomLabel.item" :options="printOptions" :paper="paper" :metrics="metrics" :scale="zoomScale" :font-family="FONT" />
                            <p class="mt-1 text-xs text-muted-foreground">Phóng to 1 tem</p>
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        <template v-if="roll">Khổ in {{ paper.page[0] }}×{{ paper.page[1] }} mm, mỗi tem {{ paper.width }}×{{ paper.height }} mm</template>
                        <template v-else>Trang {{ paper.group === 'a4' ? 'A4' : 'A5' }}, mỗi tem {{ paper.width }}×{{ paper.height }} mm, {{ paper.columns * paper.rows }} tem/trang</template>
                    </p>
                </div>
            </div>
            <div class="px-5 pb-5 text-xs text-slate-600">
                <p class="font-semibold">Lưu ý:</p>
                <ul class="mt-1 list-disc space-y-0.5 pl-5 italic">
                    <li>Nếu mã barcode in ra không đọc được, dùng khổ giấy lớn hơn, bật Khổ rộng hoặc rút ngắn mã barcode của sản phẩm.</li>
                    <li>Mã khó đọc gồm mã có ký tự đặc biệt (@ * ^ , . / ~…), tiếng Việt có dấu hoặc dài quá 15 ký tự.</li>
                </ul>
            </div>
        </Card>

        <Card title="Chọn khổ in và giấy in">
            <div class="divide-y px-5">
                <div v-for="g in paperGroups" :key="g.key" class="py-3">
                    <p class="mb-1 text-sm font-semibold">{{ g.label }}</p>
                    <label v-for="[key, p] in Object.entries(papers).filter(([, p]) => p.group === g.key)" :key="key" class="flex items-center gap-2 py-1 text-sm">
                        <input v-model="options.paper" type="radio" name="label-paper" :value="key as PaperKey" class="size-4" :data-testid="`label-paper-${key}`" />
                        {{ p.label }}
                        <span v-if="p.group === 'a4' || p.group === 'a5'" class="text-xs text-muted-foreground">· tem {{ p.width }}×{{ p.height }} mm</span>
                    </label>
                </div>
                <div class="flex flex-wrap items-center gap-2 py-3 text-sm">
                    <span class="w-full text-muted-foreground">Căn chỉnh khi in lệch (mm, số âm để dịch ngược lại)</span>
                    Trên <Input v-model.number="options.offsetTop" type="number" step="0.5" class="h-8 w-20" aria-label="Lề trên" />
                    Trái <Input v-model.number="options.offsetLeft" type="number" step="0.5" class="h-8 w-20" aria-label="Lề trái" />
                </div>
            </div>
        </Card>
    </div>

    <div class="mt-5 flex flex-wrap items-center justify-end gap-2 rounded-xl border bg-card px-5 py-3">
        <span class="mr-auto text-sm text-muted-foreground" data-testid="label-summary">
            {{ formatNumber(labels.length) }} tem · {{ formatNumber(pages.length) }} {{ roll ? 'hàng tem' : 'trang' }}<template v-if="errors"> · {{ errors }} dòng lỗi mã không in</template>
        </span>
        <Button variant="outline" :disabled="!labels.length || busy" @click="print('open')"><Printer /> In tem</Button>
        <Button :disabled="!labels.length" :loading="busy" data-testid="download-labels" @click="print('download')"><Download /> Tải PDF ({{ formatNumber(labels.length) }} tem)</Button>
    </div>
</template>
