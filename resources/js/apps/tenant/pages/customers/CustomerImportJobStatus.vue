<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { AlertTriangle, CheckCircle2, Loader2, XCircle } from 'lucide-vue-next';
import { formatNumber } from '@/shared/lib/utils';
import type { CustomerImportJob } from '@/shared/types';

/**
 * How far a large customer file checked and imported in the background has got (Nhập file in Khách hàng, Cấu hình ›
 * Chuyển dữ liệu). When nothing has picked the job up after a minute, it says so: the background worker is not running.
 */
const props = defineProps<{ job: CustomerImportJob }>();

const STUCK_AFTER = 60_000;
const now = ref(Date.now());
const tick = setInterval(() => (now.value = Date.now()), 5000);
onBeforeUnmount(() => clearInterval(tick));

const percent = computed(() => (props.job.total ? Math.min(100, Math.round((props.job.processed / props.job.total) * 100)) : 0));
const waitedSince = computed(() => {
    const at = props.job.updated_at ?? props.job.created_at;
    return at ? now.value - new Date(at).getTime() : 0;
});
const stuck = computed(() => ['pending_check', 'queued'].includes(props.job.status) && waitedSince.value > STUCK_AFTER);
</script>

<template>
    <div class="space-y-2 rounded-md border p-3 text-sm" data-testid="customer-import-job">
        <p class="font-medium">{{ job.file_name }}</p>
        <template v-if="job.status === 'pending_check' || job.status === 'checking'">
            <p class="flex items-center gap-2 text-muted-foreground">
                <Loader2 class="size-4 animate-spin" />
                {{ job.status === 'pending_check' ? 'Đã nhận file, đang chờ đến lượt kiểm tra…' : 'Đang kiểm tra toàn bộ file (chạy ngầm, file lớn mất 1–3 phút, có thể rời trang này)…' }}
            </p>
            <div class="h-2 overflow-hidden rounded-full bg-muted"><div class="h-full w-1/3 animate-pulse rounded-full bg-primary/60" /></div>
        </template>
        <template v-else-if="job.status === 'checked'">
            <div class="flex items-start gap-3 rounded-md bg-success/10 p-3" data-testid="customer-import-checked">
                <CheckCircle2 class="mt-0.5 size-5 text-success" />
                <div>
                    <p class="font-medium">File hợp lệ: {{ formatNumber(job.total) }} dòng</p>
                    <p class="text-muted-foreground">Sẽ thêm {{ formatNumber(job.created) }} khách mới và cập nhật {{ formatNumber(job.updated) }} khách. Bấm nút Nhập để nhập (chạy ngầm, tệp được giữ 24 giờ).</p>
                </div>
            </div>
        </template>
        <template v-else-if="job.status === 'queued' || job.status === 'importing'">
            <p class="flex items-center gap-2 text-muted-foreground"><Loader2 class="size-4 animate-spin" /> Đang nhập {{ formatNumber(job.processed) }} / {{ formatNumber(job.total) }} dòng. Có thể rời trang này; khách chỉ hiện khi đã nhập xong toàn bộ.</p>
            <div class="h-2 overflow-hidden rounded-full bg-muted"><div class="h-full bg-primary transition-all" :style="{ width: `${percent}%` }" /></div>
        </template>
        <template v-else-if="job.status === 'done'">
            <div class="flex items-start gap-3 rounded-md bg-success/10 p-3" data-testid="customer-import-done">
                <CheckCircle2 class="mt-0.5 size-5 text-success" />
                <p>Đã nhập xong: thêm {{ formatNumber(job.created) }} khách mới, cập nhật {{ formatNumber(job.updated) }} khách.</p>
            </div>
        </template>
        <template v-else>
            <div class="rounded-md border border-destructive/30">
                <div class="flex items-center gap-2 border-b border-destructive/20 bg-destructive/5 px-3 py-2 font-medium text-destructive">
                    <XCircle class="size-4" /> {{ job.message ?? 'Không nhập được.' }}<template v-if="job.error_count"> ({{ formatNumber(job.error_count) }} lỗi)</template>
                </div>
                <ul v-if="job.errors.length" class="max-h-48 divide-y overflow-y-auto">
                    <li v-for="(e, i) in job.errors" :key="i" class="flex gap-3 px-3 py-1.5">
                        <span class="w-16 shrink-0 text-muted-foreground">{{ e.row ? `Dòng ${e.row}` : 'Tệp' }}</span><span>{{ e.message }}</span>
                    </li>
                </ul>
            </div>
        </template>
        <p v-if="stuck" class="flex items-start gap-2 rounded-md bg-warning/15 p-2 text-amber-700" data-testid="customer-import-stuck">
            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
            <span>Đã hơn 1 phút mà việc chạy ngầm chưa bắt đầu: bộ chạy ngầm (Horizon) của máy chủ có thể đang tắt. Người quản trị máy chủ cần bật lại (<code>php artisan horizon</code>), file vẫn được giữ và sẽ tự chạy tiếp.</span>
        </p>
    </div>
</template>
