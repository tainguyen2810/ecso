<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ArrowRightLeft, CalendarClock, PackageX } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import DateInput from '@/shared/ui/DateInput.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatNumber } from '@/shared/lib/utils';
import { displayDate } from '@/shared/lib/dates';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import { lotLabel } from '../inventory/lots';

/**
 * Tab "Lô & hạn sử dụng" of a product: what each lot holds in each warehouse, earliest expiry first (the order lots
 * are sold in), and the fixes staff make: split "Chưa rõ lô" into real lots, correct dates, send a lot that may no
 * longer be sold to the defective-goods warehouse.
 */
type LotStatus = 'empty' | 'expired' | 'blocked' | 'expiring' | 'unknown' | 'ok';
interface LotRow {
    id: number;
    variant: { id: string; sku: string; name: string | null };
    warehouse: { id: string; name: string; purpose: string | null };
    lot_no: string | null;
    mfg_date: string | null;
    expiry_date: string | null;
    days_left: number | null;
    on_hand: number;
    reserved: number;
    sellable: number;
    status: LotStatus;
    received_at: string | null;
}
interface Rules {
    track_lots: boolean;
    warn_days: number;
    block_days: number;
    shelf_life_months: number | null;
}

const props = defineProps<{ productId: string; warehouse: string }>();
const emit = defineEmits<{ changed: [] }>();
const tenant = useTenantStore();
const toast = useToast();
const canAdjust = computed(() => tenant.can('inventory.adjust'));
const canTransfer = computed(() => tenant.can('inventory.transfer'));

const rows = ref<LotRow[]>([]);
const rules = ref<Rules | null>(null);
const loaded = ref(false);
const showEmpty = ref(false);

const statusMeta: Record<LotStatus, { label: string; tone: 'success' | 'warning' | 'danger' | 'muted' | 'info' }> = {
    ok: { label: 'Còn hạn', tone: 'success' },
    expiring: { label: 'Sắp hết hạn', tone: 'warning' },
    blocked: { label: 'Ngừng bán', tone: 'danger' },
    expired: { label: 'Đã hết hạn', tone: 'danger' },
    unknown: { label: 'Chưa rõ lô', tone: 'info' },
    empty: { label: 'Đã hết hàng', tone: 'muted' },
};

const visible = computed(() => rows.value.filter((r) => (!props.warehouse || r.warehouse.id === props.warehouse) && (showEmpty.value || r.status !== 'empty' || r.reserved > 0)));
const sellableTotal = computed(() => visible.value.reduce((t, r) => t + r.sellable, 0));
const stuck = computed(() => visible.value.filter((r) => ['blocked', 'expired'].includes(r.status)).reduce((t, r) => t + r.on_hand - r.reserved, 0));

async function load() {
    const { data } = await api.get(`/inventory/products/${props.productId}/lots`);
    rows.value = data.data;
    rules.value = data.rules;
    loaded.value = true;
}
onMounted(load);
defineExpose({ load });

function barColor(r: LotRow): string {
    if (r.status === 'expired' || r.status === 'blocked') return 'bg-destructive';
    if (r.status === 'expiring') return 'bg-amber-500';
    return 'bg-emerald-500';
}
// Share of the bar: a full bar is two years of shelf life left.
const barWidth = (days: number) => `${Math.max(3, Math.min(100, (days / 730) * 100))}%`;

// --- actions -------------------------------------------------------------------------------------------------
const busy = ref(false);
const error = ref<ApiError | null>(null);
const acting = ref<{ kind: 'move' | 'redate' | 'defective'; row: LotRow } | null>(null);
const form = ref({ lot_no: '', mfg_date: '', expiry_date: '', quantity: '', note: '' });

function open(kind: 'move' | 'redate' | 'defective', row: LotRow) {
    error.value = null;
    const free = Math.max(row.on_hand - row.reserved, 0);
    form.value =
        kind === 'redate'
            ? { lot_no: '', mfg_date: row.mfg_date ?? '', expiry_date: row.expiry_date ?? '', quantity: '', note: '' }
            : { lot_no: '', mfg_date: '', expiry_date: '', quantity: String(free), note: '' };
    acting.value = { kind, row };
}

const titles = { move: 'Tách / chuyển sang lô khác', redate: 'Sửa ngày của lô', defective: 'Chuyển sang kho hàng lỗi' } as const;
// Lots of the same SKU in the same warehouse the goods can go to.
const siblings = computed(() =>
    acting.value ? rows.value.filter((r) => r.id !== acting.value!.row.id && r.lot_no && r.variant.id === acting.value!.row.variant.id && r.warehouse.id === acting.value!.row.warehouse.id) : [],
);

