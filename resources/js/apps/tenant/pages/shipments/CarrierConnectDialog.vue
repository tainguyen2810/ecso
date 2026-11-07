<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Check, Copy, RefreshCw, Unplug } from 'lucide-vue-next';
import Alert from '@/shared/ui/Alert.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import { cn, formatDateTime } from '@/shared/lib/utils';
import { carrierMarks, type CarrierAccountData, type CarrierCatalogItem } from './carriers';

/**
 * "Kết nối hãng vận chuyển": one step. The shop pastes its own token from the carrier's site; ECSO checks it, lists the
 * shop's pickup points and matches them to warehouses. Free: the shop keeps its own account, price and COD.
 */
const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ accounts: CarrierAccountData[] }>();
const emit = defineEmits<{ changed: [] }>();
const tenant = useTenantStore();
const toast = useToast();
const canManage = computed(() => tenant.can('settings.manage'));

const catalog = ref<CarrierCatalogItem[]>([]);
const picked = ref<string>('');
const creds = ref<Record<string, string>>({});
const sandbox = ref(false);
const error = ref<string | null>(null);
const busy = ref(false);

watch(open, async (v) => {
    if (!v) return;
    error.value = null;
    creds.value = {};
    if (!catalog.value.length) {
        try {
            catalog.value = (await api.get('/carriers')).data.data;
        } catch (e) {
            error.value = (e as ApiError).message;
        }
    }
    picked.value = picked.value || catalog.value.find((c) => c.available && c.carrier !== 'fake')?.carrier || catalog.value.find((c) => c.available)?.carrier || '';
}, { immediate: true });

const current = computed(() => catalog.value.find((c) => c.carrier === picked.value) ?? null);
const help: Record<string, string> = {
    ghn: 'Đăng nhập khachhang.ghn.vn (tài khoản thử: 5sao.ghn.dev, bật ô sandbox) → bấm vào tài khoản ở góc trên trái → tab Bảo mật → Token API & IP tin cậy → Quản lý → bấm hình con mắt rồi chép token.',
    fake: 'Hãng giả lập để thử quy trình (chỉ có ở bản thử). Nhập bất kỳ mã nào trừ "bad".',
};

async function connect() {
    busy.value = true;
    error.value = null;
    try {
        await api.post('/carrier-accounts', { carrier: picked.value, credentials: creds.value, environment: sandbox.value ? 'sandbox' : 'production' });
        toast.success(`Đã kết nối ${current.value?.label ?? ''}`);
        creds.value = {};
        emit('changed');
    } catch (e) {
        const err = e as ApiError;
        error.value = err.field('credentials') ?? err.field('carrier') ?? err.message;
    } finally {
        busy.value = false;
    }
}

