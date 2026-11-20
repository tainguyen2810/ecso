<script setup lang="ts">
import PageHeader from '../../PageHeader.vue';
import { computed, onMounted, ref, watch } from 'vue';
import { Download, Lock, LockOpen, Search } from 'lucide-vue-next';
import Pagination from '../../components/Pagination.vue';
import Tabs from '../../components/Tabs.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import Input from '@/shared/ui/Input.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import StatTile from '@/shared/ui/StatTile.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import type { PriceBoardCell, PriceBoardRow } from '@/shared/types';
import { BadgePercent, Clock, FileSpreadsheet, TrendingDown } from 'lucide-vue-next';

/** Bảng giá sàn: price now vs price from the fee table on each marketplace shop; apply, lock, price file. */
const toast = useToast();
const tenant = useTenantStore();
const canEdit = computed(() => tenant.can('marketplace.connect'));

const tab = ref<'pending' | 'loss' | 'locked' | 'all'>('pending');
const q = ref('');
const rows = ref<PriceBoardRow[]>([]);
const accounts = ref<{ id: string; shop_name: string; marketplace: string; by_api: boolean }[]>([]);
const counts = ref({ pending: 0, loss: 0, locked: 0, all: 0 });
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const rulesUpdatedAt = ref<string | null>(null);
const loading = ref(true);
const busy = ref<string | null>(null);
const selected = ref<Set<string>>(new Set());

async function load(page = 1) {
    loading.value = true;
    try {
        const { data } = await api.get('/marketplace-pricing/board', { params: { tab: tab.value, q: q.value || undefined, page } });
        rows.value = data.data;
        accounts.value = data.accounts;
        counts.value = data.counts;
        meta.value = data.meta;
        rulesUpdatedAt.value = data.rules_updated_at;
        selected.value = new Set();
    } finally {
        loading.value = false;
    }
}
onMounted(() => load());
watch(tab, () => load());
let timer: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => load(), 300);
});

const actionable = (c?: PriceBoardCell) => !!c && (c.status === 'pending' || c.status === 'loss' || c.status === 'failed');
function toggleRow(r: PriceBoardRow, on: boolean) {
    for (const c of Object.values(r.cells)) {
        if (on) selected.value.add(c.listing_id);
        else selected.value.delete(c.listing_id);
    }
    selected.value = new Set(selected.value);
}
const rowSelected = (r: PriceBoardRow) => Object.values(r.cells).some((c) => selected.value.has(c.listing_id));
const exportable = computed(() => rows.value.some((r) => Object.values(r.cells).some((c) => c.status === 'export')));