async function submit() {
    if (!acting.value) return;
    const { kind, row } = acting.value;
    busy.value = true;
    error.value = null;
    try {
        if (kind === 'move') {
            await api.post(`/inventory/lots/${row.id}/move`, { lot_no: form.value.lot_no.trim(), mfg_date: form.value.mfg_date || null, expiry_date: form.value.expiry_date || null, quantity: Number(form.value.quantity) });
            toast.success(`Đã chuyển ${form.value.quantity} sang lô ${form.value.lot_no.trim()}`);
        } else if (kind === 'redate') {
            await api.put(`/inventory/lots/${row.id}`, { mfg_date: form.value.mfg_date || null, expiry_date: form.value.expiry_date });
            toast.success('Đã sửa ngày của lô');
        } else {
            const { data } = await api.post(`/inventory/lots/${row.id}/to-defective`, { quantity: Number(form.value.quantity) || null, note: form.value.note || null });
            toast.success(`Đã chuyển sang kho hàng lỗi (${data.data.number})`);
        }
        acting.value = null;
        await load();
        emit('changed');
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        busy.value = false;
    }
}

const fieldError = computed(() => error.value && (error.value.field('lot') ?? error.value.field('lot_no') ?? error.value.field('quantity') ?? error.value.field('expiry_date') ?? error.value.field('mfg_date') ?? error.value.message));
</script>

