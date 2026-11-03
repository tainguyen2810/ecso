<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { ChevronDown, ChevronRight, Plus, Search, Trash2, Wallet } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Pagination from '../../components/Pagination.vue';
import SkuPicker from '../../components/SkuPicker.vue';
import InventoryNav from './InventoryNav.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import type { Variant } from '@/shared/types';

interface Line {
    variant: { id: string; sku: string; name: string };
    old_cost: string;
    new_cost: string;
    on_hand: number;
    value_change: string;
}
interface Doc {
    id: string;
    number: string;
    note: string | null;
    creator: string | null;
    created_at: string;
    lines: Line[];
}

const tenant = useTenantStore();
const toast = useToast();
const canCreate = computed(() => tenant.can('inventory.adjust.approve'));

const docs = ref<Doc[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const q = ref('');
const expanded = ref<Set<string>>(new Set());

const open = ref(false);
const note = ref('');
const rows = ref<{ variant: Variant; new_cost: string }[]>([]);
const saving = ref(false);
const error = ref<ApiError | null>(null);

async function load(page = 1) {
    loading.value = true;
    try {
        const { data } = await api.get('/inventory/cost-adjustments', { params: { q: q.value || undefined, page } });
        docs.value = data.data;
        meta.value = data.meta;
    } finally {
        loading.value = false;
    }
}
onMounted(() => load());
let timer: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => load(), 250);
});

function add(v: Variant) {
    if (rows.value.some((r) => r.variant.id === v.id)) return;
    rows.value.push({ variant: v, new_cost: Number(v.cost_price) ? String(Math.round(Number(v.cost_price))) : '' });
}

const diff = (r: { variant: Variant; new_cost: string }) => (r.new_cost === '' ? 0 : Number(r.new_cost) - Number(r.variant.cost_price ?? 0));
const totalLines = (d: Doc) => d.lines.reduce((s, l) => s + Number(l.value_change), 0);

async function submit() {
    saving.value = true;
    error.value = null;
    try {
        const { data } = await api.post('/inventory/cost-adjustments', {
            note: note.value || null,
            lines: rows.value.map((r) => ({ variant_id: r.variant.id, new_cost: r.new_cost === '' ? null : Number(r.new_cost) })),
        });
        toast.success(`Đã tạo phiếu ${data.data.number}, giá vốn đã cập nhật`);
        open.value = false;
        rows.value = [];
        note.value = '';
        expanded.value = new Set([data.data.id]);
        await load();
    } catch (e) {
        error.value = e as ApiError;
        toast.error(error.value.body.errors ? 'Vui lòng kiểm tra các dòng được đánh dấu.' : error.value.message);
    } finally {
        saving.value = false;
    }
}

function toggle(id: string) {
    const s = new Set(expanded.value);
    s.has(id) ? s.delete(id) : s.add(id);
    expanded.value = s;
}
</script>

