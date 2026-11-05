<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Ban, Lightbulb, RotateCw, X } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import type { SyncOverviewData, SyncTaskData } from '@/shared/types';
import { countLabels, duration, reconnectCodes, statusInfo, taskTypes } from './labels';

/** Detail of one sync task, with the tasks sharing its correlation id (webhook → order → stock pushes). */
const props = defineProps<{ task: SyncTaskData; overview: SyncOverviewData }>();
const emit = defineEmits<{ close: []; changed: [] }>();
const toast = useToast();
const detail = ref<SyncTaskData | null>(null);
const busy = ref<'retry' | 'cancel' | null>(null);

async function load() {
    detail.value = null;
    try {
        detail.value = (await api.get(`/sync-center/tasks/${props.task.type}/${props.task.key}`)).data.data;
    } catch (e) {
        toast.error((e as ApiError).message);
        emit('close');
    }
}
onMounted(load);
watch(() => props.task.id, load);

function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') emit('close');
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

async function act(action: 'retry' | 'cancel') {
    if (!detail.value) return;
    busy.value = action;
    try {
        const { data } = await api.post(`/sync-center/tasks/${detail.value.type}/${detail.value.key}/${action}`);
        detail.value = { ...detail.value, ...data.data };
        toast.success(action === 'cancel' ? 'Đã hủy tác vụ.' : data.data.status === 'success' ? 'Đã thử lại thành công.' : 'Đã xếp tác vụ để thử lại.');
        emit('changed');
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}

/** What to do; a temporary cause that ran out of automatic attempts needs a manual retry, not patience. */
const hint = (t: SyncTaskData) => {
    const info = t.error_code ? props.overview.error_codes[t.error_code] : null;
    if (!info) return null;
    return info.auto_retry && t.status === 'dead_letter' ? 'Đã hết lượt tự thử lại. Bấm Thử lại khi sàn/website hoạt động bình thường.' : info.hint;
};
const dot = (t: SyncTaskData) =>
    ({ success: 'border-emerald-500', failed: 'border-rose-500 bg-rose-500', dead_letter: 'border-rose-500 bg-rose-500', processing: 'border-blue-500' })[t.status as string] ?? 'border-slate-400';
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-40 bg-slate-900/25" @mousedown="emit('close')" />
        <aside role="dialog" aria-modal="true" class="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col border-l bg-card shadow-2xl" data-testid="sync-task-drawer">
            <div class="flex items-start justify-between gap-3 border-b px-5 py-4">
                <div class="min-w-0">
                    <p class="text-xs text-muted-foreground">{{ taskTypes[task.type] }}<template v-if="task.account"> · {{ task.account.name }}</template></p>
                    <h2 class="mt-0.5 truncate text-base font-semibold">{{ task.object ?? taskTypes[task.type] }}</h2>
                </div>
                <button class="rounded p-1 text-muted-foreground hover:bg-muted" aria-label="Đóng" @click="emit('close')"><X class="size-4" /></button>
            </div>

            <div v-if="!detail" class="space-y-3 p-5"><Skeleton class="h-6 w-40" /><Skeleton class="h-32" /><Skeleton class="h-24" /></div>
            <div v-else class="flex-1 overflow-y-auto px-5 py-4 text-sm">
                <div class="flex flex-wrap gap-1.5">
                    <Badge :tone="statusInfo[detail.status].tone">{{ statusInfo[detail.status].label }}</Badge>
                    <span v-if="detail.error_label" class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">{{ detail.error_label }}</span>
                </div>
                <dl class="mt-4 grid grid-cols-[130px_1fr] gap-x-3 gap-y-2">
                    <dt class="text-muted-foreground">Hành động</dt><dd>{{ detail.action ?? '—' }}</dd>
                    <template v-if="detail.attempts !== null"><dt class="text-muted-foreground">Số lần thử</dt><dd class="tabular-nums">{{ detail.attempts }}</dd></template>
                    <dt class="text-muted-foreground">Tạo lúc</dt><dd>{{ formatDateTime(detail.created_at) }}</dd>
                    <template v-if="detail.next_retry_at"><dt class="text-muted-foreground">Thử lại lúc</dt><dd>{{ formatDateTime(detail.next_retry_at) }}</dd></template>
                    <dt class="text-muted-foreground">Xong lúc</dt><dd>{{ formatDateTime(detail.completed_at) }}</dd>
                    <dt class="text-muted-foreground">Thời gian xử lý</dt><dd>{{ duration(detail.duration_ms) }}</dd>
                    <template v-if="detail.counts && Object.keys(detail.counts).length">
                        <dt class="text-muted-foreground">Số đơn</dt>
                        <dd>{{ Object.entries(detail.counts).map(([k, n]) => `${n} ${countLabels[k] ?? k}`).join(' · ') }}</dd>
                    </template>
                    <dt class="text-muted-foreground">Thông báo</dt><dd class="break-words">{{ detail.message ?? '—' }}</dd>
                    <dt class="text-muted-foreground">Correlation ID</dt><dd class="break-all font-mono text-xs">{{ detail.correlation_id ?? '—' }}</dd>
                </dl>

                <div v-if="hint(detail) && !['success', 'cancelled'].includes(detail.status)" class="mt-4 flex gap-2 rounded-md border border-amber-200 bg-amber-50/70 px-3 py-2.5 text-amber-900">
                    <Lightbulb class="mt-0.5 size-4 shrink-0 text-amber-500" />
                    <p>
                        {{ hint(detail) }}
                        <RouterLink v-if="reconnectCodes.includes(detail.error_code ?? '')" to="/marketplaces" class="font-medium underline">Mở Shop &amp; tài khoản</RouterLink>
                    </p>
                </div>

                <template v-if="detail.related?.length">
                    <h3 class="mb-2 mt-6 text-sm font-semibold">Chuỗi xử lý liên quan</h3>
                    <ol class="ml-1.5 border-l-2 border-slate-200 pl-4">
                        <li v-for="r in detail.related" :key="r.id" class="relative pb-3.5">
                            <span :class="cn('absolute -left-[23px] top-1 size-2.5 rounded-full border-2 bg-card', dot(r))" />
                            <p><b class="font-medium">{{ taskTypes[r.type] }}</b> {{ r.object }} <span class="text-muted-foreground">· {{ r.action }}</span></p>
                            <p class="text-xs text-muted-foreground">{{ formatDateTime(r.created_at) }} · {{ statusInfo[r.status].label }}<template v-if="r.account"> · {{ r.account.name }}</template></p>
                        </li>
                    </ol>
                </template>
            </div>

            <div v-if="detail && overview.permissions.operate && (detail.can_retry || detail.can_cancel)" class="flex justify-end gap-2 border-t px-5 py-3">
                <Button v-if="detail.can_cancel" variant="outline" :loading="busy === 'cancel'" data-testid="sync-task-cancel" @click="act('cancel')"><Ban /> Hủy tác vụ</Button>
                <Button v-if="detail.can_retry" :loading="busy === 'retry'" data-testid="sync-task-retry" @click="act('retry')">
                    <RotateCw /> {{ detail.type === 'stock_push' ? 'Thử lại (tính lại tồn hiện tại)' : 'Thử lại' }}
                </Button>
            </div>
        </aside>
    </Teleport>
</template>
