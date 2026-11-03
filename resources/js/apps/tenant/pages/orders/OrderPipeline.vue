<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { ChevronDown, ChevronUp } from 'lucide-vue-next';
import { api } from '@/shared/lib/api';
import { cn, formatMoney } from '@/shared/lib/utils';

/** "Đơn cần xử lý": one tile per queue that waits for someone. Clicking a tile filters the list below. */
defineProps<{ active: string | null }>();
const emit = defineEmits<{ select: [key: string] }>();

const queues = [
    { key: 'to_confirm', label: 'Chờ xác nhận' },
    { key: 'payment_due', label: 'Chờ thanh toán' },
    { key: 'to_pack', label: 'Chờ đóng gói' },
    { key: 'to_handover', label: 'Chờ bàn giao' },
    { key: 'shipping', label: 'Đang giao' },
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
    <section class="mb-4 rounded-lg border bg-card" data-testid="order-pipeline">
        <div class="flex items-center justify-between px-4 py-2.5">
            <h2 class="text-sm font-semibold">Đơn cần xử lý</h2>
            <button type="button" class="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground" @click="toggle">
                <template v-if="hidden"><ChevronDown class="size-3.5" /> Hiện</template>
                <template v-else><ChevronUp class="size-3.5" /> Thu gọn</template>
            </button>
        </div>
        <div v-if="!hidden" class="grid grid-cols-2 border-t sm:grid-cols-3 lg:grid-cols-6">
            <button
                v-for="q in queues"
                :key="q.key"
                type="button"
                :class="cn('border-b border-r px-4 py-3 text-left transition-colors hover:bg-muted/40 lg:border-b-0', active === q.key && 'bg-primary/5 ring-1 ring-inset ring-primary')"
                :data-testid="`pipeline-${q.key}`"
                @click="emit('select', q.key)"
            >
                <div class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    {{ q.label }}
                    <span :class="cn('rounded-full px-1.5 text-[11px] font-semibold tabular-nums', (data?.[q.key]?.count ?? 0) > 0 ? (q.danger ? 'bg-destructive/10 text-destructive' : 'bg-primary/10 text-primary') : 'bg-muted text-muted-foreground')">{{ data?.[q.key]?.count ?? '…' }}</span>
                </div>
                <div class="mt-1 text-sm font-semibold tabular-nums">{{ data ? formatMoney(data[q.key]?.amount ?? '0') : '—' }}</div>
            </button>
        </div>
    </section>
</template>
