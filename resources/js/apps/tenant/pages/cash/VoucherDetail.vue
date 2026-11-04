<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { ExternalLink, Printer, XCircle } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import Input from '@/shared/ui/Input.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import { formatDateTime, formatMoney } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { paymentMethods } from '../orders/labels';
import { amountInWords, directionLabels, documentLink, partyKinds, printVoucher, sourceLabels, voucherCode, type VoucherRow } from './voucher';

const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ voucher: VoucherRow | null }>();
const emit = defineEmits<{ changed: [voucher: VoucherRow] }>();
const tenant = useTenantStore();
const toast = useToast();

const voiding = ref(false);
const reason = ref('');
const busy = ref(false);
const error = ref<ApiError | null>(null);
watch(open, () => ((voiding.value = false), (reason.value = ''), (error.value = null)));

const v = computed(() => props.voucher);
const isTransfer = computed(() => v.value?.source === 'transfer');
const canVoid = computed(() => !!v.value && !v.value.voided_at && (isTransfer.value ? tenant.can('finance.accounts') : v.value.source === 'voucher' && tenant.can('finance.vouchers')));
/** Sign and colour of the amount: a transfer in the list of every fund is neither money in nor out. */
const tone = computed(() => (v.value?.direction === 'receipt' ? { sign: '+', cls: 'text-success' } : v.value?.direction === 'payment' ? { sign: '−', cls: 'text-destructive' } : { sign: '', cls: '' }));
const rows = computed(() => {
    const x = v.value;
    if (!x) return [];
    if (x.source === 'transfer') {
        const [from, to] = x.direction === 'receipt' ? [x.counter_account, x.account?.name] : [x.account?.name, x.counter_account];
        return [
            ['Từ quỹ', from],
            ['Sang quỹ', to],
            ['Ngày chuyển', formatDateTime(x.paid_at)],
            ['Tham chiếu', x.reference],
            ['Diễn giải', x.note],
            ['Kết quả kinh doanh', 'Không tính (tiền chỉ đổi chỗ giữa các quỹ). Phí chuyển, nếu có, là phiếu chi riêng.'],
            ['Người lập', x.created_by],
            ['Ngày lập', formatDateTime(x.created_at)],
        ].filter(([, value]) => value);
    }
    return [
        [x.direction === 'receipt' ? 'Người nộp' : 'Người nhận', `${x.party.name} · ${partyKinds[x.party.kind]}`],
        ['Loại phiếu', x.type.name],
        ['Hình thức', paymentMethods[x.method] ?? x.method],
        [x.direction === 'receipt' ? 'Vào quỹ' : 'Chi từ quỹ', x.account?.name],
        ['Ngày ghi nhận', formatDateTime(x.paid_at)],
        ['Tham chiếu', x.reference],
        ['Diễn giải', x.note],
        ['Kết quả kinh doanh', x.source === 'voucher' ? (x.counts_in_pnl ? 'Có tính' : 'Không tính') : 'Đã tính trong doanh thu, giá vốn'],
        ['Nguồn', sourceLabels[x.source]],
        ['Người lập', x.created_by],
        ['Ngày lập', formatDateTime(x.created_at)],
    ].filter(([, value]) => value);
});

async function doVoid() {
    if (!v.value) return;
    busy.value = true;
    error.value = null;
    try {
        const url = isTransfer.value ? `/cash-transfers/${v.value.id}/void` : `/cash-vouchers/${v.value.id}/void`;
        const { data } = await api.post(url, { reason: reason.value });
        toast.success(`Đã hủy ${data.data.number}.`);
        emit('changed', data.data);
        voiding.value = false;
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open" :title="v ? `${isTransfer ? 'Chuyển quỹ' : directionLabels[v.direction]} ${voucherCode(v)}` : ''" size="lg">
        <div v-if="v" data-testid="voucher-detail">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-3xl font-semibold tabular-nums" :class="tone.cls">{{ tone.sign }}{{ formatMoney(v.amount) }}</p>
                    <p class="text-xs italic text-muted-foreground">{{ amountInWords(v.amount) }}</p>
                </div>
                <Badge :tone="v.voided_at ? 'danger' : 'success'">{{ v.voided_at ? 'Đã hủy' : 'Hoàn thành' }}</Badge>
            </div>
            <p v-if="v.voided_at" class="mb-3 rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">Hủy lúc {{ formatDateTime(v.voided_at) }}: {{ v.void_reason }}</p>
            <dl class="divide-y text-sm">
                <div v-for="[k, value] in rows" :key="k ?? ''" class="grid grid-cols-[9rem_1fr] gap-2 py-2">
                    <dt class="text-muted-foreground">{{ k }}</dt>
                    <dd>{{ value }}</dd>
                </div>
                <div v-if="v.document && documentLink(v)" class="grid grid-cols-[9rem_1fr] gap-2 py-2">
                    <dt class="text-muted-foreground">Chứng từ gốc</dt>
                    <dd><RouterLink :to="documentLink(v)!" class="inline-flex items-center gap-1 font-mono text-primary hover:underline" @click="open = false">{{ v.document.number }} <ExternalLink class="size-3.5" /></RouterLink></dd>
                </div>
            </dl>
            <p v-if="isTransfer && !v.voided_at" class="mt-3 text-xs text-muted-foreground">Hủy phiếu chuyển quỹ sẽ hủy luôn phiếu chi phí chuyển đi kèm.</p>
            <p v-else-if="v.source !== 'voucher' && !v.voided_at" class="mt-3 text-xs text-muted-foreground">Phiếu tự động không sửa, hủy ở đây. Mở chứng từ gốc để hủy khoản thanh toán.</p>
            <form v-if="voiding" class="mt-4 flex flex-wrap gap-2" @submit.prevent="doVoid">
                <Input v-model="reason" required maxlength="255" class="min-w-60 flex-1" placeholder="Lý do hủy, VD: nhập nhầm số tiền" aria-label="Lý do hủy" data-testid="void-reason" />
                <Button type="submit" variant="destructive" :loading="busy" data-testid="void-confirm">Xác nhận hủy</Button>
            </form>
            <p v-if="error" class="mt-2 text-sm text-destructive">{{ error.field('reason') ?? error.message }}</p>
        </div>
        <template #footer>
            <Button v-if="canVoid && !voiding" variant="outline" class="mr-auto text-destructive" data-testid="void-voucher" @click="voiding = true"><XCircle /> {{ isTransfer ? 'Hủy chuyển quỹ' : 'Hủy phiếu' }}</Button>
            <Button v-if="v" variant="outline" data-testid="print-voucher" @click="printVoucher(v, tenant.current?.name ?? '')"><Printer /> In phiếu</Button>
            <Button @click="open = false">Đóng</Button>
        </template>
    </Dialog>
</template>
