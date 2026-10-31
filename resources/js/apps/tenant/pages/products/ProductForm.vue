<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, ImagePlus, Plus, Sparkles, Trash2, X } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatMoney } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import type { Product, Ref } from '@/shared/types';

interface VariantRow {
    id?: string;
    sku: string;
    name: string;
    barcode: string;
    options: Record<string, string>;
    ref_price: string;
    weight_gram: string;
    cost_price?: string;
    skuTaken?: string | null;
}

const route = useRoute();
const router = useRouter();
const toast = useToast();
const tenant = useTenantStore();
const canManage = computed(() => tenant.can('products.manage'));
const productId = computed(() => (route.params.id as string | undefined) ?? null);
const isNew = computed(() => !productId.value);

const loading = ref(!isNew.value);
const loadError = ref<string | null>(null);
const categories = ref<Ref[]>([]);
const brands = ref<Ref[]>([]);
const images = ref<{ id: number; url: string }[]>([]);
const form = reactive({ name: '', description: '', status: 'active', category_id: '', brand_id: '' });
const variants = ref<VariantRow[]>([]);
const saving = ref(false);
const error = ref<ApiError | null>(null);

// Variant generator: option groups like Màu = Trắng, Đen and Size = M, L.
const groups = ref<{ name: string; values: string }[]>([
    { name: 'Màu', values: '' },
    { name: 'Size', values: '' },
]);
const skuPrefix = ref('');

function emptyRow(): VariantRow {
    return { sku: '', name: '', barcode: '', options: {}, ref_price: '', weight_gram: '' };
}

