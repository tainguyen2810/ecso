<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Merge, UsersRound } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import CustomersNav from './CustomersNav.vue';
import MergeDialog from './MergeDialog.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDate, formatMoney, formatNumber } from '@/shared/lib/utils';

interface DuplicateCard { id: string; code: string; name: string; phone: string | null; email: string | null; address: string | null; status: string; orders_count: number; total_spent: string; debt: string; created_at: string | null }
interface DuplicateGroup { reason: 'email' | 'tax_code' | 'name'; value: string; customers: DuplicateCard[] }

const groups = ref<DuplicateGroup[] | null>(null);
const error = ref<string | null>(null);
const reasons = {
    email: { label: 'Trùng email', tone: 'danger' as const, hint: 'Rất có thể là cùng một khách.' },
    tax_code: { label: 'Trùng mã số thuế', tone: 'danger' as const, hint: 'Rất có thể là cùng một đơn vị.' },
    name: { label: 'Trùng tên', tone: 'warning' as const, hint: 'Chỉ là gợi ý: hai người có thể trùng tên. Kiểm tra số điện thoại, địa chỉ trước khi gộp.' },
};

async function load() {
    error.value = null;
    try {
        groups.value = (await api.get('/customers/duplicates')).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    }
}
onMounted(load);

// The card with the most orders (then the oldest) is kept by default.
function keeper(g: DuplicateGroup): DuplicateCard {
    return [...g.customers].sort((a, b) => b.orders_count - a.orders_count || String(a.created_at).localeCompare(String(b.created_at)))[0]!;
}
const dialog = ref(false);
const pair = ref({ keep: '', source: '' });
function startMerge(keep: DuplicateCard, source: DuplicateCard) {
    pair.value = { keep: keep.id, source: source.id };
    dialog.value = true;
}
</script>

<template>
    <PageHeader title="Khách hàng" description="Các thẻ khách có thể là cùng một người. ECSO không tự gộp: bạn kiểm tra rồi chọn gộp từng cặp." />
    <CustomersNav />
    <div v-if="groups === null && !error" class="space-y-3"><Skeleton v-for="i in 3" :key="i" class="h-28" /></div>
    <ErrorState v-else-if="error" :message="error" @retry="load" />
    <Card v-else-if="!groups!.length"><EmptyState :icon="UsersRound" title="Không thấy khách trùng" description="Không có hai khách nào trùng email, mã số thuế hoặc tên." /></Card>
    <div v-else class="space-y-4" data-testid="duplicate-groups">
        <Card v-for="g in groups!" :key="g.reason + g.value">
            <div class="flex flex-wrap items-center gap-2 border-b px-4 py-2.5 text-sm">
                <Badge :tone="reasons[g.reason].tone">{{ reasons[g.reason].label }}</Badge>
                <span class="font-medium">{{ g.value }}</span>
                <span class="text-xs text-muted-foreground">{{ reasons[g.reason].hint }}</span>
            </div>
            <ul class="divide-y text-sm">
                <li v-for="c in g.customers" :key="c.id" class="flex flex-wrap items-center gap-3 px-4 py-2.5">
                    <div class="min-w-0 flex-1">
                        <RouterLink :to="`/customers/${c.id}`" class="font-medium hover:text-primary hover:underline">{{ c.name }}</RouterLink>
                        <span class="ml-1.5 font-mono text-xs text-muted-foreground">{{ c.code }}</span>
                        <Badge v-if="c.id === keeper(g).id" tone="success" class="ml-1.5">Đề xuất giữ</Badge>
                        <div class="text-xs text-muted-foreground">{{ [c.phone, c.email, c.address].filter(Boolean).join(' · ') || 'Không có liên hệ' }}</div>
                    </div>
                    <div class="text-right text-xs text-muted-foreground">
                        {{ formatNumber(c.orders_count) }} đơn · {{ formatMoney(c.total_spent) }}<template v-if="Number(c.debt) > 0"> · nợ {{ formatMoney(c.debt) }}</template>
                        <div>Thêm {{ formatDate(c.created_at) }}</div>
                    </div>
                    <Button v-if="c.id !== keeper(g).id" variant="outline" size="sm" :data-testid="`merge-${c.code}`" @click="startMerge(keeper(g), c)"><Merge /> Gộp vào {{ keeper(g).code }}</Button>
                    <span v-else class="w-32" />
                </li>
            </ul>
        </Card>
    </div>
    <MergeDialog v-model:open="dialog" :keep-id="pair.keep" :source-id="pair.source" @merged="load" />
</template>
