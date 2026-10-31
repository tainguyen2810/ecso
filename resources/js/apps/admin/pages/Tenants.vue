<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { Building2, Search } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime } from '@/shared/lib/utils';

interface Row {
    id: string;
    name: string;
    status: string;
    owner: { name: string; email: string } | null;
    plan: string | null;
    members_count: number;
    created_at: string;
}

const rows = ref<Row[] | null>(null);
const total = ref(0);
const q = ref('');
const error = ref<string | null>(null);
let timer: ReturnType<typeof setTimeout>;

async function load() {
    error.value = null;
    try {
        const { data } = await api.get('/admin/tenants', { params: { q: q.value || undefined } });
        rows.value = data.data;
        total.value = data.meta.total;
    } catch (e) {
        error.value = (e as ApiError).message;
    }
}
onMounted(load);
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});
</script>

<template>
    <div class="mb-6 flex items-end justify-between">
        <div>
            <h1 class="text-xl font-semibold">Khách hàng</h1>
            <p class="text-sm text-muted-foreground">{{ total }} tenant</p>
        </div>
        <div class="relative w-64">
            <Search class="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
            <Input v-model="q" placeholder="Tìm tenant" class="pl-8" />
        </div>
    </div>
    <Card>
        <ErrorState v-if="error" :message="error" @retry="load" />
        <div v-else-if="!rows" class="space-y-3 p-4"><Skeleton v-for="i in 5" :key="i" class="h-9" /></div>
        <EmptyState v-else-if="!rows.length" :icon="Building2" title="Không có tenant phù hợp" />
        <table v-else class="w-full text-sm">
            <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                    <th class="px-4 py-2.5 font-medium">Tenant</th>
                    <th class="px-4 py-2.5 font-medium">Chủ sở hữu</th>
                    <th class="px-4 py-2.5 font-medium">Gói</th>
                    <th class="px-4 py-2.5 font-medium">Thành viên</th>
                    <th class="px-4 py-2.5 font-medium">Trạng thái</th>
                    <th class="px-4 py-2.5 font-medium">Ngày tạo</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <tr v-for="t in rows" :key="t.id">
                    <td class="px-4 py-3 font-medium">{{ t.name }}</td>
                    <td class="px-4 py-3">
                        <p>{{ t.owner?.name }}</p>
                        <p class="text-xs text-muted-foreground">{{ t.owner?.email }}</p>
                    </td>
                    <td class="px-4 py-3">{{ t.plan }}</td>
                    <td class="px-4 py-3">{{ t.members_count }}</td>
                    <td class="px-4 py-3"><Badge :tone="t.status === 'active' ? 'success' : 'danger'">{{ t.status }}</Badge></td>
                    <td class="px-4 py-3 text-muted-foreground">{{ formatDateTime(t.created_at) }}</td>
                </tr>
            </tbody>
        </table>
    </Card>
</template>
