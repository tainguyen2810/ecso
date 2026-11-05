<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Layers, PackageSearch, Plus, Search } from 'lucide-vue-next';
import Alert from '@/shared/ui/Alert.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import Input from '@/shared/ui/Input.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import AllocationDialog, { type AllocationTarget } from './AllocationDialog.vue';
import { publish, ruleLabel, type AllocationRule } from './labels';

/** Phân bổ tồn: how much of each SKU's sellable stock every shop publishes (shop default + SKU overrides). */
interface Shop {
    id: string;
    name: string;
    marketplace: string;
    status: string;
    syncs_stock: boolean;
    warehouse: string | null;
    rule: AllocationRule;
    is_default: boolean;
    mapped_skus: number;
    overrides: number;
}
interface Override {
    variant: { id: string; sku: string; name: string | null };
    sellable: Record<string, number>;
    cells: Record<string, { rule: AllocationRule | null; published: number }>;
}

const toast = useToast();
const data = ref<{ shops: Shop[]; overrides: Override[]; can_configure: boolean } | null>(null);
const search = ref('');
const target = ref<AllocationTarget | null>(null);

async function load() {
    try {
        data.value = (await api.get('/sync-center/allocations', { params: { q: search.value.trim() || undefined } })).data.data;
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}
onMounted(load);
let timer: ReturnType<typeof setTimeout> | undefined;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});

const example = (r: AllocationRule) => {
    const out = publish(100, r);
    if (r.mode === 'all' && !r.buffer_qty && r.max_qty === null) return `đẩy ${out}`;
    if (r.mode === 'none') return 'đẩy 0';
    const parts: string[] = [];
    const base = r.buffer_qty ? `(100 − ${r.buffer_qty})` : '100';
    if (r.mode === 'percent') parts.push(`${base} × ${r.value}%`);
    else if (r.mode === 'fixed') parts.push(`cố định ${r.value}`);
    else parts.push(base);
    if (r.max_qty !== null) parts.push(`trần ${r.max_qty}`);
    return `${parts.join(', ')} → đẩy ${out}`;
};
const sellableOf = (o: Override) => {
    const values = [...new Set(Object.values(o.sellable))];
    return values.length === 1 ? formatNumber(values[0]) : values.map(formatNumber).join(' / ');
};
const totalOf = (o: Override) => Object.values(o.cells).reduce((a, c) => a + c.published, 0);
const overSold = (o: Override) => totalOf(o) > Math.max(0, ...Object.values(o.sellable));
const shopsForDialog = computed(() => data.value?.shops ?? []);

function editShop(s: Shop) {
    target.value = { account: s.id, variant: null, sku: false, rule: s.is_default ? null : s.rule };
}
function editCell(o: Override, s: Shop) {
    target.value = { account: s.id, variant: o.variant, sku: true, rule: o.cells[s.id]?.rule ?? null };
}
function addSku() {
    target.value = { account: '', variant: null, sku: true, rule: null };
}
function onSaved() {
    target.value = null;
    load();
}
</script>

