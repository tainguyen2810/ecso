<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { Download, Printer, Tags, Trash2 } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import SkuPicker from '../../components/SkuPicker.vue';
import ProductsNav from './ProductsNav.vue';
import { barcodeSvg } from './barcode';
import { expandLabels, labelCode, labelLayouts, printLabels, type LabelItem, type LabelLayoutKey, type LabelOptions } from './labels';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import type { Variant } from '@/shared/types';

type Row = LabelItem & { id: string; error: string | null };

const route = useRoute();
const toast = useToast();
const rows = ref<Row[]>([]);
const busy = ref(false);
const bulkQty = ref('');

const STORAGE_KEY = 'ecso.labels.options';
function savedOptions(): Partial<LabelOptions> {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}');
    } catch {
        return {};
    }
}
const options = reactive<LabelOptions>({ layout: '50x30', showName: true, showPrice: true, heading: '', ...savedOptions() });
if (!(options.layout in labelLayouts)) options.layout = '50x30';
watch(options, () => {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(options));
    } catch {
        // Private mode: keep the options for this visit only.
    }
});

function toRow(v: Variant, quantity = 1): Row {
    const item: Row = {
        id: v.id!,
        sku: v.sku,
        barcode: v.barcode,
        productName: v.product?.name ?? '',
        variantName: v.name,
        price: v.ref_price,
        quantity,
        error: null,
    };
    try {
        barcodeSvg(labelCode(item));
    } catch (e) {
        item.error = (e as Error).message;
    }
    return item;
}

function add(v: Variant) {
    const existing = rows.value.find((r) => r.id === v.id);
    if (existing) existing.quantity++;
    else rows.value.push(toRow(v));
}

onMounted(async () => {
    const list = (key: string) => String(route.query[key] ?? '').split(',').filter(Boolean);
    // ?lines=variant:qty,… (from a stock receipt or purchase order): one label per unit received.
    const perLine = new Map(list('lines').map((l) => l.split(':')).map(([id, q]) => [id!, Math.max(0, Math.floor(Number(q) || 0))]));
    const ids = [...list('variants'), ...perLine.keys()];
    const products = list('products');
    if (!ids.length && !products.length) return;
    try {
        const { data } = await api.get('/variants', { params: { ids: ids.length ? ids : undefined, products: products.length ? products : undefined } });
        const qty = Math.max(1, Number(route.query.qty ?? 1) || 1);
        rows.value = (data.data as Variant[]).map((v) => toRow(v, perLine.get(v.id!) ?? qty));
    } catch (e) {
        toast.error((e as ApiError).message);
    }
});

const printable = computed(() => rows.value.filter((r) => !r.error));
const total = computed(() => expandLabels(printable.value).length);
const preview = computed(() => printable.value[0] ?? null);
const layout = computed(() => labelLayouts[options.layout]);

function applyBulk() {
    const n = Math.max(0, Math.floor(Number(bulkQty.value)));
    if (!Number.isFinite(n)) return;
    rows.value.forEach((r) => (r.quantity = n));
}

