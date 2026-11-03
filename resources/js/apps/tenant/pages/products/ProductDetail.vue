<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Copy, MapPin, Package, Pencil, Tags, Trash2 } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Pagination from '../../components/Pagination.vue';
import Tabs from '../../components/Tabs.vue';
import { movementLabels, referenceLabels } from '../inventory/labels';
import Badge from '@/shared/ui/Badge.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import type { Product } from '@/shared/types';

interface StockRow {
    variant: { id: string; sku: string; name: string | null };
    warehouse: { id: string; code: string; name: string };
    on_hand: number;
    reserved: number;
    available: number;
    safety_stock: number;
    max_stock?: number | null;
    bin_location?: string | null;
    incoming: number;
    cost_price: string;
}
interface MovementRow {
    id: number;
    type: string;
    variant: { id: string; sku: string; name: string | null };
    warehouse: { name: string };
    on_hand_delta: number;
    on_hand_after: number;
    reserved_delta: number;
    reference: { type: string; number: string | null } | null;
    reason: string | null;
    actor: string | null;
    created_at: string;
}

const route = useRoute();
const router = useRouter();
const toast = useToast();
const tenant = useTenantStore();
const canManage = computed(() => tenant.can('products.manage'));
const canStock = computed(() => tenant.can('inventory.view'));
const canLocate = computed(() => tenant.can('inventory.adjust'));
const canSetMax = computed(() => tenant.can('inventory.adjust.approve'));

// Bin location and maximum stock of one SKU in one warehouse.
const locating = ref<StockRow | null>(null);
const locationForm = ref({ bin_location: '', max_stock: '' });
const savingLocation = ref(false);
function openLocation(r: StockRow) {
    locating.value = r;
    locationForm.value = { bin_location: r.bin_location ?? '', max_stock: r.max_stock === null || r.max_stock === undefined ? '' : String(r.max_stock) };
}
async function saveLocation() {
    const r = locating.value;
    if (!r) return;
    savingLocation.value = true;
    try {
        const { data } = await api.put(`/inventory/variants/${r.variant.id}/location`, {
            warehouse: r.warehouse.id,
            bin_location: locationForm.value.bin_location || null,
            ...(canSetMax.value ? { max_stock: locationForm.value.max_stock === '' ? null : Number(locationForm.value.max_stock) } : {}),
        });
        Object.assign(r, data.data);
        locating.value = null;
        toast.success('Đã lưu vị trí lưu kho');
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        savingLocation.value = false;
    }
}
const id = computed(() => route.params.id as string);

const product = ref<Product | null>(null);
const loadError = ref<string | null>(null);
const tab = ref('stock');
const stock = ref<StockRow[]>([]);
const warehouses = ref<{ id: string; name: string }[]>([]);
const warehouse = ref('');
const movements = ref<MovementRow[]>([]);
const movementMeta = ref({ current_page: 1, last_page: 1, total: 0 });
const movementsLoaded = ref(false);

const statusLabels = { active: 'Đang bán', inactive: 'Ngừng bán', archived: 'Lưu trữ' } as const;
const statusTone = { active: 'success', inactive: 'muted', archived: 'muted' } as const;

onMounted(async () => {
    try {
        product.value = (await api.get(`/products/${id.value}`)).data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
        return;
    }
    if (canStock.value) {
        const { data } = await api.get(`/inventory/products/${id.value}/stock`);
        stock.value = data.data;
        warehouses.value = data.warehouses;
    }
});

async function loadMovements(page = 1) {
    const { data } = await api.get(`/inventory/products/${id.value}/movements`, { params: { page, warehouse: warehouse.value || undefined } });
    movements.value = data.data;
    movementMeta.value = data.meta;
    movementsLoaded.value = true;
}
watch(tab, (t) => t === 'history' && !movementsLoaded.value && loadMovements());
watch(warehouse, () => movementsLoaded.value && loadMovements());

const visibleStock = computed(() => stock.value.filter((r) => !warehouse.value || r.warehouse.id === warehouse.value));

