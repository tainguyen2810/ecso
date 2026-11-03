<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { Download, Plus, Search, Truck, Undo2 } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import HeaderLink from '../../components/HeaderLink.vue';
import Tabs from '../../components/Tabs.vue';
import Pagination from '../../components/Pagination.vue';
import SupplierDialog from './SupplierDialog.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import type { Supplier } from '@/shared/types';

const tenant = useTenantStore();
const rows = ref<Supplier[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const loadError = ref<string | null>(null);
const q = ref('');
const sort = ref('name');
// Sapo tabs: Tất cả / Đang giao dịch / Ngừng giao dịch, plus "Còn nợ" which suppliers care about most.
const tab = ref<'all' | 'active' | 'inactive' | 'debt'>('all');
const page = ref(1);
const dialog = ref(false);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get('/suppliers', { params: { q: q.value || undefined, sort: sort.value, debt: tab.value === 'debt' ? 1 : undefined, active: tab.value === 'active' ? 1 : tab.value === 'inactive' ? 0 : undefined, page: page.value } });
        rows.value = data.data;
        meta.value = data.meta;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
let timer: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => ((page.value = 1), load()), 300);
});
watch([sort, tab], () => ((page.value = 1), load()));
const exporting = ref(false);
async function exportXlsx() {
    exporting.value = true;
    try {
        await downloadFile('/suppliers/export', 'nha-cung-cap.xlsx');
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        exporting.value = false;
    }
}
</script>

<template>
    <PageHeader title="Nhà cung cấp">
        <template #links>
            <HeaderLink :icon="Download" :disabled="exporting" @click="exportXlsx">Xuất file</HeaderLink>
            <HeaderLink :icon="Truck" to="/purchasing">Nhập hàng</HeaderLink>
            <HeaderLink :icon="Undo2" to="/purchasing/returns">Trả hàng nhà cung cấp</HeaderLink>
        </template>
        <Button v-if="tenant.can('purchasing.manage')" data-testid="add-supplier" @click="dialog = true"><Plus /> Thêm nhà cung cấp</Button>
    </PageHeader>
    <Card>
        <div class="border-b px-3">
            <Tabs
                v-model="tab"
                variant="underline"
                :tabs="[
                    { value: 'all', label: 'Tất cả nhà cung cấp' },
                    { value: 'active', label: 'Đang giao dịch' },
                    { value: 'inactive', label: 'Ngừng giao dịch' },
                    { value: 'debt', label: 'Còn nợ' },
                ]"
            />
        </div>
        <div class="flex flex-wrap gap-2 border-b p-4">
            <div class="relative min-w-60 flex-1">
                <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="q" class="pl-8" placeholder="Tìm theo mã, tên, số điện thoại nhà cung cấp" />
            </div>
            <Select v-model="sort" class="w-44" :options="[{ value: 'name', label: 'Tên A→Z' }, { value: 'debt', label: 'Nợ nhiều' }, { value: 'purchased', label: 'Nhập nhiều' }, { value: 'newest', label: 'Mới thêm' }]" aria-label="Sắp xếp" />
        </div>
        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 5" :key="i" class="h-10" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState v-else-if="!rows.length" :icon="Truck" title="Chưa có nhà cung cấp" description="Thêm nhà cung cấp để lập đơn nhập hàng và theo dõi công nợ." />
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="supplier-table">
                <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Mã nhà cung cấp</th>
                        <th class="px-4 py-2.5 font-medium">Tên nhà cung cấp</th>
                        <th class="px-4 py-2.5 font-medium">Số điện thoại</th>
                        <th class="px-4 py-2.5 font-medium">Email</th>
                        <th class="px-4 py-2.5 text-right font-medium">Công nợ hiện tại</th>
                        <th class="px-4 py-2.5 text-right font-medium">Tổng đơn nhập</th>
                        <th class="px-4 py-2.5 font-medium">Trạng thái</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="s in rows" :key="s.id" class="cursor-pointer hover:bg-muted/30" @click="$router.push(`/purchasing/suppliers/${s.id}`)">
                        <td class="px-4 py-3"><RouterLink :to="`/purchasing/suppliers/${s.id}`" class="font-mono text-xs font-medium text-primary hover:underline" @click.stop>{{ s.code }}</RouterLink></td>
                        <td class="px-4 py-3"><div class="font-medium">{{ s.name }}</div><div v-if="s.contact_name" class="text-xs text-muted-foreground">{{ s.contact_name }}</div></td>
                        <td class="px-4 py-3 tabular-nums">{{ s.phone ?? '—' }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ s.email ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums" :class="Number(s.debt) > 0 ? 'font-medium text-destructive' : 'text-muted-foreground'">{{ Number(s.debt) !== 0 ? formatMoney(s.debt) : '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(s.purchased) }}<span class="block text-[11px] text-muted-foreground">{{ formatNumber(s.orders_count ?? 0) }} đơn</span></td>
                        <td class="px-4 py-3"><Badge :tone="s.is_active ? 'success' : 'muted'">{{ s.is_active ? 'Đang giao dịch' : 'Ngừng giao dịch' }}</Badge></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((page = p), load())" />
    </Card>
    <SupplierDialog v-model:open="dialog" @saved="(s) => $router.push(`/purchasing/suppliers/${s.id}`)" />
</template>
