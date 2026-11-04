<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { CircleCheck, PackageX, RefreshCw, ShieldAlert } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Alert from '@/shared/ui/Alert.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatNumber } from '@/shared/lib/utils';

/**
 * "Đối soát tồn kho": the ledger check (tồn = tổng sổ kho, số đang giữ = các đơn đang giữ) and orders handed over
 * while stock was short. Read-only; corrections go through a stocktake or an adjustment.
 */
interface Named { id: string; sku?: string; code?: string; name: string }
interface LedgerRow { warehouse: Named | null; variant: Named | null; problem: string; label: string; expected: number; actual: number }
interface ShortRow { order: { id: string; number: string; marketplace: string }; warehouse: Named | null; variant: Named | null; quantity: number; shortage: number; shipped_at: string }

const data = ref<{ checked_at: string; ledger: LedgerRow[]; shortages: ShortRow[] } | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        data.value = (await api.get('/inventory/reconciliation')).data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
</script>

<template>
    <PageHeader title="Đối soát tồn kho" description="Kiểm tra tồn kho khớp với sổ kho, và các đơn đã bàn giao khi kho không đủ hàng.">
        <Button variant="outline" :loading="loading" @click="load"><RefreshCw /> Kiểm tra lại</Button>
    </PageHeader>

    <ErrorState v-if="loadError" :message="loadError" @retry="load" />
    <div v-else-if="loading && !data" class="space-y-3"><Skeleton v-for="i in 3" :key="i" class="h-16" /></div>
    <template v-else-if="data">
        <p class="mb-4 text-xs text-muted-foreground">Kiểm tra lúc {{ formatDateTime(data.checked_at) }}. ECSO cũng tự kiểm tra mỗi đêm lúc 02:30.</p>

        <Card title="Sổ kho" description="Tồn từng kho phải bằng tổng các dòng nhập, xuất trong sổ kho; số đang giữ phải bằng tổng các đơn đang giữ hàng." class="mb-5">
            <div v-if="!data.ledger.length" class="flex items-center gap-2 p-5 text-sm text-success" data-testid="ledger-ok"><CircleCheck class="size-4" /> Tồn kho khớp với sổ kho ở mọi kho.</div>
            <template v-else>
                <Alert tone="red" :icon="ShieldAlert" class="m-4" data-testid="ledger-problems">
                    Có {{ data.ledger.length }} chỗ không khớp. ECSO không tự sửa sổ kho; hãy kiểm hàng SKU đó hoặc liên hệ hỗ trợ.
                </Alert>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-y bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2.5 font-medium">Kho</th>
                                <th class="px-4 py-2.5 font-medium">SKU</th>
                                <th class="px-4 py-2.5 font-medium">Vấn đề</th>
                                <th class="px-4 py-2.5 text-right font-medium">Theo sổ kho</th>
                                <th class="px-4 py-2.5 text-right font-medium">Đang ghi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="(r, i) in data.ledger" :key="i">
                                <td class="px-4 py-2.5">{{ r.warehouse ? `${r.warehouse.code} · ${r.warehouse.name}` : '—' }}</td>
                                <td class="px-4 py-2.5">
                                    <RouterLink v-if="r.variant" :to="`/inventory/sku/${r.variant.id}`" class="font-mono text-xs font-medium text-primary hover:underline">{{ r.variant.sku }}</RouterLink>
                                    <p class="text-xs text-muted-foreground">{{ r.variant?.name }}</p>
                                </td>
                                <td class="px-4 py-2.5">{{ r.label }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ formatNumber(r.expected) }}</td>
                                <td class="px-4 py-2.5 text-right font-medium tabular-nums text-destructive">{{ formatNumber(r.actual) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </Card>

        <Card title="Đơn xuất thiếu hàng" description="Đơn đã bàn giao khi kho không đủ: phần thiếu chưa trừ khỏi tồn. ECSO tự trừ bù khi có hàng nhập về; nếu hàng thực ra đã đi, hãy kiểm hàng lại SKU đó.">
            <EmptyState v-if="!data.shortages.length" :icon="PackageX" title="Không có đơn xuất thiếu" description="90 ngày qua không có đơn nào bàn giao khi kho thiếu hàng." />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="shortage-table">
                    <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Đơn</th>
                            <th class="px-4 py-2.5 font-medium">SKU</th>
                            <th class="px-4 py-2.5 font-medium">Kho</th>
                            <th class="px-4 py-2.5 text-right font-medium">Số lượng đơn</th>
                            <th class="px-4 py-2.5 text-right font-medium">Thiếu</th>
                            <th class="px-4 py-2.5 font-medium">Bàn giao</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(r, i) in data.shortages" :key="i">
                            <td class="px-4 py-2.5"><RouterLink :to="`/orders/${r.order.id}`" class="font-medium text-primary hover:underline">{{ r.order.number }}</RouterLink></td>
                            <td class="px-4 py-2.5">
                                <RouterLink v-if="r.variant" :to="`/inventory/sku/${r.variant.id}`" class="font-mono text-xs font-medium hover:underline">{{ r.variant.sku }}</RouterLink>
                                <p class="text-xs text-muted-foreground">{{ r.variant?.name }}</p>
                            </td>
                            <td class="px-4 py-2.5">{{ r.warehouse?.code }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ formatNumber(r.quantity) }}</td>
                            <td class="px-4 py-2.5 text-right font-medium tabular-nums text-amber-600">{{ formatNumber(r.shortage) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-muted-foreground">{{ formatDateTime(r.shipped_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </template>
</template>
