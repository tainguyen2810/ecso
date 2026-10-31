<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { ArrowRight, Search, Undo2 } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Pagination from '../../components/Pagination.vue';
import Tabs from '../../components/Tabs.vue';
import Badge from '@/shared/ui/Badge.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatMoney } from '@/shared/lib/utils';
import { marketplaceNames, returnStatus, returnTypes } from '../orders/labels';
import type { OrderReturnData } from '@/shared/types';

const rows = ref<OrderReturnData[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const loadError = ref<string | null>(null);
const status = ref('all');
const type = ref('');
const q = ref('');
const page = ref(1);

const tabs = computed(() => [{ value: 'all', label: 'Tất cả' }, ...['received', 'in_transit', 'requested', 'approved', 'inspected', 'closed', 'rejected'].map((s) => ({ value: s, label: returnStatus[s]!.label }))]);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get('/returns', { params: { status: status.value === 'all' ? undefined : status.value, type: type.value || undefined, q: q.value || undefined, page: page.value } });
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
watch([status, type], () => ((page.value = 1), load()));
</script>

<template>
    <PageHeader title="Trả hàng" description="Hàng hoàn từ khách, đơn hủy sau khi gửi, giao thất bại. Kho chỉ được cộng lại sau khi kiểm hàng, theo số lượng còn bán được." />
    <Card>
        <div class="space-y-3 border-b p-4">
            <Tabs v-model="status" :tabs="tabs" />
            <div class="flex flex-wrap gap-2">
                <div class="relative min-w-60 flex-1">
                    <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                    <Input v-model="q" class="pl-8" placeholder="Số phiếu, mã đơn…" />
                </div>
                <Select v-model="type" class="w-48" :options="[{ value: '', label: 'Mọi loại' }, ...Object.entries(returnTypes).map(([value, label]) => ({ value, label }))]" aria-label="Loại" />
            </div>
        </div>
        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 5" :key="i" class="h-10" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState v-else-if="!rows.length" :icon="Undo2" title="Chưa có phiếu trả hàng" description="Phiếu được tạo tự động khi đơn bị hủy sau khi gửi hoặc giao thất bại, hoặc tạo thủ công từ trang chi tiết đơn." />
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="return-table">
                <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Số phiếu</th>
                        <th class="px-4 py-2.5 font-medium">Đơn hàng</th>
                        <th class="px-4 py-2.5 font-medium">Loại</th>
                        <th class="px-4 py-2.5 text-right font-medium">Số dòng</th>
                        <th class="px-4 py-2.5 text-right font-medium">Hoàn tiền</th>
                        <th class="px-4 py-2.5 font-medium">Trạng thái</th>
                        <th class="px-4 py-2.5 font-medium">Ngày tạo</th>
                        <th class="px-4 py-2.5" />
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="r in rows" :key="r.id" class="cursor-pointer hover:bg-muted/30" @click="$router.push(`/returns/${r.id}`)">
                        <td class="px-4 py-3 font-mono text-xs font-medium">{{ r.number }}</td>
                        <td class="px-4 py-3"><span class="font-mono text-xs">{{ r.order?.external_order_id }}</span> <span class="text-xs text-muted-foreground">· {{ marketplaceNames[r.order?.marketplace ?? ''] }}</span></td>
                        <td class="px-4 py-3">{{ returnTypes[r.type] }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ r.lines_count }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(r.refund_amount) }}</td>
                        <td class="px-4 py-3"><Badge :tone="returnStatus[r.status]?.tone">{{ returnStatus[r.status]?.label }}</Badge></td>
                        <td class="px-4 py-3 text-muted-foreground">{{ formatDateTime(r.created_at) }}</td>
                        <td class="px-4 py-3"><ArrowRight class="ml-auto size-4 text-muted-foreground" /></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((page = p), load())" />
    </Card>
</template>
