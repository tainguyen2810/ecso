<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { CircleCheck, Info, TriangleAlert } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import DateInput from '@/shared/ui/DateInput.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import FormField from '@/shared/ui/FormField.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import type { MarketplaceAccountData } from '@/shared/types';

/** "Lấy lại đơn theo ngày": picks a past range (≤ 92 days); the server splits it into 15-day windows run in the background. */
const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ account: MarketplaceAccountData }>();
const emit = defineEmits<{ progress: []; done: [] }>();
const toast = useToast();

const DAY = 86_400_000;
const MAX_DAYS = 92;
const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const daysAgo = (n: number) => ymd(new Date(Date.now() - n * DAY));
const today = daysAgo(0);

const from = ref<string | null>(daysAgo(6));
const to = ref<string | null>(today);
const withStock = ref(false);
const starting = ref(false);
const progress = ref<{ windows: number; done: number; created: number; updated: number; failed: number; error: string | null } | null>(null);
let timer: number | undefined;
let startedAt = 0;

const quick = computed(() => {
    const now = new Date();
    const lastMonthStart = new Date(now.getFullYear(), now.getMonth() - 1, 1);
    const lastMonthEnd = new Date(now.getFullYear(), now.getMonth(), 0);
    return [
        { label: 'Hôm qua', from: daysAgo(1), to: daysAgo(1) },
        { label: '7 ngày', from: daysAgo(6), to: today },
        { label: 'Tháng trước', from: ymd(lastMonthStart), to: ymd(lastMonthEnd) },
        { label: '30 ngày', from: daysAgo(29), to: today },
        { label: '90 ngày', from: daysAgo(89), to: today },
    ];
});
const days = computed(() => (from.value && to.value ? Math.round((new Date(to.value).getTime() - new Date(from.value).getTime()) / DAY) + 1 : 0));
const windows = computed(() => Math.max(1, Math.ceil(days.value / 15)));
const invalid = computed(() => {
    if (!from.value || !to.value) return 'Chọn đủ Từ ngày và Đến ngày.';
    if (from.value > to.value) return 'Từ ngày phải trước Đến ngày.';
    if (days.value > MAX_DAYS) return `Mỗi lần lấy tối đa ${MAX_DAYS} ngày.`;
    return null;
});
const running = computed(() => progress.value !== null && progress.value.done < progress.value.windows && !progress.value.error);

async function poll() {
    const { data } = await api.get('/sync-runs', { params: { account: props.account.id } });
    const rows = (data.data as { trigger: string; status: string; started_at: string; counts: Record<string, number>; error: string | null }[])
        .filter((r) => r.trigger === 'backfill' && new Date(r.started_at).getTime() >= startedAt);
    const p = progress.value!;
    const finished = rows.filter((r) => r.status !== 'running');
    p.done = finished.length;
    p.created = rows.reduce((s, r) => s + (r.counts.created ?? 0), 0);
    p.updated = rows.reduce((s, r) => s + (r.counts.updated ?? 0) + (r.counts.unchanged ?? 0), 0);
    p.failed = rows.reduce((s, r) => s + (r.counts.failed ?? 0), 0);
    p.error = finished.find((r) => r.status === 'failed')?.error ?? null;
    emit('progress');
    if (!running.value) {
        stop();
        emit('done');
    }
}
function stop() {
    if (timer) window.clearInterval(timer);
    timer = undefined;
}
onBeforeUnmount(stop);
watch(open, (v) => {
    if (!v && !running.value) progress.value = null;
});

