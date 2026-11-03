<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { ArrowRight, Plus, Search, Truck } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Pagination from '../../components/Pagination.vue';
import PurchasingNav from './PurchasingNav.vue';
import SupplierDialog from './SupplierDialog.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
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
const debtOnly = ref(false);
const page = ref(1);
const dialog = ref(false);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get('/suppliers', { params: { q: q.value || undefined, sort: sort.value, debt: debtOnly.value ? 1 : undefined, page: page.value } });
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
watch([sort, debtOnly], () => ((page.value = 1), load()));
</script>

<template>
    <PageHeader title="Mua hàng" description="Nhà cung cấp, đơn nhập hàng, nhận hàng nhiều đợt, trả hàng và công nợ nhà cung cấp.">
        <Button v-if="tenant.can('purchasing.manage')" data-testid="add-supplier" @click="dialog = true"><Plus /> Thêm nhà cung cấp</Button>
    </PageHeader>
    <PurchasingNav />
    <Card>
        <div class="flex flex-wrap gap-2 border-b p-4">
            <div class="relative min-w-60 flex-1">
                <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="q" class="pl-8" placeholder="Tên, mã, số điện thoại…" />
            </div>
            <Select v-model="sort" class="w-44" :options="[{ value: 'name', label: 'Tên A→Z' }, { value: 'debt', label: 'Nợ nhiều' }, { value: 'purchased', label: 'Nhập nhiều' }, { value: 'newest', label: 'Mới thêm' }]" aria-label="Sắp xếp" />
            <label class="flex items-center gap-2 px-2 text-sm"><input v-model="debtOnly" type="checkbox" class="size-4" /> Còn nợ NCC</label>
        </div>
        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 5" :key="i" class="h-10" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState v-else-if="!rows.length" :icon="Truck" title="Chưa có nhà cung cấp" description="Thêm nhà cung cấp để lập đơn nhập hàng và theo dõi công nợ." />
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="supplier-table">
                <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Nhà cung cấp</th>
                        <th class="px-4 py-2.5 font-medium">Liên hệ</th>
                        <th class="px-4 py-2.5 text-right font-medium">Số đơn</th>
                        <th class="px-4 py-2.5 text-right font-medium">Đã nhập</th>
                        <th class="px-4 py-2.5 text-right font-medium">Đã trả</th>
                        <th class="px-4 py-2.5 text-right font-medium">Còn nợ</th>
                        <th class="px-4 py-2.5" />
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="s in rows" :key="s.id" class="cursor-pointer hover:bg-muted/30" @click="$router.push(`/purchasing/suppliers/${s.id}`)">
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ s.name }} <Badge v-if="!s.is_active" tone="muted">Ngừng giao dịch</Badge></div>
                            <div class="font-mono text-xs text-muted-foreground">{{ s.code }}</div>
                        </td>
                        <td class="px-4 py-3"><div>{{ s.contact_name ?? '—' }}</div><div class="text-xs tabular-nums text-muted-foreground">{{ s.phone }}</div></td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ formatNumber(s.orders_count ?? 0) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(s.purchased) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(s.paid) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums" :class="Number(s.debt) > 0 ? 'font-medium text-destructive' : 'text-muted-foreground'">{{ Number(s.debt) !== 0 ? formatMoney(s.debt) : '—' }}</td>
                        <td class="px-4 py-3"><ArrowRight class="ml-auto size-4 text-muted-foreground" /></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="(p) => ((page = p), load())" />
    </Card>
    <SupplierDialog v-model:open="dialog" @saved="(s) => $router.push(`/purchasing/suppliers/${s.id}`)" />
</template>
