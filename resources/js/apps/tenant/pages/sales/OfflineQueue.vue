<script setup lang="ts">
import { ref } from 'vue';
import { CheckCircle2, CloudOff, Clock, RefreshCw, TriangleAlert } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import { formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { paymentMethods } from '../orders/labels';
import type { OfflineSale } from './offline';

/** Bán khi mất mạng: the sales kept on this device, their state, and what to do with one the server refused. */
defineProps<{ queue: OfflineSale[]; online: boolean; syncing: boolean; catalogAt: string | null; catalogCount: number }>();
const emit = defineEmits<{ sync: []; retry: [OfflineSale, boolean]; drop: [OfflineSale] }>();
const confirming = ref<string | null>(null);

function drop(s: OfflineSale) {
    if (confirming.value !== s.key) {
        confirming.value = s.key;
        return;
    }
    confirming.value = null;
    emit('drop', s);
}
</script>

<template>
    <div class="space-y-4 text-sm">
        <div :class="['flex items-start gap-3 rounded-lg p-3', online ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-900']">
            <CloudOff v-if="!online" class="mt-0.5 size-4 shrink-0" /><CheckCircle2 v-else class="mt-0.5 size-4 shrink-0" />
            <div class="flex-1">
                <b>{{ online ? 'Đã có mạng.' : 'Đang mất mạng.' }}</b>
                {{ online ? 'Đơn chờ gửi được gửi ngay.' : 'Quét mã, tìm hàng, thu tiền và in hoá đơn vẫn làm được. Khuyến mãi, mã giảm giá và điểm cần có mạng.' }}
                <div class="mt-1 text-xs opacity-80">Danh sách hàng trên máy: {{ formatNumber(catalogCount) }} mã<template v-if="catalogAt">, cập nhật {{ formatDateTime(catalogAt) }}</template>.</div>
            </div>
            <Button v-if="online && queue.some((s) => s.status === 'pending')" size="sm" :loading="syncing" @click="emit('sync')"><RefreshCw /> Gửi ngay</Button>
        </div>

        <ul v-if="queue.length" class="divide-y rounded-lg border" data-testid="pos-offline-list">
            <li v-for="s in [...queue].reverse()" :key="s.key" class="space-y-2 p-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <span class="font-mono font-medium">{{ s.number }}</span>
                        <span v-if="s.order_number" class="text-muted-foreground"> → {{ s.order_number }}</span>
                        <div class="text-xs text-muted-foreground">{{ formatDateTime(s.created_at) }} · {{ s.summary.items }} sản phẩm · {{ s.summary.customer ?? 'Khách lẻ' }} · {{ paymentMethods[s.summary.method] }}</div>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="font-semibold tabular-nums">{{ formatMoney(s.summary.total) }}</span>
                        <span v-if="s.status === 'sent'" class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700"><CheckCircle2 class="size-3.5" /> Đã gửi</span>
                        <span v-else-if="s.status === 'pending'" class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800"><Clock class="size-3.5" /> Chờ gửi</span>
                        <span v-else class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700"><TriangleAlert class="size-3.5" /> Chưa gửi được</span>
                    </div>
                </div>
                <div v-if="s.status === 'failed'" class="rounded-md bg-rose-50 p-2.5 text-rose-800">
                    <p>{{ s.error }}</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <Button size="sm" variant="outline" :disabled="!online" @click="emit('retry', s, false)">Gửi lại</Button>
                        <Button size="sm" variant="outline" :disabled="!online" title="Lưu thành đơn chờ xử lý, giữ tiền đã thu; xử lý kho sau" @click="emit('retry', s, true)">Lưu thành đơn chờ xử lý</Button>
                        <Button size="sm" variant="ghost" class="text-rose-700" @click="drop(s)">{{ confirming === s.key ? 'Bấm lần nữa để xoá hẳn' : 'Xoá đơn này' }}</Button>
                    </div>
                </div>
            </li>
        </ul>
        <p v-else class="py-6 text-center text-muted-foreground">Chưa có đơn nào bán lúc mất mạng.</p>
    </div>
</template>
