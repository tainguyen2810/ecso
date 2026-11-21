<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Camera, CheckCircle2, FileCode2, ImageUp, Loader2, Plus, Sparkles, TriangleAlert, X } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import SkuPicker from '../../components/SkuPicker.vue';
import AiNotice from '../../components/AiNotice.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { toPhotos, type Photo } from '@/shared/lib/photos';
import type { Supplier, Variant, Warehouse } from '@/shared/types';
import { buildDraft, priceRise, reviewLines, SCAN_DRAFT_KEY, skuFromLine, type ReviewLine, type ScanResult } from './scan';

/**
 * Nhập hàng › Chụp hoá đơn: photos of a supplier invoice are read by EzChat AI, each line is matched with a SKU
 * ("Khớp", "Gần giống", "Hàng mới"), the total is checked, then "Tạo phiếu nhập nháp" opens the purchase form filled in.
 */
const MAX_PAGES = 6;
const router = useRouter();
const route = useRoute();
/** Opened from "Hoá đơn mua vào" (?invoice=…): the e-invoice is already read, no photo and no AI credit. */
const invoiceId = typeof route.query.invoice === 'string' ? route.query.invoice : null;
const einvoice = ref<{ series: string; number: string; issued_on: string | null; seller: { name: string; tax_code: string }; total: string; vat_amount: string; authority_code: string | null; status: string; purchase_order: { id: string; number: string } | null } | null>(null);
const toast = useToast();

const status = ref<{ available: boolean; reason: string | null } | null>(null);
const photos = ref<Photo[]>([]);
const preparing = ref(false);
const reading = ref(false);
const failure = ref<string | null>(null);
const result = ref<ScanResult | null>(null);
const lines = ref<ReviewLine[]>([]);
const credits = ref(0);
const suppliers = ref<Supplier[]>([]);
const warehouses = ref<Warehouse[]>([]);
const supplierId = ref('');
const warehouseId = ref('');
const picking = ref<number | null>(null);
const creating = ref<number | null>(null);
const preview = ref(0);

onMounted(async () => {
    const [s, sup, w] = await Promise.all([
        api.get('/assistant/status').catch(() => null),
        api.get('/suppliers', { params: { active: 1, per_page: 200 } }),
        api.get('/warehouses'),
    ]);
    status.value = s?.data.data ?? { available: false, reason: 'error' };
    suppliers.value = sup.data.data;
    warehouses.value = (w.data.data as Warehouse[]).filter((x) => x.status === 'active' && x.purpose !== 'defective');
    warehouseId.value = (warehouses.value.find((x) => x.is_default) ?? warehouses.value[0])?.id ?? '';
    if (invoiceId) await openInvoice(invoiceId);
});

async function openInvoice(id: string) {
    reading.value = true;
    try {
        const { data } = await api.get(`/purchase-invoices/${id}`);
        einvoice.value = data.data;
        result.value = data.data.draft as ScanResult;
        lines.value = reviewLines(result.value.lines);
        supplierId.value = data.data.supplier?.id ?? result.value.supplier.match?.id ?? '';
    } catch (e) {
        failure.value = (e as ApiError).message || 'Không mở được hoá đơn.';
    } finally {
        reading.value = false;
    }
}
onBeforeUnmount(() => photos.value.forEach((p) => URL.revokeObjectURL(p.url)));

async function addFiles(e: Event) {
    const input = e.target as HTMLInputElement;
    if (!input.files?.length) return;
    preparing.value = true;
    try {
        const room = MAX_PAGES - photos.value.length;
        if (input.files.length > room) toast.info(`Tối đa ${MAX_PAGES} trang mỗi hoá đơn.`);
        photos.value.push(...(await toPhotos(input.files, room)));
    } catch {
        toast.error('Không mở được ảnh này.');
    } finally {
        preparing.value = false;
        input.value = '';
    }
}

function removePhoto(i: number) {
    URL.revokeObjectURL(photos.value[i]!.url);
    photos.value.splice(i, 1);
}

