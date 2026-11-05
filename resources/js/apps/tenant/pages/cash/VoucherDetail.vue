<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { CheckCircle2, ExternalLink, FileText, Paperclip, Printer, Trash2, XCircle } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import Input from '@/shared/ui/Input.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import { formatDateTime, formatMoney } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { paymentMethods } from '../orders/labels';
import { amountInWords, directionLabels, documentLink, partyKinds, printVoucher, sourceLabels, voucherCode, voucherStatuses, type VoucherRow } from './voucher';

const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ voucher: VoucherRow | null }>();
const emit = defineEmits<{ changed: [voucher: VoucherRow] }>();
const tenant = useTenantStore();
const toast = useToast();

/** Voiding, or refusing a payment waiting for approval: both ask for a reason. */
const voiding = ref<false | 'void' | 'reject'>(false);
const reason = ref('');
const busy = ref(false);
const error = ref<ApiError | null>(null);
watch(open, () => ((voiding.value = false), (reason.value = ''), (error.value = null)));

const v = computed(() => props.voucher);
const isTransfer = computed(() => v.value?.source === 'transfer');
const pending = computed(() => v.value?.status === 'pending');
const canVoid = computed(() => !!v.value && !v.value.voided_at && !pending.value && (isTransfer.value ? tenant.can('finance.accounts') : v.value.source === 'voucher' && tenant.can('finance.vouchers.void')));
const canApprove = computed(() => pending.value && tenant.can('finance.approve'));
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
        const url = isTransfer.value ? `/cash-transfers/${v.value.id}/void` : `/cash-vouchers/${v.value.id}/${voiding.value === 'reject' ? 'reject' : 'void'}`;
        const { data } = await api.post(url, { reason: reason.value });
        toast.success(voiding.value === 'reject' ? `Đã từ chối ${data.data.number}.` : data.data.status === 'reversed' ? `Đã hủy ${data.data.number}, ghi đảo vào hôm nay.` : `Đã hủy ${data.data.number}.`);
        emit('changed', data.data);
        voiding.value = false;
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        busy.value = false;
    }
}

