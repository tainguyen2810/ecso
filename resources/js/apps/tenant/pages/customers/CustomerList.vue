<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { ArrowRight, Search, UserPlus, UsersRound } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Pagination from '../../components/Pagination.vue';
import CustomerDialog from './CustomerDialog.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import type { Customer } from '@/shared/types';

const tenant = useTenantStore();
const rows = ref<Customer[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const groups = ref<string[]>([]);
const loading = ref(true);
const loadError = ref<string | null>(null);
const q = ref('');
const group = ref('');
const sort = ref('newest');
const debtOnly = ref(false);
const page = ref(1);
const dialog = ref(false);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get('/customers', { params: { q: q.value || undefined, group: group.value || undefined, sort: sort.value, debt: debtOnly.value ? 1 : undefined, page: page.value } });
        rows.value = data.data;
        meta.value = data.meta;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(() => {
    load();
    api.get('/customers/groups').then(({ data }) => (groups.value = data.data)).catch(() => {});
});
let timer: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => ((page.value = 1), load()), 300);
});
watch([group, sort, debtOnly], () => ((page.value = 1), load()));
</script>

<template>
    <PageHeader title="Khách hàng" description="Danh bạ khách mua qua kênh bán riêng: lịch sử mua, tổng chi tiêu và công nợ.">
        <Button v-if="tenant.can('customers.manage')" data-testid="add-customer" @click="dialog = true"><UserPlus /> Thêm khách</Button>
    </PageHeader>
    <Card>
        <div class="flex flex-wrap gap-2 border-b p-4">
            <div class="relative min-w-60 flex-1">
                <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="q" class="pl-8" placeholder="Tên, mã khách, số điện thoại (đủ số hoặc 4 số cuối)…" data-testid="customer-q" />
            </div>
            <Select v-model="group" class="w-40" :options="[{ value: '', label: 'Mọi nhóm' }, ...groups.map((g) => ({ value: g, label: g }))]" aria-label="Nhóm" />
            <Select v-model="sort" class="w-44" :options="[{ value: 'newest', label: 'Mới thêm' }, { value: 'recent', label: 'Mua gần đây' }, { value: 'spent', label: 'Chi tiêu nhiều' }, { value: 'debt', label: 'Nợ nhiều' }, { value: 'name', label: 'Tên A→Z' }]" aria-label="Sắp xếp" />
            <label class="flex items-center gap-2 px-2 text-sm"><input v-model="debtOnly" type="checkbox" class="size-4" /> Đang nợ</label>
        </div>
        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 5" :key="i" class="h-10" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState v-else-if="!rows.length" :icon="UsersRound" title="Chưa có khách hàng" description="Khách được thêm tự động khi tạo đơn bán với khách mới, hoặc thêm thủ công." />
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="customer-table">
                <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Khách hàng</th>
                        <th class="px-4 py-2.5 font-medium">Điện thoại</th>
                        <th class="px-4 py-2.5 font-medium">Nhóm</th>
                        <th class="px-4 py-2.5 text-right font-medium">Số đơn</th>
                        <th class="px-4 py-2.5 text-right font-medium">Tổng mua</th>
                        <th class="px-4 py-2.5 text-right font-medium">Còn nợ</th>
                        <th class="px-4 py-2.5 font-medium">Mua gần nhất</th>
                        <th class="px-4 py-2.5" />
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="c in rows" :key="c.id" class="cursor-pointer hover:bg-muted/30" @click="$router.push(`/customers/${c.id}`)">
                        <td class="px-4 py-3"><div class="font-medium">{{ c.name }}</div><div class="font-mono text-xs text-muted-foreground">{{ c.code }}</div></td>
                        <td class="px-4 py-3 tabular-nums">{{ c.phone ?? '—' }}</td>
                        <td class="px-4 py-3"><Badge v-if="c.group" tone="default">{{ c.group }}</Badge></td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ formatNumber(c.orders_count) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(c.total_spent) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums" :class="Number(c.debt) > 0 ? 'font-medium text-destructive' : 'text-muted-foreground'">{{ Number(c.debt) > 0 ? formatMoney(c.debt) : '—' }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ formatDateTime(c.last_order_at) }}</td>
                        <td class="px-4 py-3"><ArrowRight class="ml-auto size-4 text-muted-foreground" /></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((page = p), load())" />
    </Card>
    <CustomerDialog v-model:open="dialog" @saved="(c) => $router.push(`/customers/${c.id}`)" />
</template>
