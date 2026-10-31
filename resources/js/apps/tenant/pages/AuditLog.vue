<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { ScrollText } from 'lucide-vue-next';
import PageHeader from '../PageHeader.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime } from '@/shared/lib/utils';

interface Log {
    id: number;
    action: string;
    actor: { name: string; email: string } | null;
    subject_type: string | null;
    changes: Record<string, unknown> | null;
    ip: string | null;
    created_at: string;
}

const logs = ref<Log[]>([]);
const cursor = ref<string | null>(null);
const loading = ref(true);
const loadingMore = ref(false);
const error = ref<string | null>(null);

const labels: Record<string, string> = {
    'tenant.created': 'Tạo doanh nghiệp',
    'tenant.updated': 'Cập nhật cài đặt',
    'member.joined': 'Tham gia doanh nghiệp',
    'member.role_changed': 'Đổi vai trò',
    'member.status_changed': 'Đổi trạng thái nhân viên',
    'member.removed': 'Xóa nhân viên',
    'invitation.created': 'Gửi lời mời',
    'invitation.revoked': 'Thu hồi lời mời',
    'invitation.accepted': 'Chấp nhận lời mời',
};

async function load(more = false) {
    more ? (loadingMore.value = true) : (loading.value = true);
    error.value = null;
    try {
        const { data } = await api.get('/audit-logs', { params: { cursor: more ? cursor.value : undefined } });
        logs.value = more ? [...logs.value, ...data.data] : data.data;
        cursor.value = data.next_cursor;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
        loadingMore.value = false;
    }
}
onMounted(() => load());

function summary(changes: Record<string, unknown> | null): string {
    if (!changes) return '';
    return Object.entries(changes)
        .map(([k, v]) => `${k}: ${typeof v === 'object' ? JSON.stringify(v) : v}`)
        .join(' · ');
}
</script>

<template>
    <PageHeader title="Nhật ký thao tác" description="Mọi thay đổi quan trọng trong doanh nghiệp, không thể sửa hoặc xóa." />
    <Card>
        <div v-if="loading" class="space-y-3 p-4"><Skeleton v-for="i in 6" :key="i" class="h-9" /></div>
        <ErrorState v-else-if="error" :message="error" @retry="load()" />
        <EmptyState v-else-if="!logs.length" :icon="ScrollText" title="Chưa có hoạt động" />
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Thời gian</th>
                        <th class="px-4 py-2.5 font-medium">Người thực hiện</th>
                        <th class="px-4 py-2.5 font-medium">Hành động</th>
                        <th class="px-4 py-2.5 font-medium">Chi tiết</th>
                        <th class="px-4 py-2.5 font-medium">IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="log in logs" :key="log.id">
                        <td class="whitespace-nowrap px-4 py-2.5 text-muted-foreground">{{ formatDateTime(log.created_at) }}</td>
                        <td class="px-4 py-2.5">{{ log.actor?.name ?? 'Hệ thống' }}</td>
                        <td class="px-4 py-2.5 font-medium">{{ labels[log.action] ?? log.action }}</td>
                        <td class="max-w-md truncate px-4 py-2.5 text-muted-foreground" :title="summary(log.changes)">{{ summary(log.changes) }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs text-muted-foreground">{{ log.ip }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-if="cursor" class="border-t p-3 text-center">
            <Button variant="ghost" size="sm" :loading="loadingMore" @click="load(true)">Tải thêm</Button>
        </div>
    </Card>
</template>
