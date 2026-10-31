<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, ClipboardCheck } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatMoney } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { orderStatus, returnStatus, returnTypes } from '../orders/labels';
import type { OrderReturnData, Warehouse } from '@/shared/types';

const route = useRoute();
const tenant = useTenantStore();
const toast = useToast();

const ret = ref<OrderReturnData | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const busy = ref<string | null>(null);
const warehouses = ref<Warehouse[]>([]);
const inspect = ref({ warehouse_id: '', note: '', lines: {} as Record<number, { received_qty: number; sellable_qty: number; damaged_qty: number }> });
const inspectError = ref<string | null>(null);

const moveLabels: Record<string, string> = { approved: 'Duyệt', rejected: 'Từ chối', in_transit: 'Đang hoàn về', received: 'Đã nhận hàng', closed: 'Đóng phiếu' };

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get(`/returns/${route.params.id}`);
        ret.value = data.data;
        inspect.value.lines = Object.fromEntries((ret.value!.lines ?? []).map((l) => [l.id, { received_qty: l.quantity, sellable_qty: l.quantity, damaged_qty: 0 }]));
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(async () => {
    await load();
    warehouses.value = (await api.get('/warehouses')).data.data.filter((w: Warehouse) => w.status === 'active');
    inspect.value.warehouse_id = warehouses.value.find((w) => w.is_default)?.id ?? warehouses.value[0]?.id ?? '';
});

const canInspect = computed(() => ret.value?.status === 'received' && tenant.can('returns.inspect'));
const lineInvalid = (id: number) => {
    const l = inspect.value.lines[id]!;
    return Number(l.sellable_qty) + Number(l.damaged_qty) !== Number(l.received_qty);
};