<template>
    <PageHeader title="Kho hàng" description="Điều chỉnh giá vốn bình quân khi giá nhập ghi sai hoặc có chiết khấu sau. Số lượng tồn không đổi; lần nhập sau sẽ tính bình quân từ giá mới.">
        <Button v-if="canCreate" data-testid="new-cost-adjustment" @click="open = true"><Plus /> Tạo phiếu điều chỉnh giá vốn</Button>
    </PageHeader>
    <InventoryNav />

    <Card>
        <div class="border-b px-4 py-3">
            <div class="relative w-full sm:w-72">
                <Search class="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                <Input v-model="q" placeholder="Số phiếu hoặc SKU" class="pl-8" />
            </div>
        </div>
        <div v-if="loading && !docs.length" class="space-y-3 p-4"><Skeleton v-for="i in 4" :key="i" class="h-10" /></div>
        <EmptyState v-else-if="!docs.length" :icon="Wallet" title="Chưa có phiếu điều chỉnh giá vốn" description="Mỗi phiếu ghi lại giá vốn cũ và mới của từng SKU, ai làm và lúc nào." />
        <table v-else class="w-full text-sm" data-testid="cost-adjustment-table">
            <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                <tr>
                    <th class="px-4 py-2.5 font-medium">Số phiếu</th>
                    <th class="px-4 py-2.5 font-medium">Thời gian</th>
                    <th class="px-4 py-2.5 font-medium">Người tạo</th>
                    <th class="px-4 py-2.5 text-right font-medium">Số SKU</th>
                    <th class="px-4 py-2.5 text-right font-medium">Chênh lệch giá trị tồn</th>
                    <th class="px-4 py-2.5 font-medium">Ghi chú</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <template v-for="d in docs" :key="d.id">
                    <tr class="cursor-pointer hover:bg-muted/30" @click="toggle(d.id)">
                        <td class="px-4 py-2.5 font-mono text-xs font-medium">
                            <ChevronDown v-if="expanded.has(d.id)" class="mr-1 inline size-4" /><ChevronRight v-else class="mr-1 inline size-4" />{{ d.number }}
                        </td>
                        <td class="px-4 py-2.5 text-muted-foreground">{{ formatDateTime(d.created_at) }}</td>
                        <td class="px-4 py-2.5">{{ d.creator ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ d.lines.length }}</td>
                        <td :class="cn('px-4 py-2.5 text-right tabular-nums', totalLines(d) < 0 && 'text-destructive', totalLines(d) > 0 && 'text-success')">{{ formatMoney(totalLines(d)) }}</td>
                        <td class="max-w-64 truncate px-4 py-2.5 text-muted-foreground">{{ d.note ?? '' }}</td>
                    </tr>
                    <tr v-if="expanded.has(d.id)" class="bg-muted/20">
                        <td colspan="6" class="px-4 py-2">
                            <table class="ml-6 w-[calc(100%-1.5rem)] text-xs">
                                <tr class="text-muted-foreground">
                                    <th class="py-1 text-left font-medium">SKU</th>
                                    <th class="py-1 text-right font-medium">Giá vốn cũ</th>
                                    <th class="py-1 text-right font-medium">Giá vốn mới</th>
                                    <th class="py-1 text-right font-medium">Tồn lúc điều chỉnh</th>
                                    <th class="py-1 text-right font-medium">Chênh lệch giá trị</th>
                                </tr>
                                <tr v-for="l in d.lines" :key="l.variant.id" class="border-t border-dashed">
                                    <td class="py-1.5"><span class="font-mono">{{ l.variant.sku }}</span> <span class="text-muted-foreground">{{ l.variant.name }}</span></td>
                                    <td class="py-1.5 text-right tabular-nums">{{ formatMoney(l.old_cost) }}</td>
                                    <td class="py-1.5 text-right font-medium tabular-nums">{{ formatMoney(l.new_cost) }}</td>
                                    <td class="py-1.5 text-right tabular-nums">{{ formatNumber(l.on_hand) }}</td>
                                    <td class="py-1.5 text-right tabular-nums">{{ formatMoney(l.value_change) }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @go="load" />
    </Card>

    <Dialog v-model:open="open" title="Tạo phiếu điều chỉnh giá vốn" description="Giá vốn mới áp dụng ngay khi tạo phiếu." size="xl">
        <div class="space-y-4">
            <SkuPicker stocked placeholder="Quét hoặc tìm SKU cần điều chỉnh" :exclude="rows.map((r) => r.variant.id!)" @pick="add" />
            <p v-if="error?.field('lines')" class="text-sm text-destructive">{{ error.field('lines') }}</p>
            <table v-if="rows.length" class="w-full text-sm" data-testid="cost-lines">
                <thead class="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="py-2 font-medium">SKU</th>
                        <th class="py-2 text-right font-medium">Giá vốn hiện tại</th>
                        <th class="py-2 text-right font-medium">Giá vốn mới</th>
                        <th class="py-2 text-right font-medium">Chênh lệch</th>
                        <th class="w-10" />
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="(r, i) in rows" :key="r.variant.id">
                        <td class="py-2">
                            <p class="font-mono text-xs font-medium">{{ r.variant.sku }}</p>
                            <p class="text-xs text-muted-foreground">{{ r.variant.product?.name }}<span v-if="r.variant.name"> · {{ r.variant.name }}</span></p>
                        </td>
                        <td class="py-2 text-right tabular-nums">{{ formatMoney(r.variant.cost_price ?? 0) }}</td>
                        <td class="py-2 text-right">
                            <input v-model="r.new_cost" type="number" min="0" required :data-testid="`new-cost-${i}`" aria-label="Giá vốn mới" :class="cn('h-8 w-32 rounded-md border border-input bg-card px-2 text-right tabular-nums', error?.field(`lines.${i}.new_cost`) && 'border-destructive')" />
                        </td>
                        <td :class="cn('py-2 text-right tabular-nums', diff(r) < 0 && 'text-destructive', diff(r) > 0 && 'text-success')">{{ diff(r) > 0 ? '+' : '' }}{{ formatMoney(diff(r)) }}</td>
                        <td class="text-right"><Button variant="ghost" size="icon" class="size-8" title="Bỏ dòng" @click="rows.splice(i, 1)"><Trash2 class="text-muted-foreground" /></Button></td>
                    </tr>
                </tbody>
            </table>
            <FormField label="Ghi chú" for="cad-note">
                <Input id="cad-note" v-model="note" maxlength="1000" placeholder="Ví dụ: NCC chiết khấu 5% sau khi nhập" />
            </FormField>
        </div>
        <template #footer>
            <Button variant="outline" @click="open = false">Hủy</Button>
            <Button :disabled="!rows.length || rows.some((r) => r.new_cost === '')" :loading="saving" data-testid="submit-cost-adjustment" @click="submit">Tạo phiếu và áp dụng</Button>
        </template>
    </Dialog>
</template>