/** Per SKU, summed over warehouses (the variant table). */
const totals = computed(() => {
    const map = new Map<string, { on_hand: number; available: number; incoming: number }>();
    for (const r of stock.value) {
        const t = map.get(r.variant.id) ?? { on_hand: 0, available: 0, incoming: 0 };
        t.on_hand += r.on_hand;
        t.available += r.available;
        t.incoming += r.incoming;
        map.set(r.variant.id, t);
    }
    return map;
});

// Pack sizes repeat the base SKU's stock in another unit: leave them out of the totals.
const packIds = computed(() => new Set(product.value?.type === 'combo' ? [] : (product.value?.variants ?? []).filter((v) => v.components?.length).map((v) => v.id)));
const stockTotals = computed(() =>
    visibleStock.value.filter((r) => !packIds.value.has(r.variant.id)).reduce(
        (s, r) => ({ on_hand: s.on_hand + r.on_hand, reserved: s.reserved + r.reserved, available: s.available + r.available, incoming: s.incoming + r.incoming, value: s.value + Math.max(0, r.on_hand) * Number(r.cost_price) }),
        { on_hand: 0, reserved: 0, available: 0, incoming: 0, value: 0 },
    ),
);

async function remove() {
    if (!product.value || !window.confirm(`Xóa sản phẩm "${product.value.name}"? SKU còn tồn kho sẽ không xóa được.`)) return;
    try {
        await api.delete(`/products/${id.value}`);
        toast.success('Đã xóa sản phẩm');
        await router.push('/products');
    } catch (e) {
        toast.error((e as ApiError).field('variants') ?? (e as ApiError).message);
    }
}
</script>