async function read() {
    if (!photos.value.length || reading.value) return;
    reading.value = true;
    failure.value = null;
    try {
        const { data } = await api.post('/assistant/invoice', { images: photos.value.map((p) => p.data) }, { timeout: 180000 });
        result.value = data.data.draft as ScanResult;
        credits.value = data.data.credits;
        lines.value = reviewLines(result.value.lines);
        supplierId.value = result.value.supplier.match?.id ?? '';
        if (!lines.value.length) failure.value = 'AI không thấy dòng hàng nào trên ảnh. Chụp thẳng, đủ sáng, rõ phần bảng hàng rồi thử lại.';
    } catch (e) {
        failure.value = (e as ApiError).message || 'Chưa đọc được hoá đơn.';
    } finally {
        reading.value = false;
    }
}

function reset() {
    if (invoiceId) {
        router.push('/purchasing/invoices');
        return;
    }
    result.value = null;
    lines.value = [];
    failure.value = null;
}

function choose(i: number, v: Variant) {
    const l = lines.value[i]!;
    l.pick = { id: v.id!, sku: v.sku, name: `${v.product?.name ?? ''}${v.name ? ' · ' + v.name : ''}`, score: 1, cost_price: v.cost_price ? Number(v.cost_price) : null };
    l.include = true;
    picking.value = null;
}

function pickCandidate(i: number, id: string) {
    const l = lines.value[i]!;
    l.pick = l.candidates.find((c) => c.id === id) ?? l.pick;
}

/** "Tạo sản phẩm mới" for a line not in the catalogue: name and supplier code from the invoice. */
async function createProduct(i: number) {
    const l = lines.value[i]!;
    creating.value = i;
    try {
        const sku = skuFromLine(l);
        const free = (await api.get('/products/sku-check', { params: { sku } }).catch(() => null))?.data?.available !== false;
        const finalSku = free ? sku : `${sku}-${Date.now().toString().slice(-4)}`;
        const { data } = await api.post('/products', {
            name: l.name || finalSku,
            unit: l.unit || null,
            variants: [{ sku: finalSku, supplier_id: supplierId.value || null, supplier_sku: l.code || null }],
        });
        const v = (data.data.variants ?? [])[0];
        l.pick = { id: v.id, sku: v.sku, name: data.data.name, score: 1, cost_price: null };
        l.status = 'matched';
        l.include = true;
        toast.success(`Đã tạo sản phẩm ${v.sku}.`);
    } catch (e) {
        toast.error((e as ApiError).message || 'Chưa tạo được sản phẩm.');
    } finally {
        creating.value = null;
    }
}

async function createSupplier() {
    const r = result.value?.supplier.read;
    if (!r?.name) return;
    try {
        const { data } = await api.post('/suppliers', { name: r.name, tax_code: r.tax_code || null, address: r.address || null, phone: r.phone || null });
        suppliers.value.unshift(data.data);
        supplierId.value = data.data.id;
        toast.success('Đã thêm nhà cung cấp.');
    } catch (e) {
        toast.error((e as ApiError).message || 'Chưa thêm được nhà cung cấp.');
    }
}

const picked = computed(() => lines.value.filter((l) => l.include && l.pick).length);
const waiting = computed(() => lines.value.filter((l) => l.include && !l.pick).length);
const linked = computed(() => einvoice.value?.status === 'linked');
const ready = computed(() => !linked.value && !!supplierId.value && !!warehouseId.value && picked.value > 0 && waiting.value === 0);

function makeDraft() {
    if (!result.value || !ready.value) return;
    try {
        sessionStorage.setItem(SCAN_DRAFT_KEY, JSON.stringify(buildDraft(result.value, lines.value, supplierId.value, warehouseId.value, invoiceId)));
    } catch {
        toast.error('Trình duyệt không cho lưu tạm, hãy thử lại.');
        return;
    }
    router.push({ path: '/purchasing/new', query: { from: 'scan' } });
}

const tone = (l: ReviewLine) =>
    !l.pick ? 'bg-rose-50 text-rose-700' : l.status === 'maybe' && l.pick === l.variant ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700';
