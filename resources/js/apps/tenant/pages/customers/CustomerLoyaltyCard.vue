<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Input from '@/shared/ui/Input.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDate, formatMoney } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';

/** Tích điểm (C5) on the customer page: points, tier and the way to the next one, history, points given by hand. */
const props = defineProps<{ customerId: string }>();
const emit = defineEmits<{ changed: [] }>();
const tenant = useTenantStore();
const toast = useToast();

interface Loyalty {
    enabled: boolean;
    points: number;
    point_value: number;
    tier: { name: string; color: string | null; point_multiplier: string; discount_percent: string } | null;
    spent_12m: string;
    next_tier: { name: string; min_spent: string; missing: string } | null;
    expiring: { points: number; at: string } | null;
    entries: { id: number; kind: string; points: number; note: string | null; order: { id: string; number: string } | null; created_at: string | null }[];
}
const data = ref<Loyalty | null>(null);
const showAll = ref(false);
const adjusting = ref(false);
const form = reactive({ points: '' as string | number, note: '' });
const busy = ref(false);
const error = ref<ApiError | null>(null);

async function load() {
    try {
        data.value = (await api.get(`/customers/${props.customerId}/loyalty`)).data.data;
    } catch {
        data.value = null;
    }
}
onMounted(load);
watch(() => props.customerId, load);

const visible = computed(() => !!data.value && (data.value.enabled || data.value.points !== 0 || data.value.entries.length > 0));
const progress = computed(() => {
    const d = data.value;
    if (!d?.next_tier) return 100;
    const target = Number(d.next_tier.min_spent);
    return target > 0 ? Math.min(100, Math.round((Number(d.spent_12m) / target) * 100)) : 100;
});
const entries = computed(() => (showAll.value ? data.value?.entries : data.value?.entries.slice(0, 5)) ?? []);

async function adjust() {
    busy.value = true;
    error.value = null;
    try {
        data.value = (await api.post(`/customers/${props.customerId}/loyalty/adjust`, { points: Number(form.points), note: form.note })).data.data;
        toast.success('Đã cập nhật điểm.');
        adjusting.value = false;
        form.points = '';
        form.note = '';
        emit('changed');
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Card v-if="visible && data" title="Điểm và hạng" data-testid="customer-loyalty">
        <div class="space-y-3 px-5 py-4 text-sm">
            <div class="flex items-baseline justify-between">
                <span class="text-2xl font-bold tabular-nums" data-testid="loyalty-points">{{ data.points }} điểm</span>
                <span class="text-muted-foreground">= {{ formatMoney(data.points * data.point_value) }}</span>
            </div>
            <div v-if="data.tier" class="flex items-center gap-2">
                <span class="rounded-full px-2 py-0.5 text-xs font-semibold text-white" :style="{ background: data.tier.color || '#64748b' }" data-testid="loyalty-tier">Hạng {{ data.tier.name }}</span>
                <span class="text-xs text-muted-foreground">
                    <template v-if="Number(data.tier.discount_percent) > 0">giảm thêm {{ Number(data.tier.discount_percent) }}% · </template>điểm ×{{ Number(data.tier.point_multiplier) }}
                </span>
            </div>
            <div>
                <p class="text-xs text-muted-foreground">
                    Chi tiêu 12 tháng: <b class="text-foreground">{{ formatMoney(data.spent_12m) }}</b>
                    <template v-if="data.next_tier"> · còn {{ formatMoney(data.next_tier.missing) }} lên {{ data.next_tier.name }}</template>
                </p>
                <div v-if="data.next_tier" class="mt-1.5 h-1.5 rounded-full bg-muted"><div class="h-full rounded-full bg-primary" :style="{ width: `${progress}%` }" /></div>
            </div>
            <p v-if="data.expiring" class="text-xs text-amber-700">{{ data.expiring.points }} điểm hết hạn ngày {{ formatDate(data.expiring.at) }}</p>

            <ul v-if="entries.length" class="divide-y border-t text-xs" data-testid="loyalty-history">
                <li v-for="e in entries" :key="e.id" class="flex items-start justify-between gap-2 py-1.5">
                    <span>
                        <span class="text-muted-foreground">{{ e.created_at ? formatDate(e.created_at) : '' }}</span>
                        {{ e.note }}
                        <RouterLink v-if="e.order" :to="`/orders/${e.order.id}`" class="text-primary hover:underline">{{ e.order.number }}</RouterLink>
                    </span>
                    <b v-if="e.points" :class="e.points > 0 ? 'text-emerald-700' : 'text-destructive'" class="tabular-nums">{{ e.points > 0 ? '+' : '−' }}{{ Math.abs(e.points) }}</b>
                </li>
            </ul>
            <button v-if="(data.entries.length ?? 0) > 5" type="button" class="text-xs text-primary hover:underline" @click="showAll = !showAll">{{ showAll ? 'Thu gọn' : 'Xem tất cả' }}</button>

            <template v-if="tenant.can('promotions.manage')">
                <form v-if="adjusting" class="space-y-2 border-t pt-3" @submit.prevent="adjust">
                    <Input v-model="form.points" type="number" placeholder="Số điểm (âm để trừ)" data-testid="loyalty-adjust-points" />
                    <Input v-model="form.note" placeholder="Lý do, VD: Bù đơn giao trễ" maxlength="200" data-testid="loyalty-adjust-note" />
                    <p v-if="error" class="text-xs text-destructive">{{ error.field('points') ?? error.field('note') ?? error.message }}</p>
                    <div class="flex gap-2">
                        <Button size="sm" type="submit" :loading="busy" data-testid="loyalty-adjust-save">Lưu</Button>
                        <Button size="sm" variant="outline" type="button" @click="adjusting = false">Thôi</Button>
                    </div>
                </form>
                <Button v-else size="sm" variant="outline" data-testid="loyalty-adjust" @click="adjusting = true">Cộng/trừ điểm</Button>
            </template>
        </div>
    </Card>
</template>
