<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';
import { cn, formatMoney } from '@/shared/lib/utils';
import { carrierMarks, choiceOf, shortDate, type CarrierChoice, type QuoteOption, type QuoteResult } from './carriers';

/** "So sánh giá": the quick picks (rẻ nhất, nhanh nhất) and every service of every connected account. */
const choice = defineModel<CarrierChoice | null>({ required: true });
defineProps<{ quote: QuoteResult | null; loading?: boolean; error?: string | null }>();

const isPicked = (accountId: string, code: string) => choice.value?.account_id === accountId && choice.value?.service === code;
function pick(q: QuoteOption) {
    choice.value = choiceOf(q);
}
</script>

<template>
    <div class="space-y-3" data-testid="quote-picker">
        <div v-if="loading && !quote" class="flex items-center gap-2 py-6 text-sm text-muted-foreground"><Loader2 class="size-4 animate-spin" /> Đang hỏi giá các hãng…</div>
        <p v-else-if="error" class="text-sm text-destructive">{{ error }}</p>
        <template v-else-if="quote">
            <div class="grid gap-2 sm:grid-cols-2">
                <button
                    v-for="(q, kind) in { cheapest: quote.cheapest, fastest: quote.fastest }"
                    :key="kind"
                    :disabled="!q"
                    :class="cn('rounded-lg border p-3 text-left', q && isPicked(q.account_id, q.code) ? 'border-blue-400 bg-blue-50/70 ring-1 ring-blue-200' : 'hover:bg-muted/50', !q && 'opacity-50')"
                    :data-testid="`quote-${kind}`"
                    @click="q && pick(q)"
                >
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{{ kind === 'cheapest' ? 'Rẻ nhất' : 'Nhanh nhất' }}</div>
                    <template v-if="q">
                        <div class="text-lg font-semibold tabular-nums">{{ formatMoney(q.fee) }}</div>
                        <div class="text-xs text-muted-foreground">{{ q.carrier_label }} · {{ q.name }}<template v-if="q.expected_at"> · dự kiến {{ shortDate(q.expected_at) }}</template></div>
                    </template>
                    <div v-else class="text-sm text-muted-foreground">Chưa có giá</div>
                </button>
            </div>
            <div class="overflow-hidden rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs text-muted-foreground">
                        <tr><th class="w-8 px-3 py-2"></th><th class="px-2 py-2">Hãng · dịch vụ</th><th class="px-2 py-2">Dự kiến giao</th><th class="px-3 py-2 text-right">Phí</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <template v-for="row in quote.accounts" :key="row.account.id">
                            <tr v-if="row.error || !row.services.length" class="text-muted-foreground">
                                <td class="px-3 py-2"></td>
                                <td class="px-2 py-2"><b class="text-foreground">{{ row.account.carrier_label }}</b> · {{ row.account.name }}</td>
                                <td colspan="2" class="px-3 py-2 text-xs text-destructive">{{ row.error ?? 'Hãng không có dịch vụ cho tuyến này.' }}</td>
                            </tr>
                            <tr
                                v-for="s in row.services"
                                :key="row.account.id + s.code"
                                :class="cn('cursor-pointer hover:bg-blue-50/40', isPicked(row.account.id, s.code) && 'bg-blue-50/70')"
                                :data-testid="`quote-row-${s.code}`"
                                @click="pick({ ...s, account_id: row.account.id, carrier_label: row.account.carrier_label })"
                            >
                                <td class="px-3 py-2"><input type="radio" :checked="isPicked(row.account.id, s.code)" class="accent-blue-600" /></td>
                                <td class="px-2 py-2">
                                    <span :class="cn('mr-1.5 rounded px-1 py-0.5 text-[10px] font-bold text-white', carrierMarks[row.account.carrier]?.bg ?? 'bg-slate-500')">{{ carrierMarks[row.account.carrier]?.short ?? row.account.carrier }}</span>
                                    {{ s.name }} <span class="text-xs text-muted-foreground">· {{ row.account.name }}</span>
                                </td>
                                <td class="px-2 py-2 text-xs tabular-nums">{{ shortDate(s.expected_at) || '—' }}</td>
                                <td class="px-3 py-2 text-right font-medium tabular-nums">{{ formatMoney(s.fee) }}</td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <p class="text-[11px] text-muted-foreground">Phí ước tính theo tài khoản của shop, khối lượng {{ quote.weight_g }} g. Phí thật hãng tính sau khi lấy hàng.</p>
        </template>
    </div>
</template>