async function print(mode: 'open' | 'download') {
    if (!total.value) return;
    busy.value = true;
    try {
        await printLabels(printable.value, { ...options }, mode);
    } catch (e) {
        toast.error((e as Error).message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <PageHeader title="In tem mã vạch" description="In tem dán sản phẩm: mã vạch (EAN-13 nếu SKU có mã vạch hợp lệ, còn lại Code 128 theo SKU), tên và giá.">
        <Button variant="outline" :disabled="!total || busy" @click="print('open')"><Printer /> Mở để in</Button>
        <Button :disabled="!total" :loading="busy" data-testid="download-labels" @click="print('download')"><Download /> Tải PDF ({{ formatNumber(total) }} tem)</Button>
    </PageHeader>
    <ProductsNav />

    <div class="grid gap-5 lg:grid-cols-3">
        <Card title="SKU cần in" class="lg:col-span-2" description="Quét mã vạch hoặc tìm theo SKU, tên sản phẩm. Quét lại cùng SKU để tăng số tem.">
            <div class="flex flex-wrap items-center gap-2 border-b px-5 py-3">
                <SkuPicker class="min-w-64 flex-1" placeholder="Quét hoặc tìm SKU" @pick="add" />
                <template v-if="rows.length">
                    <Input v-model="bulkQty" type="number" min="0" class="w-24" placeholder="Số tem" aria-label="Số tem cho tất cả" />
                    <Button variant="outline" size="sm" :disabled="bulkQty === ''" @click="applyBulk">Áp dụng tất cả</Button>
                </template>
            </div>
            <EmptyState v-if="!rows.length" :icon="Tags" title="Chưa chọn SKU" description="Chọn sản phẩm ở danh sách rồi bấm In tem, hoặc quét mã vạch ở ô trên." />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="label-table">
                    <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Sản phẩm</th>
                            <th class="px-4 py-2 font-medium">Mã in trên tem</th>
                            <th class="px-4 py-2 text-right font-medium">Giá bán</th>
                            <th class="px-4 py-2 text-right font-medium">Số tem</th>
                            <th class="w-10 px-2" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(r, i) in rows" :key="r.id">
                            <td class="px-4 py-2">
                                <p class="font-medium">{{ r.productName }}</p>
                                <p class="text-xs text-muted-foreground"><span class="font-mono">{{ r.sku }}</span><span v-if="r.variantName"> · {{ r.variantName }}</span></p>
                            </td>
                            <td class="px-4 py-2 font-mono text-xs">
                                {{ labelCode(r) }}
                                <p v-if="r.error" class="font-sans text-destructive">{{ r.error }} Sửa mã vạch của SKU để in.</p>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ formatMoney(r.price) }}</td>
                            <td class="px-4 py-2 text-right">
                                <input v-model.number="r.quantity" type="number" min="0" max="1000" :disabled="!!r.error" :data-testid="`label-qty-${i}`" class="h-8 w-20 rounded-md border border-input bg-card px-2 text-right tabular-nums" />
                            </td>
                            <td class="px-2 text-right">
                                <Button variant="ghost" size="icon" class="size-8" title="Bỏ SKU" @click="rows.splice(i, 1)"><Trash2 class="text-muted-foreground" /></Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <div class="space-y-5">
            <Card title="Mẫu tem">
                <div class="space-y-4 p-5">
                    <FormField label="Khổ giấy" for="l-layout">
                        <Select id="l-layout" :model-value="options.layout" :options="Object.entries(labelLayouts).map(([value, l]) => ({ value, label: l.label }))" @update:model-value="(v?: string) => (options.layout = (v ?? options.layout) as LabelLayoutKey)" />
                    </FormField>
                    <FormField label="Dòng tiêu đề (tùy chọn)" for="l-heading">
                        <Input id="l-heading" v-model="options.heading" maxlength="40" placeholder="Tên cửa hàng" />
                    </FormField>
                    <label class="flex items-center gap-2 text-sm"><input v-model="options.showName" type="checkbox" /> In tên sản phẩm</label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="options.showPrice" type="checkbox" /> In giá bán</label>
                </div>
            </Card>
            <Card title="Xem trước" :description="`${layout.width} × ${layout.height} mm`">
                <div class="flex justify-center bg-muted/40 p-5">
                    <div
                        v-if="preview"
                        class="flex flex-col items-center justify-between overflow-hidden rounded-sm border bg-white p-1 text-center text-black shadow-sm"
                        :style="{ width: `${layout.width * 4}px`, height: `${layout.height * 4}px` }"
                        data-testid="label-preview"
                    >
                        <p v-if="options.heading" class="w-full truncate text-[10px] font-bold leading-tight">{{ options.heading }}</p>
                        <p v-if="options.showName" class="line-clamp-2 w-full text-[10px] leading-tight">{{ [preview.productName, preview.variantName].filter(Boolean).join(' - ') }}</p>
                        <div class="w-full flex-1 [&>svg]:h-full [&>svg]:w-full" v-html="barcodeSvg(labelCode(preview))" />
                        <p class="font-mono text-[10px] leading-tight">{{ labelCode(preview) }}</p>
                        <p v-if="options.showPrice && Number(preview.price) > 0" class="text-xs font-bold leading-tight">{{ formatMoney(preview.price) }}</p>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">Chọn SKU để xem mẫu.</p>
                </div>
            </Card>
        </div>
    </div>
</template>
