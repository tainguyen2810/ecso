<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Check, Link2, Search, Unlink, X } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Pagination from '../../components/Pagination.vue';
import SkuPicker from '../../components/SkuPicker.vue';
import Tabs from '../../components/Tabs.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { manualStockHint, mappingStatus, pushStatus } from '../orders/labels';
import type { ListingVariantData, MarketplaceAccountData, Variant } from '@/shared/types';

const route = useRoute();
const tenant = useTenantStore();
const toast = useToast();
const accountId = computed(() => route.params.id as string);

const account = ref<MarketplaceAccountData | null>(null);
const rows = ref<ListingVariantData[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const loadError = ref<string | null>(null);
const status = ref((route.query.status as string) ?? 'all');
const q = ref('');
const page = ref(1);
const busy = ref<string | null>(null);
const picking = ref<ListingVariantData | null>(null);

const tabs = computed(() => [
    { value: 'all', label: 'Tất cả' },
    { value: 'suggested', label: 'Gợi ý', count: account.value?.stats?.suggested },
    { value: 'unmapped', label: 'Chưa map' },
    { value: 'mapped', label: 'Đã map', count: account.value?.stats?.mapped },
    { value: 'ignored', label: 'Bỏ qua' },
    { value: 'push_error', label: 'Lỗi đẩy tồn', count: account.value?.stats?.push_errors },
]);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const [l, a] = await Promise.all([
            api.get(`/marketplace-accounts/${accountId.value}/listings`, { params: { status: status.value === 'all' ? undefined : status.value, q: q.value || undefined, page: page.value } }),
            api.get('/marketplace-accounts'),
        ]);
        rows.value = l.data.data;
        meta.value = l.data.meta;
        account.value = (a.data.data as MarketplaceAccountData[]).find((x) => x.id === accountId.value) ?? null;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
let timer: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => ((page.value = 1), load()), 300);
});
watch(status, () => ((page.value = 1), load()));

async function map(row: ListingVariantData, variant: { id?: string }) {
    busy.value = row.id;
    try {
        await api.put(`/listings/${row.id}/mapping`, { variant_id: variant.id });
        toast.success('Đã liên kết. Các đơn đang mở có sản phẩm này sẽ được giữ tồn.');
        picking.value = null;
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}

async function unmap(row: ListingVariantData, ignore = false) {
    busy.value = row.id;
    try {
        await api.delete(`/listings/${row.id}/mapping`, { params: { ignore: ignore ? 1 : undefined } });
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}

async function confirmAll() {
    busy.value = 'confirm-all';
    try {
        const { data } = await api.post(`/marketplace-accounts/${accountId.value}/listings/confirm-suggestions`);
        toast.success(`Đã xác nhận ${data.data.confirmed} gợi ý.`);
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}

function pickVariant(v: Variant) {
    if (picking.value) map(picking.value, v);
}
</script>

<template>
    <RouterLink to="/marketplaces" class="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft class="size-4" /> Kết nối sàn</RouterLink>
    <PageHeader :title="`Liên kết SKU · ${account?.shop_name ?? ''}`" description="Liên kết từng SKU trên sàn với SKU nội bộ để giữ tồn khi có đơn và đẩy tồn lên sàn. Gợi ý chỉ dựa trên SKU người bán trùng khớp chính xác, không bao giờ ghép theo tên; bạn cần xác nhận.">
        <Button v-if="tenant.can('marketplace.connect') && account?.stats?.suggested" :loading="busy === 'confirm-all'" data-testid="confirm-suggestions" @click="confirmAll"><Check /> Xác nhận {{ account.stats.suggested }} gợi ý</Button>
    </PageHeader>

    <Card>
        <div class="space-y-3 border-b p-4">
            <Tabs v-model="status" :tabs="tabs" />
            <div class="relative max-w-md">
                <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="q" class="pl-8" placeholder="SKU người bán, tên sản phẩm, SKU ID trên sàn…" />
            </div>
        </div>
        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 6" :key="i" class="h-12" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState v-else-if="!rows.length" :icon="Link2" title="Không có listing nào" description="Bấm “Tải sản phẩm” ở trang Kết nối sàn để lấy danh sách sản phẩm từ sàn." />
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="listing-table">
                <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Sản phẩm trên sàn</th>
                        <th class="px-4 py-2.5 font-medium">SKU người bán</th>
                        <th class="px-4 py-2.5 text-right font-medium">Giá</th>
                        <th class="px-4 py-2.5 font-medium">SKU nội bộ</th>
                        <th class="px-4 py-2.5 font-medium">Đẩy tồn</th>
                        <th class="px-4 py-2.5" />
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="r in rows" :key="r.id">
                        <td class="max-w-72 px-4 py-3">
                            <div class="truncate font-medium">{{ r.title }}</div>
                            <div class="truncate text-xs text-muted-foreground">{{ r.name }} · ID {{ r.external_sku_id }}</div>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">{{ r.seller_sku ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(r.price) }}</td>
                        <td class="px-4 py-3">
                            <Badge :tone="mappingStatus[r.mapping_status]?.tone">{{ mappingStatus[r.mapping_status]?.label }}</Badge>
                            <div v-if="r.variant" class="mt-1 text-xs"><span class="font-mono">{{ r.variant.sku }}</span> <span class="text-muted-foreground">· {{ r.variant.name }}</span></div>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            <template v-if="r.push_status">
                                <Badge :tone="pushStatus[r.push_status]?.tone">{{ pushStatus[r.push_status]?.label ?? r.push_status }}</Badge>
                                <div v-if="r.last_pushed_qty !== null" class="mt-1 text-muted-foreground">{{ formatNumber(r.last_pushed_qty) }} · {{ formatDateTime(r.last_pushed_at) }}</div>
                                <div v-if="r.push_status === 'unsupported'" class="mt-1 max-w-56 text-muted-foreground">{{ manualStockHint }}</div>
                                <div v-else-if="r.push_error" class="mt-1 text-destructive">{{ r.push_error }}</div>
                            </template>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-4 py-3">
                            <div v-if="tenant.can('marketplace.connect')" class="flex justify-end gap-1">
                                <Button v-if="r.mapping_status === 'suggested'" size="sm" :loading="busy === r.id" @click="map(r, r.variant!)"><Check /> Xác nhận</Button>
                                <Button v-if="r.mapping_status !== 'mapped'" size="sm" variant="outline" @click="picking = r"><Link2 /> Chọn SKU</Button>
                                <Button v-if="r.mapping_status === 'mapped' || r.mapping_status === 'suggested'" size="sm" variant="ghost" :loading="busy === r.id" :title="'Bỏ liên kết'" @click="unmap(r)"><Unlink /></Button>
                                <Button v-if="r.mapping_status === 'unmapped'" size="sm" variant="ghost" :title="'Bỏ qua listing này'" @click="unmap(r, true)"><X /></Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((page = p), load())" />
    </Card>

    <Dialog :open="picking !== null" title="Chọn SKU nội bộ" :description="picking ? `${picking.title} · ${picking.name} (SKU sàn: ${picking.seller_sku ?? '—'})` : ''" @update:open="(v) => !v && (picking = null)">
        <SkuPicker placeholder="Tìm SKU, mã vạch, tên sản phẩm…" @pick="pickVariant" />
        <p class="mt-3 text-xs text-muted-foreground">Sau khi liên kết, tồn khả dụng của SKU nội bộ sẽ được đẩy lên sàn và các đơn đang mở có sản phẩm này sẽ được giữ tồn.</p>
    </Dialog>
</template>
