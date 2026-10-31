<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { AlertTriangle, ArrowLeft, Link2, Printer, RefreshCw, Undo2 } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatMoney } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { actionLabels, capabilityLabels, deadlineLabel, historySource, mappingStatus, marketplaceNames, orderStatus, returnStatus, returnTypes } from './labels';
import { downloadPackingSlips } from './packingSlip';
import type { OrderDetail, Ref } from '@/shared/types';

const route = useRoute();
const tenant = useTenantStore();
const toast = useToast();

const order = ref<OrderDetail | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const busy = ref<string | null>(null);
const assignees = ref<Ref[]>([]);
const note = ref('');

const cancelOpen = ref(false);
const cancelReason = ref('');
const returnOpen = ref(false);
const returnForm = ref({ type: 'return', reason: '', refund_amount: '', lines: {} as Record<number, number> });
const returnErrors = ref<ApiError | null>(null);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get(`/orders/${route.params.id}`);
        order.value = data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(async () => {
    await load();
    api.get('/orders/assignees').then(({ data }) => (assignees.value = data.data)).catch(() => {});
});

const actionList = computed(() => Object.entries(order.value?.actions ?? {}).filter(([, a]) => a.allowed));
const totalQty = computed(() => order.value?.items.reduce((s, i) => s + i.quantity, 0) ?? 0);

async function act(action: string, params: Record<string, unknown> = {}) {
    busy.value = action;
    try {
        await api.post(`/orders/${order.value!.id}/actions/${action}`, params);
        toast.success(`${actionLabels[action]}: đã gửi lên sàn và cập nhật đơn.`);
        cancelOpen.value = false;
        await load();
    } catch (e) {
        const err = e as ApiError;
        toast.error(err.body.code === 'UNSUPPORTED_CAPABILITY' ? `Sàn chưa hỗ trợ thao tác này qua API (${err.message}). Hãy thực hiện trên trang quản lý của sàn.` : err.message);
    } finally {
        busy.value = null;
    }
}

