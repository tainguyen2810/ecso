<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { AlertTriangle } from 'lucide-vue-next';
import SkuPicker from '../../../components/SkuPicker.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import type { Variant } from '@/shared/types';
import { allocationModes, type AllocationRule } from './labels';

export interface AllocationTarget {
    /** Shop ulid; empty to let the user pick (SKU override). */
    account: string;
    /** null = the shop default. */
    variant: { id: string; sku: string; name: string | null } | null;
    /** true = a SKU override, picking the SKU when variant is null. */
    sku: boolean;
    /** The rule saved at this level, null when none (SKU follows the shop / shop publishes everything). */
    rule: AllocationRule | null;
}

/**
 * Edit one allocation rule with a live preview of what each listing will publish, then save and re-push.
 * Nothing is sent to a marketplace before "Lưu".
 */
const props = defineProps<{ target: AllocationTarget; shops: { id: string; name: string; mapped_skus: number; overrides: number }[] }>();
const emit = defineEmits<{ close: []; saved: [] }>();
const toast = useToast();

const open = ref(true);
watch(open, (v) => !v && emit('close'));
const account = ref(props.target.account || props.shops[0]?.id || '');
const variant = ref(props.target.variant);
const start = props.target.rule ?? { mode: 'all', value: null, buffer_qty: 0, max_qty: null };
const form = ref({ mode: start.mode, value: start.value === null ? '' : String(start.value), buffer: String(start.buffer_qty || ''), max: start.max_qty === null ? '' : String(start.max_qty) });

type Preview = { total: number; changed: number; to_zero: number; rows: { sku: string; name: string | null; sellable: number; current: number | null; next: number }[] };
const preview = ref<Preview | null>(null);
const previewError = ref('');
const saving = ref(false);

const shop = computed(() => props.shops.find((s) => s.id === account.value));
const title = computed(() => (props.target.sku ? `Cài đặt riêng${variant.value ? ' · ' + variant.value.sku : ' cho SKU'}` : `Phân bổ mặc định · ${shop.value?.name ?? ''}`));
const description = computed(() =>
    props.target.sku
        ? 'SKU này dùng cài đặt dưới đây thay cho mặc định của shop.'
        : `Áp dụng cho ${formatNumber((shop.value?.mapped_skus ?? 0) - (shop.value?.overrides ?? 0))} SKU đã liên kết của shop${shop.value?.overrides ? `, trừ ${shop.value.overrides} SKU cài đặt riêng` : ''}.`,
);
const needsValue = computed(() => form.value.mode === 'percent' || form.value.mode === 'fixed');
const num = (s: string) => (s === '' ? null : Math.max(0, Math.floor(Number(s))));

function body(reset = false) {
    const base = { account: account.value, variant: variant.value?.id ?? null };
    if (reset) return { ...base, reset: true };
    return { ...base, mode: form.value.mode, value: needsValue.value ? num(form.value.value) : null, buffer_qty: num(form.value.buffer) ?? 0, max_qty: num(form.value.max) };
}

let timer: ReturnType<typeof setTimeout> | undefined;
let seq = 0;
watch(
    [form, account, variant],
    () => {
        clearTimeout(timer);
        if (!account.value || (props.target.sku && !variant.value)) return;
        timer = setTimeout(async () => {
            const mine = ++seq;
            try {
                const { data } = await api.post('/sync-center/allocations/preview', body());
                if (mine === seq) {
                    preview.value = data.data;
                    previewError.value = '';
                }
            } catch (e) {
                if (mine === seq) {
                    preview.value = null;
                    previewError.value = (e as ApiError).message;
                }
            }
        }, 300);
    },
    { deep: true, immediate: true },
);

async function save(reset = false) {
    saving.value = true;
    try {
        const { data } = await api.put('/sync-center/allocations', body(reset));
        toast.success(data.data.queued ? `Đã lưu. Đang đẩy lại tồn cho ${formatNumber(data.data.queued)} SKU.` : 'Đã lưu. Không SKU nào cần đẩy lại.');
        emit('saved');
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        saving.value = false;
    }
}

function pick(v: Variant) {
    variant.value = { id: v.id!, sku: v.sku, name: v.name };
}
const delta = (r: Preview['rows'][number]) => r.next - (r.current ?? 0);
</script>

