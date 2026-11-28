<script setup lang="ts">
import { computed, ref } from 'vue';
import { Building2, Copy, CreditCard, Hourglass, Info, Landmark, PenLine, PlugZap, X } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import { cn, formatNumber } from '@/shared/lib/utils';
import { orderStatusLabels, orderSteps, providerNames, type EInvoiceOrder } from './einvoice';

/**
 * "Đang đăng ký hóa đơn điện tử" (mẫu 4): the shop's package order, step by step. While it waits for the money it shows
 * the bank transfer with the order's code (and Hủy); later the admin's message and the link where the shop signs.
 */
const props = defineProps<{ order: EInvoiceOrder; canManage: boolean }>();
const emit = defineEmits<{ changed: [] }>();
const toast = useToast();
const busy = ref(false);

const icons = [CreditCard, Building2, PenLine, Landmark, PlugZap];
const provider = computed(() => providerNames[props.order.provider] ?? props.order.provider);
const reached = computed(() => {
    const i = orderSteps.findIndex((s) => s.status === props.order.status);
    return i; // -1 while awaiting payment
});
/** The step being worked on: Shop ký tờ khai is a step the shop still has to do, the others are done once reached. */
const cur = computed(() => (props.order.status === 'waiting_signature' ? reached.value : reached.value + 1));
const money = (v: number | string) => `${formatNumber(v)}đ`;
const when = (iso: string | undefined) => (iso ? new Intl.DateTimeFormat('vi-VN', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).format(new Date(iso)) : '');
const label = (i: number) => (i === 1 ? `${provider.value.split(' ')[0]} tạo tài khoản` : orderSteps[i]!.label);

const headline = computed(() => {
    switch (props.order.status) {
        case 'awaiting_payment':
            return 'Chuyển khoản để bắt đầu đăng ký. Bên mình nhận tiền là gửi hồ sơ ngay.';
        case 'waiting_signature':
            return 'Còn một việc của shop: ký tờ khai đăng ký sử dụng hóa đơn gửi cơ quan thuế.';
        case 'tax_approved':
            return 'Thuế đã chấp nhận. Bên mình đang kết nối tài khoản vào đây.';
        default:
            return 'Bên mình đang làm hồ sơ với nhà cung cấp. Có việc cần shop làm sẽ báo ở đây.';
    }
});

function copy(text: string) {
    void navigator.clipboard?.writeText(text);
    toast.success('Đã chép');
}