async function setPickup(account: CarrierAccountData, warehouseId: string, pickup: string) {
    const pickups: Record<string, string | null> = Object.fromEntries(account.pickups.map((p) => [p.warehouse.id, p.pickup ? String(p.pickup.id) : null]));
    pickups[warehouseId] = pickup || null;
    try {
        await api.put(`/carrier-accounts/${account.id}`, { pickups });
        emit('changed');
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}
async function check(account: CarrierAccountData) {
    try {
        const { data } = await api.post(`/carrier-accounts/${account.id}/check`);
        if (data.data.status === 'connected') toast.success('Kết nối vẫn dùng được');
        else toast.error(data.data.last_error ?? 'Kết nối đang lỗi');
        emit('changed');
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}
async function disconnect(account: CarrierAccountData) {
    if (!window.confirm(`Ngắt kết nối ${account.carrier_label} · ${account.name}? Vận đơn đã đẩy vẫn giữ, nhưng không cập nhật từ hãng nữa.`)) return;
    try {
        await api.delete(`/carrier-accounts/${account.id}`);
        emit('changed');
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}
const copied = ref<string | null>(null);
async function copy(text: string) {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = text;
        setTimeout(() => (copied.value = null), 1500);
    } catch {
        // Clipboard not allowed: the URL is still visible to select by hand.
    }
}
const statusTone = { connected: 'success', error: 'danger', disconnected: 'muted' } as const;
const statusText = { connected: 'Đang kết nối', error: 'Lỗi kết nối', disconnected: 'Đã ngắt' };
</script>

<template>
    <Dialog v-model:open="open" title="Kết nối hãng vận chuyển" description="Dùng tài khoản hãng của chính shop: giá, COD và đối soát vẫn theo hợp đồng của shop. Miễn phí." size="lg">
        <div v-if="accounts.length" class="mb-4 space-y-2" data-testid="carrier-accounts">
            <div v-for="a in accounts" :key="a.id" class="rounded-lg border p-3 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <span :class="cn('rounded px-1.5 py-0.5 text-[10px] font-bold text-white', carrierMarks[a.carrier]?.bg ?? 'bg-slate-500')">{{ carrierMarks[a.carrier]?.short ?? a.carrier }}</span>
                    <b>{{ a.carrier_label }}</b><span class="text-muted-foreground">· {{ a.name }}</span>
                    <Badge v-if="a.environment === 'sandbox'" tone="warning">thử nghiệm</Badge>
                    <Badge :tone="statusTone[a.status]">{{ statusText[a.status] }}</Badge>
                    <span v-if="canManage" class="ml-auto flex gap-1">
                        <Button size="sm" variant="ghost" title="Kiểm tra kết nối" @click="check(a)"><RefreshCw /></Button>
                        <Button size="sm" variant="ghost" title="Ngắt kết nối" data-testid="carrier-disconnect" @click="disconnect(a)"><Unplug /></Button>
                    </span>
                </div>
                <p v-if="a.last_error" class="mt-1 text-xs text-destructive">{{ a.last_error }}</p>
                <div v-if="a.pickups.length" class="mt-2 grid gap-1.5">
                    <label v-for="p in a.pickups" :key="p.warehouse.id" class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="w-32 truncate text-muted-foreground">Kho {{ p.warehouse.name }} lấy tại</span>
                        <Select
                            class="h-8 min-w-56 flex-1"
                            :model-value="p.pickup ? String(p.pickup.id) : ''"
                            :disabled="!canManage"
                            :options="[{ value: '', label: 'Điểm đầu tiên của tài khoản' }, ...a.locations.map((l) => ({ value: String(l.id), label: l.address ? `${l.name} · ${l.address}` : l.name }))]"
                            @update:model-value="(v) => setPickup(a, p.warehouse.id, v ?? '')"
                        />
                    </label>
                </div>
                <div v-if="a.webhook_url" class="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
                    <span class="shrink-0">Địa chỉ nhận cập nhật (dán vào phần Webhook trên trang hãng):</span>
                    <code class="truncate rounded bg-muted px-1.5 py-0.5">{{ a.webhook_url }}</code>
                    <button class="shrink-0 rounded p-1 hover:bg-muted" aria-label="Chép" @click="copy(a.webhook_url)"><Check v-if="copied === a.webhook_url" class="size-3.5 text-emerald-600" /><Copy v-else class="size-3.5" /></button>
                </div>
                <p v-if="a.last_event_at" class="mt-1 text-[11px] text-muted-foreground">Tin mới nhất từ hãng: {{ formatDateTime(a.last_event_at) }}</p>
            </div>
        </div>

        <template v-if="canManage">
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ accounts.length ? 'Thêm tài khoản' : 'Chọn hãng' }}</p>
            <div class="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <button
                    v-for="c in catalog"
                    :key="c.carrier"
                    :disabled="!c.available"
                    :title="c.note ?? undefined"
                    :class="cn('rounded-lg border p-2.5 text-left text-sm', picked === c.carrier && 'border-blue-400 bg-blue-50/70 ring-1 ring-blue-200', !c.available && 'cursor-not-allowed opacity-60')"
                    :data-testid="`carrier-pick-${c.carrier}`"
                    @click="picked = c.carrier"
                >
                    <span :class="cn('rounded px-1.5 py-0.5 text-[10px] font-bold text-white', carrierMarks[c.carrier]?.bg ?? 'bg-slate-500')">{{ carrierMarks[c.carrier]?.short ?? c.carrier }}</span>
                    <div class="mt-1 font-medium">{{ c.label }}</div>
                    <div class="text-[11px] text-muted-foreground">{{ c.available ? 'Kết nối được' : 'Sắp có' }}</div>
                </button>
            </div>
            <form v-if="current?.available" class="space-y-3" @submit.prevent="connect">
                <label v-for="(label, field) in current.fields" :key="field" class="block space-y-1 text-sm">
                    <span class="text-muted-foreground">{{ label }}</span>
                    <Input v-model="creds[field]" autocomplete="off" :data-testid="`carrier-cred-${field}`" />
                </label>
                <p v-if="help[current.carrier]" class="text-xs text-muted-foreground">{{ help[current.carrier] }}</p>
                <label v-if="current.carrier !== 'fake'" class="flex items-center gap-2 text-xs text-muted-foreground"><input v-model="sandbox" type="checkbox" /> Tài khoản môi trường thử nghiệm (sandbox) của hãng</label>
                <Alert v-if="error" tone="red">{{ error }}</Alert>
                <div class="flex justify-end gap-2">
                    <Button type="button" variant="outline" @click="open = false">Đóng</Button>
                    <Button type="submit" :disabled="busy" :loading="busy" data-testid="carrier-connect">Kết nối</Button>
                </div>
            </form>
            <p v-else-if="current" class="text-sm text-muted-foreground">{{ current.note ?? 'Hãng này sắp có.' }}</p>
        </template>
        <p v-else class="text-sm text-muted-foreground">Chỉ chủ shop hoặc quản lý (quyền Cài đặt) kết nối được hãng vận chuyển.</p>
    </Dialog>
</template>
