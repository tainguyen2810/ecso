<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Camera, CheckCircle2, ChevronDown, ChevronRight, ImageUp, Loader2, Sparkles, TriangleAlert, X } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import AiNotice from '../../components/AiNotice.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatMoney } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { photoFile, toPhotos, type Photo } from '@/shared/lib/photos';
import { productBody, rows as toRows, type SuggestionRow } from './scan';
import { useSoftPage } from '@/shared/composables/useSoftPage';

useSoftPage('Sản phẩm');

/**
 * Sản phẩm › Tạo từ ảnh: photos (front, box, label… of one or many products) are read by EzChat AI, which groups
 * them per product and fills name, category, brand, SKU, variants and a factual description. The price comes
 * from the cost in the note and the markup. The user edits, ticks and saves; the photos become product images.
 */
const MAX_PHOTOS = 12;
const toast = useToast();

const status = ref<{ available: boolean; reason: string | null } | null>(null);
const photos = ref<Photo[]>([]);
const note = ref('');
const markup = ref('25');
const preparing = ref(false);
const reading = ref(false);
const saving = ref(false);
const failure = ref<string | null>(null);
const list = ref<SuggestionRow[] | null>(null);
const credits = ref(0);
const categories = ref<{ id: string; name: string; parent?: { name: string } | null }[]>([]);
const brands = ref<{ id: string; name: string }[]>([]);

onMounted(async () => {
    const [s, c, b] = await Promise.all([api.get('/assistant/status').catch(() => null), api.get('/categories'), api.get('/brands')]);
    status.value = s?.data.data ?? { available: false, reason: 'error' };
    categories.value = c.data.data;
    brands.value = b.data.data;
});
onBeforeUnmount(() => photos.value.forEach((p) => URL.revokeObjectURL(p.url)));

const catOptions = computed(() => [{ value: '', label: '— Không chọn —' }, ...categories.value.map((c) => ({ value: c.id, label: c.parent ? `${c.parent.name} › ${c.name}` : c.name }))]);
const brandOptions = computed(() => [{ value: '', label: '— Không chọn —' }, ...brands.value.map((b) => ({ value: b.id, label: b.name }))]);

async function addFiles(e: Event) {
    const input = e.target as HTMLInputElement;
    if (!input.files?.length) return;
    preparing.value = true;
    try {
        const room = MAX_PHOTOS - photos.value.length;
        if (input.files.length > room) toast.info(`Tối đa ${MAX_PHOTOS} ảnh mỗi lần.`);
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
        const { data } = await api.post('/assistant/products', { images: photos.value.map((p) => p.data), note: note.value || null, markup: Number(markup.value) || 0 }, { timeout: 180000 });
        list.value = toRows(data.data.products);
        credits.value = data.data.credits;
        if (!list.value.length) {
            list.value = null;
            failure.value = 'AI chưa nhận ra sản phẩm nào trong ảnh. Chụp rõ sản phẩm, hộp hoặc tem nhãn rồi thử lại.';
        }
    } catch (e) {
        failure.value = (e as ApiError).message || 'Chưa đọc được ảnh.';
    } finally {
        reading.value = false;
    }
}

/** Recomputes the price when the cost or the markup changes (rounded up like the server). */
function reprice(r: SuggestionRow) {
    if (!r.cost_price) return;
    const raw = r.cost_price * (1 + (Number(markup.value) || 0) / 100);
    const step = raw >= 100000 ? 10000 : 1000;
    r.price = Math.ceil(raw / step) * step;
}

async function createCategory(r: SuggestionRow) {
    const name = (r.category.read.split('›').pop() ?? '').trim();
    if (!name) return;
    try {
        const { data } = await api.post('/categories', { name });
        categories.value.push(data.data);
        r.category.id = data.data.id;
    } catch (e) {
        toast.error((e as ApiError).message || 'Chưa tạo được danh mục.');
    }
}

async function createBrand(r: SuggestionRow) {
    try {
        const { data } = await api.post('/brands', { name: r.brand.read });
        brands.value.push(data.data);
        r.brand.id = data.data.id;
    } catch (e) {
        toast.error((e as ApiError).message || 'Chưa tạo được thương hiệu.');
    }
}

