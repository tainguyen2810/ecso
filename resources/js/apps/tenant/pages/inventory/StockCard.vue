<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, History, Lock } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import InventoryNav from './InventoryNav.vue';
import Pagination from '../../components/Pagination.vue';
import Badge from '@/shared/ui/Badge.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { movementLabels, referenceLabels } from './labels';
import type { Movement } from '@/shared/types';

interface CardBalance {
    warehouse: { id: string; code: string; name: string };
    on_hand: number;
    reserved: number;
    available: number;
    safety_stock: number;
}

const route = useRoute();
const variant = ref<{ id: string; sku: string; name: string; cost_price: string } | null>(null);
const balances = ref<CardBalance[]>([]);
const reservations = ref(0);
const movements = ref<Movement[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const loadError = ref<string | null>(null);
const filters = reactive({ warehouse: '', type: '', page: 1 });

const warehouseOptions = computed(() => [{ value: '', label: 'Tất cả kho' }, ...balances.value.map((b) => ({ value: b.warehouse.id, label: `${b.warehouse.code} · ${b.warehouse.name}` }))]);
const typeOptions = [{ value: '', label: 'Mọi loại phát sinh' }, ...Object.entries(movementLabels).map(([value, l]) => ({ value, label: l.label }))];
const totals = computed(() => balances.value.reduce((t, b) => ({ on_hand: t.on_hand + b.on_hand, reserved: t.reserved + b.reserved, available: t.available + b.available }), { on_hand: 0, reserved: 0, available: 0 }));

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get(`/inventory/variants/${route.params.id}/card`, {
            params: { warehouse: filters.warehouse || undefined, type: filters.type || undefined, page: filters.page },
        });
        variant.value = data.variant;
        balances.value = data.balances;
        reservations.value = data.reservations;
        movements.value = data.data;
        meta.value = data.meta;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
watch(() => [filters.warehouse, filters.type], () => ((filters.page = 1), load()));

function delta(n: number): string {
    return n > 0 ? `+${formatNumber(n)}` : n < 0 ? `−${formatNumber(-n)}` : '—';
}
</script>

<template>
    <RouterLink to="/inventory" class="mb-3 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft class="size-4" /> Tồn kho</RouterLink>
    <PageHeader :title="variant ? `Thẻ kho · ${variant.sku}` : 'Thẻ kho'" :description="variant?.name" />
    <InventoryNav />

    <ErrorState v-if="loadError && !variant" :message="loadError" @retry="load" />
    <template v-else>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Tổng tồn thực tế</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ formatNumber(totals.on_hand) }}</p>
                <p class="text-xs text-muted-foreground">Giá vốn BQ {{ formatMoney(variant?.cost_price) }}</p>
            </Card>
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Khả dụng</p>
                <p :class="cn('mt-1 text-2xl font-semibold tabular-nums', totals.available <= 0 && 'text-destructive')">{{ formatNumber(totals.available) }}</p>
                <p class="flex items-center gap-1 text-xs text-muted-foreground"><Lock class="size-3" /> {{ formatNumber(totals.reserved) }} đang giữ cho {{ reservations }} đơn</p>
            </Card>
            <Card v-for="b in balances" :key="b.warehouse.id" class="p-4">
                <p class="text-xs text-muted-foreground">{{ b.warehouse.code }} · {{ b.warehouse.name }}</p>
                <p class="mt-1 text-lg font-semibold tabular-nums">{{ formatNumber(b.on_hand) }} <span class="text-sm font-normal text-muted-foreground">thực tế</span></p>
                <p class="text-xs text-muted-foreground">Khả dụng {{ formatNumber(b.available) }} · an toàn {{ b.safety_stock }}</p>
            </Card>
        </div>

        <Card class="mt-5" title="Lịch sử nhập xuất" description="Sổ kho bất biến: mỗi dòng là một phát sinh, kèm tồn sau phát sinh. Không thể sửa hoặc xóa.">
            <template #actions>
                <div class="flex gap-2">
                    <Select v-model="filters.warehouse" :options="warehouseOptions" class="h-8 w-44" />
                    <Select v-model="filters.type" :options="typeOptions" class="h-8 w-44" />
                </div>
            </template>
            <div v-if="loading && !movements.length" class="space-y-3 p-4"><Skeleton v-for="i in 5" :key="i" class="h-9" /></div>
            <EmptyState v-else-if="!movements.length" :icon="History" title="Chưa có phát sinh" description="Nhập kho hoặc điều chỉnh để tạo dòng đầu tiên trên thẻ kho." />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="movement-table">
                    <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Thời gian</th>
                            <th class="px-4 py-2.5 font-medium">Loại</th>
                            <th class="px-4 py-2.5 font-medium">Kho</th>
                            <th class="px-4 py-2.5 font-medium">Chứng từ</th>
                            <th class="px-4 py-2.5 text-right font-medium">Thực tế ±</th>
                            <th class="px-4 py-2.5 text-right font-medium">Giữ ±</th>
                            <th class="px-4 py-2.5 text-right font-medium">Tồn sau</th>
                            <th class="px-4 py-2.5 text-right font-medium">Khả dụng sau</th>
                            <th class="px-4 py-2.5 font-medium">Người thực hiện</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="m in movements" :key="m.id" class="hover:bg-muted/30">
                            <td class="whitespace-nowrap px-4 py-2.5 text-muted-foreground">{{ formatDateTime(m.created_at) }}</td>
                            <td class="px-4 py-2.5"><Badge :tone="movementLabels[m.type]?.tone ?? 'muted'">{{ movementLabels[m.type]?.label ?? m.type }}</Badge></td>
                            <td class="px-4 py-2.5">{{ m.warehouse.code }}</td>
                            <td class="px-4 py-2.5">
                                <span v-if="m.reference" class="text-muted-foreground">{{ referenceLabels[m.reference.type] ?? m.reference.type }}</span>
                                <span v-if="m.reference?.number" class="ml-1 font-mono text-xs">{{ m.reference.number }}</span>
                                <p v-if="m.reason" class="text-xs text-muted-foreground">{{ m.reason }}</p>
                            </td>
                            <td :class="cn('px-4 py-2.5 text-right font-medium tabular-nums', m.on_hand_delta > 0 && 'text-success', m.on_hand_delta < 0 && 'text-destructive')">{{ delta(m.on_hand_delta) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ delta(m.reserved_delta) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ formatNumber(m.on_hand_after) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ formatNumber(m.available_after) }}</td>
                            <td class="px-4 py-2.5 text-muted-foreground">{{ m.actor === 'system' ? 'Hệ thống' : m.actor }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((filters.page = p), load())" />
        </Card>
    </template>
</template>