<template>
    <div v-if="!loaded" class="space-y-2 p-4"><Skeleton v-for="i in 4" :key="i" class="h-8" /></div>
    <template v-else>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b px-4 py-2.5 text-xs text-muted-foreground">
            <span v-if="rules">Cảnh báo trước {{ rules.warn_days }} ngày · {{ rules.block_days ? `Ngừng bán khi còn dưới ${rules.block_days} ngày` : 'Ngừng bán khi hết hạn' }}<template v-if="rules.shelf_life_months"> · Hạn mặc định {{ rules.shelf_life_months }} tháng</template></span>
            <label class="ml-auto inline-flex items-center gap-1.5"><input v-model="showEmpty" type="checkbox" class="size-3.5" /> Hiện lô đã hết</label>
            <span>Sắp xếp: lô bán trước ở trên (FEFO)</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="product-lots">
                <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Số lô</th>
                        <th class="px-4 py-2.5 font-medium">SKU</th>
                        <th class="px-4 py-2.5 font-medium">Kho</th>
                        <th class="px-4 py-2.5 font-medium">NSX</th>
                        <th class="px-4 py-2.5 font-medium">Hạn sử dụng</th>
                        <th class="px-4 py-2.5 font-medium">Còn lại</th>
                        <th class="px-4 py-2.5 text-right font-medium">Tồn</th>
                        <th class="px-4 py-2.5 text-right font-medium">Đang giữ</th>
                        <th class="px-4 py-2.5 text-right font-medium">Bán được</th>
                        <th class="px-4 py-2.5 font-medium">Trạng thái</th>
                        <th v-if="canAdjust || canTransfer" class="px-2 py-2.5" />
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="r in visible" :key="r.id" :class="r.status === 'empty' && 'text-muted-foreground'">
                        <td class="px-4 py-2.5">
                            <span :class="cn('font-medium', !r.lot_no && 'italic text-sky-700')">{{ lotLabel(r.lot_no) }}</span>
                            <p v-if="r.received_at" class="text-xs text-muted-foreground">Nhập {{ displayDate(r.received_at.slice(0, 10)) }}</p>
                        </td>
                        <td class="whitespace-nowrap px-4 py-2.5"><span class="font-mono text-xs">{{ r.variant.sku }}</span></td>
                        <td class="whitespace-nowrap px-4 py-2.5">{{ r.warehouse.name }}</td>
                        <td class="px-4 py-2.5 tabular-nums">{{ displayDate(r.mfg_date) || '—' }}</td>
                        <td class="px-4 py-2.5 font-medium tabular-nums">{{ displayDate(r.expiry_date) || '—' }}</td>
                        <td class="px-4 py-2.5">
                            <template v-if="r.days_left !== null">
                                <span :class="cn('text-xs font-medium tabular-nums', r.days_left < 0 || r.status === 'blocked' ? 'text-destructive' : r.status === 'expiring' ? 'text-amber-600' : '')">
                                    {{ r.days_left < 0 ? `quá ${-r.days_left} ngày` : `${r.days_left} ngày` }}
                                </span>
                                <span class="mt-1 block h-1 w-20 overflow-hidden rounded bg-slate-100"><i :class="cn('block h-full', barColor(r))" :style="{ width: barWidth(Math.max(r.days_left, 0)) }" /></span>
                            </template>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ formatNumber(r.on_hand) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ formatNumber(r.reserved) }}</td>
                        <td :class="cn('px-4 py-2.5 text-right font-medium tabular-nums', !r.sellable && 'text-muted-foreground')">{{ formatNumber(r.sellable) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5"><Badge :tone="statusMeta[r.status].tone">{{ statusMeta[r.status].label }}</Badge></td>
                        <td v-if="canAdjust || canTransfer" class="whitespace-nowrap px-2 py-2.5 text-right">
                            <template v-if="r.on_hand > 0">
                                <Button v-if="canAdjust && r.on_hand > r.reserved" variant="ghost" size="icon" class="size-8" :title="r.lot_no ? 'Tách sang lô khác' : 'Tách ra lô thật'" :data-testid="`lot-move-${r.id}`" @click="open('move', r)"><ArrowRightLeft class="text-muted-foreground" /></Button>
                                <Button v-if="canAdjust && r.lot_no" variant="ghost" size="icon" class="size-8" title="Sửa NSX/HSD" :data-testid="`lot-redate-${r.id}`" @click="open('redate', r)"><CalendarClock class="text-muted-foreground" /></Button>
                                <Button v-if="canTransfer && r.on_hand > r.reserved && r.warehouse.purpose !== 'defective'" variant="ghost" size="icon" class="size-8" title="Chuyển sang kho hàng lỗi" :data-testid="`lot-defective-${r.id}`" @click="open('defective', r)"><PackageX class="text-muted-foreground" /></Button>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="!visible.length"><td colspan="11" class="px-4 py-8 text-center text-muted-foreground">Chưa có lô nào. Lô được tạo khi nhập hàng.</td></tr>
                </tbody>
            </table>
        </div>
        <p v-if="visible.length" class="border-t px-4 py-3 text-xs text-muted-foreground">
            Tồn đẩy lên sàn = tổng "Bán được" = <b class="text-foreground">{{ formatNumber(sellableTotal) }}</b><template v-if="stuck > 0"> (đã trừ {{ formatNumber(stuck) }} thuộc lô hết hạn hoặc ngừng bán)</template>.
        </p>
    </template>

    <Dialog :open="!!acting" :title="acting ? titles[acting.kind] : ''" :description="acting ? `${acting.row.variant.sku} · ${acting.row.warehouse.name} · lô ${lotLabel(acting.row.lot_no)}` : undefined" @update:open="(v: boolean) => !v && (acting = null)">
        <form v-if="acting" id="lot-form" class="space-y-4" @submit.prevent="submit">
            <template v-if="acting.kind === 'move'">
                <FormField label="Sang lô" for="lot-target">
                    <Input id="lot-target" v-model="form.lot_no" list="lot-siblings" maxlength="64" required placeholder="Số lô mới hoặc lô đã có" data-testid="lot-target" />
                    <datalist id="lot-siblings"><option v-for="s in siblings" :key="s.id" :value="s.lot_no!" /></datalist>
                    <p class="mt-1 text-xs text-muted-foreground">Gõ số lô đã có thì cộng vào lô đó (giữ nguyên ngày của lô).</p>
                </FormField>
                <div class="grid grid-cols-2 gap-3">
                    <FormField label="NSX"><DateInput v-model="form.mfg_date" placeholder="dd/mm/yyyy" /></FormField>
                    <FormField label="Hạn sử dụng"><DateInput v-model="form.expiry_date" placeholder="dd/mm/yyyy" data-testid="lot-target-exp" /></FormField>
                </div>
                <FormField label="Số lượng" for="lot-qty">
                    <Input id="lot-qty" v-model="form.quantity" type="number" min="1" :max="acting.row.on_hand - acting.row.reserved" required data-testid="lot-qty" />
                    <p class="mt-1 text-xs text-muted-foreground">Tối đa {{ formatNumber(acting.row.on_hand - acting.row.reserved) }} (phần chưa giữ cho đơn).</p>
                </FormField>
            </template>
            <template v-else-if="acting.kind === 'redate'">
                <div class="grid grid-cols-2 gap-3">
                    <FormField label="NSX"><DateInput v-model="form.mfg_date" placeholder="dd/mm/yyyy" /></FormField>
                    <FormField label="Hạn sử dụng"><DateInput v-model="form.expiry_date" placeholder="dd/mm/yyyy" data-testid="lot-redate-exp" /></FormField>
                </div>
                <p class="text-xs text-muted-foreground">Áp dụng cho lô này ở kho {{ acting.row.warehouse.name }}. Lịch sử kho ghi lại lần sửa.</p>
            </template>
            <template v-else>
                <FormField label="Số lượng" for="def-qty">
                    <Input id="def-qty" v-model="form.quantity" type="number" min="1" :max="acting.row.on_hand - acting.row.reserved" data-testid="def-qty" />
                </FormField>
                <FormField label="Ghi chú" for="def-note"><Input id="def-note" v-model="form.note" maxlength="255" placeholder="Hết hạn, chờ hủy…" /></FormField>
                <p class="text-xs text-muted-foreground">Tạo phiếu chuyển kho đã xuất và nhận ngay, lịch sử kho ghi rõ hàng đi đâu. Hàng ở kho hàng lỗi không được bán.</p>
            </template>
            <p v-if="fieldError" class="text-sm text-destructive">{{ fieldError }}</p>
        </form>
        <template #footer>
            <Button variant="outline" @click="acting = null">Hủy</Button>
            <Button type="submit" form="lot-form" :loading="busy" data-testid="lot-save">{{ acting?.kind === 'defective' ? 'Chuyển kho' : 'Lưu' }}</Button>
        </template>
    </Dialog>
</template>