/** Files kept with a voucher (invoice photo, transfer slip). Images are fetched with the tenant header and shown as thumbnails. */
interface Attachment { id: string; name: string; mime: string; size: number; is_image: boolean; uploaded_by: string | null; created_at: string | null }
const attachments = ref<Attachment[]>([]);
const thumbs = ref<Record<string, string>>({});
const uploading = ref(false);
const attachError = ref<string | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
function clearThumbs() {
    Object.values(thumbs.value).forEach((u) => URL.revokeObjectURL(u));
    thumbs.value = {};
}
async function showAttachments(list: Attachment[]) {
    attachments.value = list;
    for (const a of list.filter((x) => x.is_image && !thumbs.value[x.id])) {
        const blob = (await api.get(`/cash-voucher-attachments/${a.id}`, { responseType: 'blob' }).catch(() => null))?.data;
        if (blob) thumbs.value = { ...thumbs.value, [a.id]: URL.createObjectURL(blob as Blob) };
    }
}
watch(
    () => [open.value, props.voucher?.id],
    async () => {
        clearThumbs();
        attachments.value = [];
        attachError.value = null;
        if (open.value && props.voucher?.source === 'voucher') showAttachments((await api.get(`/cash-vouchers/${props.voucher.id}/attachments`).catch(() => null))?.data.data ?? []);
    },
    { immediate: true },
);
onBeforeUnmount(clearThumbs);
async function upload(e: Event) {
    const input = e.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    input.value = '';
    if (!files.length || !v.value) return;
    uploading.value = true;
    attachError.value = null;
    try {
        for (const file of files) {
            const body = new FormData();
            body.append('file', file);
            await showAttachments((await api.post(`/cash-vouchers/${v.value.id}/attachments`, body)).data.data);
        }
        toast.success('Đã đính kèm chứng từ.');
    } catch (err) {
        attachError.value = (err as ApiError).field?.('file') ?? (err as ApiError).message;
    } finally {
        uploading.value = false;
    }
}
async function removeAttachment(a: Attachment) {
    attachError.value = null;
    try {
        showAttachments((await api.delete(`/cash-voucher-attachments/${a.id}`)).data.data);
    } catch (err) {
        attachError.value = (err as ApiError).message;
    }
}
const fileSize = (n: number) => (n >= 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`);

async function approve() {
    if (!v.value) return;
    busy.value = true;
    error.value = null;
    try {
        const { data } = await api.post(`/cash-vouchers/${v.value.id}/approve`);
        toast.success(`Đã duyệt ${data.data.number}.`);
        emit('changed', data.data);
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
                <Badge :tone="voucherStatuses[v.status]?.tone ?? 'success'" data-testid="voucher-status">{{ voucherStatuses[v.status]?.label ?? 'Hoàn thành' }}</Badge>
            </div>
            <p v-if="pending" class="mb-3 rounded-md bg-warning/15 px-3 py-2 text-sm text-amber-700">Phiếu chi lớn đang chờ duyệt. Chưa trừ quỹ, chưa tính vào báo cáo cho đến khi được duyệt.</p>
            <p v-if="v.voided_at" class="mb-3 rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
                {{ v.status === 'rejected' ? 'Từ chối' : 'Hủy' }} lúc {{ formatDateTime(v.voided_at) }}: {{ v.void_reason }}
                <span v-if="v.status === 'reversed'" class="block text-xs">Ngày của phiếu đã khóa sổ: phiếu vẫn tính ở ngày cũ, khoản ngược lại được ghi vào ngày hủy.</span>
            </p>
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
            <section v-if="v.source === 'voucher'" class="mt-4 rounded-lg border p-3" data-testid="voucher-attachments">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <h3 class="flex items-center gap-1.5 text-sm font-medium"><Paperclip class="size-4 text-muted-foreground" /> Chứng từ đính kèm</h3>
                    <template v-if="tenant.can('finance.vouchers')">
                        <input ref="fileInput" type="file" class="hidden" accept="image/*,application/pdf" multiple data-testid="attachment-input" @change="upload" />
                        <Button variant="outline" size="sm" :loading="uploading" data-testid="attachment-add" @click="fileInput?.click()">Thêm ảnh, PDF</Button>
                    </template>
                </div>
                <p v-if="!attachments.length" class="text-xs text-muted-foreground">Chưa có. Đính kèm ảnh hóa đơn, ủy nhiệm chi (ảnh hoặc PDF, tối đa 5 MB mỗi file).</p>
                <ul v-else class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    <li v-for="a in attachments" :key="a.id" class="group relative overflow-hidden rounded-md border bg-slate-50" data-testid="attachment-item">
                        <button type="button" class="block w-full text-left" :title="a.name" @click="downloadFile(`/cash-voucher-attachments/${a.id}`, a.name)">
                            <img v-if="thumbs[a.id]" :src="thumbs[a.id]" :alt="a.name" class="h-24 w-full object-cover" />
                            <span v-else class="grid h-24 place-items-center text-muted-foreground"><FileText class="size-8" /></span>
                            <span class="block truncate px-2 pt-1 text-xs">{{ a.name }}</span>
                            <span class="block px-2 pb-1 text-[11px] text-muted-foreground">{{ fileSize(a.size) }}<template v-if="a.uploaded_by"> · {{ a.uploaded_by }}</template></span>
                        </button>
                        <button v-if="tenant.can('finance.vouchers')" type="button" class="absolute right-1 top-1 rounded bg-white/90 p-1 text-destructive opacity-0 shadow-sm transition group-hover:opacity-100" title="Gỡ file" @click="removeAttachment(a)"><Trash2 class="size-3.5" /></button>
                    </li>
                </ul>
                <p v-if="attachError" class="mt-2 text-sm text-destructive">{{ attachError }}</p>
            </section>
            <p v-if="isTransfer && !v.voided_at" class="mt-3 text-xs text-muted-foreground">Hủy phiếu chuyển quỹ sẽ hủy luôn phiếu chi phí chuyển đi kèm.</p>
            <p v-else-if="v.source === 'voucher_reversal'" class="mt-3 text-xs text-muted-foreground">Dòng ghi đảo khi hủy phiếu {{ v.document?.number }} của kỳ đã khóa sổ.</p>
            <p v-else-if="v.source !== 'voucher' && !v.voided_at" class="mt-3 text-xs text-muted-foreground">Phiếu tự động không sửa, hủy ở đây. Mở chứng từ gốc để hủy khoản thanh toán.</p>
            <form v-if="voiding" class="mt-4 flex flex-wrap gap-2" @submit.prevent="doVoid">
                <Input v-model="reason" required maxlength="255" class="min-w-60 flex-1" :placeholder="voiding === 'reject' ? 'Lý do từ chối, VD: chưa có hóa đơn' : 'Lý do hủy, VD: nhập nhầm số tiền'" aria-label="Lý do" data-testid="void-reason" />
                <Button type="submit" variant="destructive" :loading="busy" data-testid="void-confirm">{{ voiding === 'reject' ? 'Xác nhận từ chối' : 'Xác nhận hủy' }}</Button>
            </form>
            <p v-if="error" class="mt-2 text-sm text-destructive">{{ error.field('reason') ?? error.message }}</p>
        </div>
        <template #footer>
            <template v-if="canApprove && !voiding">
                <Button variant="outline" class="mr-auto text-destructive" data-testid="reject-voucher" @click="voiding = 'reject'"><XCircle /> Từ chối</Button>
                <Button :loading="busy" data-testid="approve-voucher" @click="approve"><CheckCircle2 /> Duyệt chi</Button>
            </template>
            <Button v-if="canVoid && !voiding" variant="outline" class="mr-auto text-destructive" data-testid="void-voucher" @click="voiding = 'void'"><XCircle /> {{ isTransfer ? 'Hủy chuyển quỹ' : 'Hủy phiếu' }}</Button>
            <Button v-if="v" variant="outline" data-testid="print-voucher" @click="printVoucher(v, tenant.current?.name ?? '')"><Printer /> In phiếu</Button>
            <Button @click="open = false">Đóng</Button>
        </template>
    </Dialog>
</template>
