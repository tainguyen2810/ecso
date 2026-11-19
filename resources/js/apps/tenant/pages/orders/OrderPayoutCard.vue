<script setup lang="ts">
import { ref, watch } from 'vue';
import { RefreshCw, Wallet } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDateTime, formatMoney } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import type { OrderPayout } from '@/shared/types';

/**
 * Đợt 5 "Tiền thực nhận": what the marketplace pays for this order after its fees (read from the sàn, "Tạm tính" until
 * it settles), then the cost of goods and the profit for members who may see them.
 */
const props = defineProps<{ orderId: string; payout: OrderPayout; marketplaceName: string }>();
const toast = useToast();
const data = ref<OrderPayout>(props.payout);
const busy = ref(false);
watch(() => props.payout, (v) => (data.value = v));

async function refresh() {
    busy.value = true;
    try {
        data.value = (await api.post(`/orders/${props.orderId}/payout`)).data.data;
        if (!data.value.settlement) toast.info(`${props.marketplaceName} chưa có số liệu cho đơn này. Thường có sau khi đơn giao thành công.`);
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = false;
    }
}
const sign = (v: string) => (Number(v) < 0 ? `−${formatMoney(Math.abs(Number(v)))}` : formatMoney(v));
</script>

<template>
    <div class="border-t px-5 py-4" data-testid="order-payout">
        <div class="mb-2 flex items-center gap-2">
            <Wallet class="size-4 text-slate-400" />
            <h3 class="text-sm font-semibold">Tiền thực nhận</h3>
            <Badge v-if="data.settlement" :tone="data.settlement.final ? 'success' : 'warning'">{{ data.settlement.final ? 'Sàn đã chốt' : 'Tạm tính' }}</Badge>
            <Button v-if="data.supported" size="sm" variant="ghost" class="ml-auto" :loading="busy" data-testid="payout-refresh" @click="refresh"><RefreshCw /> Cập nhật từ sàn</Button>
        </div>
        <template v-if="data.settlement">
            <dl class="ml-auto grid w-full grid-cols-2 gap-y-1.5 text-sm sm:w-80">
                <template v-for="(l, i) in data.settlement.lines" :key="i">
                    <dt class="text-muted-foreground">{{ l.label }}</dt>
                    <dd :class="['text-right tabular-nums', Number(l.amount) < 0 && 'text-rose-600']">{{ sign(l.amount) }}</dd>
                </template>
                <template v-if="!data.settlement.lines.length">
                    <dt class="text-muted-foreground">Tiền hàng</dt><dd class="text-right tabular-nums">{{ formatMoney(data.settlement.order_amount) }}</dd>
                    <dt class="text-muted-foreground">Phí sàn</dt><dd class="text-right tabular-nums text-rose-600">−{{ formatMoney(data.settlement.fees ?? 0) }}</dd>
                </template>
                <dt class="mt-1 border-t pt-2 font-semibold">Shop nhận</dt>
                <dd class="mt-1 border-t pt-2 text-right text-lg font-bold tabular-nums text-emerald-700" data-testid="payout-net">{{ formatMoney(data.settlement.net_amount) }}</dd>
                <template v-if="data.cogs !== null">
                    <dt class="text-muted-foreground">Giá vốn<span v-if="data.cogs_estimated" class="text-xs"> (giá vốn hiện tại)</span></dt>
                    <dd class="text-right tabular-nums">−{{ formatMoney(data.cogs) }}</dd>
                </template>
                <template v-if="data.profit !== null">
                    <dt class="font-semibold">Lãi</dt>
                    <dd :class="['text-right font-bold tabular-nums', Number(data.profit) < 0 ? 'text-rose-600' : 'text-emerald-700']" data-testid="payout-profit">{{ sign(data.profit) }}</dd>
                </template>
            </dl>
            <p class="mt-2 text-right text-xs text-muted-foreground">
                <template v-if="data.settlement.final">Sàn chốt {{ data.settlement.settled_at ? formatDateTime(data.settlement.settled_at) : '' }}.</template>
                <template v-else>Số sàn tạm tính, có thể đổi đến khi sàn chốt tiền (thường vài ngày sau khi giao).</template>
                <template v-if="data.settlement.source === 'file'"> Lấy từ file đối soát.</template>
            </p>
        </template>
        <p v-else class="text-sm text-muted-foreground">
            Chưa có số liệu từ sàn. {{ data.supported ? 'Phần mềm tự lấy sau khi đơn giao thành công, hoặc bấm "Cập nhật từ sàn".' : 'Nhập file đối soát ở Cấu hình › Phí sàn để có số này.' }}
        </p>
    </div>
</template>