onMounted(async () => {
    const [c, b] = await Promise.all([api.get('/categories'), api.get('/brands')]);
    categories.value = c.data.data;
    brands.value = b.data.data;
    if (isNew.value) {
        variants.value = [emptyRow()];
        return;
    }
    try {
        const p: Product = (await api.get(`/products/${productId.value}`)).data.data;
        Object.assign(form, { name: p.name, description: p.description ?? '', status: p.status, category_id: p.category?.id ?? '', brand_id: p.brand?.id ?? '' });
        images.value = p.images ?? [];
        variants.value = (p.variants ?? []).map((v) => ({
            id: v.id,
            sku: v.sku,
            name: v.name ?? '',
            barcode: v.barcode ?? '',
            options: Array.isArray(v.options) ? {} : v.options,
            ref_price: v.ref_price ? String(Math.round(Number(v.ref_price))) : '',
            weight_gram: v.weight_gram ? String(v.weight_gram) : '',
            cost_price: v.cost_price,
        }));
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
});

function slug(value: string): string {
    return value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/đ/gi, 'D')
        .replace(/[^A-Za-z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
        .toUpperCase();
}

function generate() {
    const active = groups.value.map((g) => ({ name: g.name.trim(), values: g.values.split(',').map((v) => v.trim()).filter(Boolean) })).filter((g) => g.name && g.values.length);
    if (!active.length) return;
    let combos: Record<string, string>[] = [{}];
    for (const g of active) combos = combos.flatMap((c) => g.values.map((v) => ({ ...c, [g.name]: v })));

    const prefix = slug(skuPrefix.value || form.name).slice(0, 20) || 'SKU';
    const price = variants.value.find((v) => v.ref_price)?.ref_price ?? '';
    const existing = new Set(variants.value.map((v) => v.sku.toUpperCase()));
    const rows = combos
        .map((options) => ({ ...emptyRow(), options, name: Object.values(options).join(' / '), sku: `${prefix}-${Object.values(options).map(slug).join('-')}`, ref_price: price }))
        .filter((r) => !existing.has(r.sku));
    variants.value = [...variants.value.filter((v) => v.id || v.sku), ...rows];
}

let skuTimer: ReturnType<typeof setTimeout> | undefined;
function checkSku(row: VariantRow) {
    clearTimeout(skuTimer);
    row.skuTaken = null;
    if (!row.sku.trim()) return;
    skuTimer = setTimeout(async () => {
        const { data } = await api.get('/products/sku-check', { params: { sku: row.sku, ignore: row.id } });
        row.skuTaken = data.available ? null : `Đã dùng cho "${data.product?.name ?? 'sản phẩm khác'}"`;
    }, 300);
}

const duplicateSkus = computed(() => {
    const seen = new Map<string, number>();
    variants.value.forEach((v) => v.sku && seen.set(v.sku.trim().toLowerCase(), (seen.get(v.sku.trim().toLowerCase()) ?? 0) + 1));
    return new Set([...seen].filter(([, n]) => n > 1).map(([k]) => k));
});

function fieldError(i: number, field: string): string | undefined {
    return error.value?.field(`variants.${i}.${field}`);
}

async function save() {
    saving.value = true;
    error.value = null;
    try {
        const payload = {
            name: form.name,
            description: form.description || null,
            status: form.status,
            category_id: form.category_id || null,
            brand_id: form.brand_id || null,
            variants: variants.value.map((v) => ({
                ...(v.id ? { id: v.id } : {}),
                sku: v.sku.trim(),
                name: v.name || null,
                barcode: v.barcode || null,
                options: Object.keys(v.options).length ? v.options : null,
                ref_price: v.ref_price === '' ? null : Number(v.ref_price),
                weight_gram: v.weight_gram === '' ? null : Number(v.weight_gram),
            })),
        };
        const { data } = isNew.value ? await api.post('/products', payload) : await api.put(`/products/${productId.value}`, payload);
        toast.success(isNew.value ? 'Đã tạo sản phẩm' : 'Đã lưu thay đổi');
        if (isNew.value) await router.replace(`/products/${data.data.id}`);
    } catch (e) {
        error.value = e as ApiError;
        toast.error(error.value.body.errors ? 'Vui lòng kiểm tra các trường được đánh dấu.' : error.value.message);
    } finally {
        saving.value = false;
    }
}

async function remove() {
    if (!window.confirm(`Xóa sản phẩm "${form.name}"? SKU còn tồn kho sẽ không xóa được.`)) return;
    try {
        await api.delete(`/products/${productId.value}`);
        toast.success('Đã xóa sản phẩm');
        await router.push('/products');
    } catch (e) {
        toast.error((e as ApiError).field('variants') ?? (e as ApiError).message);
    }
}

async function upload(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file || !productId.value) return;
    const body = new FormData();
    body.append('image', file);
    try {
        const { data } = await api.post(`/products/${productId.value}/images`, body);
        images.value.push(data);
    } catch (err) {
        toast.error((err as ApiError).field('image') ?? (err as ApiError).message);
    }
}

async function removeImage(id: number) {
    await api.delete(`/products/${productId.value}/images/${id}`);
    images.value = images.value.filter((i) => i.id !== id);
}
</script>

<template>
    <RouterLink to="/products" class="mb-3 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft class="size-4" /> Sản phẩm</RouterLink>
    <ErrorState v-if="loadError" :message="loadError" />
    <div v-else-if="loading" class="space-y-3"><Skeleton class="h-10 w-80" /><Skeleton class="h-72" /></div>
    <form v-else @submit.prevent="save">
        <PageHeader :title="isNew ? 'Thêm sản phẩm' : form.name || 'Sản phẩm'" :description="isNew ? 'Mỗi biến thể là một SKU bán được, có tồn kho riêng.' : undefined">
            <Button v-if="!isNew && canManage" variant="ghost" class="text-destructive" @click="remove"><Trash2 /> Xóa</Button>
            <Button v-if="canManage" type="submit" :loading="saving" data-testid="save-product">{{ isNew ? 'Tạo sản phẩm' : 'Lưu thay đổi' }}</Button>
        </PageHeader>

        <div class="grid gap-5 lg:grid-cols-3">
            <div class="space-y-5 lg:col-span-2">
                <Card title="Thông tin chung">
                    <div class="grid gap-4 p-5 sm:grid-cols-2">
                        <FormField label="Tên sản phẩm" for="p-name" :error="error?.field('name')" class="sm:col-span-2">
                            <Input id="p-name" v-model="form.name" required maxlength="255" placeholder="Ví dụ: Áo thun cotton cổ tròn" data-testid="product-name" />
                        </FormField>
                        <FormField label="Danh mục" for="p-cat">
                            <Select id="p-cat" v-model="form.category_id" :options="[{ value: '', label: '— Không chọn —' }, ...categories.map((c) => ({ value: c.id, label: c.name }))]" />
                        </FormField>
                        <FormField label="Thương hiệu" for="p-brand">
                            <Select id="p-brand" v-model="form.brand_id" :options="[{ value: '', label: '— Không chọn —' }, ...brands.map((b) => ({ value: b.id, label: b.name }))]" />
                        </FormField>
                        <FormField label="Mô tả" for="p-desc" class="sm:col-span-2">
                            <textarea id="p-desc" v-model="form.description" rows="3" class="w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" />
                        </FormField>
                    </div>
                </Card>

                <Card title="Biến thể và SKU" description="SKU duy nhất trong doanh nghiệp (không phân biệt hoa thường). Giá vốn tự tính từ phiếu nhập.">
                    <template v-if="canManage" #actions>
                        <Button variant="outline" size="sm" @click="variants.push(emptyRow())"><Plus /> Thêm dòng</Button>
                    </template>

                    <details v-if="canManage" class="border-b bg-muted/30 px-5 py-3 text-sm" :open="isNew">
                        <summary class="cursor-pointer font-medium"><Sparkles class="mr-1 inline size-4 text-primary" /> Tạo nhanh biến thể theo thuộc tính</summary>
                        <div class="mt-3 grid gap-3 sm:grid-cols-[8rem_1fr]">
                            <template v-for="(g, i) in groups" :key="i">
                                <Input v-model="g.name" placeholder="Thuộc tính" />
                                <Input v-model="g.values" placeholder="Giá trị, cách nhau bằng dấu phẩy (Trắng, Đen)" :data-testid="`option-values-${i}`" />
                            </template>
                            <Input v-model="skuPrefix" placeholder="Tiền tố SKU (mặc định theo tên)" />
                            <div class="flex gap-2">
                                <Button variant="secondary" size="sm" data-testid="generate-variants" @click="generate">Tạo biến thể</Button>
                                <Button variant="ghost" size="sm" @click="groups.push({ name: '', values: '' })">+ Thuộc tính</Button>
                            </div>
                        </div>
                    </details>

                    <p v-if="error?.field('variants')" class="px-5 pt-3 text-sm text-destructive">{{ error.field('variants') }}</p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" data-testid="variant-table">
                            <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th class="px-3 py-2 font-medium">SKU *</th>
                                    <th class="px-3 py-2 font-medium">Tên biến thể</th>
                                    <th class="px-3 py-2 font-medium">Mã vạch</th>
                                    <th class="px-3 py-2 text-right font-medium">Giá tham khảo</th>
                                    <th class="px-3 py-2 text-right font-medium">KL (g)</th>
                                    <th v-if="!isNew" class="px-4 py-2 text-right font-medium">Giá vốn BQ</th>
                                    <th class="px-2 py-2" />
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="(v, i) in variants" :key="v.id ?? `new-${i}`" class="align-top">
                                    <td class="px-3 py-2">
                                        <input
                                            v-model="v.sku"
                                            required
                                            maxlength="64"
                                            :disabled="!canManage"
                                            :data-testid="`sku-${i}`"
                                            :class="cn('h-8 w-36 rounded-md border border-input bg-card px-2 font-mono text-xs uppercase', (fieldError(i, 'sku') || v.skuTaken || duplicateSkus.has(v.sku.trim().toLowerCase())) && 'border-destructive')"
                                            @input="checkSku(v)"
                                        />
                                        <p v-if="fieldError(i, 'sku') || v.skuTaken" class="mt-1 max-w-36 text-xs text-destructive">{{ fieldError(i, 'sku') ?? v.skuTaken }}</p>
                                        <p v-else-if="duplicateSkus.has(v.sku.trim().toLowerCase())" class="mt-1 text-xs text-destructive">Trùng SKU</p>
                                    </td>
                                    <td class="px-3 py-2"><input v-model="v.name" :disabled="!canManage" class="h-8 w-32 rounded-md border border-input bg-card px-2" /></td>
                                    <td class="px-3 py-2">
                                        <input v-model="v.barcode" :disabled="!canManage" maxlength="64" :class="cn('h-8 w-32 rounded-md border border-input bg-card px-2 font-mono text-xs', fieldError(i, 'barcode') && 'border-destructive')" />
                                        <p v-if="fieldError(i, 'barcode')" class="mt-1 max-w-36 text-xs text-destructive">{{ fieldError(i, 'barcode') }}</p>
                                    </td>
                                    <td class="px-3 py-2 text-right"><input v-model="v.ref_price" type="number" min="0" :disabled="!canManage" class="h-8 w-24 rounded-md border border-input bg-card px-2 text-right tabular-nums" /></td>
                                    <td class="px-3 py-2 text-right"><input v-model="v.weight_gram" type="number" min="0" :disabled="!canManage" class="h-8 w-16 rounded-md border border-input bg-card px-2 text-right tabular-nums" /></td>
                                    <td v-if="!isNew" class="px-4 py-2 text-right tabular-nums text-muted-foreground">
                                        {{ v.cost_price ? formatMoney(v.cost_price) : '—' }}
                                        <RouterLink v-if="v.id" :to="`/inventory/sku/${v.id}`" class="block text-xs text-primary hover:underline">Thẻ kho</RouterLink>
                                    </td>
                                    <td class="px-2 py-2 text-right">
                                        <Button v-if="canManage && variants.length > 1" variant="ghost" size="icon" class="size-8" title="Xóa biến thể" @click="variants.splice(i, 1)"><Trash2 class="text-muted-foreground" /></Button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>
            </div>

            <div class="space-y-5">
                <Card title="Trạng thái">
                    <div class="p-5">
                        <Select
                            v-model="form.status"
                            :disabled="!canManage"
                            :options="[
                                { value: 'active', label: 'Đang bán' },
                                { value: 'inactive', label: 'Ngừng bán' },
                                { value: 'archived', label: 'Lưu trữ' },
                            ]"
                        />
                        <p class="mt-2 text-xs text-muted-foreground">Sản phẩm lưu trữ bị ẩn khỏi danh sách tồn kho và ô chọn SKU.</p>
                    </div>
                </Card>
                <Card title="Hình ảnh" :description="isNew ? 'Lưu sản phẩm trước rồi thêm ảnh.' : 'JPG, PNG, WEBP tối đa 4 MB, 9 ảnh.'">
                    <div class="grid grid-cols-3 gap-2 p-5">
                        <div v-for="img in images" :key="img.id" class="group relative aspect-square overflow-hidden rounded-md border">
                            <img :src="img.url" alt="" class="size-full object-cover" />
                            <button v-if="canManage" type="button" class="absolute right-1 top-1 hidden rounded bg-black/60 p-0.5 text-white group-hover:block" aria-label="Xóa ảnh" @click="removeImage(img.id)"><X class="size-3.5" /></button>
                        </div>
                        <label v-if="!isNew && canManage && images.length < 9" class="flex aspect-square cursor-pointer flex-col items-center justify-center gap-1 rounded-md border border-dashed text-xs text-muted-foreground hover:bg-muted">
                            <ImagePlus class="size-5" /> Thêm ảnh
                            <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="upload" />
                        </label>
                    </div>
                </Card>
            </div>
        </div>
    </form>
</template>
