<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { AlertTriangle, Box, ChevronDown, ChevronUp, HandCoins, Loader, PackageOpen, Truck } from 'lucide-vue-next';
import { api } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';

/**
 * "Đơn hàng cần xử lý": one compact column per queue (label + count pill, amount below), like Sapo's list header.
 * Clicking a column filters the list below.
 */
defineProps<{ active: string | null }>();
const emit = defineEmits<{ select: [key: string] }>();

const queues = [
    { key: 'to_confirm', label: 'Chờ xác nhận', icon: Loader, tone: 'bg-blue-50 text-blue-600' },
    { key: 'payment_due', label: 'Còn phải thu', hint: 'Đơn tự tạo, khách chưa trả đủ', icon: HandCoins, tone: 'bg-amber-50 text-amber-600' },
    { key: 'to_pack', label: 'Chờ đóng gói', icon: PackageOpen, tone: 'bg-orange-50 text-orange-600' },
    { key: 'to_handover', label: 'Chờ bàn giao', icon: Box, tone: 'bg-violet-50 text-violet-600' },
    { key: 'shipping', label: 'Đang giao hàng', icon: Truck, tone: 'bg-sky-50 text-sky-600' },
    { key: 'failed_delivery', label: 'Giao thất bại', danger: true, icon: AlertTriangle, tone: 'bg-rose-50 text-rose-600' },
];

const data = ref<Record<string, { count: number; amount: string }> | null>(null);
function storedHidden(): boolean {
    try {
        return localStorage.getItem('ecso.orders.pipeline') === 'hidden';
    } catch {
        return false;
    }
}
const hidden = ref(storedHidden());

async function load() {
    try {
        const res = await api.get('/orders/pipeline');
        data.value = res.data.data;
    } catch {
        data.value = null;
    }
}
onMounted(load);
defineExpose({ load });

function toggle() {
    hidden.value = !hidden.value;
    try {
        localStorage.setItem('ecso.orders.pipeline', hidden.value ? 'hidden' : 'shown');
    } catch {
        // private mode: the choice just isn't remembered
    }
}
</script>

<template>
    <section class="mb-6 rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]" data-testid="order-pipeline">
        <div v-if="hidden" class="flex items-center justify-between px-5 py-3">
            <h2 class="text-[13px] font-semibold text-slate-500">Đơn hàng cần xử lý</h2>
            <button type="button" class="inline-flex items-center gap-1 text-xs font-medium text-blue-700 hover:underline" @click="toggle"><ChevronDown class="size-3.5" /> Hiện</button>
        </div>
        <div v-else class="relative grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6">
            <button type="button" class="absolute right-2 top-2 z-10 rounded p-1 text-slate-300 hover:bg-slate-100 hover:text-slate-500" title="Ẩn đơn hàng cần xử lý" aria-label="Ẩn đơn hàng cần xử lý" @click="toggle"><ChevronUp class="size-3.5" /></button>
            <button
                v-for="(q, i) in queues"
                :key="q.key"
                type="button"
                :class="
                    cn(
                        'relative flex items-center gap-3.5 px-5 py-4 text-left transition-colors hover:bg-slate-50',
                        i > 0 && 'lg:border-l lg:border-slate-100',
                        i === 0 && 'rounded-l-[14px]',
                        i === queues.length - 1 && 'rounded-r-[14px]',
                        active === q.key && 'bg-blue-50/60 ring-1 ring-inset ring-primary/40 hover:bg-blue-50/60',
                    )
                "
                :data-testid="`pipeline-${q.key}`"
                :title="q.hint"
                @click="emit('select', q.key)"
            >
                <span :class="cn('grid size-10 shrink-0 place-items-center rounded-[11px]', q.tone)"><component :is="q.icon" class="size-5" :stroke-width="1.9" /></span>
                <span class="min-w-0">
                    <span class="block truncate text-[13px] text-slate-500">{{ q.label }}</span>
                    <span class="mt-0.5 flex items-baseline gap-1">
                        <span :class="cn('text-[22px] font-bold leading-none tabular-nums', (data?.[q.key]?.count ?? 0) > 0 ? (q.danger ? 'text-rose-600' : 'text-slate-900') : 'text-slate-300')">{{ data ? formatNumber(data[q.key]?.count ?? 0) : '…' }}</span>
                        <span class="text-[12px] text-slate-400">đơn</span>
                    </span>
                    <span class="mt-1 block text-[12px] tabular-nums text-slate-500">{{ data ? formatMoney(data[q.key]?.amount ?? '0') : '—' }}</span>
                </span>
            </button>
        </div>
    </section>
</template>
