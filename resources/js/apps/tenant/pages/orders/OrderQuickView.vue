<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { ExternalLink, Pencil, ReceiptText } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatMoney } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { printInvoice } from './invoice';
import { marketplaceNames, paymentStatus } from './labels';
import type { OrderDetail } from '@/shared/types';

/** Order opened in place under its row in the list: lines, buyer, notes and the usual next steps. */
const props = defineProps<{ id: string }>();
const tenant = useTenantStore();

const order = ref<OrderDetail | null>(null);
const error = ref<string | null>(null);

onMounted(async () => {
    try {
        const { data } = await api.get(`/orders/${props.id}`);
        order.value = data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    }
});
</script>

<template>
    <div class="bg-muted/20 px-4 py-4" data-testid="order-quick-view">
        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
        <div v-else-if="!order" class="space-y-2"><Skeleton class="h-20" /><Skeleton class="h-24" /></div>
        <div v-else class="space-y-3">
            <div class="grid gap-3 rounded-md border bg-card p-4 text-sm md:grid-cols-[1fr_1fr_1fr_auto]">
                <dl class="space-y-1">
                    <div class="flex gap-2"><dt class="w-24 shrink-0 text-muted-foreground">Mã đơn</dt><dd class="font-mono text-xs">{{ order.external_order_id }}</dd></div>
                    <div class="flex gap-2"><dt class="w-24 shrink-0 text-muted-foreground">Ngày đặt</dt><dd>{{ formatDateTime(order.placed_at) }}</dd></div>
                    <div class="flex gap-2"><dt class="w-24 shrink-0 text-muted-foreground">Nguồn</dt><dd>{{ order.channel?.name ?? order.shop?.name ?? marketplaceNames[order.marketplace] }}</dd></div>
                    <div class="flex gap-2"><dt class="w-24 shrink-0 text-muted-foreground">Người tạo</dt><dd>{{ order.created_by ?? '—' }}</dd></div>
                </dl>
                <div class="border-t pt-3 md:border-l md:border-t-0 md:pl-4 md:pt-0">
                    <div class="mb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">Khách hàng</div>
                    <RouterLink v-if="order.customer" :to="`/customers/${order.customer.id}`" class="font-medium text-primary hover:underline">{{ order.customer.name }}</RouterLink>
                    <div>{{ order.buyer_name ?? (order.customer ? '' : 'Khách lẻ') }} <span class="text-muted-foreground">{{ order.buyer_phone }}</span></div>
                    <div class="text-muted-foreground">{{ order.shipping_address }}</div>
                </div>
                <div class="border-t pt-3 md:border-l md:border-t-0 md:pl-4 md:pt-0">
                    <div class="mb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">Ghi chú</div>
                    <p v-if="order.buyer_note" class="italic">{{ order.buyer_note }}</p>
                    <p v-for="n in order.notes.slice(-2)" :key="n.id" class="text-xs">{{ n.body }} <span class="text-muted-foreground">· {{ n.user }}</span></p>
                    <p v-if="!order.buyer_note && !order.notes.length" class="text-muted-foreground">Chưa có ghi chú</p>
                </div>
                <div class="flex flex-row flex-wrap gap-2 md:flex-col">
                    <RouterLink :to="`/orders/${order.id}`"><Button size="sm" class="w-full"><ExternalLink /> Xem chi tiết</Button></RouterLink>
                    <RouterLink v-if="order.editable && tenant.can('orders.create')" :to="`/orders/${order.id}/edit`"><Button size="sm" variant="outline" class="w-full"><Pencil /> Sửa đơn</Button></RouterLink>
                    <Button v-if="order.source === 'manual'" size="sm" variant="outline" @click="printInvoice(order, tenant.current?.name ?? '')"><ReceiptText /> In hóa đơn</Button>
                </div>
            </div>

            <div class="overflow-x-auto rounded-md border bg-card">
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/40 text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">SKU</th>
                            <th class="px-3 py-2 font-medium">Sản phẩm</th>
                            <th class="px-3 py-2 text-right font-medium">SL</th>
                            <th class="px-3 py-2 text-right font-medium">Đơn giá</th>
                            <th class="px-3 py-2 text-right font-medium">Giảm</th>
                            <th class="px-3 py-2 text-right font-medium">Thành tiền</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="i in order.items" :key="i.id">
                            <td class="px-3 py-2 font-mono text-xs">{{ i.variant?.sku ?? i.seller_sku ?? '—' }}</td>
                            <td class="px-3 py-2">{{ i.name }}<span v-if="i.variant_name" class="text-muted-foreground"> · {{ i.variant_name }}</span></td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ i.quantity }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ formatMoney(i.unit_price) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ Number(i.discount) > 0 ? formatMoney(i.discount) : '—' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ formatMoney(i.quantity * Number(i.unit_price) - Number(i.discount)) }}</td>
                        </tr>
                    </tbody>
                </table>
                <dl class="ml-auto grid grid-cols-2 gap-x-6 gap-y-0.5 border-t px-4 py-2 text-sm sm:w-80">
                    <dt class="text-muted-foreground">Tiền hàng</dt><dd class="text-right tabular-nums">{{ formatMoney(order.subtotal) }}</dd>
                    <dt class="text-muted-foreground">Phí giao</dt><dd class="text-right tabular-nums">{{ formatMoney(order.shipping_fee) }}</dd>
                    <dt class="text-muted-foreground">Giảm giá</dt><dd class="text-right tabular-nums">−{{ formatMoney(order.discount) }}</dd>
                    <dt class="font-medium">Khách phải trả</dt><dd class="text-right font-semibold tabular-nums">{{ formatMoney(order.total) }}</dd>
                    <template v-if="order.source === 'manual' && order.payment_status">
                        <dt class="text-muted-foreground">Thanh toán</dt>
                        <dd class="text-right"><Badge :tone="paymentStatus[order.payment_status]?.tone">{{ paymentStatus[order.payment_status]?.label }}</Badge></dd>
                    </template>
                </dl>
            </div>
        </div>
    </div>
</template>