<template>
    <RouterLink to="/products" class="mb-3 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft class="size-4" /> Sản phẩm</RouterLink>
    <ErrorState v-if="loadError" :message="loadError" />
    <div v-else-if="!product" class="space-y-3"><Skeleton class="h-10 w-80" /><Skeleton class="h-72" /></div>
    <template v-else>
        <PageHeader :title="product.name" :description="product.type === 'combo' ? 'Combo: không có tồn riêng, số lượng bán được tính từ tồn các SKU thành phần.' : undefined">
            <Button v-if="canManage" variant="ghost" class="text-destructive" @click="remove"><Trash2 /> Xóa</Button>
            <RouterLink :to="{ path: '/products/labels', query: { products: product.id } }"><Button variant="outline" data-testid="print-labels"><Tags /> In tem</Button></RouterLink>
            <RouterLink v-if="canManage" :to="{ path: '/products/new', query: { copy: product.id } }"><Button variant="outline" data-testid="copy-product"><Copy /> Sao chép</Button></RouterLink>
            <RouterLink v-if="canManage" :to="`/products/${product.id}/edit`"><Button data-testid="edit-product"><Pencil /> Sửa sản phẩm</Button></RouterLink>
        </PageHeader>

        <div class="grid gap-5 lg:grid-cols-3">
            <Card title="Thông tin sản phẩm" class="lg:col-span-2">
                <template #actions><Badge :tone="statusTone[product.status]">{{ statusLabels[product.status] }}</Badge></template>
                <div class="grid gap-5 p-5 sm:grid-cols-[1fr_9rem]">
                    <dl class="grid grid-cols-[8rem_1fr] gap-x-4 gap-y-2 text-sm" data-testid="product-info">
                        <dt class="text-muted-foreground">Danh mục</dt><dd>{{ product.category?.name ?? '—' }}</dd>
                        <dt class="text-muted-foreground">Thương hiệu</dt><dd>{{ product.brand?.name ?? '—' }}</dd>
                        <dt class="text-muted-foreground">Đơn vị tính</dt><dd>{{ product.unit || '—' }}</dd>
                        <dt class="text-muted-foreground">Thuế GTGT</dt><dd>{{ product.vat_rate === null || product.vat_rate === undefined ? 'Không áp dụng' : `${Number(product.vat_rate)}%` }}</dd>
                        <dt class="text-muted-foreground">Tags</dt>
                        <dd class="flex flex-wrap gap-1">
                            <RouterLink v-for="t in product.tags" :key="t" :to="{ path: '/products', query: { tag: t } }"><Badge>{{ t }}</Badge></RouterLink>
                            <span v-if="!product.tags?.length">—</span>
                        </dd>
                        <dt class="text-muted-foreground">Ngày tạo</dt><dd>{{ formatDateTime(product.created_at) }}</dd>
                        <dt class="text-muted-foreground">Cập nhật</dt><dd>{{ formatDateTime(product.updated_at) }}</dd>
                    </dl>
                    <div class="grid grid-cols-3 gap-1 self-start sm:grid-cols-2">
                        <img v-for="img in product.images?.slice(0, 4)" :key="img.id" :src="img.url" alt="" class="aspect-square w-full rounded border object-cover" />
                        <span v-if="!product.images?.length" class="col-span-full flex aspect-square flex-col items-center justify-center gap-1 rounded border border-dashed text-xs text-muted-foreground"><Package class="size-5" /> Chưa có ảnh</span>
                    </div>
                </div>
                <p v-if="product.description" class="whitespace-pre-line border-t px-5 py-4 text-sm text-muted-foreground">{{ product.description }}</p>
            </Card>

            <Card title="Tổng quan tồn kho" description="Cộng mọi kho đang hoạt động.">
                <dl v-if="canStock" class="grid grid-cols-2 gap-4 p-5 text-sm">
                    <div><dt class="text-muted-foreground">Tồn thực tế</dt><dd class="text-lg font-semibold tabular-nums">{{ formatNumber(stockTotals.on_hand) }}</dd></div>
                    <div><dt class="text-muted-foreground">Có thể bán</dt><dd :class="cn('text-lg font-semibold tabular-nums', stockTotals.available <= 0 && 'text-destructive')">{{ formatNumber(stockTotals.available) }}</dd></div>
                    <div><dt class="text-muted-foreground">Đang giữ cho đơn</dt><dd class="font-medium tabular-nums">{{ formatNumber(stockTotals.reserved) }}</dd></div>
                    <div><dt class="text-muted-foreground">Hàng đang về</dt><dd class="font-medium tabular-nums">{{ formatNumber(stockTotals.incoming) }}</dd></div>
                    <div class="col-span-2"><dt class="text-muted-foreground">Giá trị tồn (giá vốn BQ)</dt><dd class="font-medium tabular-nums">{{ formatMoney(stockTotals.value) }}</dd></div>
                </dl>
                <p v-else class="p-5 text-sm text-muted-foreground">Bạn không có quyền xem tồn kho.</p>
            </Card>
        </div>

        <Card v-if="product.type === 'combo'" title="Thành phần combo" class="mt-5">
            <table class="w-full text-sm" data-testid="combo-components">
                <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">SKU thành phần</th>
                        <th class="px-4 py-2.5 font-medium">Sản phẩm</th>
                        <th class="px-4 py-2.5 text-right font-medium">Số lượng / combo</th>
                        <th class="px-4 py-2.5" />
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="c in product.variants?.[0]?.components ?? []" :key="c.variant.id">
                        <td class="px-4 py-2.5 font-mono text-xs font-medium">{{ c.variant.sku }}</td>
                        <td class="px-4 py-2.5">{{ c.variant.name }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ formatNumber(c.quantity) }}</td>
                        <td class="px-4 py-2.5 text-right"><RouterLink v-if="canStock" :to="`/inventory/sku/${c.variant.id}`" class="text-xs text-primary hover:underline">Thẻ kho</RouterLink></td>
                    </tr>
                </tbody>
            </table>
        </Card>

        <Card :title="product.type === 'combo' ? 'SKU và giá combo' : 'Biến thể và giá'" :description="`${product.variants?.length ?? 0} SKU`" class="mt-5">
            <div class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="variant-prices">
                    <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">SKU</th>
                            <th class="px-4 py-2.5 font-medium">Biến thể</th>
                            <th class="px-4 py-2.5 font-medium">Mã vạch</th>
                            <th class="px-4 py-2.5 text-right font-medium">Giá bán lẻ</th>
                            <th class="px-4 py-2.5 text-right font-medium">Giá bán buôn</th>
                            <th class="px-4 py-2.5 text-right font-medium">Giá vốn BQ</th>
                            <th v-if="canStock" class="px-4 py-2.5 text-right font-medium">Có thể bán</th>
                            <th class="px-4 py-2.5" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="v in product.variants" :key="v.id">
                            <td class="px-4 py-2.5 font-mono text-xs font-medium">{{ v.sku }}</td>
                            <td class="px-4 py-2.5">
                                {{ v.name ?? '—' }}
                                <span v-if="product.type !== 'combo' && v.components?.length === 1" class="block text-xs text-muted-foreground" data-testid="pack-conversion">
                                    = {{ v.components[0]!.quantity }} × {{ v.components[0]!.variant.sku }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs text-muted-foreground">{{ v.barcode ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ Number(v.ref_price) ? formatMoney(v.ref_price) : '—' }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ Number(v.wholesale_price) ? formatMoney(v.wholesale_price) : '—' }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ Number(v.cost_price) ? formatMoney(v.cost_price) : '—' }}</td>
                            <td v-if="canStock" class="px-4 py-2.5 text-right font-medium tabular-nums">{{ formatNumber(totals.get(v.id!)?.available ?? 0) }}</td>
                            <td class="px-4 py-2.5 text-right"><RouterLink v-if="canStock" :to="`/inventory/sku/${v.id}`" class="text-xs text-primary hover:underline">Thẻ kho</RouterLink></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <Card v-if="canStock" class="mt-5">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
                <Tabs v-model="tab" :tabs="[{ value: 'stock', label: 'Tồn theo kho' }, { value: 'history', label: 'Lịch sử kho' }]" />
                <Select v-model="warehouse" class="w-48" aria-label="Kho" :options="[{ value: '', label: 'Tất cả kho' }, ...warehouses.map((w) => ({ value: w.id, label: w.name }))]" />
            </div>

            <div v-if="tab === 'stock'" class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="stock-by-warehouse">
                    <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Kho</th>
                            <th class="px-4 py-2.5 font-medium">SKU</th>
                            <th class="px-4 py-2.5 text-right font-medium">Tồn thực tế</th>
                            <th class="px-4 py-2.5 text-right font-medium">Đang giữ</th>
                            <th class="px-4 py-2.5 text-right font-medium">Có thể bán</th>
                            <th class="px-4 py-2.5 text-right font-medium">Hàng đang về</th>
                            <th class="px-4 py-2.5 text-right font-medium">Tồn an toàn</th>
                            <th class="px-4 py-2.5 text-right font-medium">Tồn tối đa</th>
                            <th class="px-4 py-2.5 font-medium">Vị trí</th>
                            <th class="px-4 py-2.5 text-right font-medium">Giá vốn BQ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="r in visibleStock" :key="`${r.warehouse.id}-${r.variant.id}`">
                            <td class="px-4 py-2.5">{{ r.warehouse.name }}</td>
                            <td class="px-4 py-2.5"><span class="font-mono text-xs">{{ r.variant.sku }}</span><span v-if="r.variant.name" class="text-muted-foreground"> · {{ r.variant.name }}</span></td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ formatNumber(r.on_hand) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ formatNumber(r.reserved) }}</td>
                            <td :class="cn('px-4 py-2.5 text-right font-medium tabular-nums', r.available <= 0 && 'text-destructive', r.available > 0 && r.available <= r.safety_stock && 'text-amber-600')">{{ formatNumber(r.available) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ r.incoming ? formatNumber(r.incoming) : '—' }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ r.safety_stock ? formatNumber(r.safety_stock) : '—' }}</td>
                            <td :class="cn('px-4 py-2.5 text-right tabular-nums text-muted-foreground', r.max_stock != null && r.on_hand > r.max_stock && 'font-medium text-amber-600')" :title="r.max_stock != null && r.on_hand > r.max_stock ? 'Tồn vượt mức tối đa' : undefined">
                                {{ r.max_stock != null ? formatNumber(r.max_stock) : '—' }}
                            </td>
                            <td class="px-4 py-2.5">
                                <button
                                    v-if="canLocate && !packIds.has(r.variant.id) && product.type !== 'combo'"
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded px-1 text-xs hover:bg-muted"
                                    :data-testid="`locate-${r.variant.sku}-${r.warehouse.code}`"
                                    @click="openLocation(r)"
                                >
                                    <MapPin class="size-3.5 text-muted-foreground" />
                                    <span :class="r.bin_location ? 'font-mono' : 'text-muted-foreground'">{{ r.bin_location || 'Đặt vị trí' }}</span>
                                </button>
                                <span v-else class="font-mono text-xs">{{ r.bin_location || '—' }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ Number(r.cost_price) ? formatMoney(r.cost_price) : '—' }}</td>
                        </tr>
                        <tr v-if="!visibleStock.length"><td colspan="10" class="px-4 py-8 text-center text-muted-foreground">Chưa có kho đang hoạt động.</td></tr>
                    </tbody>
                </table>
            </div>

            <template v-else>
                <div v-if="!movementsLoaded" class="space-y-2 p-4"><Skeleton v-for="i in 4" :key="i" class="h-8" /></div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="product-movements">
                        <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2.5 font-medium">Thời gian</th>
                                <th class="px-4 py-2.5 font-medium">SKU</th>
                                <th class="px-4 py-2.5 font-medium">Nghiệp vụ</th>
                                <th class="px-4 py-2.5 font-medium">Chứng từ</th>
                                <th class="px-4 py-2.5 text-right font-medium">Thay đổi</th>
                                <th class="px-4 py-2.5 text-right font-medium">Tồn sau</th>
                                <th class="px-4 py-2.5 font-medium">Kho</th>
                                <th class="px-4 py-2.5 font-medium">Người làm</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="m in movements" :key="m.id">
                                <td class="whitespace-nowrap px-4 py-2.5 text-muted-foreground">{{ formatDateTime(m.created_at) }}</td>
                                <td class="px-4 py-2.5 font-mono text-xs">{{ m.variant.sku }}</td>
                                <td class="px-4 py-2.5"><Badge :tone="movementLabels[m.type]?.tone ?? 'default'">{{ movementLabels[m.type]?.label ?? m.type }}</Badge></td>
                                <td class="px-4 py-2.5 text-xs">
                                    <template v-if="m.reference">{{ referenceLabels[m.reference.type] ?? m.reference.type }} <span class="font-mono">{{ m.reference.number ?? '' }}</span></template>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                                <td :class="cn('px-4 py-2.5 text-right font-medium tabular-nums', m.on_hand_delta > 0 && 'text-success', m.on_hand_delta < 0 && 'text-destructive')">
                                    {{ m.on_hand_delta > 0 ? '+' : '' }}{{ m.on_hand_delta ? formatNumber(m.on_hand_delta) : '0' }}
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ formatNumber(m.on_hand_after) }}</td>
                                <td class="px-4 py-2.5">{{ m.warehouse.name }}</td>
                                <td class="px-4 py-2.5 text-muted-foreground">{{ m.actor ?? '—' }}</td>
                            </tr>
                            <tr v-if="!movements.length"><td colspan="8" class="px-4 py-8 text-center text-muted-foreground">Chưa có phát sinh kho.</td></tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :page="movementMeta.current_page" :last-page="movementMeta.last_page" :total="movementMeta.total" @go="loadMovements" />
            </template>
        </Card>

        <Dialog :open="!!locating" title="Vị trí lưu kho" :description="locating ? `${locating.variant.sku} · ${locating.warehouse.name}` : undefined" @update:open="(v: boolean) => !v && (locating = null)">
            <div class="space-y-4">
                <FormField label="Vị trí (kệ, ngăn, ô)" for="bin">
                    <Input id="bin" v-model="locationForm.bin_location" maxlength="50" placeholder="Ví dụ: A-03-2" data-testid="bin-location" />
                </FormField>
                <FormField label="Tồn tối đa" for="max-stock">
                    <Input id="max-stock" v-model="locationForm.max_stock" type="number" min="0" :disabled="!canSetMax" placeholder="Không giới hạn" data-testid="max-stock" />
                    <p class="mt-1 text-xs text-muted-foreground">{{ canSetMax ? 'Gợi ý nhập hàng không vượt mức này; tồn vượt sẽ được tô màu.' : 'Chỉ quản lý được đặt tồn tối đa.' }}</p>
                </FormField>
            </div>
            <template #footer>
                <Button variant="outline" @click="locating = null">Hủy</Button>
                <Button :loading="savingLocation" data-testid="save-location" @click="saveLocation">Lưu</Button>
            </template>
        </Dialog>
    </template>
</template>
