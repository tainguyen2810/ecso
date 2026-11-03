<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue';
import { Clock } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api } from '@/shared/lib/api';
import { formatDateTime, formatMoney } from '@/shared/lib/utils';
import ShiftPanel from './ShiftPanel.vue';
import { differenceLabel } from './shift';
import type { CashShift } from '@/shared/types';

const shifts = ref<CashShift[]>([]);
const loading = ref(true);
const canViewAll = ref(false);
const filters = reactive({ status: '', from: '', to: '' });
const meta = ref<{ current_page: number; last_page: number; total: number } | null>(null);
const page = ref(1);
const selected = ref<CashShift | null>(null);
const detailOpen = ref(false);

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/pos/shifts', { params: { status: filters.status || undefined, from: filters.from || undefined, to: filters.to || undefined, page: page.value } });
        shifts.value = data.data;
        canViewAll.value = data.meta.can_view_all;
        meta.value = data.meta;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
watch(filters, () => {
    page.value = 1;
    load();
});

async function show(s: CashShift) {
    const { data } = await api.get(`/pos/shifts/${s.id}`);
    selected.value = data.data;
    detailOpen.value = true;
}

function onChanged(s: CashShift) {
    selected.value = s;
    load();
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Ca bán hàng</h1>
                <p class="text-sm text-muted-foreground">{{ canViewAll ? 'Mọi ca của các thu ngân' : 'Các ca của bạn' }}: tiền đầu ca, tiền thu, đếm két và chênh lệch khi đóng ca.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Select v-model="filters.status" class="w-36" :options="[{ value: '', label: 'Mọi trạng thái' }, { value: 'open', label: 'Đang mở' }, { value: 'closed', label: 'Đã đóng' }]" aria-label="Trạng thái" />
                <Input v-model="filters.from" type="date" class="w-40" aria-label="Từ ngày" />
                <Input v-model="filters.to" type="date" class="w-40" aria-label="Đến ngày" />
            </div>
        </div>

        <Card class="overflow-x-auto">
            <div v-if="loading" class="space-y-2 p-4"><Skeleton v-for="i in 4" :key="i" class="h-8" /></div>
            <EmptyState v-else-if="!shifts.length" :icon="Clock" title="Chưa có ca nào" description="Thu ngân mở ca ở màn hình Bán tại quầy." />
            <table v-else class="w-full text-sm" data-testid="shift-list">
                <thead class="border-b bg-muted/40 text-left text-xs text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2">Ca</th><th class="px-3 py-2">Thu ngân</th><th class="px-3 py-2">Mở / đóng</th>
                        <th class="px-3 py-2 text-right">Số đơn</th><th class="px-3 py-2 text-right">Đã thu</th>
                        <th class="px-3 py-2 text-right">Tiền mặt hệ thống</th><th class="px-3 py-2 text-right">Thực tế</th><th class="px-3 py-2 text-right">Chênh lệch</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="s in shifts" :key="s.id" class="cursor-pointer hover:bg-muted/40" @click="show(s)">
                        <td class="px-3 py-2"><span class="font-medium">{{ s.number }}</span> <Badge v-if="s.status === 'open'" tone="success">Đang mở</Badge></td>
                        <td class="px-3 py-2">{{ s.cashier?.name }}<span v-if="s.warehouse" class="block text-xs text-muted-foreground">{{ s.warehouse.name }}</span></td>
                        <td class="px-3 py-2 text-xs">{{ formatDateTime(s.opened_at) }}<br /><span class="text-muted-foreground">{{ s.closed_at ? formatDateTime(s.closed_at) : '—' }}</span></td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ s.totals.orders }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ formatMoney(s.totals.collected) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ formatMoney(s.expected_cash ?? s.totals.expected_cash) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ s.counted_cash !== null ? formatMoney(s.counted_cash) : '—' }}</td>
                        <td class="px-3 py-2 text-right" :class="s.difference === null ? '' : Number(s.difference) === 0 ? 'text-success' : 'text-destructive font-medium'">{{ s.difference === null ? '—' : differenceLabel(Number(s.difference)) }}</td>
                    </tr>
                </tbody>
            </table>
        </Card>
        <div v-if="meta && meta.last_page > 1" class="flex justify-end gap-2">
            <Button variant="outline" size="sm" :disabled="page <= 1" @click="(page--, load())">Trước</Button>
            <Button variant="outline" size="sm" :disabled="page >= meta.last_page" @click="(page++, load())">Sau</Button>
        </div>
    </div>

    <Dialog v-model:open="detailOpen" :title="selected ? `Ca ${selected.number}` : 'Ca'" size="lg">
        <ShiftPanel v-if="selected" :shift="selected" @changed="onChanged" />
    </Dialog>
</template>
