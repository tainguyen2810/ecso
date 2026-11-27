<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { ArrowRight, CalendarCheck } from 'lucide-vue-next';
import Alert from '@/shared/ui/Alert.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatMoney } from '@/shared/lib/utils';
import { enabledMethods } from '@/shared/lib/paymentMethods';
import { useSettingsStore } from '@/shared/stores/settings';
import { useToast } from '@/shared/composables/useToast';
import { paymentMethods } from '../orders/labels';
import { day, leftText, nextStart, periodEnd, type SubscriptionDetailData } from './subscriptions';

/**
 * Gia hạn: pick a plan (a SKU of the same service), see the new period (from the day after the old end, so no day is
 * lost), take the money. It makes an ordinary completed order, so payment, debt and reports work as for any sale.
 */
const props = defineProps<{ subscriptionId: string | null }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ done: [orderId: string] }>();
const toast = useToast();
const settings = useSettingsStore();

const sub = ref<SubscriptionDetailData | null>(null);
const planId = ref('');
const customStart = ref(false);
const start = ref('');
const price = ref<number | string>('');
const method = ref('bank_transfer');
const paid = ref<number | string>('');
const saving = ref(false);
const error = ref<ApiError | null>(null);

const payOptions = computed(() => [...enabledMethods(Object.entries(paymentMethods).filter(([k]) => k !== 'cod').map(([value, label]) => ({ value, label })), settings.data), { value: 'debt', label: 'Ghi nợ (chưa thu)' }]);
const plan = computed(() => sub.value?.plans.find((p) => p.id === planId.value) ?? null);
const autoStart = computed(() => (sub.value ? nextStart(sub.value) : ''));
const from = computed(() => (customStart.value && start.value ? start.value : autoStart.value));
const to = computed(() => (from.value && plan.value?.period_months ? periodEnd(from.value, plan.value.period_months) : ''));
const continues = computed(() => !customStart.value && !!sub.value?.ends_on && from.value !== '' && from.value > sub.value.ends_on);

watch(
    () => [open.value, props.subscriptionId] as const,
    async ([isOpen, id]) => {
        if (!isOpen || !id) return;
        sub.value = null;
        error.value = null;
        customStart.value = false;
        const [{ data }, s] = await Promise.all([api.get(`/subscriptions/${id}`), settings.load()]);
        sub.value = data.data;
        if (payOptions.value.some((o) => o.value === s.payments.default_online)) method.value = s.payments.default_online;
        pick(sub.value!.variant?.id ?? sub.value!.plans.at(-1)?.id ?? '');
    },
    { immediate: true },
);

function pick(id: string) {
    planId.value = id;
    price.value = Math.round(Number(plan.value?.ref_price ?? 0));
    paid.value = price.value;
}
watch(price, (p) => (paid.value = p));

