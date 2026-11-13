<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Lock, LockOpen } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import { formatDate, formatDateTime, formatMoney } from '@/shared/lib/utils';
import type { Fund } from './voucher';
import DateInput from '@/shared/ui/DateInput.vue';

interface Closing {
    id: string;
    closed_until: string;
    balances: { name: string; balance: number }[];
    total: number;
    note: string | null;
    created_by: string | null;
    created_at: string;
    reopened_at: string | null;
    reopened_by: string | null;
    reopen_reason: string | null;
}

const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ canClose: boolean }>();
const emit = defineEmits<{ changed: [] }>();
const toast = useToast();

const closings = ref<Closing[]>([]);
const current = ref<string | null>(null);
const closedUntil = ref<string | null>(null);
const until = ref('');
const note = ref('');
const preview = ref<{ funds: Fund[]; total: number } | null>(null);
const busy = ref(false);
const error = ref<ApiError | null>(null);
const reopening = ref(false);
const reason = ref('');

const localDate = (d: Date) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const yesterday = () => localDate(new Date(Date.now() - 86400000));
const minDate = computed(() => (closedUntil.value ? localDate(new Date(new Date(closedUntil.value).getTime() + 86400000)) : undefined));

async function load() {
    const { data } = await api.get('/cash-closings');
    closings.value = data.data;
    current.value = data.meta.current;
    closedUntil.value = data.meta.closed_until;
}

watch(open, async (v) => {
    if (!v) return;
    error.value = null;
    note.value = '';
    reason.value = '';
    reopening.value = false;
    until.value = yesterday();
    await load().catch(() => {});
});

watch(until, async (d) => {
    preview.value = null;
    if (!d || !open.value) return;
    try {
        const { data } = await api.get('/cash-accounts', { params: { at: d } });
        preview.value = { funds: (data.data as Fund[]).filter((f) => f.is_active), total: data.meta.total };
    } catch {
        /* the preview is optional */
    }
});

async function close() {
    busy.value = true;
    error.value = null;
    try {
        await api.post('/cash-closings', { closed_until: until.value, note: note.value || null });
        toast.success(`Đã khóa sổ đến hết ngày ${formatDate(until.value)}.`);
        await load();
        emit('changed');
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        busy.value = false;
    }
}

async function reopen() {
    if (!current.value) return;
    busy.value = true;
    error.value = null;
    try {
        await api.post(`/cash-closings/${current.value}/reopen`, { reason: reason.value });
        toast.success('Đã mở khóa sổ.');
        reopening.value = false;
        await load();
        emit('changed');
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open" title="Khóa sổ" description="Khóa sổ đến một ngày thì mọi phiếu thu, chi, thanh toán đơn, trả nhà cung cấp, chuyển quỹ từ ngày đó trở về trước không lập thêm, không hủy, không lùi ngày được nữa. Số dư đã báo cáo vì thế không tự đổi." size="lg">
        <div class="space-y-5" data-testid="closing-dialog">
            <p class="flex items-center gap-2 rounded-md px-3 py-2 text-sm" :class="closedUntil ? 'bg-slate-100' : 'bg-muted/50 text-muted-foreground'">
                <Lock v-if="closedUntil" class="size-4" />
                <LockOpen v-else class="size-4" />
                <template v-if="closedUntil">Sổ đang khóa đến hết ngày <b>{{ formatDate(closedUntil) }}</b>.</template>
                <template v-else>Sổ chưa khóa kỳ nào.</template>
            </p>

            <form v-if="canClose" id="closing-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="close">
                <FormField label="Khóa đến hết ngày" for="cl-until" :error="error?.field('closed_until')">
                    <DateInput id="cl-until" v-model="until" required :max="yesterday()" :min="minDate" data-testid="cl-until" />
                </FormField>
                <FormField label="Ghi chú" for="cl-note"><Input id="cl-note" v-model="note" maxlength="255" placeholder="VD: Chốt sổ tháng 10" /></FormField>
                <div v-if="preview" class="rounded-md border sm:col-span-2" data-testid="cl-preview">
                    <p class="border-b px-3 py-2 text-xs text-muted-foreground">Số dư các quỹ cuối ngày {{ formatDate(until) }}, sẽ được lưu lại khi khóa</p>
                    <ul class="divide-y text-sm">
                        <li v-for="f in preview.funds" :key="f.id" class="flex justify-between px-3 py-1.5"><span>{{ f.name }}</span><span class="tabular-nums">{{ formatMoney(f.balance) }}</span></li>
                        <li class="flex justify-between px-3 py-1.5 font-medium"><span>Tổng</span><span class="tabular-nums">{{ formatMoney(preview.total) }}</span></li>
                    </ul>
                </div>
            </form>

            <div v-if="closings.length">
                <p class="mb-1.5 text-sm font-medium">Lịch sử khóa sổ</p>
                <ul class="divide-y rounded-md border text-sm" data-testid="closing-history">
                    <li v-for="c in closings" :key="c.id" class="px-3 py-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">Đến {{ formatDate(c.closed_until) }}</span>
                            <Badge :tone="c.reopened_at ? 'muted' : c.id === current ? 'info' : 'muted'">{{ c.reopened_at ? 'Đã mở lại' : c.id === current ? 'Đang áp dụng' : 'Kỳ trước' }}</Badge>
                            <span class="tabular-nums text-muted-foreground">Tổng quỹ {{ formatMoney(c.total) }}</span>
                            <span class="ml-auto text-xs text-muted-foreground">{{ c.created_by }} · {{ formatDateTime(c.created_at) }}</span>
                        </div>
                        <p v-if="c.note" class="text-xs text-muted-foreground">{{ c.note }}</p>
                        <p v-if="c.reopened_at" class="text-xs text-muted-foreground">Mở lại bởi {{ c.reopened_by }} lúc {{ formatDateTime(c.reopened_at) }}: {{ c.reopen_reason }}</p>
                    </li>
                </ul>
            </div>

            <form v-if="reopening" class="flex flex-wrap gap-2" @submit.prevent="reopen">
                <Input v-model="reason" required maxlength="255" class="min-w-60 flex-1" placeholder="Lý do mở khóa, VD: sửa phiếu nhập nhầm ngày 28" aria-label="Lý do mở khóa" data-testid="cl-reason" />
                <Button type="submit" variant="destructive" :loading="busy" data-testid="cl-reopen-confirm">Xác nhận mở khóa</Button>
            </form>
            <p v-if="error && !error.field('closed_until')" class="text-sm text-destructive">{{ error.field('reason') ?? error.field('closing') ?? error.message }}</p>
        </div>
        <template #footer>
            <Button v-if="canClose && current && !reopening" variant="outline" class="mr-auto" data-testid="cl-reopen" @click="reopening = true"><LockOpen /> Mở khóa kỳ gần nhất</Button>
            <Button variant="outline" @click="open = false">Đóng</Button>
            <Button v-if="canClose" type="submit" form="closing-form" :loading="busy && !reopening" :disabled="!until" data-testid="cl-save"><Lock /> Khóa sổ</Button>
        </template>
    </Dialog>
</template>
