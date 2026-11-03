<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { ChevronDown, ChevronUp } from 'lucide-vue-next';
import { api } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';

/**
 * "Đơn hàng cần xử lý": one compact column per queue (label + count pill, amount below), like Sapo's list header.
 * Clicking a column filters the list below.
 */
defineProps<{ active: string | null }>();
const emit = defineEmits<{ select: [key: string] }>();

const queues = [
    { key: 'to_confirm', label: 'Chờ xác nhận' },
    { key: 'payment_due', label: 'Chờ thanh toán' },
    { key: 'to_pack', label: 'Chờ đóng gói' },
    { key: 'to_handover', label: 'Chờ bàn giao' },
    { key: 'shipping', label: 'Đang giao hàng' },
    { key: 'failed_delivery', label: 'Giao thất bại', danger: true },
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
    <section class="mb-5 rounded-lg border bg-card shadow-xs" data-testid="order-pipeline">
        <div class="flex items-center justify-between px-4 pt-3" :class="hidden && 'pb-3'">
            <h2 class="text-[13px] font-bold uppercase tracking-wide">Đơn hàng cần xử lý</h2>
            <button type="button" class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline" @click="toggle">
                <template v-if="hidden"><ChevronDown class="size-3.5" /> Hiện đơn hàng cần xử lý</template>
                <template v-else><ChevronUp class="size-3.5" /> Ẩn đơn hàng cần xử lý</template>
            </button>
        </div>
        <div v-if="!hidden" class="grid grid-cols-2 px-1 py-2 sm:grid-cols-3 lg:grid-cols-6">
            <button
                v-for="(q, i) in queues"
                :key="q.key"
                type="button"
                :class="
                    cn(
                        'relative rounded-md px-3 py-2 text-left transition-colors hover:bg-muted/50',
                        i > 0 && 'lg:before:absolute lg:before:inset-y-2 lg:before:left-0 lg:before:w-px lg:before:bg-border',
                        active === q.key && 'bg-blue-50/70 ring-1 ring-inset ring-primary/40 hover:bg-blue-50/70',
                    )
                "
                :data-testid="`pipeline-${q.key}`"
                @click="emit('select', q.key)"
            >
                <div class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    {{ q.label }}
                    <span :class="cn('text-xs font-semibold tabular-nums', (data?.[q.key]?.count ?? 0) > 0 ? (q.danger ? 'text-rose-600' : 'text-primary') : 'text-muted-foreground')">{{ data ? formatNumber(data[q.key]?.count ?? 0) : '…' }}</span>
                </div>
                <div class="mt-0.5 text-sm font-semibold tabular-nums">{{ data ? formatMoney(data[q.key]?.amount ?? '0') : '—' }}</div>
            </button>
        </div>
    </section>
</template>
