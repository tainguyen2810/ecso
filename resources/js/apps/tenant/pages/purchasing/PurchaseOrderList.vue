<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ClipboardList, Plus, Search } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Pagination from '../../components/Pagination.vue';
import PurchasingNav from './PurchasingNav.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDate, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { owed, poStatus } from './labels';
import type { PurchaseOrderData } from '@/shared/types';

const tenant = useTenantStore();
const route = useRoute();
const rows = ref<PurchaseOrderData[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const loadError = ref<string | null>(null);
const q = ref('');
const status = ref(String(route.query.status ?? ''));
const page = ref(1);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get('/purchase-orders', { params: { q: q.value || undefined, status: status.value || undefined, page: page.value } });
        rows.value = data.data;
        meta.value = data.meta;
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

const statusOptions = [
    { value: '', label: 'Mọi trạng thái' },
    { value: 'open', label: 'Đang chờ hàng' },
    { value: 'unpaid', label: 'Còn nợ NCC' },
    ...Object.entries(poStatus).map(([value, s]) => ({ value, label: s.label })),
];
</script>

<template>
    <PageHeader title="Nhập hàng" description="Nhà cung cấp, đơn nhập hàng, nhận hàng nhiều đợt, trả hàng và công nợ nhà cung cấp.">
        <Button v-if="tenant.can('purchasing.manage')" data-testid="new-po" @click="$router.push('/purchasing/new')"><Plus /> Tạo đơn nhập</Button>
    </PageHeader>
    <PurchasingNav />
    <Card>
        <div class="flex flex-wrap gap-2 border-b p-4">
            <div class="relative min-w-60 flex-1">
                <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="q" class="pl-8" placeholder="Mã đơn nhập, tên nhà cung cấp…" />
            </div>
            <Select v-model="status" class="w-48" :options="statusOptions" aria-label="Trạng thái" />
        </div>
        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 5" :key="i" class="h-10" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState v-else-if="!rows.length" :icon="ClipboardList" title="Chưa có đơn nhập" description="Tạo đơn nhập để đặt hàng nhà cung cấp, sau đó nhận hàng một hoặc nhiều đợt. Mỗi đợt nhận tạo phiếu nhập kho và cập nhật giá vốn." />
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="po-table">
                <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Đơn nhập</th>
                        <th class="px-4 py-2.5 font-medium">Nhà cung cấp</th>
                        <th class="px-4 py-2.5 font-medium">Trạng thái</th>
                        <th class="px-4 py-2.5 text-right font-medium">Đã nhận</th>
                        <th class="px-4 py-2.5 text-right font-medium">Giá trị đơn</th>
                        <th class="px-4 py-2.5 text-right font-medium">Còn nợ</th>
                        <th class="px-4 py-2.5 font-medium">Dự kiến về</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="po in rows" :key="po.id" class="cursor-pointer hover:bg-muted/30" @click="$router.push(`/purchasing/${po.id}`)">
                        <td class="px-4 py-3"><div class="font-mono text-xs font-medium">{{ po.number }}</div><div class="text-xs text-muted-foreground">{{ po.warehouse?.name }}</div></td>
                        <td class="px-4 py-3">{{ po.supplier?.name }}</td>
                        <td class="px-4 py-3"><Badge :tone="poStatus[po.status]?.tone">{{ poStatus[po.status]?.label }}</Badge></td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ formatNumber(po.received_quantity ?? 0) }}/{{ formatNumber(po.total_quantity ?? 0) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(po.total) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums" :class="owed(po) > 0 ? 'font-medium text-destructive' : 'text-muted-foreground'">{{ owed(po) > 0 ? formatMoney(owed(po)) : '—' }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ po.expected_at ? formatDate(po.expected_at) : '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((page = p), load())" />
    </Card>
</template>