<template>
    <div v-if="!data" class="space-y-3"><Skeleton class="h-16" /><Skeleton class="h-48" /></div>
    <div v-else class="space-y-5">
        <Alert :icon="Layers" tone="blue">
            <b>Số tồn đẩy lên mỗi shop</b> = tồn bán được ở kho nguồn (tồn thực − đang giữ cho đơn − tồn an toàn) − <b>giữ lại</b>, rồi lấy <b>toàn bộ / theo % / số cố định</b>, không vượt <b>tối đa</b>.
            Phân bổ chỉ đổi con số công bố trên sàn; tồn kho EzSell không bao giờ bị cộng hay ghi đè theo số trên sàn.
        </Alert>

        <div>
            <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold">Mặc định theo shop</h2>
                <span class="text-xs text-muted-foreground">Áp dụng cho mọi SKU đã liên kết, trừ SKU có cài đặt riêng</span>
            </div>
            <Card>
                <EmptyState v-if="!data.shops.length" :icon="Layers" title="Chưa kết nối shop nào" description="Kết nối shop ở Shop & tài khoản để phân bổ tồn." />
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="allocation-shops">
                        <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-5 py-2.5 font-medium">Shop</th>
                                <th class="px-4 py-2.5 font-medium">Kho nguồn</th>
                                <th class="px-4 py-2.5 font-medium">Cách công bố</th>
                                <th class="px-4 py-2.5 text-right font-medium">Giữ lại</th>
                                <th class="px-4 py-2.5 text-right font-medium">Tối đa</th>
                                <th class="px-4 py-2.5 font-medium">Ví dụ: tồn bán được 100</th>
                                <th class="px-5 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="s in data.shops" :key="s.id">
                                <td class="px-5 py-3">
                                    <span class="inline-flex items-center gap-2.5">
                                        <MarketplaceLogo :marketplace="s.marketplace" :name="s.name" />
                                        <span>
                                            <span class="block font-medium">{{ s.name }}</span>
                                            <span class="text-xs text-muted-foreground">{{ formatNumber(s.mapped_skus) }} SKU đã liên kết</span>
                                        </span>
                                    </span>
                                </td>
                                <td class="px-4 py-3">{{ s.warehouse ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <Badge :tone="s.rule.mode === 'none' ? 'danger' : s.rule.mode === 'all' ? 'muted' : 'info'">{{ ruleLabel(s.rule) }}</Badge>
                                    <Badge v-if="!s.syncs_stock" tone="warning" class="ml-1">Tắt đồng bộ tồn</Badge>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ formatNumber(s.rule.buffer_qty) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums" :class="s.rule.max_qty === null && 'text-muted-foreground'">{{ s.rule.max_qty === null ? 'Không giới hạn' : formatNumber(s.rule.max_qty) }}</td>
                                <td class="px-4 py-3 text-muted-foreground">{{ example(s.rule) }}</td>
                                <td class="px-5 py-3 text-right">
                                    <Button v-if="data.can_configure" size="sm" variant="outline" data-testid="allocation-edit-shop" @click="editShop(s)">Sửa</Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </div>

        <div>
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-sm font-semibold">SKU cài đặt riêng</h2>
                    <p class="text-xs text-muted-foreground">Ví dụ: hàng hot chỉ mở bán số lượng cố định trên một sàn. Bấm vào ô để sửa.</p>
                </div>
                <div class="flex items-center gap-2">
                    <div class="relative w-56">
                        <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                        <Input v-model="search" class="pl-8" placeholder="Tìm SKU…" />
                    </div>
                    <Button v-if="data.can_configure && data.shops.length" data-testid="allocation-add-sku" @click="addSku"><Plus /> Thêm SKU</Button>
                </div>
            </div>
            <Card>
                <EmptyState v-if="!data.overrides.length" :icon="PackageSearch" title="Chưa có SKU cài đặt riêng" description="Mọi SKU đang theo mặc định của từng shop." />
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="allocation-overrides">
                        <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-5 py-2.5 font-medium">SKU</th>
                                <th class="px-4 py-2.5 text-right font-medium">Tồn bán được</th>
                                <th v-for="s in data.shops" :key="s.id" class="px-4 py-2.5 font-medium normal-case">{{ s.name }}</th>
                                <th class="px-5 py-2.5 text-right font-medium">Tổng công bố</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="o in data.overrides" :key="o.variant.id">
                                <td class="px-5 py-3"><span class="block font-medium">{{ o.variant.sku }}</span><span class="text-xs text-muted-foreground">{{ o.variant.name }}</span></td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ sellableOf(o) }}</td>
                                <td v-for="s in data.shops" :key="s.id" class="px-4 py-3">
                                    <button type="button" class="rounded px-1 py-0.5 text-left hover:bg-muted disabled:cursor-default disabled:hover:bg-transparent" :disabled="!data.can_configure" @click="editCell(o, s)">
                                        <b class="tabular-nums">{{ formatNumber(o.cells[s.id]?.published ?? 0) }}</b>
                                        <span v-if="o.cells[s.id]?.rule" class="ml-1.5 rounded bg-blue-50 px-1.5 py-0.5 text-xs text-blue-600">{{ ruleLabel(o.cells[s.id].rule!) }}</span>
                                        <span v-else class="ml-1.5 text-xs text-muted-foreground">mặc định</span>
                                    </button>
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    <b :class="overSold(o) && 'text-amber-600'">{{ formatNumber(totalOf(o)) }}</b>
                                    <span v-if="overSold(o)" class="block text-[11px] text-muted-foreground">&gt; tồn bán được</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
            <p class="mt-2 text-xs text-muted-foreground">
                Tổng công bố lớn hơn tồn bán được là bình thường khi nhiều sàn bán chung một kho: đơn mới giữ tồn ngay và số tồn mới được đẩy giảm tức thì lên các sàn còn lại. Muốn chắc chắn không bán vượt, chia % sao cho tổng không quá 100%.
            </p>
        </div>
    </div>

    <AllocationDialog v-if="target" :target="target" :shops="shopsForDialog" @close="target = null" @saved="onSaved" />
</template>