async function cancel() {
    if (!window.confirm('Hủy đăng ký gói hóa đơn này? Chỉ hủy được khi chưa chuyển khoản.')) return;
    busy.value = true;
    try {
        await api.post(`/einvoice/orders/${props.order.id}/cancel`);
        emit('changed');
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="rounded-[14px] border border-slate-200 bg-white p-5" data-testid="einvoice-order">
        <div class="flex flex-wrap items-start gap-4">
            <span class="grid size-11 shrink-0 place-items-center rounded-[11px] bg-amber-50 text-amber-600"><Hourglass class="size-5" /></span>
            <div class="min-w-0 flex-1">
                <p class="text-[17px] font-semibold text-slate-900">Đang đăng ký hóa đơn điện tử · {{ provider }}, gói {{ formatNumber(order.invoices) }} hóa đơn</p>
                <p class="mt-0.5 text-[14px] text-slate-500">{{ headline }}</p>
            </div>
            <span :class="cn('rounded-md px-2 py-0.5 text-[13px] font-medium', order.status === 'awaiting_payment' || order.status === 'waiting_signature' ? 'bg-amber-50 text-amber-700' : 'bg-blue-50 text-blue-700')" data-testid="einvoice-order-status">
                {{ orderStatusLabels[order.status] }}
            </span>
        </div>

        <ol class="mt-6 grid grid-cols-5 gap-1">
            <li v-for="(s, i) in orderSteps" :key="s.status" class="relative flex flex-col items-center text-center">
                <span v-if="i > 0" :class="cn('absolute right-1/2 top-5 h-0.5 w-full', i <= cur ? 'bg-emerald-400' : 'bg-slate-200')" />
                <span
                    :class="cn('relative grid size-10 place-items-center rounded-full', i < cur ? 'bg-emerald-500 text-white' : i === cur ? 'bg-blue-600 text-white ring-4 ring-blue-100' : 'bg-slate-100 text-slate-400')"
                >
                    <component :is="icons[i]" class="size-[18px]" />
                </span>
                <span :class="cn('mt-2 text-[13px] font-medium sm:text-[14px]', i < cur ? 'text-slate-900' : i === cur ? 'text-blue-600' : 'text-slate-400')">{{ label(i) }}</span>
                <span class="text-[12px] text-slate-500">{{ i < cur ? when(order.steps[s.status]) : i === cur ? (s.status === 'paid' ? 'Chờ chuyển khoản' : s.status === 'waiting_signature' ? 'Đang chờ shop' : 'Đang làm') : s.hint }}</span>
            </li>
        </ol>

        <div v-if="order.status === 'awaiting_payment' && order.bank" class="mt-6 grid gap-4 rounded-[12px] bg-slate-50 p-4 sm:grid-cols-[1fr_auto]" data-testid="einvoice-order-bank">
            <dl v-if="order.bank.configured" class="grid gap-x-6 gap-y-2 text-[14px] sm:grid-cols-2">
                <div><dt class="text-slate-500">Ngân hàng</dt><dd class="font-medium">{{ order.bank.bank_name }}{{ order.bank.branch ? ` · ${order.bank.branch}` : '' }}</dd></div>
                <div><dt class="text-slate-500">Chủ tài khoản</dt><dd class="font-medium">{{ order.bank.account_name }}</dd></div>
                <div>
                    <dt class="text-slate-500">Số tài khoản</dt>
                    <dd class="flex items-center gap-1.5 font-medium">{{ order.bank.account_number }}<button type="button" class="text-slate-400 hover:text-slate-700" aria-label="Chép số tài khoản" @click="copy(order.bank.account_number ?? '')"><Copy class="size-3.5" /></button></dd>
                </div>
                <div><dt class="text-slate-500">Số tiền</dt><dd class="font-semibold text-blue-600">{{ money(order.amount) }}</dd></div>
                <div class="sm:col-span-2">
                    <dt class="text-slate-500">Nội dung chuyển khoản (bắt buộc)</dt>
                    <dd class="flex items-center gap-1.5 font-mono text-[15px] font-semibold" data-testid="einvoice-order-memo">{{ order.transfer_code }}<button type="button" class="text-slate-400 hover:text-slate-700" aria-label="Chép nội dung" @click="copy(order.transfer_code)"><Copy class="size-3.5" /></button></dd>
                </div>
            </dl>
            <p v-else class="text-[14px] text-slate-600">Liên hệ EzChat Bán hàng để nhận số tài khoản. Nội dung chuyển khoản: <b class="font-mono">{{ order.transfer_code }}</b>, số tiền {{ money(order.amount) }}.</p>
            <div v-if="canManage" class="flex items-end justify-end">
                <Button type="button" variant="ghost" class="text-rose-600" :loading="busy" data-testid="einvoice-order-cancel" @click="cancel"><X /> Hủy đăng ký</Button>
            </div>
        </div>

        <div v-else-if="order.shop_note || order.sign_url" class="mt-6 flex flex-wrap items-center gap-3 rounded-[12px] bg-blue-50/60 p-4" data-testid="einvoice-order-note">
            <Info class="size-5 shrink-0 text-blue-600" />
            <p class="min-w-60 flex-1 whitespace-pre-line text-[14px] text-slate-700">{{ order.shop_note ?? 'Bên mình đã điền sẵn tờ khai 01/ĐKTĐ-HĐĐT. Bấm Ký tờ khai để mở trang của nhà cung cấp và ký bằng chữ ký số.' }}</p>
            <a v-if="order.sign_url" :href="order.sign_url" target="_blank" rel="noopener" class="inline-flex h-10 items-center gap-2 rounded-[10px] bg-blue-600 px-4 text-[14px] font-medium text-white hover:bg-blue-700" data-testid="einvoice-order-sign">
                <PenLine class="size-4" /> Ký tờ khai
            </a>
        </div>
    </div>
</template>
