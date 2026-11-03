<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ClipboardCheck, ClipboardList, Plus, Search } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import HeaderLink from '../../components/HeaderLink.vue';
import Pagination from '../../components/Pagination.vue';
import Tabs from '../../components/Tabs.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { documentStatus } from './labels';
import type { InventoryDocument, Warehouse } from '@/shared/types';

/** "Kiểm hàng" like Sapo: stocktake sheets (counting drafts, waiting for approval, balanced). */
const tenant = useTenantStore();
const rows = ref<InventoryDocument[]>([]);
const warehouses = ref<Warehouse[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const loadError = ref<string | null>(null);
const filters = reactive({ status: '', warehouse: '', q: '', page: 1 });

const tabs = [
    { value: '', label: 'Tất cả phiếu kiểm' },
    { value: 'draft', label: 'Đang kiểm' },
    { value: 'pending', label: 'Chờ duyệt' },
    { value: 'approved', label: 'Đã cân bằng' },
    { value: 'cancelled', label: 'Đã hủy' },
];
const statusOf = (s?: string) => (s === 'draft' ? documentStatus.counting! : s === 'approved' ? { label: 'Đã cân bằng', tone: 'success' as const } : documentStatus[s ?? ''] ?? { label: s ?? '', tone: 'muted' as const });
const linkOf = (d: InventoryDocument) => (d.status === 'draft' ? `/inventory/stocktake/${d.id}` : `/inventory/adjustments/${d.id}`);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get('/inventory/adjustments', {
            params: { reason: 'stocktake', status: filters.status || undefined, warehouse: filters.warehouse || undefined, q: filters.q || undefined, page: filters.page },
        });
        rows.value = data.data;
        meta.value = data.meta;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(async () => {
    load();
    warehouses.value = (await api.get('/warehouses')).data.data;
});
let timer: ReturnType<typeof setTimeout> | undefined;
watch(() => filters.q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => ((filters.page = 1), load()), 300);
});
watch(() => [filters.status, filters.warehouse], () => ((filters.page = 1), load()));
const canCount = computed(() => tenant.can('inventory.adjust'));
</script>

<template>
    <PageHeader title="Kiểm hàng">
        <template #links>
            <HeaderLink :icon="ClipboardList" to="/inventory/adjustments">Điều chỉnh tồn</HeaderLink>
        </template>
        <RouterLink v-if="canCount" to="/inventory/stocktake/new"><Button data-testid="new-stocktake"><Plus /> Tạo phiếu kiểm</Button></RouterLink>
    </PageHeader>
    <Card>
        <div class="border-b px-3"><Tabs v-model="filters.status" :tabs="tabs" variant="underline" /></div>
        <div class="flex flex-wrap gap-3 border-b p-4">
            <div class="relative w-full sm:min-w-64 sm:flex-1">
                <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="filters.q" class="pl-8" placeholder="Tìm kiếm theo mã phiếu kiểm hàng, ghi chú" />
            </div>
            <Select v-model="filters.warehouse" class="w-full sm:w-48" aria-label="Kho" :options="[{ value: '', label: 'Kho: Tất cả' }, ...warehouses.map((w) => ({ value: w.id, label: w.name }))]" />
        </div>
        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 5" :key="i" class="h-10" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState v-else-if="!rows.length" :icon="ClipboardCheck" title="Chưa có phiếu kiểm" description="Tạo phiếu kiểm, quét mã hoặc nhập số đếm thực tế, lưu lại nhiều lần trong lúc kiểm, xong thì bấm Cân bằng kho." />
        <div v-else class="overflow-x-auto" :class="loading && 'opacity-60'">
            <table class="w-full text-sm" data-testid="stocktake-list">
                <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Mã phiếu kiểm</th>
                        <th class="px-4 py-2.5 font-medium">Trạng thái</th>
                        <th class="px-4 py-2.5 font-medium">Kho</th>
                        <th class="px-4 py-2.5 font-medium">Ngày tạo</th>
                        <th class="px-4 py-2.5 font-medium">Ngày cân bằng</th>
                        <th class="px-4 py-2.5 font-medium">Nhân viên tạo</th>
                        <th class="px-4 py-2.5 font-medium">Nhân viên cân bằng</th>
                        <th class="px-4 py-2.5 text-right font-medium">Số SKU</th>
                        <th class="px-4 py-2.5 font-medium">Ghi chú</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="d in rows" :key="d.id" class="hover:bg-muted/30">
                        <td class="px-4 py-3"><RouterLink :to="linkOf(d)" class="font-medium text-primary hover:underline">{{ d.number }}</RouterLink></td>
                        <td class="px-4 py-3"><Badge :tone="statusOf(d.status).tone">{{ statusOf(d.status).label }}</Badge></td>
                        <td class="px-4 py-3">{{ d.warehouse?.name }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-muted-foreground">{{ formatDateTime(d.created_at) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-muted-foreground">{{ d.status === 'approved' ? formatDateTime(d.decided_at) : '—' }}</td>
                        <td class="px-4 py-3">{{ d.created_by ?? '—' }}</td>
                        <td class="px-4 py-3">{{ d.status === 'approved' ? d.decided_by ?? '—' : '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ d.lines_count ?? '—' }}</td>
                        <td class="max-w-64 truncate px-4 py-3 text-muted-foreground">{{ d.note || '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((filters.page = p), load())" />
    </Card>
</template>