const variantText = (r: SuggestionRow, i: number) => r.variants[i]!.values.join(', ');
function setVariant(r: SuggestionRow, i: number, field: 'name' | 'values', value: string) {
    if (field === 'name') r.variants[i]!.name = value;
    else r.variants[i]!.values = value.split(',').map((x) => x.trim()).filter(Boolean);
}

const ticked = computed(() => (list.value ?? []).filter((r) => r.include && !r.saved));

async function save() {
    if (!ticked.value.length || saving.value) return;
    saving.value = true;
    let done = 0;
    for (const r of ticked.value) {
        r.error = null;
        try {
            const { data } = await api.post('/products', productBody(r));
            const id = data.data.id as string;
            for (const n of r.images.slice(0, 8)) {
                const p = photos.value[n - 1];
                if (!p) continue;
                const form = new FormData();
                form.append('image', photoFile(p, `anh-${n}.jpg`));
                await api.post(`/products/${id}/images`, form).catch(() => null);
            }
            r.saved = id;
            r.open = false;
            done++;
        } catch (e) {
            const err = e as ApiError;
            r.error = Object.values(err.body?.errors ?? {}).flat()[0] as string | undefined ?? err.message;
            r.open = true;
        }
    }
    saving.value = false;
    if (done) toast.success(`Đã lưu ${done} sản phẩm.`);
}

function reset() {
    list.value = null;
    failure.value = null;
}
</script>