async function run(key: string, fn: () => Promise<string | void>) {
    busy.value = key;
    try {
        const msg = await fn();
        if (msg) toast.success(msg);
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}
const apply = (ids: string[] | null) => run(ids?.length === 1 ? `apply-${ids[0]}` : 'apply', async () => {
    const { data } = await api.post('/marketplace-pricing/board/apply', ids ? { listing_ids: ids } : { all: true });
    await load(meta.value.current_page);
    const d = data.data;
    const parts = [`Đã áp dụng ${formatNumber(d.applied)} giá.`];
    if (d.pushed) parts.push(`${formatNumber(d.pushed)} giá đang được đẩy lên sàn.`);
    if (d.exported) parts.push(`${formatNumber(d.exported)} giá đang chờ trong file giá để tải lên sàn.`);
    return parts.join(' ');
});

/** Prices that would go straight to a marketplace: shown first, sent only after "Đẩy giá lên sàn". */
type Preview = { name: string; shop: string; marketplace: string; current: number | null; next: number };
const confirmOpen = ref(false);
const confirmIds = ref<string[] | null>(null);
const preview = ref<Preview[]>([]);
const apiShops = computed(() => new Set(accounts.value.filter((a) => a.by_api).map((a) => a.id)));
function askApply(ids: string[] | null) {
    const list: Preview[] = [];
    for (const r of rows.value) {
        for (const [accountId, c] of Object.entries(r.cells)) {
            if (!actionable(c) || c.new === null || !apiShops.value.has(accountId)) continue;
            if (ids && !ids.includes(c.listing_id)) continue;
            const a = accounts.value.find((x) => x.id === accountId)!;
            list.push({ name: [r.name, r.variant_name].filter(Boolean).join(' · '), shop: a.shop_name, marketplace: a.marketplace, current: c.current, next: c.new });
        }
    }
    if (!list.length) return apply(ids);
    preview.value = list;
    confirmIds.value = ids;
    confirmOpen.value = true;
}
async function confirmApply() {
    confirmOpen.value = false;
    await apply(confirmIds.value);
}
const previewMore = computed(() => (ids: string[] | null) => !ids && counts.value.pending > rows.value.length);
const lock = (ids: string[], locked: boolean) => run(`lock-${ids[0]}`, async () => {
    await api.post('/marketplace-pricing/board/lock', { listing_ids: ids, locked });
    await load(meta.value.current_page);
    return locked ? 'Đã khóa giá, EzChat Bán hàng không đổi giá này nữa.' : 'Đã mở khóa giá.';
});
function exportFile(accountId: string) {
    window.location.href = `/api/v1/marketplace-pricing/board/export?account=${accountId}`;
}
const diffText = (c: PriceBoardCell) => (c.current !== null && c.new !== null ? c.new - c.current : null);
</script>

<template>
    <PageHeader title="Bảng giá sàn" :back="{ to: '/marketplaces', label: 'Kết nối sàn' }">
        <template #description>Giá niêm yết từng sàn tính từ giá gốc và <RouterLink to="/settings/pricing" class="text-primary hover:underline">bảng phí</RouterLink>. Duyệt rồi áp dụng, hoặc xuất file giá để tải lên Seller Center.</template>
        <Button v-for="a in accounts" v-show="exportable" :key="a.id" variant="outline" :data-testid="`export-${a.id}`" @click="exportFile(a.id)"><Download /> File giá {{ a.shop_name }}</Button>
        <Button v-if="canEdit && selected.size" variant="outline" :loading="busy?.startsWith('lock')" @click="lock([...selected], true)"><Lock /> Khóa giá đã chọn</Button>
        <Button v-if="canEdit" :loading="busy === 'apply'" :disabled="!counts.pending" data-testid="apply-prices" @click="askApply(selected.size ? [...selected] : null)">
            {{ selected.size ? `Áp dụng đã chọn (${selected.size})` : `Áp dụng tất cả (${counts.pending})` }}
        </Button>
    </PageHeader>

    <Card class="mb-4">
        <div class="grid divide-y sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-4 lg:divide-x">
            <StatTile :icon="Clock" label="Chờ duyệt giá mới" :value="formatNumber(counts.pending)" :tone="counts.pending ? 'amber' : 'slate'" />
            <StatTile :icon="TrendingDown" label="Lỗ hoặc dưới lãi tối thiểu" :value="formatNumber(counts.loss)" :tone="counts.loss ? 'red' : 'slate'" />
            <StatTile :icon="Lock" label="Đang khóa giá" :value="formatNumber(counts.locked)" tone="slate" />
            <StatTile :icon="BadgePercent" label="Bảng phí cập nhật" :value="rulesUpdatedAt ? formatDateTime(rulesUpdatedAt) : null" tone="blue" />
        </div>
    </Card>

    <Card>
        <div class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
            <Tabs v-model="tab" :tabs="[{ value: 'pending', label: 'Chờ duyệt', count: counts.pending }, { value: 'loss', label: 'Lỗ sau phí', count: counts.loss }, { value: 'locked', label: 'Đang khóa', count: counts.locked }, { value: 'all', label: 'Tất cả', count: counts.all }]" />
            <div class="relative w-64"><Search class="absolute left-2.5 top-2.5 size-4 text-muted-foreground" /><Input v-model="q" placeholder="Tìm SKU, tên sản phẩm…" class="pl-8" /></div>
        </div>
        <div v-if="loading && !rows.length" class="space-y-2 p-4"><Skeleton v-for="i in 5" :key="i" class="h-12" /></div>
        <EmptyState v-else-if="!accounts.length" :icon="FileSpreadsheet" title="Chưa có bảng phí" description="Khai bảng phí cho từng gian hàng ở Cấu hình → Thuế và chính sách giá." />
        <EmptyState v-else-if="!rows.length" :icon="FileSpreadsheet" :title="tab === 'pending' ? 'Không có giá cần duyệt' : 'Không có sản phẩm'" description="Giá trên sàn đang khớp với bảng phí." />
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="price-board">
                <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Sản phẩm</th>
                        <th class="px-3 py-2.5 text-right font-medium">Giá gốc</th>
                        <th class="px-3 py-2.5 text-right font-medium">Giá vốn</th>
                        <template v-for="a in accounts" :key="a.id">
                            <th class="border-l px-3 py-2.5 text-right font-medium"><span class="inline-flex items-center gap-1.5"><MarketplaceLogo :marketplace="a.marketplace" :name="a.shop_name" size="sm" />Hiện tại</span></th>
                            <th class="px-3 py-2.5 text-right font-medium">Giá mới</th>
                        </template>
                        <th class="px-4 py-2.5" />
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="r in rows" :key="r.variant_id" :class="r.flags.loss ? 'bg-rose-50/60' : ''">
                        <td class="px-4 py-2.5">
                            <label class="flex items-start gap-2">
                                <input v-if="canEdit" type="checkbox" class="mt-1" :checked="rowSelected(r)" @change="toggleRow(r, ($event.target as HTMLInputElement).checked)" />
                                <span>
                                    <RouterLink v-if="r.product_id" :to="`/products/${r.product_id}`" class="font-medium hover:text-primary">{{ r.name }}</RouterLink>
                                    <span v-if="r.variant_name" class="text-muted-foreground"> · {{ r.variant_name }}</span>
                                    <span v-if="r.flags.loss" class="ml-1 rounded-full bg-rose-100 px-1.5 text-xs text-rose-700">lỗ sau phí</span>
                                    <span class="block font-mono text-xs text-muted-foreground">{{ r.sku }}</span>
                                </span>
                            </label>
                        </td>
                        <td class="whitespace-nowrap px-3 text-right tabular-nums">{{ formatMoney(r.base) }}</td>
                        <td class="whitespace-nowrap px-3 text-right tabular-nums text-muted-foreground">{{ r.cost ? formatMoney(r.cost) : '—' }}</td>
                        <template v-for="a in accounts" :key="a.id">
                            <template v-if="r.cells[a.id]">
                                <td class="whitespace-nowrap border-l px-3 text-right tabular-nums">
                                    {{ r.cells[a.id].current !== null ? formatMoney(r.cells[a.id].current) : '—' }}
                                    <span v-if="r.cells[a.id].below_margin && r.cells[a.id].current_outcome" class="block text-xs text-destructive">
                                        thu về {{ formatMoney(Math.round(r.cells[a.id].current_outcome!.net)) }}{{ (r.cells[a.id].current_outcome!.profit ?? 0) < 0 ? ` · lỗ ${formatMoney(-Math.round(r.cells[a.id].current_outcome!.profit!))}` : '' }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-3 text-right tabular-nums">
                                    <template v-if="r.cells[a.id].locked">
                                        <span class="text-muted-foreground">giữ {{ formatMoney(r.cells[a.id].current) }}</span>
                                        <span class="block text-xs text-muted-foreground">tính ra {{ formatMoney(r.cells[a.id].new) }}</span>
                                    </template>
                                    <template v-else-if="r.cells[a.id].new !== null">
                                        <span class="font-semibold">{{ formatMoney(r.cells[a.id].new) }}</span>
                                        <span v-if="r.cells[a.id].status === 'export'" class="block text-xs text-blue-600">trong file giá</span>
                                        <span v-else-if="r.cells[a.id].status === 'pushing'" class="block text-xs text-blue-600">đang đẩy lên sàn…</span>
                                        <span v-else-if="r.cells[a.id].status === 'failed'" class="block max-w-56 whitespace-normal text-xs text-destructive" :title="r.cells[a.id].push_error ?? ''">sàn chưa nhận: {{ r.cells[a.id].push_error }}</span>
                                        <span v-else-if="r.cells[a.id].raised && actionable(r.cells[a.id])" class="block text-xs text-amber-600">nâng lên lãi tối thiểu {{ r.cells[a.id].min_margin_pct }}%</span>
                                        <span v-else-if="actionable(r.cells[a.id]) && diffText(r.cells[a.id]) !== null" class="block text-xs text-amber-600">{{ diffText(r.cells[a.id])! > 0 ? '+' : '' }}{{ formatMoney(diffText(r.cells[a.id])) }}</span>
                                        <span v-else class="block text-xs text-muted-foreground">không đổi</span>
                                    </template>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                            </template>
                            <td v-else colspan="2" class="border-l px-3 text-center text-xs text-muted-foreground">chưa liên kết</td>
                        </template>
                        <td class="whitespace-nowrap px-4 text-right text-sm">
                            <template v-if="canEdit">
                                <button v-if="Object.values(r.cells).some(actionable)" type="button" class="text-primary hover:underline" @click="askApply(Object.values(r.cells).filter(actionable).map((c) => c.listing_id))">Áp dụng</button>
                                <button v-if="r.flags.locked" type="button" class="ml-2 inline-flex items-center gap-1 text-muted-foreground hover:text-foreground" @click="lock(Object.values(r.cells).filter((c) => c.locked).map((c) => c.listing_id), false)"><LockOpen class="size-3.5" /> Mở khóa</button>
                                <button v-else type="button" class="ml-2 text-muted-foreground hover:text-foreground" title="Khóa giá: EzChat Bán hàng không đổi giá SKU này trên sàn" @click="lock(Object.values(r.cells).map((c) => c.listing_id), true)"><Lock class="size-3.5" /></button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination v-if="rows.length" :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="load" />
        <p class="border-t px-5 py-3 text-xs text-muted-foreground">
            <template v-if="accounts.some((a) => a.by_api)"><b>Áp dụng</b> đẩy giá mới thẳng lên Shopee và TikTok Shop sau khi bạn xem lại và xác nhận. Giá đổi là giá niêm yết; chương trình giảm giá đang chạy trên sàn vẫn giữ nguyên. </template>
            <template v-if="accounts.some((a) => !a.by_api)">Sàn chưa đổi giá qua API được thì <b>Áp dụng</b> ghi giá mới vào <b>file giá</b> (Excel: mã sản phẩm, mã phân loại, SKU, giá mới) để bạn tải lên công cụ cập nhật hàng loạt của sàn. Khi sàn hiện đúng giá mới, EzChat Bán hàng tự đánh dấu xong.</template>
        </p>
    </Card>

    <Dialog v-model:open="confirmOpen" title="Đẩy giá lên sàn" :description="`${formatNumber(preview.length)} giá sẽ đổi ngay trên sàn. Xem lại trước khi đẩy.`" size="lg">
        <table class="w-full text-sm" data-testid="price-push-preview">
            <thead class="text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                <tr><th class="py-1.5 font-medium">Sản phẩm</th><th class="py-1.5 font-medium">Shop</th><th class="py-1.5 text-right font-medium">Đang bán</th><th class="py-1.5 text-right font-medium">Giá mới</th></tr>
            </thead>
            <tbody class="divide-y">
                <tr v-for="(p, i) in preview.slice(0, 50)" :key="i">
                    <td class="py-2 pr-3">{{ p.name }}</td>
                    <td class="py-2 pr-3"><span class="inline-flex items-center gap-1.5 whitespace-nowrap"><MarketplaceLogo :marketplace="p.marketplace" :name="p.shop" size="sm" />{{ p.shop }}</span></td>
                    <td class="whitespace-nowrap py-2 text-right tabular-nums text-muted-foreground">{{ p.current !== null ? formatMoney(p.current) : '—' }}</td>
                    <td class="whitespace-nowrap py-2 text-right font-semibold tabular-nums">{{ formatMoney(p.next) }}</td>
                </tr>
            </tbody>
        </table>
        <p v-if="preview.length > 50 || previewMore(confirmIds)" class="mt-2 text-xs text-muted-foreground">Bảng chỉ hiện các giá ở trang này; "Áp dụng tất cả" đẩy mọi giá đang chờ duyệt.</p>
        <template #footer>
            <Button variant="outline" @click="confirmOpen = false">Để sau</Button>
            <Button data-testid="confirm-price-push" @click="confirmApply">Đẩy giá lên sàn</Button>
        </template>
    </Dialog>
</template>
