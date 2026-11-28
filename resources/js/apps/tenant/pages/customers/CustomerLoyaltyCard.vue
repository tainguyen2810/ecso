<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { Crown, Diff, Hourglass, Minus, Plus } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import SegmentedControl from '@/shared/ui/SegmentedControl.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDate, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import { card } from './frame';

/** Tích điểm (C5) on the customer page: points, tier and the way to the next one, history, points given by hand. */
const props = defineProps<{ customerId: string; customerName?: string }>();
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
const mode = ref<'add' | 'sub'>('add');
const form = reactive({ points: '' as string | number, note: '' });
const busy = ref(false);
const error = ref<ApiError | null>(null);
const reasons = ['Bù đơn giao trễ', 'Tặng sinh nhật', 'Khách giới thiệu bạn', 'Sửa nhập sai'];

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
/** A negative number typed in "Cộng" still means taking points away, as before. */
const signed = computed(() => {
    const n = Number(form.points) || 0;
    return mode.value === 'sub' ? -Math.abs(n) : n;
});
const after = computed(() => (data.value?.points ?? 0) + signed.value);

function open() {
    mode.value = 'add';
    form.points = '';
    form.note = '';
    error.value = null;
    adjusting.value = true;
}
async function adjust() {
    busy.value = true;
    error.value = null;
    try {
        data.value = (await api.post(`/customers/${props.customerId}/loyalty/adjust`, { points: signed.value, note: form.note })).data.data;
        toast.success('Đã cập nhật điểm.');
        adjusting.value = false;
        emit('changed');
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section v-if="visible && data" :class="cn(card, 'p-5')" data-testid="customer-loyalty">
        <div class="flex items-center justify-between">
            <h3 class="text-[16px] font-semibold text-slate-900">Điểm và hạng</h3>
            <span v-if="data.tier" class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-[12px] font-semibold text-white" :style="{ background: data.tier.color || '#64748b' }" data-testid="loyalty-tier"><Crown class="size-3" /> Hạng {{ data.tier.name }}</span>
        </div>
        <div class="mt-3 flex items-end gap-2">
            <span class="text-[30px] font-bold leading-none tracking-tight text-violet-600 tabular-nums" data-testid="loyalty-points">{{ formatNumber(data.points) }} điểm</span>
            <span class="mb-0.5 text-[13px] text-slate-500">≈ {{ formatMoney(data.points * data.point_value) }}</span>
        </div>
        <p v-if="data.tier" class="mt-2 text-[12.5px] text-slate-500">
            <template v-if="Number(data.tier.discount_percent) > 0">Giảm thêm {{ Number(data.tier.discount_percent) }}% · </template>điểm ×{{ Number(data.tier.point_multiplier) }}
        </p>
        <div class="mt-3">
            <div class="flex justify-between text-[12.5px] text-slate-500">
                <span>Chi tiêu 12 tháng <b class="text-slate-800">{{ formatMoney(data.spent_12m) }}</b></span>
                <span v-if="data.next_tier">{{ data.next_tier.name }} {{ formatMoney(data.next_tier.min_spent) }}</span>
            </div>
            <div v-if="data.next_tier" class="mt-1.5 h-2 rounded-full bg-slate-100"><div class="h-full rounded-full bg-gradient-to-r from-blue-500 to-violet-500" :style="{ width: `${progress}%` }" /></div>
            <p v-if="data.next_tier" class="mt-1.5 text-[12.5px] text-slate-500">Còn {{ formatMoney(data.next_tier.missing) }} để lên <b class="text-slate-700">{{ data.next_tier.name }}</b></p>
        </div>
        <p v-if="data.expiring" class="mt-3 flex items-center gap-1.5 rounded-[10px] bg-amber-50 px-3 py-2 text-[13px] text-amber-800"><Hourglass class="size-3.5" /> {{ formatNumber(data.expiring.points) }} điểm hết hạn ngày {{ formatDate(data.expiring.at) }}</p>

        <ul v-if="entries.length" class="mt-3 divide-y divide-slate-100 text-[13px]" data-testid="loyalty-history">
            <li v-for="e in entries" :key="e.id" class="flex items-start justify-between gap-2 py-2">
                <span class="text-slate-600">
                    <span class="text-slate-400">{{ e.created_at ? formatDate(e.created_at) : '' }}</span>
                    {{ e.note }}
                    <RouterLink v-if="e.order" :to="`/orders/${e.order.id}`" class="text-blue-600 hover:underline">{{ e.order.number }}</RouterLink>
                </span>
                <b v-if="e.points" :class="e.points > 0 ? 'text-emerald-600' : 'text-red-500'" class="tabular-nums">{{ e.points > 0 ? '+' : '−' }}{{ formatNumber(Math.abs(e.points)) }}</b>
            </li>
        </ul>
        <button v-if="(data.entries.length ?? 0) > 5" type="button" class="mt-1 text-[13px] text-blue-600 hover:underline" @click="showAll = !showAll">{{ showAll ? 'Thu gọn' : 'Xem tất cả' }}</button>

        <button
            v-if="tenant.can('promotions.manage')"
            type="button"
            class="mt-4 flex h-10 w-full items-center justify-center gap-2 rounded-[10px] border border-slate-200 text-[14px] font-medium text-slate-700 hover:border-blue-300 hover:text-blue-700"
            data-testid="loyalty-adjust"
            @click="open"
        >
            <Diff class="size-4" /> Cộng/trừ điểm
        </button>

        <Dialog v-model:open="adjusting" title="Cộng/trừ điểm" description="Ghi vào lịch sử điểm của khách, có tên người thực hiện.">
            <form id="loyalty-adjust-form" class="space-y-4" @submit.prevent="adjust">
                <div class="flex items-center gap-3 rounded-[12px] bg-slate-50 px-4 py-3">
                    <span class="grid size-10 place-items-center rounded-full bg-violet-100 text-violet-700"><Crown class="size-4" /></span>
                    <div>
                        <p class="text-[14px] font-semibold text-slate-900">{{ customerName ?? 'Khách hàng' }}</p>
                        <p class="text-[12.5px] text-slate-500">Đang có {{ formatNumber(data.points) }} điểm<template v-if="data.tier"> · Hạng {{ data.tier.name }}</template></p>
                    </div>
                </div>
                <SegmentedControl
                    v-model="mode"
                    full
                    :options="[
                        { value: 'add', label: 'Cộng điểm', icon: Plus },
                        { value: 'sub', label: 'Trừ điểm', icon: Minus },
                    ]"
                    data-testid="loyalty-adjust-mode"
                />
                <FormField label="Số điểm" for="la-points" required :error="error?.field('points')">
                    <Input id="la-points" v-model="form.points" type="number" inputmode="numeric" suffix="điểm" data-testid="loyalty-adjust-points" />
                </FormField>
                <FormField label="Lý do" for="la-note" required :error="error?.field('note') ?? (error && !error.field('points') ? error.message : undefined)">
                    <Input id="la-note" v-model="form.note" maxlength="200" placeholder="VD: Bù đơn giao trễ" data-testid="loyalty-adjust-note" />
                </FormField>
                <div class="-mt-2 flex flex-wrap gap-1.5">
                    <button v-for="r in reasons" :key="r" type="button" :class="cn('rounded-full border px-2.5 py-1 text-[12.5px]', form.note === r ? 'border-blue-300 bg-blue-50 text-blue-700' : 'border-slate-200 text-slate-600 hover:border-slate-300')" @click="form.note = r">{{ r }}</button>
                </div>
                <div v-if="Number(form.points)" :class="cn('flex justify-between rounded-[12px] border px-4 py-3 text-[14px]', after < 0 ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-800')">
                    <span>Sau khi lưu</span><b class="tabular-nums">{{ formatNumber(after) }} điểm</b>
                </div>
            </form>
            <template #footer>
                <Button variant="outline" @click="adjusting = false">Hủy</Button>
                <Button type="submit" form="loyalty-adjust-form" :loading="busy" :disabled="!Number(form.points) || !form.note.trim()" data-testid="loyalty-adjust-save">
                    {{ signed < 0 ? `Trừ ${formatNumber(Math.abs(signed))} điểm` : signed > 0 ? `Cộng ${formatNumber(signed)} điểm` : 'Lưu' }}
                </Button>
            </template>
        </Dialog>
    </section>
</template>
