<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { ArrowRight } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError, idempotent, newIdempotencyKey } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import { formatMoney, formatNumber } from '@/shared/lib/utils';
import { amountInWords, type Fund } from './voucher';

const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ funds: Fund[]; from?: string | null }>();
const emit = defineEmits<{ saved: [] }>();
const toast = useToast();

const form = reactive({ from: '', to: '', amount: '', fee: '', transferred_at: '', reference: '', note: '' });
const saving = ref(false);
const error = ref<ApiError | null>(null);
let key = newIdempotencyKey();

const active = computed(() => props.funds.filter((f) => f.is_active));
const source = computed(() => active.value.find((f) => f.id === form.from));
const amount = computed(() => Number(form.amount || 0));
const fee = computed(() => Number(form.fee || 0));
const short = computed(() => !!source.value && amount.value + fee.value > source.value.balance);

function nowLocal(): string {
    const d = new Date();
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
}

watch(open, (v) => {
    if (!v) return;
    error.value = null;
    key = newIdempotencyKey();
    const from = props.from ?? active.value.find((f) => f.kind === 'cash')?.id ?? active.value[0]?.id ?? '';
    Object.assign(form, { from, to: active.value.find((f) => f.id !== from && f.kind === 'bank')?.id ?? '', amount: '', fee: '', transferred_at: nowLocal(), reference: '', note: '' });
});

const digits = (e: Event) => (e.target as HTMLInputElement).value.replace(/\D/g, '').slice(0, 10);

async function save() {
    saving.value = true;
    error.value = null;
    try {
        await api.post(
            '/cash-transfers',
            {
                from: form.from,
                to: form.to,
                amount: form.amount,
                fee: form.fee || 0,
                transferred_at: form.transferred_at ? new Date(form.transferred_at).toISOString() : null,
                reference: form.reference || null,
                note: form.note || null,
            },
            idempotent(key),
        );
        toast.success(`Đã chuyển ${formatMoney(amount.value)}.`);
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
    <Dialog v-model:open="open" title="Chuyển quỹ" description="Chuyển tiền giữa các quỹ của cửa hàng, ví dụ nộp tiền mặt vào ngân hàng. Không tính là thu hay chi." size="lg">
        <form id="transfer-form" class="grid gap-4 sm:grid-cols-[1fr_auto_1fr]" data-testid="transfer-form" @submit.prevent="save">
            <FormField label="Từ quỹ" for="t-from" :error="error?.field('from')">
                <Select id="t-from" v-model="form.from" :options="active.map((f) => ({ value: f.id, label: `${f.name} · ${formatMoney(f.balance)}` }))" data-testid="t-from" />
            </FormField>
            <ArrowRight class="mt-8 hidden size-4 text-muted-foreground sm:block" />
            <FormField label="Sang quỹ" for="t-to" :error="error?.field('to')">
                <Select id="t-to" v-model="form.to" :options="[{ value: '', label: 'Chọn quỹ nhận' }, ...active.filter((f) => f.id !== form.from).map((f) => ({ value: f.id, label: f.name }))]" data-testid="t-to" />
            </FormField>
            <FormField label="Số tiền" for="t-amount" class="sm:col-span-3" :error="error?.field('amount')" :hint="amount ? amountInWords(amount) : undefined">
                <Input id="t-amount" :model-value="form.amount ? formatNumber(form.amount) : ''" inputmode="numeric" required class="text-right text-base font-semibold tabular-nums" placeholder="0" data-testid="t-amount" @input="form.amount = digits($event)" />
            </FormField>
            <p v-if="short" class="-mt-2 text-xs text-amber-700 sm:col-span-3" data-testid="t-short">Số dư {{ source?.name }} trên phần mềm ({{ formatMoney(source?.balance ?? 0) }}) ít hơn số chuyển. Kiểm tra lại số dư đầu của quỹ nếu cần.</p>
            <div class="grid gap-4 sm:col-span-3 sm:grid-cols-2">
                <FormField label="Phí chuyển" for="t-fee" hint="Ghi thành phiếu chi “Phí ngân hàng” của quỹ chuyển." :error="error?.field('fee')">
                    <Input id="t-fee" :model-value="form.fee ? formatNumber(form.fee) : ''" inputmode="numeric" class="text-right tabular-nums" placeholder="0" data-testid="t-fee" @input="form.fee = digits($event)" />
                </FormField>
                <FormField label="Ngày chuyển" for="t-at" :error="error?.field('transferred_at')">
                    <Input id="t-at" v-model="form.transferred_at" type="datetime-local" :max="nowLocal()" />
                </FormField>
                <FormField label="Tham chiếu" for="t-ref" hint="Mã giao dịch ngân hàng, số giấy nộp tiền…"><Input id="t-ref" v-model="form.reference" maxlength="100" /></FormField>
                <FormField label="Diễn giải" for="t-note"><Input id="t-note" v-model="form.note" maxlength="500" placeholder="VD: Nộp tiền bán hàng ngày 04/11" /></FormField>
            </div>
            <p v-if="error && !error.body.errors" class="text-sm text-destructive sm:col-span-3">{{ error.message }}</p>
        </form>
        <template #footer>
            <Button variant="outline" @click="open = false">Hủy</Button>
            <Button type="submit" form="transfer-form" :loading="saving" :disabled="!form.from || !form.to || !amount" data-testid="t-save">Chuyển {{ amount ? formatMoney(amount) : '' }}</Button>
        </template>
    </Dialog>
</template>
