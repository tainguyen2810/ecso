<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Check, PackageCheck, Send, X } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import { adjustmentReasons, documentKinds, documentStatus, type DocumentKindKey } from './labels';
import type { InventoryDocument } from '@/shared/types';

const route = useRoute();
const tenant = useTenantStore();
const toast = useToast();
const kindKey = computed(() => route.meta.kind as DocumentKindKey);
const kind = computed(() => documentKinds[kindKey.value]);

const doc = ref<InventoryDocument | null>(null);
const loadError = ref<string | null>(null);
const busy = ref<string | null>(null);

async function load() {
    loadError.value = null;
    try {
        doc.value = (await api.get(`/inventory/${kindKey.value}/${route.params.id}`)).data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    }
}
onMounted(load);

async function act(action: string, body: Record<string, unknown> = {}, message = 'Đã cập nhật phiếu') {
    busy.value = action;
    try {
        doc.value = (await api.post(`/inventory/${kindKey.value}/${route.params.id}/${action}`, body)).data.data;
        toast.success(message);
        return true;
    } catch (e) {
        toast.error((e as ApiError).message);
        return false;
    } finally {
        busy.value = null;
    }
}

const rejectOpen = ref(false);
const rejectNote = ref('');
async function reject() {
    if (await act('reject', { note: rejectNote.value }, 'Đã từ chối phiếu')) rejectOpen.value = false;
}

const isPending = computed(() => doc.value?.kind === 'adjustment' && doc.value.status === 'pending');
const isMine = computed(() => doc.value?.is_mine === true);
const steps = computed(() => {
    const d = doc.value;
    if (!d) return [];
    if (d.kind === 'adjustment')
        return [
            { label: 'Tạo phiếu', who: d.created_by, at: d.created_at, done: true },
            { label: d.status === 'rejected' ? 'Từ chối' : d.status === 'cancelled' ? 'Đã hủy' : 'Duyệt và áp dụng', who: d.decided_by, at: d.decided_at, done: d.status !== 'pending', note: d.decision_note },
        ];
    if (d.kind === 'transfer')
        return [
            { label: 'Tạo nháp', who: d.created_by, at: d.created_at, done: true },
            { label: `Xuất khỏi ${d.from_warehouse?.code}`, at: d.dispatched_at, done: !!d.dispatched_at },
            { label: d.status === 'cancelled' ? 'Đã hủy' : `Nhận vào ${d.to_warehouse?.code}`, at: d.received_at, done: d.status === 'received' || d.status === 'cancelled' },
        ];
    return [{ label: 'Nhập kho', who: d.created_by, at: d.created_at, done: true }];
});
</script>

