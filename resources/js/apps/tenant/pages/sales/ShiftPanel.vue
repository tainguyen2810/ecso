<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { ArrowDownToLine, ArrowUpFromLine, Printer, RotateCcw } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { paymentMethods } from '../orders/labels';
import { cashDifference, DENOMINATIONS, denominationTotal, differenceLabel, printShiftReport } from './shift';
import type { CashShift } from '@/shared/types';

/**
 * Open a shift, put cash in or take it out of the drawer, count and close. Used in the POS dialog and on the shift
 * history page (a manager closing someone else's forgotten shift).
 */
const props = defineProps<{ shift: CashShift | null; warehouseId?: string; suggestedOpening?: string }>();
const emit = defineEmits<{ changed: [shift: CashShift] }>();
const toast = useToast();
const tenant = useTenantStore();

const mode = ref<'view' | 'move' | 'close' | 'reopen'>('view');
const reopenReason = ref('');
const busy = ref(false);
const error = ref<string | null>(null);
const openForm = reactive({ opening_cash: '', opening_note: '' });
const moveForm = reactive({ kind: 'in' as 'in' | 'out', amount: '', reason: '', real: false, voucher_type: '' });
/** Active cash voucher types, loaded the first time the cashier records a real receipt or payment. */
const voucherTypes = ref<{ id: string; direction: 'receipt' | 'payment'; name: string }[] | null>(null);
const typeOptions = computed(() =>
    (voucherTypes.value ?? []).filter((v) => v.direction === (moveForm.kind === 'in' ? 'receipt' : 'payment')).map((v) => ({ value: v.id, label: v.name })),
);

async function startMove(kind: 'in' | 'out') {
    Object.assign(moveForm, { kind, amount: '', reason: '', real: false, voucher_type: '' });
    error.value = null;
    mode.value = 'move';
    if (voucherTypes.value === null) {
        try {
            voucherTypes.value = (await api.get('/pos/shifts/voucher-types')).data.data;
        } catch {
            voucherTypes.value = [];
        }
    }
}
const counts = reactive<Record<string, number | string>>({});
const closeForm = reactive({ counted: '', handed_over: '', closing_note: '', byNotes: true });

watch(
    () => props.suggestedOpening,
    (v) => (openForm.opening_cash = v ? String(Math.round(Number(v))) : ''),
    { immediate: true },
);
watch(
    () => props.shift?.id,
    () => {
        mode.value = 'view';
        error.value = null;
    },
);

watch([() => closeForm.closing_note, () => moveForm.amount, () => openForm.opening_cash], () => (error.value = null));

const t = computed(() => props.shift?.totals);
const counted = computed(() => (closeForm.byNotes ? denominationTotal(counts) : Number(closeForm.counted) || 0));
const diff = computed(() => (t.value ? cashDifference(counted.value, t.value.expected_cash) : 0));

function fail(e: unknown) {
    const err = e as ApiError;
    const errors = err.body?.errors as Record<string, string[]> | undefined;
    error.value = errors ? (Object.values(errors)[0]?.[0] ?? err.message) : err.message;
}

async function open() {
    busy.value = true;
    error.value = null;
    try {
        const { data } = await api.post('/pos/shifts', { opening_cash: Number(openForm.opening_cash) || 0, opening_note: openForm.opening_note || null, warehouse_id: props.warehouseId || null });
        toast.success(`Đã mở ca ${data.data.number}`);
        emit('changed', data.data);
    } catch (e) {
        fail(e);
    } finally {
        busy.value = false;
    }
}

async function move() {
    if (!props.shift) return;
    busy.value = true;
    error.value = null;
    try {
        const { data } = await api.post(`/pos/shifts/${props.shift.id}/movements`, {
            kind: moveForm.kind,
            amount: Number(moveForm.amount) || 0,
            reason: moveForm.reason,
            voucher_type: moveForm.real ? moveForm.voucher_type || null : null,
        });
        toast.success(moveForm.real ? 'Đã ghi phiếu vào sổ quỹ tiền mặt' : moveForm.kind === 'in' ? 'Đã ghi nộp tiền vào két' : 'Đã ghi rút tiền khỏi két');
        Object.assign(moveForm, { amount: '', reason: '', real: false, voucher_type: '' });
        mode.value = 'view';
        emit('changed', data.data);
    } catch (e) {
        fail(e);
    } finally {
        busy.value = false;
    }
}