async function move(status: string) {
    busy.value = status;
    try {
        const { data } = await api.post(`/returns/${ret.value!.id}/status`, { status });
        ret.value = data.data;
        toast.success('Đã cập nhật phiếu.');
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}

async function submitInspect() {
    inspectError.value = null;
    busy.value = 'inspect';
    try {
        const { data } = await api.post(`/returns/${ret.value!.id}/inspect`, {
            warehouse_id: inspect.value.warehouse_id,
            note: inspect.value.note || null,
            lines: Object.entries(inspect.value.lines).map(([id, l]) => ({ line_id: Number(id), received_qty: Number(l.received_qty), sellable_qty: Number(l.sellable_qty), damaged_qty: Number(l.damaged_qty) })),
        });
        ret.value = data.data;
        toast.success('Đã kiểm hàng. Hàng còn bán được đã được nhập lại kho.');
    } catch (e) {
        const err = e as ApiError;
        inspectError.value = Object.values(err.body.errors ?? {})[0]?.[0] ?? err.message;
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div v-if="loading && !ret" class="space-y-4"><Skeleton class="h-10 w-80" /><Skeleton class="h-64" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <template v-else-if="ret">
        <RouterLink to="/returns" class="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft class="size-4" /> Trả hàng</RouterLink>
        <PageHeader :title="`Phiếu ${ret.number}`" :description="`${returnTypes[ret.type]} · tạo ${formatDateTime(ret.created_at)}${ret.created_by ? ' bởi ' + ret.created_by : ''}`">
            <Badge :tone="returnStatus[ret.status]?.tone" data-testid="return-status">{{ returnStatus[ret.status]?.label }}</Badge>
            <template v-if="tenant.can('returns.inspect')">
                <Button v-for="s in ret.next.filter((n) => n !== 'inspected')" :key="s" :variant="s === 'rejected' ? 'outline' : 'default'" :loading="busy === s" @click="move(s)">{{ moveLabels[s] }}</Button>
            </template>
        </PageHeader>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <Card v-if="ret.expects_goods" :title="canInspect ? 'Kiểm hàng hoàn' : 'Hàng hoàn'" :description="canInspect ? 'Nhập số lượng nhận được, bao nhiêu còn bán được và bao nhiêu hỏng. Chỉ phần còn bán được được cộng lại vào kho.' : undefined">
                    <table class="w-full text-sm" data-testid="return-lines">
                        <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2 font-medium">Sản phẩm</th>
                                <th class="px-4 py-2 text-right font-medium">Trả</th>
                                <th class="w-24 px-2 py-2 text-right font-medium">Nhận</th>
                                <th class="w-24 px-2 py-2 text-right font-medium">Bán được</th>
                                <th class="w-24 px-2 py-2 text-right font-medium">Hỏng</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="l in ret.lines" :key="l.id">
                                <td class="px-4 py-3">
                                    <div class="font-medium">{{ l.name }}</div>
                                    <div class="text-xs text-muted-foreground">{{ l.variant_name }} · <span class="font-mono">{{ l.sku ?? 'chưa map' }}</span></div>
                                    <div v-if="!l.mapped" class="text-xs text-destructive">Chưa liên kết SKU: không thể nhập lại kho dòng này.</div>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ l.quantity }}</td>
                                <template v-if="canInspect">
                                    <td class="px-2 py-2"><Input v-model.number="inspect.lines[l.id]!.received_qty" type="number" min="0" :max="l.quantity" class="text-right" /></td>
                                    <td class="px-2 py-2"><Input v-model.number="inspect.lines[l.id]!.sellable_qty" type="number" min="0" class="text-right" :invalid="lineInvalid(l.id)" /></td>
                                    <td class="px-2 py-2"><Input v-model.number="inspect.lines[l.id]!.damaged_qty" type="number" min="0" class="text-right" :invalid="lineInvalid(l.id)" /></td>
                                </template>
                                <template v-else>
                                    <td class="px-2 py-3 text-right tabular-nums">{{ l.received_qty }}</td>
                                    <td class="px-2 py-3 text-right tabular-nums text-success">{{ l.sellable_qty }}</td>
                                    <td class="px-2 py-3 text-right tabular-nums text-destructive">{{ l.damaged_qty }}</td>
                                </template>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="canInspect" class="grid gap-3 border-t p-4 sm:grid-cols-2">
                        <FormField label="Nhập lại vào kho">
                            <Select v-model="inspect.warehouse_id" :options="warehouses.map((w) => ({ value: w.id!, label: `${w.code} · ${w.name}` }))" />
                        </FormField>
                        <FormField label="Ghi chú kiểm hàng"><Input v-model="inspect.note" /></FormField>
                        <p class="text-xs text-muted-foreground sm:col-span-2">Bán được + Hỏng phải bằng Nhận. Hàng hỏng không cộng kho.</p>
                        <p v-if="inspectError" class="text-sm text-destructive sm:col-span-2">{{ inspectError }}</p>
                        <div class="sm:col-span-2">
                            <Button :loading="busy === 'inspect'" :disabled="Object.keys(inspect.lines).some((id) => lineInvalid(Number(id)))" data-testid="submit-inspect" @click="submitInspect"><ClipboardCheck /> Xác nhận kiểm hàng</Button>
                        </div>
                    </div>
                </Card>
                <Card v-else title="Chỉ hoàn tiền">
                    <p class="px-5 py-4 text-sm text-muted-foreground">Khách không gửi trả hàng: phiếu này không làm thay đổi tồn kho.</p>
                </Card>
            </div>
            <Card title="Thông tin">
                <dl class="space-y-2 px-5 py-4 text-sm">
                    <div><dt class="text-xs text-muted-foreground">Đơn hàng</dt><dd><RouterLink :to="`/orders/${ret.order?.id}`" class="font-mono text-xs hover:underline">{{ ret.order?.external_order_id }}</RouterLink> <Badge class="ml-1" :tone="orderStatus[ret.order?.status ?? '']?.tone">{{ orderStatus[ret.order?.status ?? '']?.label }}</Badge></dd></div>
                    <div><dt class="text-xs text-muted-foreground">Lý do</dt><dd>{{ ret.reason ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-muted-foreground">Hoàn tiền</dt><dd>{{ formatMoney(ret.refund_amount) }}</dd></div>
                    <div v-if="ret.warehouse"><dt class="text-xs text-muted-foreground">Kho nhập lại</dt><dd>{{ ret.warehouse.code }} · {{ ret.warehouse.name }}</dd></div>
                    <div><dt class="text-xs text-muted-foreground">Nhận hàng</dt><dd>{{ formatDateTime(ret.received_at) }}</dd></div>
                    <div><dt class="text-xs text-muted-foreground">Kiểm hàng</dt><dd>{{ formatDateTime(ret.inspected_at) }}<span v-if="ret.inspected_by"> · {{ ret.inspected_by }}</span></dd></div>
                    <div><dt class="text-xs text-muted-foreground">Đóng phiếu</dt><dd>{{ formatDateTime(ret.closed_at) }}</dd></div>
                    <div v-if="ret.note"><dt class="text-xs text-muted-foreground">Ghi chú</dt><dd class="whitespace-pre-line">{{ ret.note }}</dd></div>
                </dl>
            </Card>
        </div>
    </template>
</template>
