<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { Download, Info, Wallet } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import RangePicker from './RangePicker.vue';
import ReportsNav from './ReportsNav.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatMoney } from '@/shared/lib/utils';
import { paymentMethods } from '../orders/labels';
import { downloadCsv, presetRange, toCsv } from './range';

interface Entry {
    paid_at: string;
    type: 'payment' | 'refund' | 'supplier';
    method: string;
    amount: number;
    balance: number;
    document: { id: string; number: string; kind: 'order' | 'purchase' } | null;
    party: string | null;
    reference: string | null;
    created_by: string | null;
}
interface Book {
    opening: number;
    in: number;
    out: number;
    closing: number;
    by_method: { method: string; in: number; out: number }[];
    rows: Entry[];
}

const types: Record<Entry['type'], string> = { payment: 'Thu tiền bán hàng', refund: 'Hoàn tiền khách', supplier: 'Chi trả nhà cung cấp' };
const range = ref(presetRange('month'));
const method = ref('');
const data = ref<Book | null>(null);
const error = ref<string | null>(null);
const loading = ref(false);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        data.value = (await api.get('/reports/cashbook', { params: { ...range.value, method: method.value || undefined } })).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
watch([range, method], load, { deep: true });

function exportCsv() {
    if (!data.value) return;
    downloadCsv(
        `so-quy-${method.value || 'tat-ca'}-${range.value.from}-${range.value.to}.csv`,
        toCsv(
            ['Thời gian', 'Loại', 'Hình thức', 'Chứng từ', 'Đối tượng', 'Tham chiếu', 'Thu', 'Chi', 'Tồn quỹ', 'Người lập'],
            [...data.value.rows].reverse().map((e) => [formatDateTime(e.paid_at), types[e.type], paymentMethods[e.method] ?? e.method, e.document?.number ?? '', e.party, e.reference, e.amount > 0 ? e.amount : null, e.amount < 0 ? -e.amount : null, e.balance, e.created_by]),
        ),
    );
}
</script>

<template>
    <PageHeader title="Báo cáo" description="Sổ quỹ: tiền thu từ khách kênh riêng, hoàn tiền và chi trả nhà cung cấp.">
        <RangePicker v-model:from="range.from" v-model:to="range.to" />
    </PageHeader>
    <ReportsNav />
    <ErrorState v-if="error" :message="error" @retry="load" />
    <div v-else-if="!data" class="grid gap-4 md:grid-cols-4"><Skeleton v-for="i in 4" :key="i" class="h-24" /></div>
    <template v-else>
        <div class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-testid="cashbook-kpis">
            <Card><div class="p-4"><p class="text-xs text-muted-foreground">Tồn đầu kỳ</p><p class="mt-1 text-2xl font-semibold tabular-nums">{{ formatMoney(data.opening) }}</p></div></Card>
            <Card><div class="p-4"><p class="text-xs text-muted-foreground">Tổng thu</p><p class="mt-1 text-2xl font-semibold tabular-nums text-success">+{{ formatMoney(data.in) }}</p></div></Card>
            <Card><div class="p-4"><p class="text-xs text-muted-foreground">Tổng chi</p><p class="mt-1 text-2xl font-semibold tabular-nums text-destructive">−{{ formatMoney(data.out) }}</p></div></Card>
            <Card><div class="p-4"><p class="text-xs text-muted-foreground">Tồn cuối kỳ</p><p class="mt-1 text-2xl font-semibold tabular-nums" data-testid="cashbook-closing">{{ formatMoney(data.closing) }}</p></div></Card>
        </div>
        <Card>
            <div class="flex flex-wrap items-center gap-2 border-b p-3">
                <Select v-model="method" class="w-52" :options="[{ value: '', label: 'Tất cả hình thức' }, ...Object.entries(paymentMethods).map(([value, label]) => ({ value, label: value === 'cash' ? 'Tiền mặt (sổ quỹ tiền mặt)' : label }))]" aria-label="Hình thức" data-testid="cashbook-method" />
                <div class="flex flex-wrap gap-3 text-xs text-muted-foreground">
                    <span v-for="m in data.by_method" :key="m.method">{{ paymentMethods[m.method] ?? m.method }}: <span class="text-success">+{{ formatMoney(m.in) }}</span> / <span class="text-destructive">−{{ formatMoney(m.out) }}</span></span>
                </div>
                <Button variant="outline" size="sm" class="ml-auto" @click="exportCsv"><Download /> Xuất CSV</Button>
            </div>
            <EmptyState v-if="!data.rows.length" :icon="Wallet" title="Không có phiếu thu chi" description="Chưa có khoản thu, hoàn hay chi nào trong kỳ này." />
            <div v-else class="overflow-x-auto" :class="loading && 'opacity-60'">
                <table class="w-full text-sm" data-testid="cashbook-table">
                    <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Thời gian</th>
                            <th class="px-4 py-2.5 font-medium">Nội dung</th>
                            <th class="px-4 py-2.5 font-medium">Chứng từ</th>
                            <th class="px-4 py-2.5 text-right font-medium">Thu</th>
                            <th class="px-4 py-2.5 text-right font-medium">Chi</th>
                            <th class="px-4 py-2.5 text-right font-medium">Tồn quỹ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(e, i) in data.rows" :key="i">
                            <td class="whitespace-nowrap px-4 py-2 text-muted-foreground">{{ formatDateTime(e.paid_at) }}<div class="text-xs">{{ e.created_by }}</div></td>
                            <td class="px-4 py-2">{{ types[e.type] }} · {{ paymentMethods[e.method] ?? e.method }}<div class="text-xs text-muted-foreground">{{ e.party }}<template v-if="e.reference"> · {{ e.reference }}</template></div></td>
                            <td class="px-4 py-2"><RouterLink v-if="e.document" :to="e.document.kind === 'order' ? `/orders/${e.document.id}` : `/purchasing/${e.document.id}`" class="font-mono text-xs hover:underline">{{ e.document.number }}</RouterLink><span v-else class="text-xs text-muted-foreground">Thanh toán chung</span></td>
                            <td class="px-4 py-2 text-right tabular-nums text-success">{{ e.amount > 0 ? formatMoney(e.amount) : '' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-destructive">{{ e.amount < 0 ? formatMoney(-e.amount) : '' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ formatMoney(e.balance) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="flex items-start gap-2 border-t px-4 py-2.5 text-xs text-muted-foreground"><Info class="mt-0.5 size-3.5 shrink-0" /> Tiền sàn chuyển về (payout) chưa có ở đây vì cần dữ liệu đối soát từ API sàn. Phiếu đã hủy không được tính.</p>
        </Card>
    </template>
</template>
