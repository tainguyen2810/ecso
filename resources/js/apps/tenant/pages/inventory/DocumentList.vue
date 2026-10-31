<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowRight, FileText, Plus } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import InventoryNav from './InventoryNav.vue';
import Pagination from '../../components/Pagination.vue';
import Tabs from '../../components/Tabs.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { adjustmentReasons, documentKinds, documentStatus, type DocumentKindKey } from './labels';
import type { InventoryDocument } from '@/shared/types';

const route = useRoute();
const tenant = useTenantStore();
const kindKey = computed(() => route.meta.kind as DocumentKindKey);
const kind = computed(() => documentKinds[kindKey.value]);

const docs = ref<InventoryDocument[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const loadError = ref<string | null>(null);
const status = ref('all');
const page = ref(1);

const statusTabs = computed(() => {
    if (kindKey.value === 'adjustments') return ['all', 'pending', 'approved', 'rejected', 'cancelled'];
    if (kindKey.value === 'transfers') return ['all', 'draft', 'in_transit', 'received', 'cancelled'];
    return [];
});

const descriptions: Record<DocumentKindKey, string> = {
    receipts: 'Nhập hàng vào kho. Giá vốn bình quân được cập nhật tự động theo từng phiếu.',
    adjustments: 'Điều chỉnh theo kiểm kê, hàng hỏng, thất lạc. Chỉ thay đổi tồn sau khi được duyệt.',
    transfers: 'Chuyển hàng giữa các kho: xuất khỏi kho nguồn khi gửi, cộng vào kho đích khi nhận.',
};

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get(`/inventory/${kindKey.value}`, { params: { status: status.value === 'all' ? undefined : status.value, page: page.value } });
        docs.value = data.data;
        meta.value = data.meta;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
watch(status, () => ((page.value = 1), load()));
watch(kindKey, () => ((status.value = 'all'), (page.value = 1), load()));
</script>

<template>
    <PageHeader title="Kho hàng" description="Chứng từ kho: mọi thay đổi tồn đều có phiếu và được ghi vào sổ kho.">
        <RouterLink v-if="tenant.can(kind.permission)" :to="`/inventory/${kindKey}/new`"><Button data-testid="new-document"><Plus /> {{ kind.create }}</Button></RouterLink>
    </PageHeader>
    <InventoryNav />

    <Card :title="kind.title" :description="descriptions[kindKey]">
        <template v-if="statusTabs.length" #actions>
            <Tabs v-model="status" :tabs="statusTabs.map((s) => ({ value: s, label: s === 'all' ? 'Tất cả' : documentStatus[s]!.label }))" />
        </template>

        <div v-if="loading && !docs.length" class="space-y-3 p-4"><Skeleton v-for="i in 5" :key="i" class="h-10" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState v-else-if="!docs.length" :icon="FileText" :title="`Chưa có ${kind.title.toLowerCase()}`" :description="descriptions[kindKey]">
            <RouterLink v-if="tenant.can(kind.permission)" :to="`/inventory/${kindKey}/new`"><Button><Plus /> {{ kind.create }}</Button></RouterLink>
        </EmptyState>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="document-table">
                <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Số phiếu</th>
                        <th class="px-4 py-2.5 font-medium">{{ kindKey === 'transfers' ? 'Từ kho → Đến kho' : 'Kho' }}</th>
                        <th v-if="kindKey === 'adjustments'" class="px-4 py-2.5 font-medium">Lý do</th>
                        <th class="px-4 py-2.5 text-right font-medium">Số dòng</th>
                        <th v-if="kindKey === 'receipts'" class="px-4 py-2.5 text-right font-medium">Số lượng</th>
                        <th v-if="kindKey === 'receipts'" class="px-4 py-2.5 text-right font-medium">Giá trị</th>
                        <th v-if="kindKey !== 'receipts'" class="px-4 py-2.5 font-medium">Trạng thái</th>
                        <th class="px-4 py-2.5 font-medium">Người tạo</th>
                        <th class="px-4 py-2.5 font-medium">Ngày tạo</th>
                        <th class="px-4 py-2.5" />
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="d in docs" :key="d.id" class="cursor-pointer hover:bg-muted/30" @click="$router.push(`/inventory/${kindKey}/${d.id}`)">
                        <td class="px-4 py-3 font-mono text-xs font-medium">{{ d.number }}</td>
                        <td class="px-4 py-3">
                            <template v-if="d.kind === 'transfer'">{{ d.from_warehouse?.code }} → {{ d.to_warehouse?.code }}</template>
                            <template v-else>{{ d.warehouse?.code }} · {{ d.warehouse?.name }}</template>
                        </td>
                        <td v-if="kindKey === 'adjustments'" class="px-4 py-3">{{ adjustmentReasons[d.reason ?? ''] ?? d.reason }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ d.lines_count }}</td>
                        <td v-if="kindKey === 'receipts'" class="px-4 py-3 text-right tabular-nums">{{ formatNumber(d.total_quantity) }}</td>
                        <td v-if="kindKey === 'receipts'" class="px-4 py-3 text-right tabular-nums">{{ formatMoney(d.total_value) }}</td>
                        <td v-if="kindKey !== 'receipts'" class="px-4 py-3"><Badge :tone="documentStatus[d.status!]?.tone">{{ documentStatus[d.status!]?.label }}</Badge></td>
                        <td class="px-4 py-3 text-muted-foreground">{{ d.created_by }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ formatDateTime(d.created_at) }}</td>
                        <td class="px-4 py-3 text-right"><ArrowRight class="ml-auto size-4 text-muted-foreground" /></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((page = p), load())" />
    </Card>
</template>