async function sync() {
    busy.value = 'sync';
    try {
        const { data } = await api.post(`/orders/${order.value!.id}/sync`);
        toast.info(data.data.result === 'unchanged' ? 'Đơn đã khớp với sàn.' : 'Đã cập nhật đơn từ sàn.');
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}

async function assign(userId: string | undefined) {
    try {
        await api.put(`/orders/${order.value!.id}/assignee`, { user_id: userId || null });
        toast.success('Đã cập nhật người xử lý.');
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}

async function addNote() {
    if (!note.value.trim()) return;
    busy.value = 'note';
    try {
        const { data } = await api.post(`/orders/${order.value!.id}/notes`, { body: note.value });
        order.value!.notes.push(data.data);
        note.value = '';
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}

async function print() {
    busy.value = 'print';
    try {
        const { data } = await api.post('/orders/packing-slips', { ids: [order.value!.id] });
        await downloadPackingSlips(data.data);
        await load();
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Không tạo được file PDF.');
    } finally {
        busy.value = null;
    }
}

function openReturn() {
    returnErrors.value = null;
    returnForm.value = { type: 'return', reason: '', refund_amount: '', lines: Object.fromEntries(order.value!.items.map((i) => [i.id, i.quantity])) };
    returnOpen.value = true;
}

async function createReturn() {
    busy.value = 'return';
    returnErrors.value = null;
    const f = returnForm.value;
    try {
        await api.post(`/orders/${order.value!.id}/returns`, {
            type: f.type,
            reason: f.reason || null,
            refund_amount: f.refund_amount === '' ? null : Number(f.refund_amount),
            lines: f.type === 'return' ? Object.entries(f.lines).filter(([, q]) => Number(q) > 0).map(([id, q]) => ({ order_item_id: Number(id), quantity: Number(q) })) : [],
        });
        toast.success('Đã tạo phiếu trả hàng.');
        returnOpen.value = false;
        await load();
    } catch (e) {
        returnErrors.value = e as ApiError;
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div v-if="loading && !order" class="space-y-4"><Skeleton class="h-10 w-80" /><Skeleton class="h-64" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <template v-else-if="order">
        <RouterLink to="/orders" class="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft class="size-4" /> Đơn hàng</RouterLink>
        <PageHeader :title="`Đơn ${order.external_order_id}`" :description="`${marketplaceNames[order.marketplace] ?? order.marketplace} · ${order.shop?.name ?? ''} · đặt lúc ${formatDateTime(order.placed_at)}`">
            <Badge :tone="orderStatus[order.status]?.tone" data-testid="order-status">{{ orderStatus[order.status]?.label ?? order.status }}</Badge>
            <Button variant="outline" :loading="busy === 'sync'" @click="sync"><RefreshCw /> Đồng bộ từ sàn</Button>
            <Button v-if="tenant.can('orders.print')" variant="outline" :loading="busy === 'print'" @click="print"><Printer /> In phiếu</Button>
        </PageHeader>

        <div v-if="order.sync_warning" class="mb-4 flex items-start gap-2 rounded-md border border-warning/40 bg-warning/10 px-4 py-3 text-sm" data-testid="sync-warning">
            <AlertTriangle class="mt-0.5 size-4 shrink-0" /> {{ order.sync_warning }}
        </div>
        <div v-if="order.flags.shortage" class="mb-4 flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive">
            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
            <span v-if="['UNPAID', 'PENDING_CONFIRMATION', 'READY_TO_PACK', 'PACKED'].includes(order.status)">Đơn đang thiếu hàng: phần thiếu sẽ được giữ tự động khi có hàng nhập về (ưu tiên đơn cũ trước).</span>
            <span v-else>Sàn báo đã giao nhưng kho không đủ hàng cho một phần đơn: phần này chưa được trừ kho (không để âm kho). Hãy kiểm kê và lập phiếu điều chỉnh.</span>
        </div>
        <div v-if="order.flags.unmapped" class="mb-4 flex items-start gap-2 rounded-md border border-warning/40 bg-warning/10 px-4 py-3 text-sm">
            <Link2 class="mt-0.5 size-4 shrink-0" />
            <span>Có sản phẩm chưa liên kết với SKU nội bộ nên chưa giữ tồn. <RouterLink :to="`/marketplaces/${order.shop?.id}/listings?status=unmapped`" class="font-medium underline">Liên kết SKU</RouterLink> — đơn sẽ tự cập nhật sau khi map.</span>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <Card title="Sản phẩm" :description="`${order.items.length} dòng · ${totalQty} sản phẩm`">
                    <table class="w-full text-sm" data-testid="order-items">
                        <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2 font-medium">Sản phẩm trên sàn</th>
                                <th class="px-4 py-2 font-medium">SKU nội bộ</th>
                                <th class="px-4 py-2 text-right font-medium">SL</th>
                                <th class="px-4 py-2 text-right font-medium">Đơn giá</th>
                                <th class="px-4 py-2 font-medium">Tồn kho</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="i in order.items" :key="i.id">
                                <td class="px-4 py-3">
                                    <div class="font-medium">{{ i.name }}</div>
                                    <div class="text-xs text-muted-foreground">{{ i.variant_name }} · SKU sàn: {{ i.seller_sku ?? '—' }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <RouterLink v-if="i.variant" :to="`/inventory/sku/${i.variant.id}`" class="font-mono text-xs hover:underline">{{ i.variant.sku }}</RouterLink>
                                    <Badge v-else :tone="mappingStatus[i.mapping_status]?.tone">{{ mappingStatus[i.mapping_status]?.label }}</Badge>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ i.quantity }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(i.unit_price) }}</td>
                                <td class="px-4 py-3 text-xs">
                                    <template v-if="i.reservation">
                                        <template v-if="i.reservation.status === 'consumed'">
                                            <span class="text-muted-foreground">Đã xuất {{ i.reservation.quantity }}</span>
                                            <span v-if="i.reservation.shortage" class="ml-1 font-medium text-destructive">· {{ i.reservation.shortage }} giao khi kho không đủ</span>
                                        </template>
                                        <span v-else-if="i.reservation.status === 'released'" class="text-muted-foreground">Đã nhả giữ</span>
                                        <template v-else>
                                            <span class="text-success">Giữ {{ i.reservation.quantity }}</span>
                                            <span v-if="i.reservation.shortage" class="ml-1 font-medium text-destructive">· thiếu {{ i.reservation.shortage }}</span>
                                        </template>
                                    </template>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-1 border-t px-5 py-3 text-sm sm:w-80 sm:ml-auto">
                        <dt class="text-muted-foreground">Tiền hàng</dt><dd class="text-right tabular-nums">{{ formatMoney(order.subtotal) }}</dd>
                        <dt class="text-muted-foreground">Phí vận chuyển</dt><dd class="text-right tabular-nums">{{ formatMoney(order.shipping_fee) }}</dd>
                        <dt class="text-muted-foreground">Giảm giá</dt><dd class="text-right tabular-nums">−{{ formatMoney(order.discount) }}</dd>
                        <dt class="font-medium">Khách trả</dt><dd class="text-right font-semibold tabular-nums">{{ formatMoney(order.total) }}</dd>
                    </dl>
                    <p class="border-t px-5 py-2 text-xs text-muted-foreground">Số tiền khách trả chưa phải tiền thực nhận: phí sàn, phí thanh toán và đối soát sẽ được tính ở module Tài chính.</p>
                </Card>

                <Card title="Lịch sử trạng thái">
                    <ol class="space-y-3 px-5 py-4 text-sm" data-testid="order-history">
                        <li v-for="(h, idx) in order.history" :key="idx" class="flex gap-3">
                            <div class="mt-1.5 size-2 shrink-0 rounded-full bg-primary" />
                            <div>
                                <div>
                                    <span v-if="h.from" class="text-muted-foreground">{{ orderStatus[h.from]?.label ?? h.from }} → </span>
                                    <span class="font-medium">{{ orderStatus[h.to]?.label ?? h.to }}</span>
                                    <span class="ml-2 font-mono text-xs text-muted-foreground">{{ h.raw_status }}</span>
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ formatDateTime(h.occurred_at ?? h.created_at) }} · {{ historySource[h.source] ?? h.source }}<span v-if="h.actor"> · {{ h.actor }}</span>
                                    <span v-if="h.note"> · {{ h.note }}</span>
                                </div>
                            </div>
                        </li>
                    </ol>
                </Card>

                <Card title="Ghi chú nội bộ">
                    <ul class="divide-y text-sm">
                        <li v-for="n in order.notes" :key="n.id" class="px-5 py-3">
                            <div class="whitespace-pre-line">{{ n.body }}</div>
                            <div class="mt-1 text-xs text-muted-foreground">{{ n.user }} · {{ formatDateTime(n.created_at) }}</div>
                        </li>
                    </ul>
                    <form v-if="tenant.can('orders.update')" class="flex gap-2 border-t p-4" @submit.prevent="addNote">
                        <Input v-model="note" placeholder="Thêm ghi chú cho đồng nghiệp…" data-testid="note-input" />
                        <Button type="submit" variant="outline" :loading="busy === 'note'">Thêm</Button>
                    </form>
                </Card>
            </div>

            <div class="space-y-4">
                <Card title="Xử lý đơn">
                    <div class="space-y-3 px-5 py-4">
                        <p v-if="!actionList.length" class="text-sm text-muted-foreground">Không có thao tác nào ở trạng thái hiện tại.</p>
                        <div v-for="[action, a] in actionList" :key="action" class="flex items-center justify-between gap-2">
                            <Button
                                v-if="tenant.can('orders.update')"
                                :variant="action === 'cancel' ? 'outline' : 'default'"
                                class="flex-1"
                                :disabled="a.capability !== 'supported'"
                                :loading="busy === action"
                                :data-testid="`action-${action}`"
                                @click="action === 'cancel' ? (cancelOpen = true) : act(action)"
                            >
                                {{ actionLabels[action] }}
                            </Button>
                            <Badge v-if="a.capability !== 'supported'" :tone="capabilityLabels[a.capability]?.tone" :title="'Sàn chưa hỗ trợ hoặc chưa xác minh thao tác này qua API'">{{ capabilityLabels[a.capability]?.label }}</Badge>
                        </div>
                        <p v-if="actionList.some(([, a]) => a.capability !== 'supported')" class="text-xs text-muted-foreground">Thao tác chưa được xác minh với API chính thức của sàn sẽ không được giả lập. Hãy xử lý trên trang của sàn; đơn sẽ tự cập nhật qua webhook/đối soát.</p>
                        <div class="border-t pt-3">
                            <label class="mb-1 block text-xs font-medium text-muted-foreground">Người xử lý</label>
                            <Select
                                :model-value="order.assignee?.id ?? ''"
                                :disabled="!tenant.can('orders.assign')"
                                :options="[{ value: '', label: '— Chưa phân công —' }, ...assignees.map((a) => ({ value: a.id, label: a.name }))]"
                                aria-label="Người xử lý"
                                @update:model-value="assign"
                            />
                        </div>
                        <div v-if="order.ship_by_at" class="text-sm">
                            <span class="text-muted-foreground">Hạn giao cho vận chuyển:</span>
                            <span :class="order.flags.overdue ? 'ml-1 font-medium text-destructive' : order.flags.due_soon ? 'ml-1 font-medium text-[oklch(0.5_0.13_70)]' : 'ml-1'">{{ formatDateTime(order.ship_by_at) }} ({{ deadlineLabel(order.ship_by_at) }})</span>
                        </div>
                    </div>
                </Card>

                <Card title="Giao hàng">
                    <dl class="space-y-2 px-5 py-4 text-sm">
                        <div><dt class="text-xs text-muted-foreground">Người nhận</dt><dd>{{ order.buyer_name ?? '—' }} <span class="text-muted-foreground">{{ order.buyer_phone }}</span></dd></div>
                        <div><dt class="text-xs text-muted-foreground">Địa chỉ</dt><dd>{{ order.shipping_address ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Đơn vị vận chuyển · Mã vận đơn</dt><dd>{{ order.carrier ?? '—' }} · <span class="font-mono text-xs">{{ order.tracking_number ?? '—' }}</span></dd></div>
                        <div><dt class="text-xs text-muted-foreground">Thanh toán</dt><dd>{{ order.is_cod ? 'Thu hộ (COD)' : (order.payment_method ?? 'Đã thanh toán') }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Kho xuất</dt><dd>{{ order.warehouse ? `${order.warehouse.code} · ${order.warehouse.name}` : '—' }}</dd></div>
                        <div v-if="order.buyer_note"><dt class="text-xs text-muted-foreground">Ghi chú của khách</dt><dd class="italic">{{ order.buyer_note }}</dd></div>
                        <div v-if="order.cancel_reason"><dt class="text-xs text-muted-foreground">Lý do hủy</dt><dd>{{ order.cancel_reason }}</dd></div>
                    </dl>
                    <p class="border-t px-5 py-2 text-xs text-muted-foreground">Số điện thoại chỉ lưu dạng che; tên và địa chỉ được mã hóa khi lưu.</p>
                </Card>

                <Card title="Trả hàng / hoàn tiền">
                    <template v-if="tenant.can('returns.inspect')" #actions>
                        <Button size="sm" variant="outline" data-testid="create-return" @click="openReturn"><Undo2 /> Tạo</Button>
                    </template>
                    <ul v-if="order.returns.length" class="divide-y text-sm">
                        <li v-for="r in order.returns" :key="r.id" class="flex items-center justify-between px-5 py-3">
                            <RouterLink :to="`/returns/${r.id}`" class="hover:underline">
                                <div class="font-mono text-xs font-medium">{{ r.number }}</div>
                                <div class="text-xs text-muted-foreground">{{ returnTypes[r.type] }}</div>
                            </RouterLink>
                            <Badge :tone="returnStatus[r.status]?.tone">{{ returnStatus[r.status]?.label }}</Badge>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-4 text-sm text-muted-foreground">Chưa có.</p>
                </Card>
            </div>
        </div>

        <Dialog v-model:open="cancelOpen" title="Hủy đơn" description="Yêu cầu hủy được gửi lên sàn. Hàng đang giữ sẽ được nhả khi sàn xác nhận hủy.">
            <FormField label="Lý do"><Input v-model="cancelReason" placeholder="Hết hàng, khách yêu cầu…" /></FormField>
            <template #footer>
                <Button variant="outline" @click="cancelOpen = false">Đóng</Button>
                <Button variant="destructive" :loading="busy === 'cancel'" @click="act('cancel', { reason: cancelReason || null })">Hủy đơn</Button>
            </template>
        </Dialog>

        <Dialog v-model:open="returnOpen" title="Tạo phiếu trả hàng" size="lg" description="Tồn kho chỉ được cộng lại khi kiểm hàng hoàn về, theo số lượng còn bán được. Hoàn tiền không kèm hàng không cộng kho.">
            <div class="space-y-4">
                <FormField label="Loại">
                    <Select v-model="returnForm.type" :options="[{ value: 'return', label: returnTypes.return! }, { value: 'refund_only', label: returnTypes.refund_only! }]" />
                </FormField>
                <table v-if="returnForm.type === 'return'" class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground"><tr><th class="py-1">Sản phẩm</th><th class="py-1 text-right">Đã mua</th><th class="w-28 py-1 text-right">Trả lại</th></tr></thead>
                    <tbody class="divide-y">
                        <tr v-for="i in order.items" :key="i.id">
                            <td class="py-2">{{ i.name }} <span class="text-xs text-muted-foreground">{{ i.variant_name }}</span></td>
                            <td class="py-2 text-right tabular-nums">{{ i.quantity }}</td>
                            <td class="py-2"><Input v-model.number="returnForm.lines[i.id]" type="number" min="0" :max="i.quantity" class="text-right" /></td>
                        </tr>
                    </tbody>
                </table>
                <FormField label="Lý do"><Input v-model="returnForm.reason" /></FormField>
                <FormField label="Số tiền hoàn (nếu có)" :error="returnErrors?.field('refund_amount')"><Input v-model="returnForm.refund_amount" type="number" min="0" /></FormField>
                <p v-if="returnErrors" class="text-sm text-destructive">{{ returnErrors.field('lines') ?? returnErrors.message }}</p>
            </div>
            <template #footer>
                <Button variant="outline" @click="returnOpen = false">Hủy</Button>
                <Button :loading="busy === 'return'" @click="createReturn">Tạo phiếu</Button>
            </template>
        </Dialog>
    </template>
</template>
