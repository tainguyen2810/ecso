<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { ChevronDown, ChevronUp, ClipboardCheck, CreditCard, HandHelping, PackageOpen, TriangleAlert, Truck } from 'lucide-vue-next';
import IconBubble from '@/shared/ui/IconBubble.vue';
import { api } from '@/shared/lib/api';
import { toneClasses } from '@/shared/lib/tones';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';

/** "Đơn cần xử lý": one tile per queue that waits for someone. Clicking a tile filters the list below. */
defineProps<{ active: string | null }>();
const emit = defineEmits<{ select: [key: string] }>();

// One blue for every queue; red only for failed deliveries (docs/DESIGN-SYSTEM.md).
const queues = [
    { key: 'to_confirm', label: 'Chờ xác nhận', icon: ClipboardCheck },
    { key: 'payment_due', label: 'Chờ thanh toán', icon: CreditCard },
    { key: 'to_pack', label: 'Chờ đóng gói', icon: PackageOpen },
    { key: 'to_handover', label: 'Chờ bàn giao', icon: HandHelping },
    { key: 'shipping', label: 'Đang giao', icon: Truck },
    { key: 'failed_delivery', label: 'Giao thất bại', icon: TriangleAlert, danger: true },
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
        <div class="flex items-center justify-between border-b px-5 py-3">
            <h2 class="text-[13px] font-bold uppercase tracking-wide">Đơn cần xử lý</h2>
            <button type="button" class="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground" @click="toggle">
                <template v-if="hidden"><ChevronDown class="size-3.5" /> Hiện</template>
                <template v-else><ChevronUp class="size-3.5" /> Thu gọn</template>
            </button>
        </div>
        <div v-if="!hidden" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6">
            <button
                v-for="(q, i) in queues"
                :key="q.key"
                type="button"
                :class="
                    cn(
                        'flex items-center gap-3 border-b px-4 py-3.5 text-left transition-colors hover:bg-muted/40 lg:border-b-0',
                        i > 0 && 'lg:border-l',
                        i % 2 === 1 && 'border-l sm:border-l-0',
                        i % 3 !== 0 && 'sm:border-l',
                        active === q.key && 'bg-blue-50/70 ring-1 ring-inset ring-primary/40 hover:bg-blue-50/70',
                    )
                "
                :data-testid="`pipeline-${q.key}`"
                @click="emit('select', q.key)"
            >
                <IconBubble :icon="q.icon" :tone="q.danger ? 'red' : 'blue'" class="max-sm:hidden" />
                <div class="min-w-0">
                    <p class="truncate text-xs text-muted-foreground">{{ q.label }}</p>
                    <p :class="cn('text-lg font-bold leading-6 tabular-nums', q.danger && (data?.[q.key]?.count ?? 0) > 0 && toneClasses.red.value)">{{ data ? formatNumber(data[q.key]?.count ?? 0) : '…' }}</p>
                    <p class="truncate text-[11px] tabular-nums text-muted-foreground">{{ data ? formatMoney(data[q.key]?.amount ?? '0') : '—' }}</p>
                </div>
            </button>
        </div>
    </section>
</template>