<template>
    <RouterLink :to="`/inventory/${kindKey}`" class="mb-3 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft class="size-4" /> {{ kind.title }}</RouterLink>

    <ErrorState v-if="loadError" :message="loadError" @retry="load" />
    <div v-else-if="!doc" class="space-y-3"><Skeleton class="h-10 w-72" /><Skeleton class="h-64" /></div>
    <template v-else>
        <PageHeader :title="`${kind.title} ${doc.number}`" :description="doc.note ?? undefined">
            <Badge v-if="doc.status" :tone="documentStatus[doc.status]?.tone" class="px-3 py-1 text-sm" data-testid="document-status">{{ documentStatus[doc.status]?.label }}</Badge>
            <template v-if="isPending">
                <Button v-if="tenant.can('inventory.adjust.approve') || isMine" variant="ghost" :loading="busy === 'cancel'" @click="act('cancel', {}, 'Đã hủy phiếu')">Hủy phiếu</Button>
                <Button v-if="tenant.can('inventory.adjust.approve')" variant="outline" @click="rejectOpen = true"><X /> Từ chối</Button>
                <Button v-if="tenant.can('inventory.adjust.approve')" data-testid="approve" :loading="busy === 'approve'" @click="act('approve', {}, 'Đã duyệt, tồn kho đã được cập nhật')"><Check /> Duyệt</Button>
            </template>
            <template v-if="doc.kind === 'transfer' && tenant.can('inventory.transfer')">
                <Button v-if="doc.status === 'draft' || doc.status === 'in_transit'" variant="ghost" :loading="busy === 'cancel'" @click="act('cancel', {}, 'Đã hủy phiếu chuyển')">Hủy phiếu</Button>
                <Button v-if="doc.status === 'draft'" data-testid="dispatch" :loading="busy === 'dispatch'" @click="act('dispatch', {}, 'Đã xuất khỏi kho nguồn')"><Send /> Xuất kho</Button>
                <Button v-if="doc.status === 'in_transit'" data-testid="receive" :loading="busy === 'receive'" @click="act('receive', {}, 'Đã nhận hàng vào kho đích')"><PackageCheck /> Nhận hàng</Button>
            </template>
        </PageHeader>

        <div class="grid gap-5 lg:grid-cols-3">
            <Card class="lg:col-span-2" title="Chi tiết hàng hóa">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="detail-lines">
                        <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2.5 font-medium">SKU</th>
                                <th v-if="doc.kind === 'adjustment'" class="px-4 py-2.5 font-medium">Cách nhập</th>
                                <th class="px-4 py-2.5 text-right font-medium">Số lượng</th>
                                <th v-if="doc.kind === 'receipt'" class="px-4 py-2.5 text-right font-medium">Đơn giá</th>
                                <th v-if="doc.kind === 'receipt'" class="px-4 py-2.5 text-right font-medium">Thành tiền</th>
                                <th v-if="doc.kind === 'adjustment'" class="px-4 py-2.5 text-right font-medium">Tồn trước</th>
                                <th v-if="doc.kind === 'adjustment'" class="px-4 py-2.5 text-right font-medium">Đã áp dụng</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="l in doc.lines" :key="l.variant.id">
                                <td class="px-4 py-2.5">
                                    <RouterLink :to="`/inventory/sku/${l.variant.id}`" class="font-mono text-xs font-medium text-primary hover:underline">{{ l.variant.sku }}</RouterLink>
                                    <p class="text-xs text-muted-foreground">{{ l.variant.name }}</p>
                                </td>
                                <td v-if="doc.kind === 'adjustment'" class="px-4 py-2.5 text-muted-foreground">{{ l.mode === 'count' ? 'Số đếm thực tế' : 'Chênh lệch' }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ l.mode === 'delta' && l.quantity > 0 ? '+' : '' }}{{ formatNumber(l.quantity) }}</td>
                                <td v-if="doc.kind === 'receipt'" class="px-4 py-2.5 text-right tabular-nums">{{ formatMoney(l.unit_cost) }}</td>
                                <td v-if="doc.kind === 'receipt'" class="px-4 py-2.5 text-right tabular-nums">{{ formatMoney(l.quantity * Number(l.unit_cost)) }}</td>
                                <td v-if="doc.kind === 'adjustment'" class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ l.on_hand_before ?? '—' }}</td>
                                <td v-if="doc.kind === 'adjustment'" :class="cn('px-4 py-2.5 text-right font-medium tabular-nums', (l.applied_delta ?? 0) > 0 && 'text-success', (l.applied_delta ?? 0) < 0 && 'text-destructive')">
                                    {{ l.applied_delta === undefined ? 'Chờ duyệt' : (l.applied_delta > 0 ? '+' : '') + l.applied_delta }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="doc.kind === 'receipt'" class="flex justify-end gap-8 border-t px-4 py-3 text-sm">
                    <span class="text-muted-foreground">Tổng số lượng <b class="ml-1 text-foreground tabular-nums">{{ formatNumber(doc.total_quantity) }}</b></span>
                    <span class="text-muted-foreground">Tổng giá trị <b class="ml-1 text-foreground tabular-nums">{{ formatMoney(doc.total_value) }}</b></span>
                </div>
            </Card>

            <div class="space-y-5">
                <Card title="Thông tin">
                    <dl class="divide-y text-sm">
                        <div v-if="doc.kind === 'transfer'" class="flex justify-between px-5 py-2.5"><dt class="text-muted-foreground">Kho xuất</dt><dd>{{ doc.from_warehouse?.code }} · {{ doc.from_warehouse?.name }}</dd></div>
                        <div v-if="doc.kind === 'transfer'" class="flex justify-between px-5 py-2.5"><dt class="text-muted-foreground">Kho nhận</dt><dd>{{ doc.to_warehouse?.code }} · {{ doc.to_warehouse?.name }}</dd></div>
                        <div v-else class="flex justify-between px-5 py-2.5"><dt class="text-muted-foreground">Kho</dt><dd>{{ doc.warehouse?.code }} · {{ doc.warehouse?.name }}</dd></div>
                        <div v-if="doc.kind === 'adjustment'" class="flex justify-between px-5 py-2.5"><dt class="text-muted-foreground">Lý do</dt><dd>{{ adjustmentReasons[doc.reason ?? ''] }}</dd></div>
                        <div v-if="doc.kind === 'receipt'" class="flex justify-between px-5 py-2.5"><dt class="text-muted-foreground">Nguồn</dt><dd>{{ doc.source === 'opening' ? 'Tồn đầu kỳ' : 'Nhập thủ công' }}</dd></div>
                    </dl>
                </Card>
                <Card title="Tiến trình">
                    <ol class="space-y-4 p-5">
                        <li v-for="(s, i) in steps" :key="i" class="flex gap-3">
                            <span :class="cn('mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold', s.done ? 'bg-primary text-primary-foreground' : 'border bg-card text-muted-foreground')">{{ i + 1 }}</span>
                            <div class="text-sm">
                                <p :class="cn('font-medium', !s.done && 'text-muted-foreground')">{{ s.label }}</p>
                                <p v-if="s.done && (s.who || s.at)" class="text-xs text-muted-foreground">{{ 'who' in s && s.who ? `${s.who} · ` : '' }}{{ formatDateTime(s.at) }}</p>
                                <p v-if="'note' in s && s.note" class="mt-1 rounded bg-muted px-2 py-1 text-xs">“{{ s.note }}”</p>
                            </div>
                        </li>
                    </ol>
                </Card>
            </div>
        </div>
    </template>

    <Dialog v-model:open="rejectOpen" title="Từ chối phiếu điều chỉnh" description="Người tạo phiếu sẽ thấy lý do từ chối.">
        <form id="reject-form" @submit.prevent="reject">
            <FormField label="Lý do" for="reject-note"><Input id="reject-note" v-model="rejectNote" required maxlength="255" autofocus /></FormField>
        </form>
        <template #footer>
            <Button variant="outline" @click="rejectOpen = false">Đóng</Button>
            <Button type="submit" form="reject-form" variant="destructive" :loading="busy === 'reject'">Từ chối</Button>
        </template>
    </Dialog>
</template>