async function submit() {
    if (!sub.value || !plan.value) return;
    saving.value = true;
    error.value = null;
    try {
        const amount = Number(paid.value) || 0;
        const { data } = await api.post(`/subscriptions/${sub.value.id}/renew`, {
            variant_id: plan.value.id,
            start: customStart.value && start.value ? start.value : null,
            unit_price: Number(price.value) !== Math.round(Number(plan.value.ref_price ?? 0)) ? Number(price.value) || 0 : null,
            payments: method.value !== 'debt' && amount > 0 ? [{ method: method.value, amount }] : [],
        });
        toast.success(`Đã gia hạn ${sub.value.code} đến ${day(to.value)} (đơn ${data.data.external_order_id})`);
        open.value = false;
        emit('done', data.data.id);
    } catch (e) {
        error.value = e as ApiError;
        toast.error(error.value.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open" :title="sub ? `Gia hạn ${sub.code}` : 'Gia hạn'" :description="sub ? `${sub.customer?.name ?? ''} · ${sub.product?.name ?? ''}${sub.label ? ` · ${sub.label}` : ''}` : undefined" size="lg">
        <div v-if="!sub" class="py-10 text-center text-sm text-muted-foreground">Đang tải…</div>
        <div v-else class="space-y-4" data-testid="renew-dialog">
            <Alert v-if="sub.pending_order" tone="amber" class="text-sm" data-testid="renew-pending">
                Đã có đơn gia hạn <RouterLink :to="`/orders/${sub.pending_order.id}`" class="font-medium text-primary hover:underline" @click="open = false">{{ sub.pending_order.number }}</RouterLink> chờ xác nhận. Khách đã trả tiền thì xác nhận đơn đó, không cần tạo đơn mới.
            </Alert>
            <p class="text-sm">
                Hết hạn <b>{{ day(sub.ends_on) }}</b>
                <span :class="cn('ml-1', (sub.days_left ?? 0) < 0 ? 'text-destructive' : (sub.days_left ?? 99) <= 30 ? 'text-amber-600' : 'text-muted-foreground')">({{ leftText(sub.days_left) }})</span>
            </p>
            <div>
                <p class="mb-2 text-sm font-medium">Chọn gói</p>
                <div class="grid gap-2 sm:grid-cols-3" role="radiogroup" aria-label="Gói gia hạn">
                    <button
                        v-for="p in sub.plans"
                        :key="p.id"
                        type="button"
                        role="radio"
                        :aria-checked="planId === p.id"
                        :data-testid="`plan-${p.sku}`"
                        :class="cn('rounded-lg border p-3 text-left text-sm transition-colors hover:border-primary/60', planId === p.id && 'border-primary bg-blue-50/50 ring-2 ring-primary/20')"
                        @click="pick(p.id)"
                    >
                        <p class="font-medium">{{ p.name || `${p.period_months} tháng` }}</p>
                        <p class="mt-0.5 font-semibold tabular-nums text-primary">{{ formatMoney(p.ref_price) }}</p>
                        <p v-if="p.period_months && p.period_months > 1 && Number(p.ref_price)" class="text-xs text-muted-foreground">{{ formatMoney(Math.round(Number(p.ref_price) / p.period_months)) }} / tháng</p>
                    </button>
                </div>
            </div>

            <div class="rounded-lg border bg-slate-50 p-3" data-testid="renew-period">
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <CalendarCheck class="size-4 text-primary" />
                    <span>Kỳ mới</span>
                    <b class="tabular-nums">{{ day(from) }}</b>
                    <ArrowRight class="size-3.5 text-muted-foreground" />
                    <b class="tabular-nums">{{ day(to) }}</b>
                    <span v-if="plan?.period_months" class="text-muted-foreground">({{ plan.period_months }} tháng)</span>
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    <template v-if="continues">Nối tiếp ngày hết hạn cũ: khách gia hạn sớm không mất ngày nào.</template>
                    <template v-else-if="!customStart && sub.ends_on">Đã quá {{ sub.grace_days }} ngày ân hạn nên kỳ mới tính từ hôm nay.</template>
                </p>
                <label class="mt-2 flex items-center gap-2 text-xs">
                    <input v-model="customStart" type="checkbox" class="size-4" data-testid="custom-start" />
                    Chọn ngày bắt đầu khác
                </label>
                <Input v-if="customStart" v-model="start" type="date" class="mt-2 w-44" data-testid="renew-start" />
            </div>

            <div class="grid gap-3 sm:grid-cols-3">
                <FormField label="Giá gia hạn" for="renew-price" :error="error?.field('unit_price')">
                    <Input id="renew-price" v-model="price" type="number" min="0" data-testid="renew-price" />
                </FormField>
                <FormField label="Thu tiền" for="renew-method">
                    <Select id="renew-method" v-model="method" :options="payOptions" data-testid="renew-method" />
                </FormField>
                <FormField v-if="method !== 'debt'" label="Số tiền thu" for="renew-paid" :error="error?.field('payments.0.amount')">
                    <Input id="renew-paid" v-model="paid" type="number" min="0" data-testid="renew-paid" />
                </FormField>
            </div>
            <Alert v-if="method === 'debt' || Number(paid) < Number(price)" tone="amber" class="text-xs">Phần chưa thu {{ formatMoney(Math.max(0, Number(price) - (method === 'debt' ? 0 : Number(paid) || 0))) }} được ghi vào công nợ của khách.</Alert>
            <p v-if="error?.field('subscription') || error?.field('variant_id') || error?.field('customer_id')" class="text-sm text-destructive">{{ error.field('subscription') ?? error.field('variant_id') ?? error.field('customer_id') }}</p>
        </div>
        <template #footer>
            <Button variant="outline" @click="open = false">Huỷ</Button>
            <Button :loading="saving" :disabled="!plan" data-testid="renew-submit" @click="submit">Gia hạn {{ plan ? formatMoney(price) : '' }}</Button>
        </template>
    </Dialog>
</template>