const label = (l: ReviewLine) =>
    !l.pick ? 'Hàng mới' : l.status === 'maybe' && l.pick === l.variant ? `Gần giống ${Math.round((l.pick.score ?? 0) * 100)}%` : 'Khớp';
</script>

<template>
    <PageHeader
        v-if="invoiceId"
        :title="einvoice ? `Tạo phiếu nhập từ hoá đơn ${einvoice.series} ${einvoice.number}` : 'Tạo phiếu nhập từ hoá đơn'"
        description="Từng dòng hoá đơn được ghép với sản phẩm trong kho. Kiểm tra rồi tạo phiếu nhập nháp."
        :back="{ to: '/purchasing/invoices', label: 'Hoá đơn mua vào' }"
    />
    <PageHeader v-else title="Chụp hoá đơn nhập hàng" description="AI đọc hoá đơn của nhà cung cấp, ghép với sản phẩm trong kho rồi tạo phiếu nhập nháp cho bạn kiểm tra." :back="{ to: '/purchasing', label: 'Nhập hàng' }" />

    <AiNotice v-if="!invoiceId && status && !status.available" :reason="status.reason" class="mb-4" />

    <div v-if="invoiceId && !result" class="py-16 text-center text-sm text-muted-foreground">
        <Loader2 v-if="reading" class="mx-auto mb-2 size-5 animate-spin" />
        <span v-if="reading">Đang mở hoá đơn…</span>
        <span v-else class="text-rose-700">{{ failure }}</span>
    </div>

    <!-- Step 1: photos -->
    <Card v-else-if="!result" class="p-5">
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-primary/30 bg-primary/5 p-6 text-center" data-testid="scan-camera">
                <span class="grid size-12 place-items-center rounded-2xl bg-card text-primary shadow-sm"><Camera class="size-6" /></span>
                <span class="mt-3 font-medium">Chụp bằng camera</span>
                <span class="mt-1 text-sm text-muted-foreground">Trên điện thoại: mở camera, chụp từng trang.</span>
                <input type="file" accept="image/*" capture="environment" class="sr-only" @change="addFiles" />
            </label>
            <label class="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed p-6 text-center" data-testid="scan-files">
                <span class="grid size-12 place-items-center rounded-2xl bg-muted text-muted-foreground"><ImageUp class="size-6" /></span>
                <span class="mt-3 font-medium">Chọn ảnh có sẵn</span>
                <span class="mt-1 text-sm text-muted-foreground">Ảnh hoá đơn giấy, ảnh nhà cung cấp gửi qua Zalo. Nhiều trang thì chọn nhiều ảnh (tối đa {{ MAX_PAGES }}).</span>
                <input type="file" accept="image/*" multiple class="sr-only" data-testid="scan-input" @change="addFiles" />
            </label>
        </div>
        <div v-if="photos.length || preparing" class="mt-5 flex flex-wrap gap-3">
            <div v-for="(p, i) in photos" :key="p.url" class="relative size-28 overflow-hidden rounded-lg border bg-muted">
                <img :src="p.url" alt="" class="size-full object-cover" />
                <span class="absolute bottom-1 left-1 rounded bg-white/90 px-1.5 text-[11px]">Trang {{ i + 1 }}</span>
                <button type="button" class="absolute right-1 top-1 grid size-6 place-items-center rounded-full bg-white/90 text-slate-600" aria-label="Bỏ ảnh" @click="removePhoto(i)"><X class="size-3.5" /></button>
            </div>
            <div v-if="preparing" class="grid size-28 place-items-center rounded-lg border"><Loader2 class="size-5 animate-spin text-muted-foreground" /></div>
        </div>
        <div v-if="failure" class="mt-4 flex gap-2 rounded-lg bg-rose-50 p-3 text-sm text-rose-700" data-testid="scan-error"><TriangleAlert class="mt-0.5 size-4 shrink-0" />{{ failure }}</div>
        <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t pt-4">
            <span class="text-sm text-muted-foreground"><Sparkles class="mr-1 inline size-4 text-violet-500" />Dùng credit EzChat AI, khoảng 1 lượt đọc ảnh mỗi trang.</span>
            <Button :disabled="!photos.length || !status?.available" :loading="reading" data-testid="scan-read" @click="read"><Sparkles /> {{ reading ? 'AI đang đọc…' : 'Đọc hoá đơn' }}</Button>
        </div>
    </Card>

    <!-- Step 2: review -->
    <div v-else class="grid grid-cols-[minmax(0,1fr)] items-start gap-6 xl:grid-cols-[380px_minmax(0,1fr)]">
        <div class="space-y-3 xl:sticky xl:top-4">
            <Card v-if="einvoice" class="space-y-3 p-4 text-sm" data-testid="einvoice-card">
                <div class="flex items-center gap-2 font-semibold"><FileCode2 class="size-4 text-primary" />Hoá đơn điện tử</div>
                <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1.5">
                    <dt class="text-muted-foreground">Người bán</dt><dd class="font-medium">{{ einvoice.seller.name }}</dd>
                    <dt class="text-muted-foreground">MST</dt><dd>{{ einvoice.seller.tax_code }}</dd>
                    <dt class="text-muted-foreground">Ký hiệu, số</dt><dd>{{ einvoice.series }} · {{ einvoice.number }}</dd>
                    <dt class="text-muted-foreground">Ngày</dt><dd>{{ einvoice.issued_on?.split('-').reverse().join('/') ?? '—' }}</dd>
                    <dt class="text-muted-foreground">Mã CQT</dt><dd class="break-all">{{ einvoice.authority_code || 'Không có' }}</dd>
                    <dt class="text-muted-foreground">Thuế GTGT</dt><dd class="tabular-nums">{{ formatMoney(Number(einvoice.vat_amount)) }}</dd>
                    <dt class="text-muted-foreground">Tổng tiền</dt><dd class="font-semibold tabular-nums">{{ formatMoney(Number(einvoice.total)) }}</dd>
                </dl>
                <div v-if="einvoice.purchase_order" class="rounded-lg bg-emerald-50 p-3 text-emerald-800">
                    Đã vào phiếu nhập <RouterLink :to="`/purchasing/${einvoice.purchase_order.id}`" class="font-medium underline">{{ einvoice.purchase_order.number }}</RouterLink>.
                </div>
            </Card>
            <Card v-else class="p-3">
                <img v-if="photos[preview]" :src="photos[preview]!.url" alt="Ảnh hoá đơn" class="max-h-[40vh] w-full rounded-lg object-contain xl:max-h-[70vh]" />
                <div v-if="photos.length > 1" class="mt-2 flex gap-2">
                    <button v-for="(p, i) in photos" :key="p.url" type="button" class="size-12 overflow-hidden rounded border" :class="i === preview ? 'ring-2 ring-primary' : ''" @click="preview = i"><img :src="p.url" alt="" class="size-full object-cover" /></button>
                </div>
            </Card>
            <Button variant="outline" class="w-full" @click="reset">{{ invoiceId ? 'Về danh sách hoá đơn' : 'Chụp lại' }}</Button>
        </div>

        <Card>
            <div class="flex flex-wrap items-center gap-2 border-b px-4 py-3">
                <span class="font-semibold">{{ invoiceId ? 'Dòng hàng trên hoá đơn' : 'AI đã đọc được' }}</span>
                <span v-if="!invoiceId" class="inline-flex items-center gap-1 rounded-full bg-violet-50 px-2 py-0.5 text-xs font-medium text-violet-700"><Sparkles class="size-3" /> EzChat AI</span>
                <span class="ml-auto text-xs text-muted-foreground">{{ lines.length }} dòng<template v-if="!invoiceId"> · {{ credits }} credit</template></span>
            </div>
            <div class="grid gap-4 border-b px-4 py-3 text-sm sm:grid-cols-3">
                <div>
                    <div class="mb-1 text-muted-foreground">Nhà cung cấp</div>
                    <Select v-model="supplierId" :options="[{ value: '', label: '— Chọn nhà cung cấp —' }, ...suppliers.map((s) => ({ value: s.id, label: s.name }))]" data-testid="scan-supplier" />
                    <div v-if="result.supplier.read.name" class="mt-1 text-xs text-muted-foreground">
                        Trên hoá đơn: {{ result.supplier.read.name }}<template v-if="result.supplier.read.tax_code"> · MST {{ result.supplier.read.tax_code }}</template>
                        <button v-if="!supplierId" type="button" class="ml-1 font-medium text-primary" data-testid="scan-new-supplier" @click="createSupplier">+ Thêm nhà cung cấp này</button>
                    </div>
                </div>
                <div>
                    <div class="mb-1 text-muted-foreground">Số hoá đơn</div>
                    <div class="font-medium">{{ [result.invoice.series, result.invoice.number].filter(Boolean).join(' ') || '—' }}<template v-if="result.invoice.date"> · {{ result.invoice.date.split('-').reverse().join('/') }}</template></div>
                </div>
                <div>
                    <div class="mb-1 text-muted-foreground">Kho nhập</div>
                    <Select v-model="warehouseId" :options="warehouses.map((w) => ({ value: w.id, label: w.name }))" />
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="scan-lines">
                    <thead class="hidden bg-muted/50 text-left text-xs text-muted-foreground sm:table-header-group">
                        <tr><th class="w-8 px-3 py-2"></th><th class="px-3 py-2">Trên hoá đơn</th><th class="hidden px-3 py-2 text-right sm:table-cell">SL</th><th class="hidden px-3 py-2 text-right sm:table-cell">Đơn giá</th><th class="px-3 py-2">Ghép với sản phẩm</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(l, i) in lines" :key="i" class="border-t align-top max-sm:grid max-sm:grid-cols-[2.5rem_minmax(0,1fr)]" :class="!l.include ? 'opacity-50' : !l.pick ? 'bg-rose-50/40' : ''">
                            <td class="px-3 py-3 max-sm:row-span-2"><input v-model="l.include" type="checkbox" class="size-4" :aria-label="`Đưa dòng ${i + 1} vào phiếu`" /></td>
                            <td class="px-3 py-3">
                                <div>{{ l.name }}</div>
                                <div v-if="l.code || l.unit" class="text-xs text-muted-foreground">{{ [l.code, l.unit].filter(Boolean).join(' · ') }}</div>
                                <div class="mt-0.5 text-xs font-medium tabular-nums sm:hidden">{{ formatNumber(l.quantity) }} × {{ formatMoney(l.unit_price) }}</div>
                            </td>
                            <td class="hidden px-3 py-3 text-right tabular-nums sm:table-cell">{{ formatNumber(l.quantity) }}</td>
                            <td class="hidden whitespace-nowrap px-3 py-3 text-right tabular-nums sm:table-cell">{{ formatMoney(l.unit_price) }}</td>
                            <td class="px-3 py-3 max-sm:col-start-2 max-sm:pt-0 sm:min-w-72">
                                <template v-if="picking === i">
                                    <SkuPicker inline :initial-query="l.code || l.name.split(' ').slice(0, 3).join(' ')" placeholder="Tìm sản phẩm…" @pick="(v) => choose(i, v)" />
                                    <button type="button" class="mt-1 text-xs text-muted-foreground" @click="picking = null">Huỷ</button>
                                </template>
                                <template v-else>
                                    <div v-if="l.pick" class="font-medium">{{ l.pick.name }} <span class="font-normal text-muted-foreground">· {{ l.pick.sku }}</span></div>
                                    <div v-else class="text-muted-foreground">Chưa có sản phẩm này</div>
                                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                                        <span class="rounded-full px-2 py-0.5 font-medium" :class="tone(l)">{{ label(l) }}</span>
                                        <span v-if="priceRise(l) !== null && Math.abs(priceRise(l)!) >= 3" :class="priceRise(l)! > 0 ? 'text-amber-700' : 'text-emerald-700'">
                                            Giá {{ priceRise(l)! > 0 ? 'tăng' : 'giảm' }} {{ Math.abs(priceRise(l)!) }}% so với giá vốn
                                        </span>
                                        <select v-if="l.candidates.length > 1" class="rounded border bg-card px-1 py-0.5" :value="l.pick?.id ?? ''" aria-label="Gợi ý khác" @change="pickCandidate(i, ($event.target as HTMLSelectElement).value)">
                                            <option v-for="c in l.candidates" :key="c.id" :value="c.id">{{ c.sku }} ({{ Math.round(c.score * 100) }}%)</option>
                                        </select>
                                        <button type="button" class="font-medium text-primary" @click="picking = i">{{ l.pick ? 'Đổi' : 'Chọn sản phẩm' }}</button>
                                        <button v-if="!l.pick" type="button" class="inline-flex items-center gap-1 font-medium text-primary" :disabled="creating === i" data-testid="scan-create-product" @click="createProduct(i)">
                                            <Loader2 v-if="creating === i" class="size-3 animate-spin" /><Plus v-else class="size-3" />Tạo sản phẩm mới
                                        </button>
                                    </div>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="space-y-1 border-t px-4 py-3 text-sm">
                <div class="flex justify-between"><span class="text-muted-foreground">Cộng tiền hàng</span><span class="tabular-nums">{{ formatMoney(result.sum) }}</span></div>
                <div v-if="result.discount" class="flex justify-between"><span class="text-muted-foreground">Chiết khấu</span><span class="tabular-nums">−{{ formatMoney(result.discount) }}</span></div>
                <div v-if="result.shipping" class="flex justify-between"><span class="text-muted-foreground">Phí vận chuyển (vào chi phí nhập)</span><span class="tabular-nums">{{ formatMoney(result.shipping) }}</span></div>
                <div v-if="result.vat_amount" class="flex justify-between"><span class="text-muted-foreground">Thuế GTGT<template v-if="result.vat_rate !== null"> {{ result.vat_rate }}%</template></span><span class="tabular-nums">{{ formatMoney(result.vat_amount) }}</span></div>
                <div v-if="result.total" class="flex justify-between font-medium"><span>Tổng trên hoá đơn</span><span class="tabular-nums">{{ formatMoney(result.total) }}</span></div>
            </div>
            <div v-if="result.total_matches === true" class="mx-4 mb-3 flex gap-2 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800" data-testid="scan-total-ok"><CheckCircle2 class="mt-0.5 size-4 shrink-0" />Cộng lại khớp tổng tiền trên hoá đơn.</div>
            <div v-else-if="result.total_matches === false" class="mx-4 mb-3 flex gap-2 rounded-lg bg-rose-50 p-3 text-sm text-rose-700" data-testid="scan-total-bad"><TriangleAlert class="mt-0.5 size-4 shrink-0" />Cộng các dòng không khớp tổng tiền trên hoá đơn. Soát lại số lượng, đơn giá từng dòng so với ảnh.</div>
            <div v-if="result.unreadable" class="mx-4 mb-3 flex gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-800"><TriangleAlert class="mt-0.5 size-4 shrink-0" />AI chưa đọc rõ: {{ result.unreadable }}</div>

            <div class="flex flex-wrap items-center gap-3 border-t px-4 py-3">
                <span v-if="linked" class="text-sm text-emerald-700">Hoá đơn này đã vào phiếu nhập.</span>
                <span v-else-if="waiting" class="text-sm text-rose-700">Còn {{ waiting }} dòng chưa có sản phẩm: chọn, tạo mới hoặc bỏ tick.</span>
                <span v-else-if="!supplierId" class="text-sm text-rose-700">Chọn nhà cung cấp.</span>
                <div class="ml-auto flex gap-2">
                    <Button variant="outline" @click="reset">Huỷ</Button>
                    <Button :disabled="!ready" data-testid="scan-make-draft" @click="makeDraft">Tạo phiếu nhập nháp</Button>
                </div>
            </div>
        </Card>
    </div>
</template>
