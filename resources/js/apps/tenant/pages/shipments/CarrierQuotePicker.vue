<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';
import { cn, formatMoney } from '@/shared/lib/utils';
import CarrierLogo from '@/shared/ui/CarrierLogo.vue';
import { choiceOf, shortDate, type CarrierChoice, type QuoteOption, type QuoteResult } from './carriers';

/** "So sánh giá": the quick picks (rẻ nhất, nhanh nhất) and every service of every connected account. */
const choice = defineModel<CarrierChoice | null>({ required: true });
defineProps<{ quote: QuoteResult | null; loading?: boolean; error?: string | null }>();

const isPicked = (accountId: string, code: string) => choice.value?.account_id === accountId && choice.value?.service === code;
function pick(q: QuoteOption) {
    choice.value = choiceOf(q);
}
</script>

<template>
    <!-- Sapo layout: quick picks as two radio lines, then every service grouped by carrier with the carrier's logo. -->
    <div class="space-y-3" data-testid="quote-picker">
        <div v-if="loading && !quote" class="flex items-center gap-2 py-6 text-sm text-muted-foreground"><Loader2 class="size-4 animate-spin" /> Đang hỏi giá các hãng…</div>
        <p v-else-if="error" class="text-sm text-destructive">{{ error }}</p>
        <template v-else-if="quote">
            <div class="space-y-2 text-sm">
                <button
                    v-for="(q, kind) in { cheapest: quote.cheapest, fastest: quote.fastest }"
                    :key="kind"
                    type="button"
                    :disabled="!q"
                    :class="cn('flex w-full items-center gap-2 text-left', !q && 'opacity-50')"
                    :data-testid="`quote-${kind}`"
                    @click="q && pick(q)"
                >
                    <input type="radio" tabindex="-1" :checked="!!q && isPicked(q.account_id, q.code)" class="pointer-events-none size-4 accent-[#0088ff]" />
                    <template v-if="q">
                        <span>{{ q.carrier_label }} - {{ q.name }}: <b class="tabular-nums">{{ formatMoney(q.fee) }}</b></span>
                    </template>
                    <span v-else class="text-muted-foreground">Chưa có giá</span>
                    <span :class="cn('rounded-[3px] px-1.5 text-xs leading-5', kind === 'cheapest' ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-primary')">{{ kind === 'cheapest' ? 'Rẻ nhất' : 'Nhanh nhất' }}</span>
                </button>
            </div>
            <p class="pt-1 text-sm font-medium">Phí vận chuyển ước tính</p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted text-left">
                        <tr class="h-10"><th class="w-36 px-3 font-medium">Hãng</th><th class="px-3 font-medium">Dịch vụ</th><th class="w-32 px-3 font-medium">Dự kiến giao</th><th class="w-28 px-3 text-right font-medium">Phí</th></tr>
                    </thead>
                    <tbody>
                        <template v-for="row in quote.accounts" :key="row.account.id">
                            <tr v-if="row.error || !row.services.length" class="border-t">
                                <td class="px-3 py-3">
                                    <div class="flex flex-col items-center gap-1.5"><CarrierLogo :carrier="row.account.carrier" :name="row.account.carrier_label" /><span class="max-w-full truncate rounded-[3px] bg-muted px-2 text-[11.5px] leading-5 text-primary">{{ row.account.name }}</span></div>
                                </td>
                                <td colspan="3" class="px-3 py-2 text-[13px] text-destructive">{{ row.error ?? 'Hãng không có dịch vụ cho tuyến này.' }}</td>
                            </tr>
                            <template v-else>
                            <tr
                                v-for="(s, i) in row.services"
                                :key="row.account.id + s.code"
                                :class="cn(i === 0 && 'border-t')"
                                :data-testid="`quote-row-${s.code}`"
                                @click="pick({ ...s, account_id: row.account.id, carrier_label: row.account.carrier_label })"
                            >
                                <td v-if="i === 0" :rowspan="row.services.length" class="px-3 py-3 align-middle">
                                    <div class="flex flex-col items-center gap-1.5"><CarrierLogo :carrier="row.account.carrier" :name="row.account.carrier_label" /><span class="max-w-full truncate rounded-[3px] bg-muted px-2 text-[11.5px] leading-5 text-primary">{{ row.account.name }}</span></div>
                                </td>
                                <td :class="cn('cursor-pointer px-3 py-2 hover:text-primary', isPicked(row.account.id, s.code) && 'text-primary')">
                                    <span class="flex items-center gap-2"><input type="radio" tabindex="-1" :checked="isPicked(row.account.id, s.code)" class="pointer-events-none size-4 accent-[#0088ff]" /> {{ s.name }}</span>
                                </td>
                                <td class="cursor-pointer px-3 py-2 text-muted-foreground tabular-nums">{{ shortDate(s.expected_at) || '—' }}</td>
                                <td class="cursor-pointer px-3 py-2 text-right tabular-nums">{{ formatMoney(s.fee) }}</td>
                            </tr>
                            </template>
                        </template>
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-muted-foreground">Phí ước tính theo tài khoản của shop, khối lượng {{ quote.weight_g }} g. Phí thật hãng tính sau khi lấy hàng.</p>
        </template>
    </div>
</template>
