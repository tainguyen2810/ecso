<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { AlertTriangle, CheckCircle2, RefreshCw } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime } from '@/shared/lib/utils';

interface Snapshot {
    checked_at: string;
    queue_connection: string;
    queues: Record<string, number | null>;
    failed_jobs_total: number;
    failed_jobs_24h: number;
    failed_jobs_recent: { id: number; queue: string; job: string; exception: string; failed_at: string }[];
    scheduler_last_run: string | null;
    scheduler_ok: boolean;
    webhooks_24h: Record<string, number>;
    outbox: Record<string, number>;
    circuits_open: number;
    disk_free_percent: number | null;
    problems: string[];
}

const data = ref<Snapshot | null>(null);
const error = ref<string | null>(null);
const loading = ref(false);
let timer: ReturnType<typeof setInterval>;

async function load() {
    loading.value = true;
    error.value = null;
    try {
        data.value = (await api.get('/admin/monitoring')).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(() => {
    load();
    timer = setInterval(load, 30000);
});
onUnmounted(() => clearInterval(timer));

const webhookLabels: Record<string, string> = { received: 'Đã nhận', processed: 'Đã xử lý', ignored: 'Bỏ qua', failed: 'Lỗi', rejected: 'Từ chối' };
</script>

<template>
    <div class="mb-6 flex items-end justify-between">
        <div>
            <h1 class="text-xl font-semibold">Giám sát hệ thống</h1>
            <p class="text-sm text-muted-foreground">Queue, job lỗi, scheduler, đồng bộ sàn. Tự làm mới mỗi 30 giây. Chỉ số đếm và tên lớp — không có dữ liệu nghiệp vụ của tenant.</p>
        </div>
        <Button variant="outline" size="sm" :loading="loading" @click="load"><RefreshCw /> Làm mới</Button>
    </div>
    <ErrorState v-if="error" :message="error" @retry="load" />
    <div v-else-if="!data" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"><Skeleton v-for="i in 8" :key="i" class="h-24" /></div>
    <template v-else>
        <div
            :class="['mb-6 flex items-start gap-3 rounded-lg border p-4 text-sm', data.problems.length ? 'border-destructive/30 bg-destructive/5' : 'border-success/30 bg-success/5']"
            data-testid="monitor-status"
        >
            <AlertTriangle v-if="data.problems.length" class="mt-0.5 size-4 text-destructive" />
            <CheckCircle2 v-else class="mt-0.5 size-4 text-success" />
            <div>
                <p class="font-medium">{{ data.problems.length ? `${data.problems.length} vấn đề cần xử lý` : 'Hệ thống bình thường' }}</p>
                <ul v-if="data.problems.length" class="mt-1 list-disc pl-5">
                    <li v-for="p in data.problems" :key="p">{{ p }}</li>
                </ul>
                <p class="mt-1 text-xs text-muted-foreground">Kiểm tra lúc {{ formatDateTime(data.checked_at) }}</p>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Scheduler</p>
                <p class="mt-2"><Badge :tone="data.scheduler_ok ? 'success' : 'danger'">{{ data.scheduler_ok ? 'Đang chạy' : 'Không có heartbeat' }}</Badge></p>
                <p class="mt-1 text-xs text-muted-foreground">Lần cuối: {{ data.scheduler_last_run ? formatDateTime(data.scheduler_last_run) : '—' }}</p>
            </Card>
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Job lỗi 24 giờ</p>
                <p :class="['mt-2 text-2xl font-semibold', data.failed_jobs_24h > 0 && 'text-destructive']">{{ data.failed_jobs_24h }}</p>
                <p class="text-xs text-muted-foreground">Tổng {{ data.failed_jobs_total }}</p>
            </Card>
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Shop ngắt mạch đồng bộ</p>
                <p :class="['mt-2 text-2xl font-semibold', data.circuits_open > 0 && 'text-destructive']">{{ data.circuits_open }}</p>
            </Card>
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Ổ đĩa trống</p>
                <p class="mt-2 text-2xl font-semibold">{{ data.disk_free_percent === null ? '—' : data.disk_free_percent + '%' }}</p>
            </Card>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <Card :title="`Queue (${data.queue_connection})`">
                <table class="w-full text-sm" data-testid="queues">
                    <tbody class="divide-y">
                        <tr v-for="(size, name) in data.queues" :key="name">
                            <td class="px-5 py-2.5 font-mono text-xs">{{ name }}</td>
                            <td class="px-5 py-2.5 text-right tabular-nums">{{ size === null ? 'không đọc được' : size }}</td>
                        </tr>
                    </tbody>
                </table>
            </Card>
            <Card title="Webhook 24 giờ">
                <p v-if="!Object.keys(data.webhooks_24h).length" class="p-5 text-sm text-muted-foreground">Không có webhook.</p>
                <table v-else class="w-full text-sm">
                    <tbody class="divide-y">
                        <tr v-for="(n, s) in data.webhooks_24h" :key="s">
                            <td class="px-5 py-2.5">{{ webhookLabels[s] ?? s }}</td>
                            <td class="px-5 py-2.5 text-right tabular-nums">{{ n }}</td>
                        </tr>
                    </tbody>
                </table>
            </Card>
            <Card title="Đẩy tồn lên sàn (outbox)">
                <p v-if="!Object.keys(data.outbox).length" class="p-5 text-sm text-muted-foreground">Không có lệnh chờ hoặc lỗi.</p>
                <table v-else class="w-full text-sm">
                    <tbody class="divide-y">
                        <tr v-for="(n, s) in data.outbox" :key="s">
                            <td class="px-5 py-2.5">{{ s === 'pending' ? 'Chờ đẩy' : 'Lỗi' }}</td>
                            <td class="px-5 py-2.5 text-right tabular-nums">{{ n }}</td>
                        </tr>
                    </tbody>
                </table>
            </Card>
        </div>

        <Card class="mt-6" title="Job lỗi gần đây" description="Chỉ hiện tên job và loại lỗi; chi tiết xem trong log server (đã che dữ liệu nhạy cảm).">
            <p v-if="!data.failed_jobs_recent.length" class="p-5 text-sm text-muted-foreground">Không có job lỗi.</p>
            <table v-else class="w-full text-sm" data-testid="failed-jobs">
                <thead class="border-b text-left text-muted-foreground">
                    <tr>
                        <th class="px-5 py-2.5 font-medium">Job</th>
                        <th class="px-5 py-2.5 font-medium">Queue</th>
                        <th class="px-5 py-2.5 font-medium">Lỗi</th>
                        <th class="px-5 py-2.5 font-medium">Lúc</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="j in data.failed_jobs_recent" :key="j.id">
                        <td class="px-5 py-2.5 font-mono text-xs">{{ j.job }}</td>
                        <td class="px-5 py-2.5">{{ j.queue }}</td>
                        <td class="px-5 py-2.5 font-mono text-xs">{{ j.exception }}</td>
                        <td class="px-5 py-2.5 text-muted-foreground">{{ formatDateTime(j.failed_at) }}</td>
                    </tr>
                </tbody>
            </table>
        </Card>
    </template>
</template>
