<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Check, ImagePlus } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { marketplaceNames } from '../orders/labels';
import type { MarketplaceImageSource } from '@/shared/types';

/**
 * "Ảnh từ sàn": the pictures each linked shop has for this product. The chosen ones are copied into the product
 * (first one = cover when the product has none); a colour's picture goes to the variants of that colour.
 */
const props = defineProps<{ productId: string; room: number }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ added: [images: { id: number; url: string }[]] }>();
const toast = useToast();

const sources = ref<MarketplaceImageSource[] | null>(null);
const tab = ref(0);
const picked = ref<string[]>([]);
const colours = ref(true);
const busy = ref(false);

watch(open, async (v) => {
    if (!v) return;
    sources.value = null;
    tab.value = 0;
    try {
        sources.value = (await api.get(`/products/${props.productId}/marketplace-images`)).data.data;
        picked.value = (sources.value?.[0]?.images ?? []).slice(0, props.room);
    } catch (e) {
        toast.error((e as ApiError).message);
        open.value = false;
    }
});
watch(tab, () => (picked.value = (sources.value?.[tab.value]?.images ?? []).slice(0, props.room)));

const source = computed(() => sources.value?.[tab.value] ?? null);
function toggle(url: string) {
    if (picked.value.includes(url)) picked.value = picked.value.filter((u) => u !== url);
    else if (picked.value.length < props.room) picked.value = [...picked.value, url];
    else toast.error(`Sản phẩm chỉ còn chỗ cho ${props.room} ảnh.`);
}
const total = computed(() => picked.value.length + (colours.value ? source.value?.variants.length ?? 0 : 0));

async function save() {
    if (!source.value) return;
    busy.value = true;
    try {
        const { data } = await api.post(`/products/${props.productId}/marketplace-images`, {
            urls: picked.value,
            variant_images: colours.value ? source.value.variants.map((v) => ({ url: v.url, variant_ids: v.variant_ids })) : [],
        });
        toast.success(`Đã thêm ${data.data.added} ảnh từ sàn.${data.data.failed ? ` ${data.data.failed} ảnh sàn không tải được.` : ''}`);
        emit('added', data.data.images);
        open.value = false;
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open" size="lg" title="Ảnh từ sàn" description="Bấm chọn ảnh muốn thêm. Ảnh được tải về lưu trong phần mềm, sàn xoá ảnh thì sản phẩm vẫn còn.">
        <div data-testid="marketplace-image-picker">
            <div v-if="!sources" class="grid grid-cols-4 gap-2"><Skeleton v-for="i in 4" :key="i" class="aspect-square" /></div>
            <p v-else-if="!sources.length" class="py-6 text-center text-sm text-muted-foreground">Sản phẩm chưa liên kết với shop nào có ảnh. Liên kết ở Kết nối sàn › Liên kết sản phẩm.</p>
            <template v-else>
                <div v-if="sources.length > 1" class="mb-3 flex gap-1 border-b">
                    <button v-for="(s, i) in sources" :key="s.account.id" type="button" :class="cn('-mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium', tab === i ? 'border-primary text-primary' : 'border-transparent text-muted-foreground')" @click="tab = i">
                        <MarketplaceLogo :marketplace="s.account.marketplace" size="sm" /> {{ s.account.name }}
                    </button>
                </div>
                <p v-else class="mb-3 flex items-center gap-2 text-sm text-muted-foreground"><MarketplaceLogo :marketplace="sources[0]!.account.marketplace" size="sm" /> {{ marketplaceNames[sources[0]!.account.marketplace] }} · {{ sources[0]!.account.name }}</p>
                <div v-if="source?.images.length" class="grid grid-cols-4 gap-2 sm:grid-cols-6">
                    <button v-for="(url, i) in source.images" :key="url" type="button" :class="cn('relative aspect-square overflow-hidden rounded-lg border-2', picked.includes(url) ? 'border-primary' : 'border-transparent')" :aria-pressed="picked.includes(url)" @click="toggle(url)">
                        <img :src="url" alt="" class="size-full object-cover" loading="lazy" />
                        <span :class="cn('absolute right-1 top-1 grid size-5 place-items-center rounded', picked.includes(url) ? 'bg-primary text-white' : 'border bg-white/90')"><Check v-if="picked.includes(url)" class="size-3.5" /></span>
                        <span v-if="i === 0" class="absolute bottom-1 left-1 rounded bg-white/90 px-1.5 text-[10px] font-medium">Ảnh bìa</span>
                    </button>
                </div>
                <p v-if="!room" class="mt-2 text-xs text-amber-700">Sản phẩm đã đủ 9 ảnh. Xoá bớt ảnh để thêm ảnh từ sàn.</p>
                <label v-if="source?.variants.length" class="mt-4 block rounded-xl border p-3">
                    <span class="flex items-center gap-2 text-sm font-medium"><input v-model="colours" type="checkbox" class="size-4" /> Ảnh phân loại trên sàn</span>
                    <span class="mt-2 flex flex-wrap gap-4">
                        <span v-for="v in source.variants" :key="v.url" class="flex items-center gap-2">
                            <img :src="v.url" alt="" class="size-9 rounded border object-cover" loading="lazy" />
                            <span class="text-xs">{{ v.label }}<span class="block text-muted-foreground">gắn cho {{ v.variant_ids.length }} phiên bản</span></span>
                        </span>
                    </span>
                </label>
            </template>
        </div>
        <template #footer>
            <span class="mr-auto self-center text-sm text-muted-foreground">Đã chọn {{ total }} ảnh</span>
            <Button variant="outline" @click="open = false">Hủy</Button>
            <Button :loading="busy" :disabled="!total" data-testid="marketplace-image-save" @click="save"><ImagePlus /> Thêm {{ total }} ảnh</Button>
        </template>
    </Dialog>
</template>
