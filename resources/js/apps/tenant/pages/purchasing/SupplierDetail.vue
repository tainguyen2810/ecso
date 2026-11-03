<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Pencil, Plus, Trash2, Wallet } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import SupplierDialog from './SupplierDialog.vue';
import SupplierPaymentDialog from './SupplierPaymentDialog.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { owed, poStatus, supplierPaymentMethods } from './labels';
import type { PurchaseOrderData, Supplier, SupplierPaymentData, SupplierReturnData } from '@/shared/types';

const route = useRoute();
const router = useRouter();
const toast = useToast();
const tenant = useTenantStore();
const supplier = ref<(Supplier & { orders: PurchaseOrderData[]; payments: SupplierPaymentData[]; returns: SupplierReturnData[] }) | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const dialog = ref(false);
const payOpen = ref(false);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        supplier.value = (await api.get(`/suppliers/${route.params.id}`)).data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

async function remove() {
    try {
        await api.delete(`/suppliers/${supplier.value!.id}`);
        toast.success('Đã xóa nhà cung cấp.');
        await router.push('/purchasing/suppliers');
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}
</script>

<template>
    <div v-if="loading && !supplier" class="space-y-4"><Skeleton class="h-10 w-80" /><Skeleton class="h-64" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <template v-else-if="supplier">
        <RouterLink to="/purchasing/suppliers" class="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft class="size-4" /> Nhà cung cấp</RouterLink>
        <PageHeader :title="supplier.name" :description="[supplier.code, supplier.contact_name, supplier.phone, supplier.tax_code && 'MST ' + supplier.tax_code].filter(Boolean).join(' · ')">
            <Badge v-if="!supplier.is_active" tone="muted" class="self-center">Ngừng giao dịch</Badge>
            <Button v-if="tenant.can('purchasing.manage') && supplier.is_active" @click="$router.push({ path: '/purchasing/new', query: { supplier: supplier.id } })"><Plus /> Tạo đơn nhập</Button>
            <Button v-if="tenant.can('purchasing.payment')" variant="outline" data-testid="supplier-pay" @click="payOpen = true"><Wallet /> Chi tiền</Button>
            <Button v-if="tenant.can('purchasing.manage')" variant="outline" @click="dialog = true"><Pencil /> Sửa</Button>
            <Button v-if="tenant.can('purchasing.manage') && !supplier.orders.length && !supplier.payments.length" variant="ghost" title="Xóa" @click="remove"><Trash2 /></Button>
        </PageHeader>

        <div class="mb-4 grid gap-4 sm:grid-cols-3">
            <Card><div class="p-4"><p class="text-xs text-muted-foreground">Đã nhập (trừ hàng trả)</p><p class="mt-1 text-2xl font-semibold tabular-nums">{{ formatMoney(supplier.purchased) }}</p></div></Card>
            <Card><div class="p-4"><p class="text-xs text-muted-foreground">Đã thanh toán</p><p class="mt-1 text-2xl font-semibold tabular-nums">{{ formatMoney(supplier.paid) }}</p></div></Card>
            <Card><div class="p-4"><p class="text-xs text-muted-foreground">{{ Number(supplier.debt) < 0 ? 'Đã trả trước' : 'Còn nợ' }}</p><p class="mt-1 text-2xl font-semibold tabular-nums" :class="Number(supplier.debt) > 0 && 'text-destructive'" data-testid="supplier-debt">{{ formatMoney(Math.abs(Number(supplier.debt))) }}</p></div></Card>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card title="Đơn nhập" class="lg:col-span-2">
                <table v-if="supplier.orders.length" class="w-full text-sm">
                    <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr><th class="px-4 py-2 font-medium">Đơn nhập</th><th class="px-4 py-2 font-medium">Ngày tạo</th><th class="px-4 py-2 font-medium">Trạng thái</th><th class="px-4 py-2 text-right font-medium">Giá trị</th><th class="px-4 py-2 text-right font-medium">Còn nợ</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="o in supplier.orders" :key="o.id" class="cursor-pointer hover:bg-muted/30" @click="$router.push(`/purchasing/${o.id}`)">
                            <td class="px-4 py-2.5 font-mono text-xs font-medium">{{ o.number }}</td>
                            <td class="px-4 py-2.5 text-muted-foreground">{{ formatDateTime(o.created_at) }}</td>
                            <td class="px-4 py-2.5"><Badge :tone="poStatus[o.status]?.tone">{{ poStatus[o.status]?.label }}</Badge></td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ formatMoney(o.total) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums" :class="owed(o) > 0 && 'text-destructive'">{{ owed(o) > 0 ? formatMoney(owed(o)) : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="px-5 py-4 text-sm text-muted-foreground">Chưa có đơn nhập.</p>
            </Card>
            <div class="space-y-4">
                <Card title="Phiếu chi">
                    <ul v-if="supplier.payments.length" class="divide-y text-sm">
                        <li v-for="p in supplier.payments" :key="p.id" class="flex items-center justify-between px-5 py-2.5" :class="p.voided_at && 'opacity-50 line-through'">
                            <div><div>{{ supplierPaymentMethods[p.method] }}<span v-if="p.reference" class="text-muted-foreground"> · {{ p.reference }}</span></div><div class="text-xs text-muted-foreground">{{ p.order?.number ?? 'Thanh toán chung' }} · {{ formatDateTime(p.paid_at) }}</div></div>
                            <span class="tabular-nums">{{ formatMoney(p.amount) }}</span>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-4 text-sm text-muted-foreground">Chưa có.</p>
                </Card>
                <Card title="Trả hàng">
                    <ul v-if="supplier.returns.length" class="divide-y text-sm">
                        <li v-for="r in supplier.returns" :key="r.id" class="flex items-center justify-between px-5 py-2.5">
                            <div><span class="font-mono text-xs font-medium">{{ r.number }}</span><div class="text-xs text-muted-foreground">{{ r.order?.number }} · {{ formatDateTime(r.created_at) }}</div></div>
                            <span class="tabular-nums text-destructive">−{{ formatMoney(r.total) }}</span>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-4 text-sm text-muted-foreground">Chưa có.</p>
                </Card>
                <Card title="Thông tin">
                    <dl class="space-y-2 px-5 py-4 text-sm">
                        <div><dt class="text-xs text-muted-foreground">Email</dt><dd>{{ supplier.email ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Địa chỉ</dt><dd>{{ supplier.address ?? '—' }}</dd></div>
                        <div v-if="supplier.note"><dt class="text-xs text-muted-foreground">Ghi chú</dt><dd>{{ supplier.note }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Số đơn</dt><dd>{{ formatNumber(supplier.orders_count) }}</dd></div>
                    </dl>
                </Card>
            </div>
        </div>
        <SupplierDialog v-model:open="dialog" :supplier="supplier" @saved="load" />
        <SupplierPaymentDialog v-model:open="payOpen" :supplier-id="supplier.id" :suggested="Number(supplier.debt)" @saved="load" />
    </template>
</template>
