<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatNumber } from '@/shared/lib/utils';
import { displayDate } from '@/shared/lib/dates';
import { useToast } from '@/shared/composables/useToast';
import { lotLabel } from '../inventory/lots';

/**
 * "Đổi lô" on an order line: the lots the hold took (earliest expiry first) and the other lots of the SKU in the
 * order's warehouse. Staff pick other lots, e.g. the customer asked for a newer one; quantities must add up.
 */
interface Choice {
    lot_id: number;
    lot_no: string;
    expiry_date: string | null;
    days_left: number | null;
    free: number;
    taken: number;
    blocked: boolean;
}
interface Hold {
    reservation_id: number;
    sku: string;
    quantity: number;
    lots: Choice[];
}

const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ orderId: string; itemId: number; title: string }>();
const emit = defineEmits<{ saved: [] }>();
const toast = useToast();

const holds = ref<Hold[] | null>(null);
const picked = ref<Record<number, Record<number, string>>>({});
const busy = ref(false);
const error = ref<string | null>(null);

watch(
    open,
    async (v) => {
        if (!v) return;
        holds.value = null;
        error.value = null;
        const { data } = await api.get(`/orders/${props.orderId}/items/${props.itemId}/lots`);
        holds.value = data.data;
        picked.value = Object.fromEntries((data.data as Hold[]).map((h) => [h.reservation_id, Object.fromEntries(h.lots.map((l) => [l.lot_id, l.taken ? String(l.taken) : '']))]));
    },
    { immediate: true },
);

const sumOf = (h: Hold) => Object.values(picked.value[h.reservation_id] ?? {}).reduce((t, q) => t + (Number(q) || 0), 0);
const valid = computed(() => (holds.value ?? []).every((h) => sumOf(h) === h.quantity && h.lots.every((l) => (Number(picked.value[h.reservation_id]?.[l.lot_id]) || 0) <= l.free)));

async function save() {
    if (!holds.value) return;
    busy.value = true;
    error.value = null;
    try {
        for (const h of holds.value) {
            const lots = h.lots.map((l) => ({ lot_id: l.lot_id, quantity: Number(picked.value[h.reservation_id][l.lot_id]) || 0 })).filter((l) => l.quantity > 0);
            const unchanged = h.lots.every((l) => (Number(picked.value[h.reservation_id][l.lot_id]) || 0) === l.taken);
            if (!unchanged) await api.put(`/orders/${props.orderId}/items/${props.itemId}/lots`, { reservation_id: h.reservation_id, lots });
        }
        toast.success('Đã đổi lô');
        open.value = false;
        emit('saved');
    } catch (e) {
        const err = e as ApiError;
        error.value = err.field('lots') ?? err.message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open" size="lg" :title="`Chọn lô · ${title}`" description="Mặc định lấy lô hết hạn sớm nhất trước (FEFO). Lô hết hạn hoặc đã ngừng bán không chọn được.">
        <div v-if="!holds" class="space-y-2"><Skeleton v-for="i in 3" :key="i" class="h-8" /></div>
        <div v-else class="space-y-4">
            <div v-for="h in holds" :key="h.reservation_id">
                <p class="mb-1.5 text-sm font-medium"><span class="font-mono text-xs">{{ h.sku }}</span> · cần {{ formatNumber(h.quantity) }}</p>
                <table class="w-full text-sm" :data-testid="`lot-choices-${h.reservation_id}`">
                    <thead class="text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <tr><th class="py-1 font-medium">Số lô</th><th class="py-1 font-medium">HSD</th><th class="py-1 text-right font-medium">Lấy được</th><th class="py-1 text-right font-medium">Lấy</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="l in h.lots" :key="l.lot_id" :class="l.blocked && !l.taken && 'text-muted-foreground'">
                            <td class="py-2 font-medium">{{ lotLabel(l.lot_no) }}</td>
                            <td class="py-2 tabular-nums">
                                {{ displayDate(l.expiry_date) || '—' }}
                                <span v-if="l.days_left !== null" :class="cn('ml-1 text-xs', l.blocked ? 'text-destructive' : 'text-muted-foreground')">{{ l.blocked ? '· ngừng bán' : `· còn ${l.days_left} ngày` }}</span>
                            </td>
                            <td class="py-2 text-right tabular-nums text-muted-foreground">{{ formatNumber(l.blocked ? l.taken : l.free) }}</td>
                            <td class="py-2 text-right">
                                <input
                                    v-model="picked[h.reservation_id][l.lot_id]"
                                    type="number"
                                    min="0"
                                    :max="l.blocked ? l.taken : l.free"
                                    :disabled="l.blocked && !l.taken"
                                    :data-testid="`pick-lot-${l.lot_id}`"
                                    class="h-8 w-20 rounded-md border border-input bg-card px-2 text-right tabular-nums disabled:opacity-50"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p :class="cn('mt-1 text-right text-xs', sumOf(h) === h.quantity ? 'text-muted-foreground' : 'font-medium text-destructive')">Đã chọn {{ formatNumber(sumOf(h)) }}/{{ formatNumber(h.quantity) }}</p>
            </div>
            <p v-if="!holds.length" class="text-sm text-muted-foreground">Dòng này không còn giữ hàng theo lô.</p>
            <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
        </div>
        <template #footer>
            <Button variant="outline" @click="open = false">Hủy</Button>
            <Button :loading="busy" :disabled="!valid || !holds?.length" data-testid="save-lots" @click="save">Lưu</Button>
        </template>
    </Dialog>
</template>
