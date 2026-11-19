<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { CircleCheck, CircleX, PackageCheck, TriangleAlert } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import LabelPrint from './LabelPrint.vue';

/**
 * "Chuẩn bị hàng" for Shopee / TikTok orders: per shop, the parcel is picked up (address + slot the marketplace offers)
 * or dropped off; the marketplace then books its carrier. In "select" mode (packing screen) the choice is only
 * returned, the caller sends it with the packing.
 */
const props = withDefaults(defineProps<{ ids: string[]; mode?: 'run' | 'select' }>(), { mode: 'run' });
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ done: []; chosen: [shipping: Record<string, Choice>] }>();
const toast = useToast();
const tenant = useTenantStore();

interface Slot { id: string; label: string }
interface Address { id: string; label: string; slots: Slot[] }
interface Group {
    account: { id: string; name: string; marketplace: string };
    orders: { id: string; external_order_id: string }[];
    options: { pickup: { available: boolean; addresses: Address[] }; dropoff: { available: boolean } } | null;
    error: string | null;
    last: { method: 'pickup' | 'dropoff'; address_id: string | null } | null;
}
interface Choice { method: 'pickup' | 'dropoff'; address_id: string | null; slot_id: string | null; slot_label: string | null; address_label: string | null }
interface Result { id: string; ok: boolean; message: string | null; external_order_id?: string; tracking_number?: string | null }

const loading = ref(false);
const loadError = ref<string | null>(null);
const groups = ref<Group[]>([]);
const skipped = ref<{ id: string; external_order_id: string; message: string }[]>([]);
const form = ref<Record<string, { method: 'pickup' | 'dropoff' | ''; address_id: string; slot_id: string }>>({});
const printAfter = ref(true);
const busy = ref(false);
const results = ref<Result[] | null>(null);

watch(open, async (v) => {
    if (!v) return;
    results.value = null;
    loadError.value = null;
    groups.value = [];
    skipped.value = [];
    loading.value = true;
    try {
        const { data } = await api.post('/orders/ship-options', { ids: props.ids });
        groups.value = data.data.groups;
        skipped.value = data.data.skipped;
        form.value = Object.fromEntries(groups.value.map((g) => [g.account.id, initial(g)]));
    } catch (e) {
        loadError.value = e instanceof ApiError ? e.message : 'Không tải được cách giao hàng từ sàn.';
    } finally {
        loading.value = false;
    }
}, { immediate: true });

/** The shop's last choice when the marketplace still offers it, else pickup at the first address with slots. */
function initial(g: Group) {
    const o = g.options;
    if (!o) return { method: '' as const, address_id: '', slot_id: '' };
    const withSlots = o.pickup.addresses.filter((a) => a.slots.length);
    const address = withSlots.find((a) => a.id === (g.last?.address_id ?? '')) ?? withSlots[0];
    const pickup = o.pickup.available && !!address;
    const method = g.last?.method === 'dropoff' && o.dropoff.available ? 'dropoff' : pickup ? 'pickup' : o.dropoff.available ? 'dropoff' : '';
    return { method: method as 'pickup' | 'dropoff' | '', address_id: address?.id ?? '', slot_id: address?.slots[0]?.id ?? '' };
}
const addressOf = (g: Group) => g.options?.pickup.addresses.find((a) => a.id === form.value[g.account.id]?.address_id);
function pickAddress(g: Group, id: string) {
    form.value[g.account.id] = { ...form.value[g.account.id]!, address_id: id, slot_id: g.options?.pickup.addresses.find((a) => a.id === id)?.slots[0]?.id ?? '' };
}

const ready = computed(() => groups.value.filter((g) => {
    const f = form.value[g.account.id];
    return g.options && f && (f.method === 'dropoff' || (f.method === 'pickup' && f.slot_id));
}));
const count = computed(() => ready.value.reduce((n, g) => n + g.orders.length, 0));

function choices(): Record<string, Choice> {
    return Object.fromEntries(ready.value.map((g) => {
        const f = form.value[g.account.id]!;
        const address = addressOf(g);
        return [g.account.id, {
            method: f.method as 'pickup' | 'dropoff',
            address_id: f.method === 'pickup' ? f.address_id || null : null,
            slot_id: f.method === 'pickup' ? f.slot_id : null,
            slot_label: f.method === 'pickup' ? (address?.slots.find((s) => s.id === f.slot_id)?.label ?? null) : null,
            address_label: f.method === 'pickup' ? (address?.label ?? null) : null,
        }];
    }));
}

