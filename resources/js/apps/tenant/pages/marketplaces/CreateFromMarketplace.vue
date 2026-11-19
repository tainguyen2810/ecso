<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { ImageOff, Info, PackagePlus } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatMoney } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { marketplaceNames } from '../orders/labels';

/**
 * "Tạo sản phẩm từ hàng trên sàn": the shop's products that are not in the warehouse at all, previewed with their
 * pictures, variants, SKUs and prices, then created and linked. Pictures are copied into the shop's storage.
 */
const props = defineProps<{ accountId: string; marketplace: string; preselected?: string[] }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ done: [] }>();
const toast = useToast();

type Pending = {
    id: string;
    title: string;
    image_url: string | null;
    images: number;
    variants: { name: string | null; seller_sku: string | null; sku: string; price: string | null; image_url: string | null }[];
};
const rows = ref<Pending[] | null>(null);
const picked = ref<Set<string>>(new Set());
const images = ref(true);
const showAll = ref(false);
const busy = ref(false);
const error = ref<string | null>(null);

watch(open, async (v) => {
    if (!v) return;
    rows.value = null;
    error.value = null;
    showAll.value = false;
    try {
        const { data } = await api.get(`/marketplace-accounts/${props.accountId}/new-products`);
        rows.value = data.data;
        const only = props.preselected?.length ? new Set(props.preselected) : null;
        picked.value = new Set((data.data as Pending[]).filter((r) => !only || only.has(r.id)).map((r) => r.id));
    } catch (e) {
        error.value = (e as ApiError).message;
    }
});

const shown = computed(() => (showAll.value ? rows.value ?? [] : (rows.value ?? []).slice(0, 6)));
const variantCount = computed(() => (rows.value ?? []).filter((r) => picked.value.has(r.id)).reduce((n, r) => n + r.variants.length, 0));
const name = computed(() => marketplaceNames[props.marketplace] ?? props.marketplace);

function toggle(id: string) {
    const next = new Set(picked.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    picked.value = next;
}

function variantsLine(r: Pending): string {
    const names = r.variants.map((v) => v.name).filter(Boolean);
    return names.length ? `${r.variants.length} phân loại: ${names.slice(0, 4).join('; ')}${names.length > 4 ? '…' : ''}` : '1 phân loại';
}

function priceOf(r: Pending): string {
    const prices = r.variants.map((v) => Number(v.price ?? 0)).filter((p) => p > 0);
    if (!prices.length) return '—';
    const lo = Math.min(...prices);
    const hi = Math.max(...prices);
    return lo === hi ? formatMoney(lo) : `${formatMoney(lo)} – ${formatMoney(hi)}`;
}

async function create() {
    busy.value = true;
    try {
        const { data } = await api.post(`/marketplace-accounts/${props.accountId}/new-products/approve`, { listing_ids: [...picked.value], images: images.value });
        const made = data.data.created as number;
        toast.success(`Đã tạo ${made} sản phẩm và liên kết với ${name.value}.${images.value && made ? ' Ảnh đang được tải về, vài phút sẽ xong.' : ''}`);
        open.value = false;
        emit('done');
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open" size="lg" :title="`Tạo sản phẩm từ ${name}`" :description="rows ? `${rows.length} sản phẩm chưa có trong kho. Tạo xong sẽ tự liên kết với sàn.` : ''">
        <div data-testid="create-from-marketplace">
            <div v-if="error" class="rounded-md bg-rose-50 p-3 text-sm text-rose-700">{{ error }}</div>
            <div v-else-if="!rows" class="space-y-2"><Skeleton v-for="i in 3" :key="i" class="h-14" /></div>
            <p v-else-if="!rows.length" class="py-6 text-center text-sm text-muted-foreground">Mọi sản phẩm trên shop đều đã có trong kho.</p>
            <template v-else>
                <ul class="divide-y rounded-xl border px-3">
                    <li v-for="r in shown" :key="r.id" class="flex items-center gap-3 py-2.5">
                        <input type="checkbox" class="size-4" :checked="picked.has(r.id)" :aria-label="r.title" @change="toggle(r.id)" />
                        <div class="relative">
                            <img v-if="r.image_url" :src="r.image_url" alt="" class="size-12 rounded-lg border object-cover" />
                            <span v-else class="grid size-12 place-items-center rounded-lg border border-dashed text-slate-300"><ImageOff class="size-5" /></span>
                            <span v-if="r.images > 1" class="absolute -bottom-1 -right-1 rounded bg-slate-900/80 px-1 text-[10px] font-medium text-white">{{ r.images }} ảnh</span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 font-medium">
                                <span class="truncate">{{ r.title }}</span>
                                <span v-if="r.variants.some((v) => !v.seller_sku)" class="shrink-0 rounded-full bg-amber-50 px-2 text-[11px] font-medium text-amber-700">Sàn chưa có SKU</span>
                            </div>
                            <div class="truncate text-xs text-muted-foreground">{{ variantsLine(r) }} · SKU: {{ r.variants.map((v) => v.sku).slice(0, 3).join(', ') }}{{ r.variants.length > 3 ? '…' : '' }}</div>
                        </div>
                        <div class="shrink-0 text-right text-sm tabular-nums">{{ priceOf(r) }}</div>
                    </li>
                    <li v-if="rows.length > shown.length" class="py-2 text-center"><button type="button" class="text-sm text-primary hover:underline" @click="showAll = true">Xem thêm {{ rows.length - shown.length }} sản phẩm</button></li>
                </ul>
                <div class="mt-4 space-y-2 rounded-xl border p-4 text-sm">
                    <div class="font-medium">Lấy từ sàn</div>
                    <p class="text-muted-foreground">Tên, mô tả, phân loại, giá đang bán trên sàn (không tính giá khuyến mãi của sàn) và cân nặng.</p>
                    <label class="flex items-start gap-2.5">
                        <input v-model="images" type="checkbox" class="mt-0.5 size-4" data-testid="create-with-images" />
                        <span>Ảnh sản phẩm<span class="block text-xs text-muted-foreground">Tất cả ảnh của sản phẩm (tối đa 9) và ảnh từng phân loại, tải về lưu trong phần mềm.</span></span>
                    </label>
                </div>
                <div class="mt-3 flex gap-2.5 rounded-xl bg-slate-50 p-3 text-sm">
                    <Info class="mt-0.5 size-4 shrink-0 text-slate-400" />
                    <span>Mã nào sàn chưa có SKU sẽ tự đặt mã <b>MP-…</b>. Giá vốn và tồn đầu kỳ để trống: nhập ở bước <b>Nhập tồn đầu kỳ</b> hoặc phiếu nhập hàng.</span>
                </div>
            </template>
        </div>
        <template #footer>
            <Button variant="outline" @click="open = false">Hủy</Button>
            <Button :loading="busy" :disabled="!picked.size" data-testid="create-from-marketplace-go" @click="create"><PackagePlus /> Tạo {{ picked.size }} sản phẩm<span v-if="variantCount > picked.size" class="font-normal opacity-80">({{ variantCount }} phân loại)</span></Button>
        </template>
    </Dialog>
</template>