<template>
    <PageHeader title="Tạo sản phẩm từ ảnh" description="Gửi ảnh sản phẩm, hộp, tem nhãn. AI gợi ý tên, danh mục, phân loại, giá và viết mô tả, bạn sửa rồi lưu." :back="{ to: '/products', label: 'Danh sách sản phẩm' }" />

    <AiNotice v-if="status && !status.available" :reason="status.reason" class="mb-4" />

    <Card v-if="!list" class="p-5">
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-primary/30 bg-primary/5 p-6 text-center">
                <span class="grid size-12 place-items-center rounded-2xl bg-card text-primary shadow-sm"><Camera class="size-6" /></span>
                <span class="mt-3 font-medium">Chụp bằng camera</span>
                <span class="mt-1 text-sm text-muted-foreground">Chụp mặt trước, hộp và tem nhãn (có mã model thì AI đọc chính xác hơn).</span>
                <input type="file" accept="image/*" capture="environment" class="sr-only" @change="addFiles" />
            </label>
            <label class="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed p-6 text-center">
                <span class="grid size-12 place-items-center rounded-2xl bg-muted text-muted-foreground"><ImageUp class="size-6" /></span>
                <span class="mt-3 font-medium">Chọn nhiều ảnh</span>
                <span class="mt-1 text-sm text-muted-foreground">Một hay nhiều sản phẩm cùng lúc (tối đa {{ MAX_PHOTOS }} ảnh), AI tự gom ảnh của cùng một sản phẩm.</span>
                <input type="file" accept="image/*" multiple class="sr-only" data-testid="product-scan-input" @change="addFiles" />
            </label>
        </div>
        <div v-if="photos.length || preparing" class="mt-5 flex flex-wrap gap-3">
            <div v-for="(p, i) in photos" :key="p.url" class="relative size-24 overflow-hidden rounded-lg border bg-muted">
                <img :src="p.url" alt="" class="size-full object-cover" />
                <span class="absolute bottom-1 left-1 rounded bg-white/90 px-1.5 text-[11px]">{{ i + 1 }}</span>
                <button type="button" class="absolute right-1 top-1 grid size-6 place-items-center rounded-full bg-white/90 text-slate-600" aria-label="Bỏ ảnh" @click="removePhoto(i)"><X class="size-3.5" /></button>
            </div>
            <div v-if="preparing" class="grid size-24 place-items-center rounded-lg border"><Loader2 class="size-5 animate-spin text-muted-foreground" /></div>
        </div>
        <div class="mt-5 grid gap-4 sm:grid-cols-[1fr_200px]">
            <div>
                <label class="mb-1 block text-sm font-medium" for="ps-note">Ghi chú thêm (không bắt buộc)</label>
                <textarea id="ps-note" v-model="note" rows="2" maxlength="1000" class="w-full rounded-[10px] border border-slate-200 bg-white px-3.5 py-2.5 text-[15px] leading-6 placeholder:text-slate-400 focus:border-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-100" placeholder="VD: router TP-Link AX23, giá nhập 820k, có 2 màu đen trắng, bảo hành 24 tháng" data-testid="product-scan-note"></textarea>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium" for="ps-markup">Lãi trên giá nhập (%)</label>
                <Input id="ps-markup" v-model="markup" inputmode="decimal" />
                <p class="mt-1 text-xs text-muted-foreground">Giá bán gợi ý = giá nhập + lãi, làm tròn lên.</p>
            </div>
        </div>
        <div v-if="failure" class="mt-4 flex gap-2 rounded-lg bg-rose-50 p-3 text-sm text-rose-700"><TriangleAlert class="mt-0.5 size-4 shrink-0" />{{ failure }}</div>
        <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t pt-4">
            <span class="text-sm text-muted-foreground"><Sparkles class="mr-1 inline size-4 text-violet-500" />Dùng credit EzChat AI, khoảng 1 lượt đọc ảnh mỗi ảnh.</span>
            <Button :disabled="!photos.length || !status?.available" :loading="reading" data-testid="product-scan-read" @click="read"><Sparkles /> {{ reading ? 'AI đang xem ảnh…' : 'Để AI gợi ý' }}</Button>
        </div>
    </Card>

    <template v-else>
        <div class="mb-4 flex flex-wrap items-center gap-3 text-sm">
            <span class="font-medium">AI gợi ý {{ list.length }} sản phẩm từ {{ photos.length }} ảnh</span>
            <span class="inline-flex items-center gap-1 rounded-full bg-violet-50 px-2 py-0.5 text-xs font-medium text-violet-700"><Sparkles class="size-3" /> {{ credits }} credit</span>
            <div class="ml-auto flex gap-2">
                <Button variant="outline" @click="reset">Chụp lại</Button>
                <Button :disabled="!ticked.length" :loading="saving" data-testid="product-scan-save" @click="save">Lưu {{ ticked.length }} sản phẩm</Button>
            </div>
        </div>
        <div class="space-y-3" data-testid="product-scan-list">
            <Card v-for="(r, i) in list" :key="i" :class="r.saved ? 'opacity-70' : ''">
                <div class="flex items-center gap-3 p-4">
                    <input v-if="!r.saved" v-model="r.include" type="checkbox" class="size-4" :aria-label="`Lưu ${r.name}`" />
                    <CheckCircle2 v-else class="size-5 text-emerald-600" />
                    <div class="flex -space-x-2">
                        <img v-for="n in r.images.slice(0, 4)" :key="n" :src="photos[n - 1]?.url" alt="" class="size-10 rounded-lg object-cover ring-2 ring-white" />
                    </div>
                    <button type="button" class="min-w-0 flex-1 text-left" @click="r.open = !r.open">
                        <div class="truncate font-medium">{{ r.name }}</div>
                        <div class="truncate text-xs text-muted-foreground">{{ r.category.path || r.category.read || 'Chưa có danh mục' }} · {{ r.sku }}<template v-if="r.price"> · {{ formatMoney(r.price) }}</template></div>
                    </button>
                    <span v-if="r.saved" class="text-sm"><RouterLink :to="`/products/${r.saved}`" class="font-medium text-primary">Mở sản phẩm</RouterLink></span>
                    <span v-else-if="r.duplicate" class="rounded-full bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700">Giống {{ r.duplicate.sku }}</span>
                    <span v-else-if="r.missing" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">Cần bổ sung</span>
                    <component :is="r.open ? ChevronDown : ChevronRight" class="size-4 text-muted-foreground" />
                </div>
                <div v-if="r.open && !r.saved" class="space-y-4 border-t p-4 text-sm">
                    <div v-if="r.seen" class="rounded-lg bg-muted/60 p-3 text-xs text-muted-foreground"><b class="text-foreground">AI nhận ra:</b> {{ r.seen }}</div>
                    <div v-if="r.duplicate" class="rounded-lg bg-rose-50 p-3 text-xs text-rose-700">
                        Có vẻ trùng với sản phẩm đã có: <RouterLink v-if="r.duplicate.product_id" :to="`/products/${r.duplicate.product_id}`" class="font-medium underline">{{ r.duplicate.name }} ({{ r.duplicate.sku }})</RouterLink>. Bỏ tick nếu không muốn tạo trùng.
                    </div>
                    <div v-if="r.missing" class="rounded-lg bg-amber-50 p-3 text-xs text-amber-800">Cần bổ sung: {{ r.missing }}</div>
                    <div v-if="r.error" class="rounded-lg bg-rose-50 p-3 text-xs text-rose-700">{{ r.error }}</div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div><label class="mb-1 block font-medium">Tên sản phẩm</label><Input v-model="r.name" /></div>
                        <div>
                            <label class="mb-1 block font-medium">Danh mục</label>
                            <Select :model-value="r.category.id ?? ''" :options="catOptions" @update:model-value="(v: unknown) => (r.category.id = String(v ?? '') || null)" />
                            <button v-if="!r.category.id && r.category.read" type="button" class="mt-1 text-xs font-medium text-primary" @click="createCategory(r)">+ Tạo danh mục "{{ r.category.read.split('›').pop()?.trim() }}"</button>
                        </div>
                    </div>
                    <div class="grid gap-4 md:grid-cols-4">
                        <div>
                            <label class="mb-1 block font-medium">Thương hiệu</label>
                            <Select :model-value="r.brand.id ?? ''" :options="brandOptions" @update:model-value="(v: unknown) => (r.brand.id = String(v ?? '') || null)" />
                            <button v-if="!r.brand.id && r.brand.read" type="button" class="mt-1 text-xs font-medium text-primary" @click="createBrand(r)">+ Tạo "{{ r.brand.read }}"</button>
                        </div>
                        <div><label class="mb-1 block font-medium">Mã SKU</label><Input v-model="r.sku" /></div>
                        <div><label class="mb-1 block font-medium">Giá nhập</label><Input :model-value="r.cost_price ? String(r.cost_price) : ''" inputmode="numeric" @update:model-value="(v: unknown) => ((r.cost_price = Number(v) || null), reprice(r))" /></div>
                        <div><label class="mb-1 block font-medium">Giá bán</label><Input :model-value="r.price ? String(r.price) : ''" inputmode="numeric" @update:model-value="(v: unknown) => (r.price = Number(v) || null)" /></div>
                    </div>
                    <div class="grid gap-4 md:grid-cols-4">
                        <div><label class="mb-1 block font-medium">Bảo hành (tháng)</label><Input :model-value="r.warranty_months ? String(r.warranty_months) : ''" inputmode="numeric" @update:model-value="(v: unknown) => (r.warranty_months = Number(v) || null)" /></div>
                        <div><label class="mb-1 block font-medium">Cân nặng giao hàng (g)</label><Input :model-value="r.weight_gram ? String(r.weight_gram) : ''" inputmode="numeric" @update:model-value="(v: unknown) => (r.weight_gram = Number(v) || null)" /></div>
                    </div>
                    <div>
                        <label class="mb-1 block font-medium">Phân loại</label>
                        <div v-for="(v, vi) in r.variants" :key="vi" class="mb-2 grid gap-2 sm:grid-cols-[160px_1fr_auto]">
                            <Input :model-value="v.name" placeholder="Màu" @update:model-value="(x: unknown) => setVariant(r, vi, 'name', String(x ?? ''))" />
                            <Input :model-value="variantText(r, vi)" placeholder="Đen, Trắng" @update:model-value="(x: unknown) => setVariant(r, vi, 'values', String(x ?? ''))" />
                            <Button variant="ghost" size="sm" @click="r.variants.splice(vi, 1)">Bỏ</Button>
                        </div>
                        <button type="button" class="text-xs font-medium text-primary" @click="r.variants.push({ name: '', values: [] })">+ Thêm phân loại</button>
                    </div>
                    <div>
                        <label class="mb-1 block font-medium">Mô tả bán hàng</label>
                        <textarea v-model="r.description" rows="6" class="w-full rounded-[10px] border border-slate-200 bg-white px-3.5 py-2.5 text-[15px] leading-6 placeholder:text-slate-400 focus:border-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-100"></textarea>
                    </div>
                </div>
            </Card>
        </div>
    </template>
</template>
