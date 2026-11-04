<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import { api, ApiError, idempotent, newIdempotencyKey } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import { formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import type { Fund } from './voucher';

interface Count {
    id: string;
    counted_at: string;
    system_balance: number;
    counted_amount: number;
    difference: number;
    voucher: { id: string; number: string } | null;
    note: string | null;
    created_by: string | null;
}

const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ fund: Fund | null; canCount: boolean }>();
const emit = defineEmits<{ saved: [] }>();
const toast = useToast();

const counted = ref('');
const note = ref('');
const saving = ref(false);
const error = ref<ApiError | null>(null);
const history = ref<Count[]>([]);
let key = newIdempotencyKey();

watch(open, async (v) => {
    if (!v || !props.fund) return;
    counted.value = '';
    note.value = '';
    error.value = null;
    key = newIdempotencyKey();
    history.value = [];
    try {
        history.value = (await api.get(`/cash-accounts/${props.fund.id}/counts`)).data.data;
    } catch {
        /* the form still works */
    }
});

const difference = computed(() => (counted.value === '' || !props.fund ? null : Number(counted.value) - props.fund.balance));

async function save() {
    if (!props.fund) return;
    saving.value = true;
    error.value = null;
    try {
        const { data } = await api.post(`/cash-accounts/${props.fund.id}/counts`, { counted_amount: Number(counted.value), note: note.value || null }, idempotent(key));
        const d = data.data as Count;
        toast.success(d.voucher ? `Đã kiểm quỹ, ghi ${d.difference > 0 ? 'thừa' : 'thiếu'} ${formatMoney(Math.abs(d.difference))} vào phiếu ${d.voucher.number}.` : 'Đã kiểm quỹ, khớp số dư.');
        open.value = false;
        emit('saved');
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open" :title="fund ? `Kiểm quỹ ${fund.name}` : ''" description="Đếm tiền thực có (hoặc xem số dư trên sao kê) rồi nhập vào. Phần chênh lệch được ghi thành phiếu thu hoặc phiếu chi “Chênh lệch kiểm quỹ” ngày hôm nay." size="lg">
        <form v-if="fund" id="count-form" class="grid gap-4 sm:grid-cols-2" data-testid="count-form" @submit.prevent="save">
            <div class="rounded-md bg-muted/50 p-3">
                <p class="text-xs text-muted-foreground">Số dư trên phần mềm</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">{{ formatMoney(fund.balance) }}</p>
            </div>
            <FormField label="Số tiền thực có" for="c-amount" :error="error?.field('counted_amount')">
                <Input id="c-amount" :model-value="counted ? formatNumber(counted) : ''" inputmode="numeric" required class="text-right text-base font-semibold tabular-nums" placeholder="0" data-testid="c-amount" @input="counted = ($event.target as HTMLInputElement).value.replace(/\D/g, '').slice(0, 13)" />
            </FormField>
            <p v-if="difference !== null" class="rounded-md px-3 py-2 text-sm sm:col-span-2" :class="difference === 0 ? 'bg-success/10 text-success' : 'bg-amber-50 text-amber-800'" data-testid="c-diff">
                <template v-if="difference === 0">Khớp số dư, không phát sinh phiếu.</template>
                <template v-else>{{ difference > 0 ? 'Thừa' : 'Thiếu' }} {{ formatMoney(Math.abs(difference)) }}: sẽ lập phiếu {{ difference > 0 ? 'thu' : 'chi' }} “Chênh lệch kiểm quỹ”, không tính vào kết quả kinh doanh.</template>
            </p>
            <FormField label="Ghi chú" for="c-note" class="sm:col-span-2"><Input id="c-note" v-model="note" maxlength="255" data-testid="c-note" placeholder="VD: Kiểm két cuối ngày, thiếu do thối nhầm" /></FormField>
            <p v-if="error && !error.body.errors" class="text-sm text-destructive sm:col-span-2">{{ error.message }}</p>
        </form>
        <div v-if="history.length" class="mt-5">
            <p class="mb-1.5 text-sm font-medium">Các lần kiểm gần đây</p>
            <ul class="divide-y rounded-md border text-sm" data-testid="count-history">
                <li v-for="c in history" :key="c.id" class="flex flex-wrap items-center gap-x-3 gap-y-0.5 px-3 py-2">
                    <span class="text-muted-foreground">{{ formatDateTime(c.counted_at) }}</span>
                    <span class="tabular-nums">{{ formatMoney(c.counted_amount) }}</span>
                    <span class="tabular-nums" :class="c.difference === 0 ? 'text-success' : 'text-amber-700'">{{ c.difference === 0 ? 'Khớp' : `${c.difference > 0 ? '+' : '−'}${formatMoney(Math.abs(c.difference))}` }}</span>
                    <RouterLink v-if="c.voucher" :to="`/cash?open=voucher:${c.voucher.id}`" class="font-mono text-xs text-primary hover:underline" @click="open = false">{{ c.voucher.number }}</RouterLink>
                    <span class="ml-auto text-xs text-muted-foreground">{{ c.created_by }}<template v-if="c.note"> · {{ c.note }}</template></span>
                </li>
            </ul>
        </div>
        <template #footer>
            <Button variant="outline" @click="open = false">{{ canCount ? 'Hủy' : 'Đóng' }}</Button>
            <Button v-if="canCount" type="submit" form="count-form" :loading="saving" :disabled="counted === ''" data-testid="c-save">Ghi kết quả kiểm</Button>
        </template>
    </Dialog>
</template>
