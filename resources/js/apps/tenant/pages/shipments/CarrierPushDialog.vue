<script setup lang="ts">
import { ref, watch } from 'vue';
import Alert from '@/shared/ui/Alert.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import { api, ApiError, idempotent, newIdempotencyKey } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import { formatMoney } from '@/shared/lib/utils';
import CarrierQuotePicker from './CarrierQuotePicker.vue';
import ParcelOptionsPanel from './ParcelOptionsPanel.vue';
import { choiceOf, openLabels, type CarrierChoice, type ParcelOptions, type QuoteResult } from './carriers';
import type { ShipmentData } from './shipments';

/** "Đẩy qua hãng vận chuyển" for one saved order: parcel on the left, prices on the right, push (and print). */
const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ orderId: string; orderCode?: string }>();
const emit = defineEmits<{ pushed: [shipment: ShipmentData] }>();
const toast = useToast();

const opts = ref<ParcelOptions>({ required_note: 'view_no_try', payer: 'shop' });
const quote = ref<QuoteResult | null>(null);
const choice = ref<CarrierChoice | null>(null);
const loading = ref(false);
const quoteError = ref<string | null>(null);
const error = ref<string | null>(null);
const busy = ref(false);
const print = ref(true);
let key = newIdempotencyKey();
let seq = 0;

function payload() {
    return Object.fromEntries(Object.entries(opts.value).filter(([, v]) => v !== null && v !== undefined && v !== ''));
}
async function loadQuote() {
    const mine = ++seq;
    loading.value = true;
    quoteError.value = null;
    try {
        const { data } = await api.post('/shipping/quotes', { order_id: props.orderId, ...payload() });
        if (mine !== seq) return;
        quote.value = data.data;
        // Keep the shop's pick when it is still offered; otherwise the cheapest.
        const still = quote.value!.accounts.some((a) => a.account.id === choice.value?.account_id && a.services.some((s) => s.code === choice.value?.service));
        if (!still) choice.value = quote.value!.cheapest ? choiceOf(quote.value!.cheapest) : null;
        else {
            const row = quote.value!.accounts.find((a) => a.account.id === choice.value!.account_id)!.services.find((s) => s.code === choice.value!.service)!;
            choice.value = { ...choice.value!, fee: row.fee };
        }
    } catch (e) {
        if (mine === seq) quoteError.value = (e as ApiError).message;
    } finally {
        if (mine === seq) loading.value = false;
    }
}
watch(open, (v) => {
    if (!v) return;
    opts.value = { required_note: 'view_no_try', payer: 'shop' };
    quote.value = null;
    choice.value = null;
    error.value = null;
    key = newIdempotencyKey();
    void loadQuote();
}, { immediate: true });
let timer: ReturnType<typeof setTimeout> | undefined;
watch(opts, () => {
    if (!open.value) return;
    clearTimeout(timer);
    timer = setTimeout(loadQuote, 400);
}, { deep: true });

async function push() {
    if (!choice.value) return;
    busy.value = true;
    error.value = null;
    try {
        const { data } = await api.post(`/orders/${props.orderId}/carrier-shipments`, { account_id: choice.value.account_id, service: choice.value.service, service_name: choice.value.service_name, ...payload() }, idempotent(key));
        const s = data.data as ShipmentData;
        toast.success(`Đã đẩy qua ${choice.value.carrier_label}: ${s.tracking_number}`);
        open.value = false;
        emit('pushed', s);
        if (print.value) {
            try {
                await openLabels([s.id]);
            } catch (e) {
                toast.error((e as ApiError).field('ids') ?? (e as ApiError).message);
            }
        }
    } catch (e) {
        const err = e as ApiError;
        error.value = err.field('carrier') ?? err.field('order') ?? err.field('account_id') ?? err.message;
        // A refused parcel keeps its code: pushing again is a new attempt.
        key = newIdempotencyKey();
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open" title="Đẩy qua hãng vận chuyển" :description="orderCode ? `Đơn ${orderCode}` : undefined" size="xl">
        <div class="grid gap-4 md:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
            <ParcelOptionsPanel v-model="opts" :weight-hint="quote?.weight_g" :cod-hint="quote ? String(Number(quote.cod_amount)) : undefined" />
            <CarrierQuotePicker v-model="choice" :quote="quote" :loading="loading" :error="quoteError" />
        </div>
        <Alert v-if="error" tone="red" class="mt-3" data-testid="push-error">{{ error }}</Alert>
        <template #footer>
            <label class="mr-auto flex items-center gap-2 text-sm text-muted-foreground"><input v-model="print" type="checkbox" /> In nhãn của hãng sau khi đẩy</label>
            <Button variant="outline" @click="open = false">Đóng</Button>
            <Button :disabled="!choice || busy || loading" :loading="busy" data-testid="push-confirm" @click="push">
                Đẩy đơn<template v-if="choice"> · {{ choice.carrier_label }} {{ formatMoney(choice.fee) }}</template>
            </Button>
        </template>
    </Dialog>
</template>
