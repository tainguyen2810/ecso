<script setup lang="ts">
import { computed } from 'vue';
import { Plus, X } from 'lucide-vue-next';
import DateInput from '@/shared/ui/DateInput.vue';
import { cn } from '@/shared/lib/utils';
import { displayDate } from '@/shared/lib/dates';
import { blankLot, daysLeft, DEFAULT_WARN_DAYS, effectiveExpiry, lotProblem, type LotDraft, type LotRules } from '../pages/inventory/lots';

/**
 * Lots of one receipt line: lot number, manufacturing date, expiry date and quantity per lot. With one lot its
 * quantity follows the line's; with several the quantities must add up to it (checked here and on the server).
 */
const lots = defineModel<LotDraft[]>({ required: true });
const props = defineProps<{ quantity: number; rules: LotRules; error?: string; testid?: string }>();

const single = computed(() => lots.value.length === 1);
// With one lot its quantity is the line's.
const problem = computed(() => lotProblem(single.value ? [{ ...lots.value[0]!, quantity: String(props.quantity) }] : lots.value, props.quantity, props.rules));
const warnDays = computed(() => props.rules.expiry_warn_days ?? DEFAULT_WARN_DAYS);

function add() {
    const used = lots.value.reduce((t, l) => t + (Number(l.quantity) || 0), 0);
    lots.value.push(blankLot(String(Math.max(props.quantity - used, 0) || '')));
}

function hint(l: LotDraft): { text: string; tone: 'muted' | 'warn' | 'bad' } | null {
    const expiry = effectiveExpiry(l, props.rules);
    const left = daysLeft(expiry);
    if (left === null) return null;
    const auto = !l.expiry_date ? `HSD tự tính ${displayDate(expiry)} · ` : '';
    if (left < 0) return { text: `${auto}Đã hết hạn ${-left} ngày`, tone: 'bad' };
    if (left <= warnDays.value) return { text: `${auto}Còn ${left} ngày, dưới ngưỡng cảnh báo ${warnDays.value} ngày`, tone: 'warn' };
    return auto ? { text: `${auto}còn ${left} ngày`, tone: 'muted' } : null;
}
</script>

<template>
    <div class="space-y-2 rounded-md border border-sky-100 bg-sky-50/50 p-3" :data-testid="testid">
        <div v-for="(l, i) in lots" :key="i" class="flex flex-wrap items-start gap-2">
            <div class="w-36">
                <label v-if="i === 0" class="mb-1 block text-[11px] font-medium text-muted-foreground">Số lô</label>
                <input
                    v-model="l.lot_no"
                    maxlength="64"
                    placeholder="VD: L2509A"
                    :data-testid="`${testid}-no-${i}`"
                    :class="cn('h-8 w-full rounded-md border border-input bg-card px-2 text-sm', error && !l.lot_no.trim() && 'border-destructive')"
                />
            </div>
            <div class="w-36">
                <label v-if="i === 0" class="mb-1 block text-[11px] font-medium text-muted-foreground">NSX{{ rules.require_mfg_date ? '' : ' (không bắt buộc)' }}</label>
                <DateInput v-model="l.mfg_date" class="h-8 text-sm" placeholder="dd/mm/yyyy" :max="l.expiry_date || undefined" :data-testid="`${testid}-mfg-${i}`" />
            </div>
            <div class="w-40">
                <label v-if="i === 0" class="mb-1 block text-[11px] font-medium text-muted-foreground">Hạn sử dụng</label>
                <DateInput
                    v-model="l.expiry_date"
                    class="h-8 text-sm"
                    :placeholder="rules.shelf_life_months && l.mfg_date ? displayDate(effectiveExpiry(l, rules)) : 'dd/mm/yyyy'"
                    :min="l.mfg_date || undefined"
                    :data-testid="`${testid}-exp-${i}`"
                />
            </div>
            <div class="w-24">
                <label v-if="i === 0" class="mb-1 block text-[11px] font-medium text-muted-foreground">SL</label>
                <input
                    v-if="!single"
                    v-model="l.quantity"
                    type="number"
                    min="1"
                    :data-testid="`${testid}-qty-${i}`"
                    class="h-8 w-full rounded-md border border-input bg-card px-2 text-right text-sm tabular-nums"
                />
                <p v-else class="flex h-8 items-center justify-end text-sm tabular-nums text-muted-foreground">{{ quantity || '—' }}</p>
            </div>
            <button v-if="!single" type="button" class="mt-1 rounded p-1 text-muted-foreground hover:bg-muted" :class="i === 0 && 'mt-6'" title="Bỏ lô này" @click="lots.splice(i, 1)"><X class="size-4" /></button>
            <p v-if="hint(l)" :class="cn('basis-full text-xs', hint(l)!.tone === 'bad' && 'text-destructive', hint(l)!.tone === 'warn' && 'text-amber-600', hint(l)!.tone === 'muted' && 'text-muted-foreground')">
                {{ l.lot_no || 'Lô' }}: {{ hint(l)!.text }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <button type="button" class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline" :data-testid="`${testid}-add`" @click="add"><Plus class="size-3.5" /> Thêm lô</button>
            <p v-if="error" class="text-xs text-destructive">{{ error }}</p>
            <p v-else-if="problem && lots.some((l) => l.lot_no)" class="text-xs text-muted-foreground">{{ problem }}</p>
        </div>
    </div>
</template>
