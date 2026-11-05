<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { FileUp } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError, idempotent, newIdempotencyKey } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import { formatMoney } from '@/shared/lib/utils';
import { carriers, codStatuses, type CodLine, type CodReconciliation } from './cod';
import type { Fund } from './voucher';

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [r: CodReconciliation] }>();
const toast = useToast();

const carrier = ref('ghn');
const account = ref('');
const remittedOn = ref('');
const reference = ref('');
const file = ref<File | null>(null);
const funds = ref<Fund[]>([]);
const preview = ref<{ lines: CodLine[]; totals: { cod: string; fee: string; booked: string; transferred: string; matched: number; problems: number } } | null>(null);
const busy = ref(false);
const error = ref<string | null>(null);
let key = newIdempotencyKey();

const today = () => new Date().toLocaleDateString('sv-SE'); // yyyy-mm-dd, local
watch(open, async (v) => {
    if (!v) return;
    carrier.value = 'ghn';
    remittedOn.value = today();
    reference.value = '';
    file.value = null;
    preview.value = null;
    error.value = null;
    key = newIdempotencyKey();
    funds.value = (await api.get('/cash-accounts')).data.data;
    // The carrier pays into the bank: the bank fund is picked unless there is none.
    account.value = (funds.value.find((f) => f.is_active && f.kind === 'bank') ?? funds.value.find((f) => f.is_active))?.id ?? '';
});

const fundOptions = computed(() => funds.value.filter((f) => f.is_active).map((f) => ({ value: f.id, label: f.name })));

async function onFile(e: Event) {
    file.value = (e.target as HTMLInputElement).files?.[0] ?? null;
    preview.value = null;
    error.value = null;
    if (!file.value) return;
    busy.value = true;
    const body = new FormData();
    body.append('file', file.value);
    try {
        preview.value = (await api.post('/cod-reconciliations/preview', body)).data.data;
    } catch (err) {
        const x = err as ApiError;
        error.value = x.field('file') ?? x.message;
    } finally {
        busy.value = false;
    }
}

async function confirm() {
    if (!preview.value) return;
    busy.value = true;
    error.value = null;
    try {
        const { data } = await api.post(
            '/cod-reconciliations',
            {
                carrier: carrier.value,
                account: account.value || null,
                // Today: now. An earlier day: noon of that day.
                remitted_at: remittedOn.value && remittedOn.value !== today() ? new Date(`${remittedOn.value}T12:00:00`).toISOString() : null,
                file_name: file.value?.name ?? null,
                reference: reference.value || null,
                lines: preview.value.lines.map((l) => ({ tracking: l.tracking, cod: Number(l.cod), fee: Number(l.fee) })),
            },
            idempotent(key),
        );
        toast.success(`Đã ghi đối soát ${data.data.number}: ${data.data.matched_count}/${data.data.lines_count} dòng khớp.`);
        open.value = false;
        emit('saved', data.data);
    } catch (err) {
        const x = err as ApiError;
        error.value = x.field('remitted_at') ?? x.message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open" title="Nhập file đối soát COD" description="Tải file đối soát hãng gửi (CSV hoặc Excel). Xem trước từng dòng, rồi mới ghi tiền thu hộ vào đơn và phí của hãng vào sổ quỹ." size="xl">
        <div class="space-y-4 text-sm" data-testid="cod-import-dialog">
            <div class="grid gap-3 sm:grid-cols-4">
                <FormField label="Hãng vận chuyển"><Select v-model="carrier" :options="Object.entries(carriers).map(([value, label]) => ({ value, label }))" data-testid="cod-carrier" /></FormField>
                <FormField label="Tiền về quỹ"><Select v-model="account" :options="fundOptions" data-testid="cod-account" /></FormField>
                <FormField label="Ngày tiền về"><Input v-model="remittedOn" type="date" :max="today()" data-testid="cod-date" /></FormField>
                <FormField label="Số chứng từ (UNC)"><Input v-model="reference" placeholder="Không bắt buộc" /></FormField>
            </div>
            <label class="flex cursor-pointer items-center justify-center gap-2 rounded-md border border-dashed px-4 py-5 text-muted-foreground hover:bg-muted/40">
                <FileUp class="size-5" />
                <span>{{ file ? file.name : 'Chọn file đối soát (.csv, .xlsx)' }}</span>
                <input type="file" accept=".csv,.xlsx" class="hidden" data-testid="cod-file" @change="onFile" />
            </label>
            <p class="text-xs text-muted-foreground">Cần có cột mã vận đơn và cột tiền thu hộ (COD). Cột phí là không bắt buộc. Tên cột theo file của GHN, Viettel Post, SPX, J&amp;T đều đọc được.</p>
            <p v-if="error" class="text-destructive" data-testid="cod-error">{{ error }}</p>

            <template v-if="preview">
                <div class="grid gap-2 sm:grid-cols-4" data-testid="cod-totals">
                    <div class="rounded-md border p-3"><p class="text-xs text-muted-foreground">Tổng thu hộ</p><p class="font-semibold tabular-nums">{{ formatMoney(preview.totals.cod) }}</p></div>
                    <div class="rounded-md border p-3"><p class="text-xs text-muted-foreground">Phí của hãng</p><p class="font-semibold tabular-nums text-destructive">−{{ formatMoney(preview.totals.fee) }}</p></div>
                    <div class="rounded-md border p-3"><p class="text-xs text-muted-foreground">Hãng phải chuyển</p><p class="font-semibold tabular-nums">{{ formatMoney(preview.totals.transferred) }}</p></div>
                    <div class="rounded-md border p-3"><p class="text-xs text-muted-foreground">Sẽ ghi vào đơn</p><p class="font-semibold tabular-nums text-success">{{ formatMoney(preview.totals.booked) }}</p></div>
                </div>
                <p v-if="preview.totals.problems" class="rounded-md bg-warning/15 px-3 py-2 text-amber-700">
                    {{ preview.totals.problems }} dòng lệch tiền hoặc không tìm thấy đơn. Dòng lệch chỉ ghi phần đơn còn phải thu; dòng không thấy đơn không ghi gì. Kiểm tra lại với hãng.
                </p>
                <div class="max-h-[45vh] overflow-auto rounded-md border">
                    <table class="w-full text-sm" data-testid="cod-preview">
                        <thead class="sticky top-0 border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                            <tr><th class="px-3 py-2">Mã vận đơn</th><th class="px-3 py-2">Đơn</th><th class="px-3 py-2 text-right">Còn phải thu</th><th class="px-3 py-2 text-right">Thu hộ</th><th class="px-3 py-2 text-right">Phí</th><th class="px-3 py-2">Kết quả</th></tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="(l, i) in preview.lines" :key="i">
                                <td class="px-3 py-2 font-mono text-xs">{{ l.tracking }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ l.order?.number ?? '—' }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ l.order ? formatMoney(l.order.due ?? 0) : '' }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ formatMoney(l.cod) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ formatMoney(l.fee) }}</td>
                                <td class="px-3 py-2"><Badge :tone="codStatuses[l.status].tone">{{ codStatuses[l.status].label }}</Badge><div v-if="l.note" class="text-xs text-muted-foreground">{{ l.note }}</div></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
        <template #footer>
            <Button variant="outline" @click="open = false">Hủy</Button>
            <Button :disabled="!preview || !preview.lines.length" :loading="busy" data-testid="cod-confirm" @click="confirm">Ghi đối soát</Button>
        </template>
    </Dialog>
</template>