async function close() {
    if (!props.shift) return;
    busy.value = true;
    error.value = null;
    try {
        const notes = Object.fromEntries(Object.entries(counts).filter(([, n]) => Number(n) > 0).map(([v, n]) => [v, Math.floor(Number(n))]));
        const { data } = await api.post(`/pos/shifts/${props.shift.id}/close`, {
            ...(closeForm.byNotes ? { denominations: notes } : { counted_cash: Number(closeForm.counted) || 0 }),
            handed_over: Number(closeForm.handed_over) || 0,
            closing_note: closeForm.closing_note || null,
        });
        toast.success(`Đã đóng ca ${data.data.number}`);
        mode.value = 'view';
        emit('changed', data.data);
    } catch (e) {
        fail(e);
    } finally {
        busy.value = false;
    }
}

/** Shift supervisors: open a closed shift again to correct it (reason kept in the log). */
async function reopen() {
    busy.value = true;
    error.value = null;
    try {
        const { data } = await api.post(`/pos/shifts/${props.shift!.id}/reopen`, { reason: reopenReason.value });
        toast.success('Đã mở lại ca.');
        reopenReason.value = '';
        mode.value = 'view';
        emit('changed', data.data);
    } catch (e) {
        fail(e);
    } finally {
        busy.value = false;
    }
}

function print() {
    if (props.shift) printShiftReport(props.shift, tenant.current?.name ?? '');
}
</script>