async function start() {
    if (invalid.value) return;
    starting.value = true;
    try {
        startedAt = Date.now() - 5000;
        const { data } = await api.post(`/marketplace-accounts/${props.account.id}/backfill-orders`, { from: from.value, to: to.value, with_stock: withStock.value });
        progress.value = { windows: data.data.windows, done: 0, created: 0, updated: 0, failed: 0, error: null };
        stop();
        timer = window.setInterval(() => poll().catch(() => {}), 3000);
        poll().catch(() => {});
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        starting.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open" title="Lấy lại đơn theo ngày" :description="`${account.shop_name} · ${account.marketplace_name}`" size="lg">
        <div class="space-y-4" data-testid="backfill-dialog">
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="q in quick"
                    :key="q.label"
                    type="button"
                    :class="['rounded-full border px-3 py-1 text-sm', from === q.from && to === q.to ? 'border-primary bg-primary/5 text-primary' : 'hover:bg-muted/50']"
                    :disabled="running"
                    @click="(from = q.from), (to = q.to)"
                >
                    {{ q.label }}
                </button>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <FormField label="Từ ngày"><DateInput v-model="from" :max="to ?? today" :disabled="running" data-testid="backfill-from" /></FormField>
                <FormField label="Đến ngày"><DateInput v-model="to" :min="from ?? undefined" :max="today" :disabled="running" data-testid="backfill-to" /></FormField>
            </div>

            <ul class="space-y-1.5 text-sm">
                <li v-if="invalid" class="flex gap-2 text-destructive"><TriangleAlert class="mt-0.5 size-4 shrink-0" /> {{ invalid }}</li>
                <li v-else class="flex gap-2"><Info class="mt-0.5 size-4 shrink-0 text-primary" /> {{ windows > 1 ? `Sàn chỉ cho lấy 15 ngày mỗi lần, hệ thống tự chia làm ${windows} đợt và chạy nền.` : 'Chạy nền, xong trong ít phút.' }}</li>
                <li class="flex gap-2"><CircleCheck class="mt-0.5 size-4 shrink-0 text-emerald-600" /> Đơn đã có chỉ được cập nhật trạng thái, không tạo trùng.</li>
                <li class="flex gap-2"><TriangleAlert class="mt-0.5 size-4 shrink-0 text-amber-500" /> Đơn cũ không trừ tồn kho (mặc định), tránh lệch kho hiện tại.</li>
            </ul>

            <label class="flex items-start gap-2 rounded-md border px-3 py-2.5 text-sm">
                <input v-model="withStock" type="checkbox" class="mt-0.5" :disabled="running" data-testid="backfill-with-stock" />
                <span>Trừ tồn kho cho đơn lấy lại<span class="block text-xs text-muted-foreground">Chỉ bật khi kho trong phần mềm chưa trừ các đơn này.</span></span>
            </label>

            <div v-if="progress" class="rounded-md bg-muted/50 px-3 py-2.5 text-sm" data-testid="backfill-progress">
                <div class="flex items-center justify-between">
                    <span class="font-medium">{{ progress.error ? 'Dừng vì lỗi' : running ? `Đang lấy… đợt ${Math.min(progress.done + 1, progress.windows)}/${progress.windows}` : 'Đã lấy xong' }}</span>
                    <span class="tabular-nums">{{ formatNumber(progress.created + progress.updated + progress.failed) }} đơn</span>
                </div>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200"><div class="h-full bg-primary transition-all" :style="{ width: `${(progress.done / progress.windows) * 100}%` }" /></div>
                <p class="mt-1.5 text-xs text-muted-foreground">
                    Mới {{ formatNumber(progress.created) }} · Cập nhật {{ formatNumber(progress.updated) }} · Lỗi {{ formatNumber(progress.failed) }}<template v-if="running"> — có thể đóng cửa sổ, việc vẫn chạy.</template>
                </p>
                <p v-if="progress.error" class="mt-1 text-xs text-destructive">{{ progress.error }}</p>
            </div>
        </div>
        <template #footer>
            <Button variant="outline" @click="open = false">Đóng</Button>
            <Button :loading="starting" :disabled="!!invalid || running" data-testid="backfill-start" @click="start">Bắt đầu lấy đơn</Button>
        </template>
    </Dialog>
</template>
