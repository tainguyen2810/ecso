<script setup lang="ts">
import { computed, ref } from 'vue';
import { ListChecks, Package, PackageCheck, Search } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import Input from '@/shared/ui/Input.vue';
import { cn, formatNumber } from '@/shared/lib/utils';
import { kindCounts, pickOrders, pickTotals, visibleProducts, type ArrangeKind, type ArrangeSummary } from './arrange';

/** "Chọn theo sản phẩm": the orders waiting for "Chuẩn bị hàng" grouped by product, to take a whole product at once. */
const props = defineProps<{ summary: ArrangeSummary; canArrange: boolean }>();
const emit = defineEmits<{ select: [ids: string[]]; arrange: [ids: string[]] }>();
const open = defineModel<boolean>('open', { required: true });

const search = ref('');
const kind = ref<ArrangeKind>('all');
const picked = ref<Set<string>>(new Set());
// Skipping orders short of goods only makes sense when some orders do have them.
const skipShort = ref(props.summary.ready > 0 && props.summary.ready < props.summary.orders.length);

const counts = computed(() => kindCounts(props.summary));
const products = computed(() => visibleProducts(props.summary, search.value, kind.value));
const chosen = computed(() => pickOrders(props.summary, picked.value, kind.value, skipShort.value));
const totals = computed(() => pickTotals(props.summary, picked.value, chosen.value));
const allPicked = computed(() => products.value.length > 0 && products.value.every((p) => picked.value.has(p.key)));
const hasShort = computed(() => props.summary.orders.some((o) => o.shortage));

function toggle(key: string) {
    const next = new Set(picked.value);
    if (next.has(key)) next.delete(key);
    else next.add(key);
    picked.value = next;
}
function toggleAll() {
    const next = new Set(picked.value);
    for (const p of products.value) {
        if (allPicked.value) next.delete(p.key);
        else next.add(p.key);
    }
    picked.value = next;
}
const kinds: { value: ArrangeKind; label: string }[] = [
    { value: 'all', label: 'Tất cả' },
    { value: 'single', label: 'Đơn 1 loại sản phẩm' },
    { value: 'multi', label: 'Đơn nhiều loại' },
];
function done(event: 'select' | 'arrange') {
    const ids = chosen.value.map((o) => o.id);
    open.value = false;
    if (event === 'select') emit('select', ids);
    else emit('arrange', ids);
}
</script>

<template>
    <Dialog v-model:open="open" title="Chọn theo sản phẩm" :description="`${formatNumber(summary.orders.length)} đơn Shopee, TikTok đang chờ Chuẩn bị hàng, gom theo mặt hàng`" size="xl">
        <div class="space-y-3" data-testid="arrange-by-product">
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative min-w-52 flex-1">
                    <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                    <Input v-model="search" class="pl-8" placeholder="Tìm tên sản phẩm, SKU…" data-testid="arrange-search" />
                </div>
                <button
                    v-for="k in kinds"
                    :key="k.value"
                    type="button"
                    :class="cn('rounded-full border px-2.5 py-1 text-xs', kind === k.value ? 'border-primary/30 bg-blue-50 font-medium text-blue-700' : 'bg-card text-muted-foreground hover:bg-muted')"
                    :data-testid="`arrange-kind-${k.value}`"
                    @click="kind = k.value"
                >
                    {{ k.label }} · {{ counts[k.value] }}
                </button>
            </div>

            <div class="overflow-hidden rounded-lg border">
                <div class="flex items-center gap-3 bg-slate-50 px-3 py-2 text-xs font-semibold text-foreground/80">
                    <input type="checkbox" :checked="allPicked" aria-label="Chọn tất cả sản phẩm" @change="toggleAll" />
                    <span class="flex-1 pl-11">Sản phẩm</span>
                    <span class="w-16 text-right">Số đơn</span>
                    <span class="w-16 text-right">Cần lấy</span>
                    <span class="w-24 text-right">Kho</span>
                </div>
                <p v-if="!products.length" class="px-3 py-6 text-center text-sm text-muted-foreground">Không có sản phẩm nào khớp.</p>
                <label
                    v-for="p in products"
                    :key="p.key"
                    :class="cn('flex cursor-pointer items-center gap-3 border-t px-3 py-2.5 text-sm hover:bg-muted/50', picked.has(p.key) && 'bg-blue-50/60 hover:bg-blue-50/60')"
                    data-testid="arrange-product"
                >
                    <input type="checkbox" :checked="picked.has(p.key)" :aria-label="`Chọn ${p.name}`" @change="toggle(p.key)" />
                    <img v-if="p.image" :src="p.image" alt="" class="size-9 shrink-0 rounded-md border object-cover" />
                    <span v-else class="grid size-9 shrink-0 place-items-center rounded-md bg-muted text-muted-foreground"><Package class="size-4" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{ p.name }}<span v-if="p.variant_name" class="font-normal text-muted-foreground"> · {{ p.variant_name }}</span></span>
                        <span class="block truncate text-xs text-muted-foreground">{{ p.sku ?? 'Chưa có SKU' }}<template v-if="!p.mapped"> · chưa liên kết sản phẩm</template></span>
                    </span>
                    <span class="w-16 text-right tabular-nums"><b>{{ p.orders.length }}</b> <span class="text-muted-foreground">đơn</span></span>
                    <span class="w-16 text-right tabular-nums"><b>{{ formatNumber(p.quantity) }}</b> <span class="text-muted-foreground">cái</span></span>
                    <span class="w-24 text-right">
                        <span v-if="p.on_hand === null" class="text-xs text-muted-foreground">—</span>
                        <span v-else :class="cn('rounded-full px-2 py-0.5 text-xs tabular-nums', p.short ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700')">Tồn {{ formatNumber(p.on_hand) }}{{ p.short ? ' · thiếu' : '' }}</span>
                    </span>
                </label>
            </div>

            <label v-if="hasShort" class="flex items-center gap-2 text-sm">
                <input v-model="skipShort" type="checkbox" data-testid="arrange-skip-short" /> Bỏ qua đơn còn thiếu hàng
            </label>
        </div>
        <template #footer>
            <div class="mr-auto self-center text-sm" data-testid="arrange-picked">
                <template v-if="picked.size">
                    <b>{{ picked.size }} sản phẩm → {{ chosen.length }} đơn</b>
                    <span v-if="totals.length" class="text-muted-foreground"> · lấy {{ totals.map((t) => `${formatNumber(t.quantity)} ${t.name}`).join(', ') }}</span>
                </template>
                <span v-else class="text-muted-foreground">Tích sản phẩm để chọn các đơn có sản phẩm đó.</span>
            </div>
            <Button variant="outline" :disabled="!chosen.length" data-testid="arrange-select" @click="done('select')"><ListChecks /> Chỉ chọn {{ chosen.length }} đơn này</Button>
            <Button v-if="canArrange" :disabled="!chosen.length" data-testid="arrange-run" @click="done('arrange')"><PackageCheck /> Chuẩn bị hàng {{ chosen.length }} đơn</Button>
        </template>
    </Dialog>
</template>