async function submit() {
    if (props.mode === 'select') {
        emit('chosen', choices());
        open.value = false;
        return;
    }
    busy.value = true;
    try {
        const ids = ready.value.flatMap((g) => g.orders.map((o) => o.id));
        const { data } = await api.post('/orders/bulk', { ids, action: 'pack', shipping: choices() });
        const names = Object.fromEntries(ready.value.flatMap((g) => g.orders.map((o) => [o.id, o.external_order_id])));
        results.value = (data.data as Result[]).map((r) => ({ ...r, external_order_id: r.external_order_id ?? names[r.id] }));
        const ok = results.value.filter((r) => r.ok).length;
        if (ok) toast.success(`Đã chuẩn bị ${ok} đơn trên sàn.`);
        emit('done');
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Không chuẩn bị được hàng.');
    } finally {
        busy.value = false;
    }
}
const okIds = computed(() => (results.value ?? []).filter((r) => r.ok).map((r) => r.id));
const title = computed(() => (results.value ? `Đã chuẩn bị ${okIds.value.length}/${results.value.length} đơn` : props.mode === 'select' ? 'Chọn cách giao cho đơn sàn' : `Chuẩn bị hàng cho ${count.value || props.ids.length} đơn`));
</script>

<template>
    <Dialog v-model:open="open" :title="title" :description="results ? undefined : 'Sàn sẽ gọi đơn vị vận chuyển và cấp mã vận đơn.'" size="lg">
        <div v-if="loading" class="space-y-3"><Skeleton class="h-28" /><Skeleton class="h-20" /></div>
        <p v-else-if="loadError" class="rounded-md bg-rose-50 p-3 text-sm text-rose-700">{{ loadError }}</p>

        <!-- After: one line per order, then the labels of the ones that went through. -->
        <div v-else-if="results" class="space-y-4" data-testid="ship-results">
            <ul class="divide-y rounded-xl border text-sm">
                <li v-for="r in results" :key="r.id" :class="cn('flex items-start gap-2.5 px-3 py-2', !r.ok && 'bg-rose-50/60')">
                    <div class="min-w-0 flex-1">
                        <span class="font-medium tabular-nums">{{ r.external_order_id }}</span>
                        <span v-if="r.ok && r.tracking_number" class="ml-2 text-xs text-muted-foreground tabular-nums">Mã vận đơn {{ r.tracking_number }}</span>
                        <span v-else-if="r.ok" class="ml-2 text-xs text-muted-foreground">Sàn đang cấp mã vận đơn</span>
                        <div v-if="!r.ok" class="text-xs text-rose-700">{{ r.message }}</div>
                    </div>
                    <CircleCheck v-if="r.ok" class="mt-0.5 size-4 shrink-0 text-emerald-600" />
                    <CircleX v-else class="mt-0.5 size-4 shrink-0 text-rose-600" />
                </li>
            </ul>
            <LabelPrint v-if="okIds.length && printAfter && tenant.can('orders.print')" :ids="okIds" />
            <p v-if="results.some((r) => !r.ok)" class="text-xs text-muted-foreground">Đơn lỗi vẫn ở bước Chờ đóng gói.</p>
        </div>

        <div v-else class="space-y-3">
            <div v-for="g in groups" :key="g.account.id" class="rounded-xl border p-4" data-testid="ship-group">
                <div class="mb-3 flex items-center gap-2.5">
                    <MarketplaceLogo :marketplace="g.account.marketplace" :name="g.account.name" size="sm" />
                    <span class="font-semibold">{{ g.account.name }}</span>
                    <span class="text-muted-foreground">· {{ g.orders.length }} đơn</span>
                </div>
                <p v-if="g.error" class="rounded-md bg-rose-50 p-2.5 text-sm text-rose-700">{{ g.error }}</p>
                <div v-else-if="g.options" class="grid gap-3 sm:grid-cols-2">
                    <label :class="cn('cursor-pointer rounded-xl border p-3', form[g.account.id]!.method === 'pickup' && 'border-primary bg-blue-50/30 ring-2 ring-blue-100', !g.options.pickup.available && 'cursor-not-allowed opacity-50')">
                        <div class="flex items-start gap-2.5">
                            <input v-model="form[g.account.id]!.method" type="radio" value="pickup" class="mt-1" :disabled="!g.options.pickup.available" :data-testid="`ship-pickup-${g.account.id}`" />
                            <div class="min-w-0 flex-1">
                                <div class="font-medium">Shipper đến lấy</div>
                                <div class="text-xs text-muted-foreground">{{ g.options.pickup.available ? 'Chọn khung giờ và nơi lấy hàng' : 'Sàn chưa có khung giờ lấy hàng' }}</div>
                                <template v-if="form[g.account.id]!.method === 'pickup'">
                                    <Select
                                        v-if="g.options.pickup.addresses.length > 1"
                                        :model-value="form[g.account.id]!.address_id"
                                        class="mt-2.5"
                                        aria-label="Nơi lấy hàng"
                                        :options="g.options.pickup.addresses.map((a) => ({ value: a.id, label: a.label, disabled: !a.slots.length }))"
                                        @update:model-value="(v) => pickAddress(g, v ?? '')"
                                    />
                                    <p v-else class="mt-2 text-xs">{{ g.options.pickup.addresses[0]?.label }}</p>
                                    <Select
                                        v-model="form[g.account.id]!.slot_id"
                                        class="mt-2"
                                        aria-label="Khung giờ lấy hàng"
                                        :data-testid="`ship-slot-${g.account.id}`"
                                        :options="(addressOf(g)?.slots ?? []).map((s) => ({ value: s.id, label: s.label }))"
                                    />
                                </template>
                            </div>
                        </div>
                    </label>
                    <label :class="cn('cursor-pointer rounded-xl border p-3', form[g.account.id]!.method === 'dropoff' && 'border-primary bg-blue-50/30 ring-2 ring-blue-100', !g.options.dropoff.available && 'cursor-not-allowed opacity-50')">
                        <div class="flex items-start gap-2.5">
                            <input v-model="form[g.account.id]!.method" type="radio" value="dropoff" class="mt-1" :disabled="!g.options.dropoff.available" :data-testid="`ship-dropoff-${g.account.id}`" />
                            <div>
                                <div class="font-medium">Tự mang ra bưu cục</div>
                                <div class="text-xs text-muted-foreground">Mang hàng ra điểm gửi của đơn vị vận chuyển</div>
                            </div>
                        </div>
                    </label>
                </div>
            </div>

            <ul v-if="skipped.length" class="space-y-0.5 rounded-md bg-slate-50 p-2.5 text-xs text-muted-foreground" data-testid="ship-skipped">
                <li v-for="s in skipped" :key="s.id"><b class="tabular-nums text-foreground">{{ s.external_order_id }}</b>: {{ s.message }}</li>
            </ul>
            <p v-if="!groups.length && !loading" class="text-sm text-muted-foreground">Không có đơn Shopee hoặc TikTok Shop nào cần chuẩn bị hàng trong các đơn đã chọn.</p>

            <div v-if="groups.length" class="flex items-start gap-2.5 rounded-xl bg-amber-50 p-3 text-sm text-amber-800">
                <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                <span>Sau khi chuẩn bị, sàn gọi đơn vị vận chuyển và đơn chuyển sang <b>Chờ bàn giao</b>. Muốn đổi cách giao phải làm trong trang quản lý của sàn.</span>
            </div>
            <label v-if="mode === 'run' && groups.length && tenant.can('orders.print')" class="flex items-center gap-2 text-sm"><input v-model="printAfter" type="checkbox" class="size-4" /> In phiếu sàn ngay khi xong</label>
        </div>

        <template #footer>
            <template v-if="results"><Button variant="outline" @click="open = false">Đóng</Button></template>
            <template v-else>
                <Button variant="outline" @click="open = false">Để sau</Button>
                <Button :disabled="!count" :loading="busy" data-testid="ship-submit" @click="submit"><PackageCheck /> {{ mode === 'select' ? 'Chuẩn bị hàng' : `Chuẩn bị ${count} đơn` }}</Button>
            </template>
        </template>
    </Dialog>
</template>
