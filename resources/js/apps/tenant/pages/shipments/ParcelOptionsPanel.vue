<script setup lang="ts">
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import { cn } from '@/shared/lib/utils';
import { requiredNotes, sizePresets, type ParcelOptions } from './carriers';

/** The parcel the carrier will carry (khung nét đứt bên trái): weight, size, COD, delivery requirement, who pays, note. */
const opts = defineModel<ParcelOptions>({ required: true });
defineProps<{ codHint?: string; weightHint?: number | null; hideCod?: boolean }>();

const num = (v: string | number | null | undefined) => (v === '' || v === null || v === undefined ? null : Number(v));
function setSize(l: number, w: number, h: number) {
    opts.value = { ...opts.value, length_cm: l, width_cm: w, height_cm: h };
}
const isSize = (l: number, w: number, h: number) => opts.value.length_cm === l && opts.value.width_cm === w && opts.value.height_cm === h;
</script>

<template>
    <div class="space-y-3 rounded-lg border border-dashed border-slate-300 p-3 text-sm" data-testid="parcel-options">
        <div class="grid grid-cols-2 gap-3">
            <label class="space-y-1">
                <span class="text-xs text-muted-foreground">Khối lượng (g)</span>
                <Input :model-value="opts.weight_g ?? ''" type="number" min="1" :placeholder="weightHint ? String(weightHint) : '500'" data-testid="parcel-weight" @update:model-value="(v) => (opts = { ...opts, weight_g: num(v) })" />
            </label>
            <label v-if="!hideCod" class="space-y-1">
                <span class="text-xs text-muted-foreground">Tiền thu hộ COD (₫)</span>
                <Input :model-value="opts.cod_amount ?? ''" type="number" min="0" :placeholder="codHint ?? '0'" data-testid="parcel-cod" @update:model-value="(v) => (opts = { ...opts, cod_amount: num(v) })" />
            </label>
        </div>
        <div>
            <span class="text-xs text-muted-foreground">Kích thước (cm)</span>
            <div class="mt-1 flex flex-wrap gap-1.5">
                <button v-for="p in sizePresets" :key="p.label" type="button" :class="cn('rounded-full border px-2.5 py-1 text-xs', isSize(p.l, p.w, p.h) ? 'border-blue-400 bg-blue-50 text-blue-700' : 'text-muted-foreground')" @click="setSize(p.l, p.w, p.h)">{{ p.label }}</button>
                <span class="flex items-center gap-1">
                    <Input :model-value="opts.length_cm ?? ''" type="number" min="1" class="h-7 w-14 px-1.5 text-xs" placeholder="D" @update:model-value="(v) => (opts = { ...opts, length_cm: num(v) })" />×
                    <Input :model-value="opts.width_cm ?? ''" type="number" min="1" class="h-7 w-14 px-1.5 text-xs" placeholder="R" @update:model-value="(v) => (opts = { ...opts, width_cm: num(v) })" />×
                    <Input :model-value="opts.height_cm ?? ''" type="number" min="1" class="h-7 w-14 px-1.5 text-xs" placeholder="C" @update:model-value="(v) => (opts = { ...opts, height_cm: num(v) })" />
                </span>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <label class="space-y-1">
                <span class="text-xs text-muted-foreground">Yêu cầu khi giao</span>
                <Select :model-value="opts.required_note ?? 'view_no_try'" :options="requiredNotes" data-testid="parcel-required-note" @update:model-value="(v) => (opts = { ...opts, required_note: v as ParcelOptions['required_note'] })" />
            </label>
            <label class="space-y-1">
                <span class="text-xs text-muted-foreground">Phí ship</span>
                <Select :model-value="opts.payer ?? 'shop'" :options="[{ value: 'shop', label: 'Shop trả' }, { value: 'buyer', label: 'Khách trả' }]" @update:model-value="(v) => (opts = { ...opts, payer: v as 'shop' | 'buyer' })" />
            </label>
        </div>
        <label class="block space-y-1">
            <span class="text-xs text-muted-foreground">Ghi chú cho hãng</span>
            <Input :model-value="opts.note ?? ''" placeholder="Ví dụ: gọi trước khi giao" @update:model-value="(v) => (opts = { ...opts, note: v ? String(v) : null })" />
        </label>
    </div>
</template>
