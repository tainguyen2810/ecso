<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { ShoppingBasket, Sparkles } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import PurchasingNav from './PurchasingNav.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { draftFromRows, REORDER_DRAFT_KEY, type ReorderRow } from './reorder';
import type { Warehouse } from '@/shared/types';

const router = useRouter();
const tenant = useTenantStore();
const warehouses = ref<Warehouse[]>([]);
const warehouse = ref('');
const cover = ref('14');
const rows = ref<ReorderRow[]>([]);
const picked = ref<Record<string, boolean>>({});
const qty = ref<Record<string, number>>({});
const loading = ref(true);
const loadError = ref<string | null>(null);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get('/purchase-orders/reorder', { params: { warehouse_id: warehouse.value || undefined, cover_days: cover.value } });
        rows.value = data.data;
        warehouse.value ||= data.meta.warehouse.id;
        picked.value = Object.fromEntries(rows.value.map((r) => [r.variant.id, true]));
        qty.value = Object.fromEntries(rows.value.map((r) => [r.variant.id, r.suggested]));
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(async () => {
    warehouses.value = ((await api.get('/warehouses')).data.data as Warehouse[]).filter((w) => w.status === 'active');
    await load();
});
watch(cover, load);
watch(warehouse, (now, before) => before && load());

const selected = computed(() => rows.value.filter((r) => picked.value[r.variant.id]));
const selectedValue = computed(() => selected.value.reduce((t, r) => t + (qty.value[r.variant.id] ?? 0) * Number(r.unit_cost), 0));

async function createOrder() {
    try {
        sessionStorage.setItem(REORDER_DRAFT_KEY, JSON.stringify(draftFromRows(selected.value, warehouse.value, qty.value)));
    } catch {
        /* storage unavailable */
    }
    await router.push({ path: '/purchasing/new', query: { from: 'reorder' } });
}
</script>

<template>
    <PageHeader title="Gợi ý nhập hàng" description="Nhà cung cấp, đơn nhập hàng, nhận hàng nhiều đợt, trả hàng và công nợ nhà cung cấp.">
        <Button v-if="tenant.can('purchasing.manage')" :disabled="!selected.length" data-testid="reorder-create" @click="createOrder"><ShoppingBasket /> Tạo đơn nhập ({{ selected.length }})</Button>
    </PageHeader>
    <PurchasingNav />
    <Card>
        <div class="flex flex-wrap items-center gap-3 border-b p-4 text-sm">
            <Sparkles class="size-4 text-primary" />
            <span class="text-muted-foreground">Gợi ý = bán trung bình/ngày (30 ngày qua) × số ngày cần đủ hàng + tồn an toàn − khả dụng − đang về.</span>
            <div class="ml-auto flex gap-2">
                <Select v-model="warehouse" class="w-48" :options="warehouses.map((w) => ({ value: w.id, label: w.name }))" aria-label="Kho" />
                <Select v-model="cover" class="w-44" :options="[7, 14, 21, 30, 45, 60].map((d) => ({ value: String(d), label: `Đủ hàng ${d} ngày` }))" aria-label="Số ngày" />
            </div>
        </div>
        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 4" :key="i" class="h-10" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState v-else-if="!rows.length" :icon="Sparkles" title="Chưa cần nhập thêm" description="Không SKU nào sắp thiếu hàng theo tốc độ bán hiện tại và tồn an toàn đã đặt." />
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="reorder-table">
                <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="w-10 px-4 py-2.5" />
                        <th class="px-4 py-2.5 font-medium">SKU</th>
                        <th class="px-4 py-2.5 text-right font-medium">Bán 30 ngày</th>
                        <th class="px-4 py-2.5 text-right font-medium">TB/ngày</th>
                        <th class="px-4 py-2.5 text-right font-medium">Khả dụng</th>
                        <th class="px-4 py-2.5 text-right font-medium">Đang về</th>
                        <th class="px-4 py-2.5 text-right font-medium">Đặt thêm</th>
                        <th class="px-4 py-2.5 font-medium">NCC gần nhất</th>
                        <th class="px-4 py-2.5 text-right font-medium">Giá nhập</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="r in rows" :key="r.variant.id">
                        <td class="px-4 py-2.5"><input v-model="picked[r.variant.id]" type="checkbox" class="size-4" /></td>
                        <td class="px-4 py-2.5"><p class="font-mono text-xs font-medium">{{ r.variant.sku }}</p><p class="max-w-64 truncate text-xs text-muted-foreground">{{ r.variant.name }}</p></td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ formatNumber(r.sold) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ r.daily.toLocaleString('vi-VN') }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums" :class="r.available <= r.safety_stock && 'font-medium text-destructive'">{{ formatNumber(r.available) }}<span v-if="r.safety_stock" class="text-xs text-muted-foreground"> / an toàn {{ r.safety_stock }}</span></td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ r.on_order ? formatNumber(r.on_order) : '—' }}</td>
                        <td class="px-4 py-2.5 text-right"><input v-model.number="qty[r.variant.id]" type="number" min="1" class="h-8 w-20 rounded-md border border-input bg-card px-2 text-right font-medium tabular-nums" /></td>
                        <td class="px-4 py-2.5 text-muted-foreground">{{ r.supplier?.name ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ formatMoney(r.unit_cost) }}</td>
                    </tr>
                </tbody>
            </table>
            <p class="border-t px-4 py-3 text-right text-sm">Đã chọn {{ selected.length }} SKU · ước tính <span class="font-semibold tabular-nums">{{ formatMoney(selectedValue) }}</span></p>
        </div>
    </Card>
</template>
