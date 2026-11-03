<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Undo2 } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Pagination from '../../components/Pagination.vue';
import PurchasingNav from './PurchasingNav.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatMoney } from '@/shared/lib/utils';
import type { SupplierReturnData } from '@/shared/types';

const rows = ref<SupplierReturnData[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const loadError = ref<string | null>(null);
const page = ref(1);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get('/supplier-returns', { params: { page: page.value } });
        rows.value = data.data;
        meta.value = data.meta;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
</script>

<template>
    <PageHeader title="Mua hàng" description="Nhà cung cấp, đơn nhập hàng, nhận hàng nhiều đợt, trả hàng và công nợ nhà cung cấp." />
    <PurchasingNav />
    <Card>
        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 4" :key="i" class="h-10" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState v-else-if="!rows.length" :icon="Undo2" title="Chưa trả hàng nhà cung cấp" description="Mở một đơn nhập đã nhận hàng và bấm Trả hàng để xuất trả hàng lỗi. Công nợ NCC giảm tương ứng." />
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <tr><th class="px-4 py-2.5 font-medium">Phiếu</th><th class="px-4 py-2.5 font-medium">Nhà cung cấp</th><th class="px-4 py-2.5 font-medium">Đơn nhập</th><th class="px-4 py-2.5 font-medium">Lý do</th><th class="px-4 py-2.5 font-medium">Ngày</th><th class="px-4 py-2.5 text-right font-medium">Giá trị</th></tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="r in rows" :key="r.id" :class="r.order && 'cursor-pointer hover:bg-muted/30'" @click="r.order && $router.push(`/purchasing/${r.order.id}`)">
                        <td class="px-4 py-3 font-mono text-xs font-medium">{{ r.number }}</td>
                        <td class="px-4 py-3">{{ r.supplier?.name }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ r.order?.number ?? '—' }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ r.reason ?? '—' }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ formatDateTime(r.created_at) }}<div class="text-xs">{{ r.created_by }}</div></td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(r.total) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((page = p), load())" />
    </Card>
</template>
