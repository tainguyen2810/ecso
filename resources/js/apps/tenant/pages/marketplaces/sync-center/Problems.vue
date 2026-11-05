<script setup lang="ts">
import { computed, ref } from 'vue';
import { CheckCircle2, History, RotateCw, Scale } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import type { SyncOverviewData } from '@/shared/types';
import { reconnectCodes } from './labels';

/**
 * Lỗi cần xử lý: failed tasks of the last 7 days grouped by cause, each with what to do. Temporary causes (busy
 * store, slow network) retry by themselves and only show here once they ran out of attempts.
 */
const props = defineProps<{ overview: SyncOverviewData }>();
const emit = defineEmits<{ changed: [] }>();
const toast = useToast();

const groups = computed(() =>
    Object.entries(props.overview.problems.failed_by_code).map(([code, count]) => {
        const info = props.overview.error_codes[code];
        return {
            code,
            count,
            label: info?.label ?? 'Lỗi khác',
            // Only failed tasks are counted here, so a temporary cause has already used up its automatic attempts.
            hint: info?.auto_retry
                ? 'Đã hết lượt tự thử lại (sàn/website lỗi kéo dài). Khi sàn hoạt động bình thường, bấm Thử lại tất cả.'
                : (info?.hint ?? 'Lỗi chưa phân loại (ví dụ đẩy giá thất bại). Mở từng tác vụ để xem sàn trả về gì.'),
            autoRetry: info?.auto_retry ?? false,
        };
    }),
);

const confirm = ref<(typeof groups.value)[number] | null>(null);
const running = ref(false);
async function retryAll() {
    if (!confirm.value) return;
    running.value = true;
    try {
        const { data } = await api.post('/sync-center/tasks/retry-failed', { error_code: confirm.value.code });
        const r = data.data as { retried: number; skipped: { reason: string; count: number }[]; limited: boolean };
        const skipped = r.skipped.reduce((a, s) => a + s.count, 0);
        toast.success(`Đã xếp ${formatNumber(r.retried)} tác vụ để thử lại.${skipped ? ` Bỏ qua ${formatNumber(skipped)}: ${r.skipped.map((s) => s.reason).join(' ')}` : ''}${r.limited ? ' Còn tác vụ khác, bấm lại sau khi xong lượt này.' : ''}`);
        confirm.value = null;
        emit('changed');
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        running.value = false;
    }
}
</script>

<template>
    <div class="space-y-5">
        <p class="text-sm text-muted-foreground">
            Tác vụ thất bại trong 7 ngày qua, gom theo nguyên nhân. Lỗi tạm thời (sàn quá tải, mạng chậm) hệ thống tự thử lại với thời gian chờ tăng dần, chỉ hiện ở đây khi đã hết lượt thử.
        </p>

        <Card v-if="!groups.length"><EmptyState :icon="CheckCircle2" title="Không có lỗi cần xử lý" description="Không có tác vụ đồng bộ nào thất bại trong 7 ngày qua." /></Card>
        <div v-else class="grid gap-3 md:grid-cols-2 xl:grid-cols-3" data-testid="sync-problem-groups">
            <Card v-for="g in groups" :key="g.code" class="flex flex-col p-4" data-testid="sync-problem-group">
                <div class="flex items-start justify-between gap-2">
                    <h3 class="font-semibold">{{ g.label }}</h3>
                    <Badge :tone="g.autoRetry ? 'warning' : 'danger'" class="tabular-nums">{{ formatNumber(g.count) }}</Badge>
                </div>
                <p class="mb-3 mt-1.5 flex-1 text-sm text-muted-foreground">{{ g.hint }}</p>
                <span v-if="g.autoRetry" class="mb-2 w-fit rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-600">Lỗi tạm thời</span>
                <div class="flex flex-wrap gap-2">
                    <RouterLink v-if="reconnectCodes.includes(g.code)" to="/marketplaces"><Button size="sm">Kết nối lại</Button></RouterLink>
                    <RouterLink :to="{ query: g.code === 'other' ? { tab: 'queue', status: 'failed' } : { tab: 'queue', error_code: g.code } }">
                        <Button size="sm" variant="outline">Xem tác vụ</Button>
                    </RouterLink>
                    <Button v-if="overview.permissions.operate && g.code !== 'other'" size="sm" variant="outline" data-testid="sync-problem-retry-all" @click="confirm = g"><RotateCw /> Thử lại tất cả</Button>
                </div>
            </Card>
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            <Card class="flex items-start gap-3 p-4">
                <History class="mt-0.5 size-5 shrink-0 text-muted-foreground" />
                <div class="flex-1 text-sm">
                    <p class="font-medium">Đơn chưa đồng bộ được: {{ formatNumber(overview.problems.open_errors) }}</p>
                    <p class="text-muted-foreground">Đơn sàn đọc về bị lỗi. Hệ thống thử lại ở lượt đối soát sau; khi đồng bộ được, lỗi tự đóng.</p>
                    <RouterLink :to="{ query: { tab: 'log' } }" class="text-primary hover:underline">Mở Nhật ký đơn</RouterLink>
                </div>
            </Card>
            <Card class="flex items-start gap-3 p-4">
                <Scale class="mt-0.5 size-5 shrink-0 text-muted-foreground" />
                <div class="flex-1 text-sm">
                    <p class="font-medium">SKU tồn lệch trên sàn: {{ formatNumber(overview.problems.stock_drift) }}</p>
                    <p class="text-muted-foreground">Sàn báo số tồn khác số EzSell đã đẩy lên, thường do sửa trực tiếp trên sàn. EzSell không tự sửa tồn nội bộ theo số này; lần đẩy tồn sau đặt lại số trên sàn.</p>
                </div>
            </Card>
        </div>
    </div>

    <Dialog :open="!!confirm" :title="`Thử lại ${confirm ? formatNumber(confirm.count) : ''} tác vụ “${confirm?.label ?? ''}”?`" @update:open="(v) => !v && (confirm = null)">
        <p class="text-sm">
            Mỗi tác vụ được chạy lại an toàn: đơn được đọc lại từ sàn, tồn được tính lại từ kho hiện tại, giá đặt theo giá EzSell. Tác vụ của shop đang mất kết nối hoặc tắt đồng bộ sẽ được bỏ qua. Tối đa 100 tác vụ mỗi lần.
        </p>
        <template #footer>
            <Button variant="outline" @click="confirm = null">Để sau</Button>
            <Button :loading="running" data-testid="sync-problem-retry-confirm" @click="retryAll"><RotateCw /> Thử lại</Button>
        </template>
    </Dialog>
</template>
