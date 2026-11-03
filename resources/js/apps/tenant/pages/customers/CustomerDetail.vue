<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import CustomerDialog from './CustomerDialog.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { orderStatus, paymentMethods, paymentStatus } from '../orders/labels';
import type { Customer, OrderPaymentData, OrderSummary } from '@/shared/types';

const route = useRoute();
const router = useRouter();
const toast = useToast();
const tenant = useTenantStore();
const customer = ref<(Customer & { orders: OrderSummary[]; payments: OrderPaymentData[] }) | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const dialog = ref(false);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get(`/customers/${route.params.id}`);
        customer.value = data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

async function remove() {
    try {
        await api.delete(`/customers/${customer.value!.id}`);
        toast.success('Đã xóa khách hàng.');
        await router.push('/customers');
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}
</script>

<template>
    <div v-if="loading && !customer" class="space-y-4"><Skeleton class="h-10 w-80" /><Skeleton class="h-64" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <template v-else-if="customer">
        <RouterLink to="/customers" class="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft class="size-4" /> Khách hàng</RouterLink>
        <PageHeader :title="customer.name" :description="`${customer.code}${customer.phone ? ' · ' + customer.phone : ''}${customer.group ? ' · nhóm ' + customer.group : ''}`">
            <Button v-if="tenant.can('orders.create')" @click="$router.push({ path: '/orders/new', query: { customer: customer.id } })"><Plus /> Tạo đơn</Button>
            <Button v-if="tenant.can('customers.manage')" variant="outline" @click="dialog = true"><Pencil /> Sửa</Button>
            <Button v-if="tenant.can('customers.manage') && !customer.orders_count" variant="ghost" title="Xóa" @click="remove"><Trash2 /></Button>
        </PageHeader>

        <div class="mb-4 grid gap-4 sm:grid-cols-3">
            <Card><div class="p-4"><p class="text-xs text-muted-foreground">Số đơn</p><p class="mt-1 text-2xl font-semibold tabular-nums">{{ formatNumber(customer.orders_count) }}</p></div></Card>
            <Card><div class="p-4"><p class="text-xs text-muted-foreground">Tổng mua (không tính đơn hủy/hoàn)</p><p class="mt-1 text-2xl font-semibold tabular-nums">{{ formatMoney(customer.total_spent) }}</p></div></Card>
            <Card><div class="p-4"><p class="text-xs text-muted-foreground">Còn nợ</p><p class="mt-1 text-2xl font-semibold tabular-nums" :class="Number(customer.debt) > 0 && 'text-destructive'" data-testid="customer-debt">{{ formatMoney(customer.debt) }}</p></div></Card>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card title="Đơn hàng gần đây" class="lg:col-span-2">
                <table v-if="customer.orders.length" class="w-full text-sm">
                    <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr><th class="px-4 py-2 font-medium">Mã đơn</th><th class="px-4 py-2 font-medium">Ngày</th><th class="px-4 py-2 font-medium">Trạng thái</th><th class="px-4 py-2 font-medium">Thanh toán</th><th class="px-4 py-2 text-right font-medium">Tổng</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="o in customer.orders" :key="o.id" class="cursor-pointer hover:bg-muted/30" @click="$router.push(`/orders/${o.id}`)">
                            <td class="px-4 py-2.5 font-mono text-xs font-medium">{{ o.external_order_id }}<div class="font-sans text-muted-foreground">{{ o.channel?.name }}</div></td>
                            <td class="px-4 py-2.5 text-muted-foreground">{{ formatDateTime(o.placed_at) }}</td>
                            <td class="px-4 py-2.5"><Badge :tone="orderStatus[o.status]?.tone">{{ orderStatus[o.status]?.label }}</Badge></td>
                            <td class="px-4 py-2.5"><Badge v-if="o.payment_status" :tone="paymentStatus[o.payment_status]?.tone">{{ paymentStatus[o.payment_status]?.label }}</Badge></td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ formatMoney(o.total) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="px-5 py-4 text-sm text-muted-foreground">Chưa có đơn.</p>
            </Card>
            <div class="space-y-4">
                <Card title="Thông tin">
                    <dl class="space-y-2 px-5 py-4 text-sm">
                        <div><dt class="text-xs text-muted-foreground">Điện thoại</dt><dd>{{ customer.phone ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Email</dt><dd>{{ customer.email ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Địa chỉ</dt><dd>{{ customer.address ?? '—' }}</dd></div>
                        <div v-if="customer.note"><dt class="text-xs text-muted-foreground">Ghi chú</dt><dd>{{ customer.note }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Ngày thêm</dt><dd>{{ formatDateTime(customer.created_at) }}</dd></div>
                    </dl>
                </Card>
                <Card title="Thu / hoàn tiền gần đây">
                    <ul v-if="customer.payments.length" class="divide-y text-sm">
                        <li v-for="p in customer.payments" :key="p.id" class="flex items-center justify-between px-5 py-2.5" :class="p.voided_at && 'opacity-50 line-through'">
                            <div><div>{{ p.kind === 'refund' ? 'Hoàn' : 'Thu' }} · {{ paymentMethods[p.method] }}</div><div class="text-xs text-muted-foreground">{{ p.order?.number }} · {{ formatDateTime(p.paid_at) }}</div></div>
                            <span class="tabular-nums" :class="p.kind === 'refund' ? 'text-destructive' : 'text-success'">{{ p.kind === 'refund' ? '−' : '+' }}{{ formatMoney(p.amount) }}</span>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-4 text-sm text-muted-foreground">Chưa có.</p>
                </Card>
            </div>
        </div>
        <CustomerDialog v-model:open="dialog" :customer="customer" @saved="load" />
    </template>
</template>
