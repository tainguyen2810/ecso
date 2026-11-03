<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { CircleHelp, Download, Info, Search, Wallet } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import RangePicker from './RangePicker.vue';
import ReportsNav from './ReportsNav.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
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
const direction = ref<'all' | 'in' | 'out'>('all');
const search = ref('');
const glossary = ref(false);
const directions = { all: 'Tất cả', in: 'Thu', out: 'Chi' } as const;
const terms = [
    ['Tồn đầu kỳ', 'Tiền trong quỹ tính đến hết ngày trước ngày bắt đầu kỳ đang xem.'],
    ['Tổng thu', 'Tiền thu vào trong kỳ: khách trả tiền đơn hàng.'],
    ['Tổng chi', 'Tiền chi ra trong kỳ: hoàn tiền cho khách và trả tiền nhà cung cấp.'],
    ['Tồn cuối kỳ', 'Tồn đầu kỳ + Tổng thu − Tổng chi, tức tiền trong quỹ tính đến hết ngày cuối kỳ.'],
    ['Tồn quỹ từng dòng', 'Số dư ngay sau khoản thu hoặc chi đó, cộng dồn từ tồn đầu kỳ.'],
    ['Hình thức', 'Chọn Tiền mặt để xem riêng quỹ tiền mặt. Các hình thức khác là tiền qua ngân hàng, thẻ, ví.'],
] as const;

// Tabs and search only narrow the list; the totals and running balance stay those of the whole book.
const rows = computed(() => {
    const q = search.value.trim().toLowerCase();
    return (data.value?.rows ?? []).filter(
        (e) =>
            (direction.value === 'all' || (direction.value === 'in' ? e.amount > 0 : e.amount < 0)) &&
            (!q || [e.document?.number, e.party, e.reference, e.created_by].some((v) => v?.toLowerCase().includes(q))),
    );
});

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
        <Button variant="ghost" size="sm" data-testid="cashbook-glossary" @click="glossary = true"><CircleHelp /> Giải thích thuật ngữ</Button>
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
            <div class="flex gap-1 border-b px-3 pt-2" role="tablist">
                <button
                    v-for="(label, key) in directions"
                    :key="key"
                    type="button"
                    role="tab"
                    :aria-selected="direction === key"
                    :data-testid="`cashbook-tab-${key}`"
                    class="-mb-px border-b-2 px-3 py-2 text-sm"
                    :class="direction === key ? 'border-primary font-medium text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'"
                    @click="direction = key"
                >
                    {{ label }}
                </button>
            </div>
            <div class="flex flex-wrap items-center gap-2 border-b p-3">
                <div class="relative w-full sm:w-64">
                    <Search class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input v-model="search" class="pl-8" placeholder="Tìm chứng từ, đối tượng, tham chiếu" aria-label="Tìm trong sổ quỹ" data-testid="cashbook-search" />
                </div>
                <Select v-model="method" class="w-52" :options="[{ value: '', label: 'Tất cả hình thức' }, ...Object.entries(paymentMethods).map(([value, label]) => ({ value, label: value === 'cash' ? 'Tiền mặt (sổ quỹ tiền mặt)' : label }))]" aria-label="Hình thức" data-testid="cashbook-method" />
                <div class="flex flex-wrap gap-3 text-xs text-muted-foreground">
                    <span v-for="m in data.by_method" :key="m.method">{{ paymentMethods[m.method] ?? m.method }}: <span class="text-success">+{{ formatMoney(m.in) }}</span> / <span class="text-destructive">−{{ formatMoney(m.out) }}</span></span>
                </div>
                <Button variant="outline" size="sm" class="ml-auto" @click="exportCsv"><Download /> Xuất CSV</Button>
            </div>
            <EmptyState v-if="!data.rows.length" :icon="Wallet" title="Không có phiếu thu chi" description="Chưa có khoản thu, hoàn hay chi nào trong kỳ này." />
            <EmptyState v-else-if="!rows.length" :icon="Search" title="Không có dòng phù hợp" description="Thử đổi tab hoặc từ khóa tìm kiếm." />
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
                        <tr v-for="(e, i) in rows" :key="i">
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
    <Dialog v-model:open="glossary" title="Giải thích thuật ngữ" size="lg">
        <dl class="divide-y text-sm">
            <div v-for="[term, meaning] in terms" :key="term" class="grid gap-1 py-2.5 sm:grid-cols-[10rem_1fr]">
                <dt class="font-medium">{{ term }}</dt>
                <dd class="text-muted-foreground">{{ meaning }}</dd>
            </div>
        </dl>
    </Dialog>
</template>