<template>
    <!-- No shift: open one -->
    <form v-if="!shift" class="space-y-3" data-testid="shift-open-form" @submit.prevent="open">
        <p class="text-sm text-muted-foreground">Đếm tiền mặt có sẵn trong két trước khi bán. Hệ thống gợi ý số tiền ca trước để lại.</p>
        <label class="block text-sm font-medium">Tiền đầu ca
            <Input v-model="openForm.opening_cash" type="number" min="0" class="mt-1 text-right text-base tabular-nums" data-testid="shift-opening" />
        </label>
        <label class="block text-sm font-medium">Ghi chú <Input v-model="openForm.opening_note" class="mt-1" placeholder="Không bắt buộc" /></label>
        <p v-if="error" class="text-sm text-destructive" data-testid="shift-error">{{ error }}</p>
        <Button type="submit" class="w-full" :loading="busy" data-testid="shift-open">Mở ca</Button>
    </form>

    <div v-else-if="t" class="space-y-4 text-sm" data-testid="shift-panel">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <p class="font-semibold">{{ shift.number }} · {{ shift.cashier?.name }}</p>
                <p class="text-xs text-muted-foreground">{{ formatDateTime(shift.opened_at) }} → {{ shift.closed_at ? formatDateTime(shift.closed_at) : 'đang mở' }}</p>
            </div>
            <Button variant="outline" size="sm" data-testid="shift-print" @click="print"><Printer /> In bàn giao ca</Button>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <dl class="grid grid-cols-2 gap-y-1.5 rounded-md border p-3">
                <dt class="text-muted-foreground">Số đơn</dt><dd class="text-right tabular-nums">{{ t.orders }} <span v-if="t.cancelled" class="text-xs text-muted-foreground">(hủy {{ t.cancelled }})</span></dd>
                <dt class="text-muted-foreground">Doanh số</dt><dd class="text-right tabular-nums">{{ formatMoney(t.revenue) }}</dd>
                <template v-for="(amount, method) in t.by_method" :key="method">
                    <template v-if="Number(amount) !== 0"><dt class="text-muted-foreground">{{ paymentMethods[method as keyof typeof paymentMethods] ?? method }}</dt><dd class="text-right tabular-nums">{{ formatMoney(amount) }}</dd></template>
                </template>
                <dt class="border-t pt-1.5 font-medium">Tổng đã thu</dt><dd class="border-t pt-1.5 text-right font-semibold tabular-nums">{{ formatMoney(t.collected) }}</dd>
            </dl>
            <dl class="grid grid-cols-2 gap-y-1.5 rounded-md border p-3" data-testid="shift-cash">
                <dt class="text-muted-foreground">Tiền đầu ca</dt><dd class="text-right tabular-nums">{{ formatMoney(t.opening_cash) }}</dd>
                <dt class="text-muted-foreground">+ Thu tiền mặt</dt><dd class="text-right tabular-nums">{{ formatMoney(t.cash_sales) }}</dd>
                <dt class="text-muted-foreground">− Hoàn tiền mặt</dt><dd class="text-right tabular-nums">{{ formatMoney(t.cash_refunds) }}</dd>
                <dt class="text-muted-foreground">+ Nộp vào két</dt><dd class="text-right tabular-nums">{{ formatMoney(t.cash_in) }}</dd>
                <dt class="text-muted-foreground">− Rút khỏi két</dt><dd class="text-right tabular-nums">{{ formatMoney(t.cash_out) }}</dd>
                <dt class="border-t pt-1.5 font-medium">Tiền mặt trong két</dt><dd class="border-t pt-1.5 text-right font-semibold tabular-nums text-primary" data-testid="shift-expected">{{ formatMoney(t.expected_cash) }}</dd>
                <template v-if="shift.status === 'closed'">
                    <dt class="font-medium">Thực tế đếm được</dt><dd class="text-right font-semibold tabular-nums">{{ formatMoney(shift.counted_cash) }}</dd>
                    <dt class="font-medium">Chênh lệch</dt>
                    <dd class="text-right font-semibold" :class="Number(shift.difference) === 0 ? 'text-success' : 'text-destructive'" data-testid="shift-difference">{{ differenceLabel(Number(shift.difference)) }}</dd>
                    <dt class="text-muted-foreground">Nộp về / để lại két</dt><dd class="text-right tabular-nums">{{ formatNumber(shift.handed_over) }} / {{ formatNumber(shift.kept_in_drawer) }}</dd>
                </template>
            </dl>
        </div>
        <p v-if="shift.closing_note" class="whitespace-pre-line rounded-md bg-muted/50 px-3 py-2 text-xs">Ghi chú đóng ca: {{ shift.closing_note }}<span v-if="shift.closed_by"> · đóng bởi {{ shift.closed_by }}</span></p>

        <div v-if="shift.status === 'closed' && tenant.can('orders.shifts')" class="rounded-md border border-dashed p-3" data-testid="shift-reopen-box">
            <Button v-if="mode !== 'reopen'" variant="outline" size="sm" data-testid="shift-reopen-start" @click="mode = 'reopen'"><RotateCcw /> Mở lại ca để điều chỉnh</Button>
            <form v-else class="space-y-2" @submit.prevent="reopen">
                <p class="text-xs text-muted-foreground">Chỉ mở lại được ca gần nhất của thu ngân. Số liệu đóng ca cũ được lưu trong nhật ký thao tác.</p>
                <Input v-model="reopenReason" placeholder="Lý do mở lại (bắt buộc)" required data-testid="shift-reopen-reason" />
                <p v-if="error" class="text-xs text-destructive">{{ error }}</p>
                <div class="flex justify-end gap-2"><Button variant="ghost" type="button" @click="mode = 'view'">Hủy</Button><Button type="submit" :loading="busy" :disabled="!reopenReason.trim()" data-testid="shift-reopen">Mở lại ca</Button></div>
            </form>
        </div>

        <div v-if="shift.movements?.length">
            <p class="mb-1 font-medium">Nộp / rút tiền trong ca</p>
            <ul class="divide-y rounded-md border">
                <li v-for="(m, i) in shift.movements" :key="i" class="flex items-center justify-between px-3 py-1.5">
                    <span><span :class="m.kind === 'in' ? 'text-success' : 'text-destructive'">{{ m.kind === 'in' ? 'Nộp vào' : 'Rút ra' }}</span> · {{ m.reason }}&nbsp;<template v-if="m.voucher"><RouterLink v-if="tenant.can('finance.view')" :to="{ path: '/cash', query: { q: m.voucher.number } }" class="font-mono text-xs text-primary hover:underline" :data-testid="`shift-movement-voucher-${i}`">{{ m.voucher.number }}</RouterLink><span v-else class="font-mono text-xs" :data-testid="`shift-movement-voucher-${i}`">{{ m.voucher.number }}</span></template>
                        <span v-else class="text-xs text-muted-foreground">(két tổng)</span>
                        <span class="ml-1 text-xs text-muted-foreground">{{ formatDateTime(m.created_at) }}</span></span>
                    <span class="tabular-nums">{{ m.kind === 'in' ? '+' : '−' }}{{ formatNumber(m.amount) }}</span>
                </li>
            </ul>
        </div>

        <template v-if="shift.status === 'open'">
            <div v-if="mode === 'view'" class="flex flex-wrap gap-2">
                <Button variant="outline" data-testid="shift-cash-in" @click="startMove('in')"><ArrowDownToLine /> Nộp tiền vào két</Button>
                <Button variant="outline" data-testid="shift-cash-out" @click="startMove('out')"><ArrowUpFromLine /> Rút tiền</Button>
                <Button class="ml-auto" data-testid="shift-close-start" @click="mode = 'close'">Đóng ca</Button>
            </div>

            <form v-else-if="mode === 'move'" class="space-y-2 rounded-md border p-3" @submit.prevent="move">
                <p class="font-medium">{{ moveForm.kind === 'in' ? 'Nộp tiền vào két' : 'Rút tiền khỏi két' }}</p>
                <div class="grid gap-2 sm:grid-cols-2" role="radiogroup">
                    <label class="flex cursor-pointer gap-2 rounded-md border p-2" :class="!moveForm.real && 'border-primary bg-primary/5'">
                        <input v-model="moveForm.real" type="radio" :value="false" data-testid="shift-move-internal" />
                        <span><span class="font-medium">Chuyển với két tổng</span><span class="block text-xs text-muted-foreground">{{ moveForm.kind === 'in' ? 'Lấy tiền lẻ từ két tổng.' : 'Nộp về két tổng / két sắt.' }} Quỹ tiền mặt không đổi.</span></span>
                    </label>
                    <label class="flex cursor-pointer gap-2 rounded-md border p-2" :class="moveForm.real && 'border-primary bg-primary/5'">
                        <input v-model="moveForm.real" type="radio" :value="true" data-testid="shift-move-real" />
                        <span><span class="font-medium">{{ moveForm.kind === 'in' ? 'Thu tiền thật' : 'Chi tiền thật' }}</span><span class="block text-xs text-muted-foreground">{{ moveForm.kind === 'in' ? 'Tiền từ ngoài vào (thu khác).' : 'Trả chi phí, mua đồ lặt vặt.' }} Ghi phiếu vào sổ quỹ.</span></span>
                    </label>
                </div>
                <Select v-if="moveForm.real" v-model="moveForm.voucher_type" :options="[{ value: '', label: moveForm.kind === 'in' ? 'Chọn loại thu…' : 'Chọn loại chi…' }, ...typeOptions]" data-testid="shift-move-type" />
                <div class="flex gap-2">
                    <Input v-model="moveForm.amount" type="number" min="1" placeholder="Số tiền" class="w-40 text-right tabular-nums" data-testid="shift-move-amount" />
                    <Input v-model="moveForm.reason" placeholder="Lý do" class="flex-1" data-testid="shift-move-reason" />
                </div>
                <p v-if="error" class="text-sm text-destructive" data-testid="shift-error">{{ error }}</p>
                <div class="flex justify-end gap-2"><Button variant="ghost" type="button" @click="mode = 'view'">Hủy</Button><Button type="submit" :loading="busy" :disabled="moveForm.real && !moveForm.voucher_type" data-testid="shift-move-save">Ghi nhận</Button></div>
            </form>

            <form v-else class="space-y-3 rounded-md border p-3" data-testid="shift-close-form" @submit.prevent="close">
                <div class="flex items-center justify-between">
                    <p class="font-medium">Đếm tiền mặt trong két</p>
                    <label class="flex items-center gap-1.5 text-xs text-muted-foreground"><input v-model="closeForm.byNotes" type="checkbox" /> Đếm theo mệnh giá</label>
                </div>
                <div v-if="closeForm.byNotes" class="grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-2">
                    <label v-for="d in DENOMINATIONS" :key="d" class="flex items-center justify-between gap-2">
                        <span class="tabular-nums">{{ formatNumber(d) }}</span>
                        <span class="flex items-center gap-1"><span class="text-xs text-muted-foreground">×</span><input v-model="counts[d]" type="number" min="0" class="h-7 w-16 rounded-md border bg-card px-2 text-right tabular-nums" :data-testid="`shift-note-${d}`" /></span>
                    </label>
                </div>
                <Input v-else v-model="closeForm.counted" type="number" min="0" placeholder="Tổng tiền mặt đếm được" class="text-right tabular-nums" data-testid="shift-counted" />
                <div class="flex justify-between rounded-md bg-muted/50 px-3 py-2">
                    <span>Thực tế <b class="tabular-nums" data-testid="shift-counted-total">{{ formatMoney(counted) }}</b> · hệ thống {{ formatMoney(t.expected_cash) }}</span>
                    <b :class="diff === 0 ? 'text-success' : 'text-destructive'" data-testid="shift-diff-live">{{ differenceLabel(diff) }}</b>
                </div>
                <label class="block">Nộp về (phần còn lại để làm tiền đầu ca sau)
                    <Input v-model="closeForm.handed_over" type="number" min="0" class="mt-1 text-right tabular-nums" data-testid="shift-handed-over" />
                </label>
                <label class="block">Ghi chú <span v-if="diff !== 0" class="text-destructive">(bắt buộc khi lệch)</span>
                    <Input v-model="closeForm.closing_note" class="mt-1" data-testid="shift-close-note" />
                </label>
                <p v-if="error" class="text-sm text-destructive" data-testid="shift-error">{{ error }}</p>
                <div class="flex justify-end gap-2"><Button variant="ghost" type="button" @click="mode = 'view'">Hủy</Button><Button type="submit" :loading="busy" data-testid="shift-close">Xác nhận đóng ca</Button></div>
            </form>
        </template>
    </div>
</template>