<template>
    <Dialog v-model:open="open" :title="title" :description="description" size="lg">
        <div class="space-y-4 text-sm" data-testid="allocation-dialog">
            <template v-if="target.sku">
                <div v-if="!variant" class="rounded-md border p-2"><SkuPicker inline placeholder="Tìm SKU cần cài đặt riêng…" @pick="pick" /></div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <FormField v-if="variant" label="SKU">
                        <div class="flex h-9 items-center justify-between rounded-md border bg-muted/40 px-3">
                            <span class="truncate"><b class="font-medium">{{ variant.sku }}</b> <span class="text-muted-foreground">{{ variant.name }}</span></span>
                            <button v-if="!target.variant" type="button" class="text-xs text-primary hover:underline" @click="variant = null">Đổi</button>
                        </div>
                    </FormField>
                    <FormField label="Shop">
                        <Select v-model="account" :options="shops.map((s) => ({ value: s.id, label: s.name }))" :disabled="!!target.account" data-testid="allocation-shop" />
                    </FormField>
                </div>
            </template>

            <FormField label="Cách công bố">
                <div class="inline-flex flex-wrap gap-0.5 rounded-md bg-muted p-1" data-testid="allocation-mode">
                    <button
                        v-for="m in allocationModes"
                        :key="m.value"
                        type="button"
                        :class="cn('rounded px-3 py-1 text-muted-foreground', form.mode === m.value && 'bg-card font-medium text-foreground shadow-xs')"
                        @click="form.mode = m.value"
                    >
                        {{ m.label }}
                    </button>
                </div>
            </FormField>

            <div v-if="form.mode !== 'none'" class="grid gap-3 sm:grid-cols-3">
                <FormField v-if="needsValue" :label="form.mode === 'percent' ? 'Tỷ lệ (%)' : 'Số lượng công bố'">
                    <Input v-model="form.value" type="number" min="0" :max="form.mode === 'percent' ? 100 : undefined" data-testid="allocation-value" />
                </FormField>
                <FormField label="Giữ lại mỗi SKU" hint="Trừ trước khi tính">
                    <Input v-model="form.buffer" type="number" min="0" placeholder="0" data-testid="allocation-buffer" />
                </FormField>
                <FormField label="Tối đa mỗi SKU" hint="Để trống = không giới hạn">
                    <Input v-model="form.max" type="number" min="0" placeholder="Không giới hạn" data-testid="allocation-max" />
                </FormField>
            </div>

            <div v-if="preview">
                <p class="mb-1.5 text-xs font-medium text-muted-foreground">Xem trước thay đổi</p>
                <div class="overflow-hidden rounded-md border">
                    <table class="w-full text-sm" data-testid="allocation-preview">
                        <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                            <tr><th class="px-3 py-2 font-medium">SKU</th><th class="px-3 py-2 text-right font-medium">Tồn bán được</th><th class="px-3 py-2 text-right font-medium">Đang trên sàn</th><th class="px-3 py-2 text-right font-medium">Sẽ đẩy</th></tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="r in preview.rows" :key="r.sku">
                                <td class="px-3 py-2"><span class="font-medium">{{ r.sku }}</span> <span class="text-xs text-muted-foreground">{{ r.name }}</span></td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ formatNumber(r.sellable) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-muted-foreground">{{ r.current === null ? '—' : formatNumber(r.current) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    <b>{{ formatNumber(r.next) }}</b>
                                    <span v-if="r.current !== null" :class="['ml-1 text-xs', delta(r) < 0 ? 'text-rose-600' : 'text-emerald-600']">{{ delta(r) < 0 ? '↓' : '↑' }}{{ formatNumber(Math.abs(delta(r))) }}</span>
                                </td>
                            </tr>
                            <tr v-if="!preview.rows.length"><td colspan="4" class="px-3 py-3 text-center text-muted-foreground">Không SKU nào đổi số công bố.</td></tr>
                            <tr v-else-if="preview.changed > preview.rows.length">
                                <td colspan="4" class="px-3 py-2 text-xs text-muted-foreground">… và {{ formatNumber(preview.changed - preview.rows.length) }} SKU khác thay đổi, {{ formatNumber(preview.total - preview.changed) }} SKU giữ nguyên</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="preview.changed" class="mt-3 flex gap-2 rounded-md border border-amber-200 bg-amber-50/70 px-3 py-2.5 text-amber-900">
                    <AlertTriangle class="mt-0.5 size-4 shrink-0 text-amber-500" />
                    <p>
                        <b>{{ formatNumber(preview.changed) }} SKU sẽ được đẩy lại lên sàn</b><template v-if="preview.to_zero">, trong đó {{ formatNumber(preview.to_zero) }} SKU về 0 (không bán được trên shop này)</template>. Việc đẩy chạy nền theo giới hạn API của sàn.
                    </p>
                </div>
            </div>
            <p v-else-if="previewError" class="text-sm text-rose-600">{{ previewError }}</p>
        </div>

        <template #footer>
            <Button v-if="target.rule" variant="ghost" class="mr-auto" :loading="saving" data-testid="allocation-reset" @click="save(true)">
                {{ target.sku ? 'Bỏ cài đặt riêng' : 'Về mặc định (toàn bộ)' }}
            </Button>
            <Button variant="outline" @click="open = false">Hủy</Button>
            <Button :loading="saving" :disabled="!preview || (target.sku && !variant)" data-testid="allocation-save" @click="save()">
                {{ preview?.changed ? `Lưu và đẩy lại ${formatNumber(preview.changed)} SKU` : 'Lưu' }}
            </Button>
        </template>
    </Dialog>
</template>
