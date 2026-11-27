<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { Ban, BellRing, CalendarClock, Pause, Play, RefreshCw, UserRound } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import RenewDialog from './RenewDialog.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import Switch from '@/shared/ui/Switch.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDateTime, formatMoney } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import { day, eventLabels, leftText, stateLabels, type SubscriptionDetailData } from './subscriptions';

/** One subscription: how long it has left, every period paid (each from its order), what happened, and the actions. */
const route = useRoute();
const tenant = useTenantStore();
const toast = useToast();
const canSell = computed(() => tenant.can('orders.create'));
const sub = ref<SubscriptionDetailData | null>(null);
const loadError = ref<string | null>(null);
const renewOpen = ref(false);
const cancelOpen = ref(false);
const cancelReason = ref('');
const busy = ref(false);
const staff = ref<{ id: string; name: string }[]>([]);
const info = reactive({ label: '', note: '', remind: true, assigned_to: '' });
const infoSaved = ref('');
const infoDirty = computed(() => JSON.stringify(info) !== infoSaved.value);

async function load() {
    try {
        const { data } = await api.get(`/subscriptions/${route.params.id}`);
        fill(data.data);
    } catch (e) {
        loadError.value = (e as ApiError).message;
    }
}
function fill(s: SubscriptionDetailData) {
    sub.value = s;
    Object.assign(info, { label: s.label ?? '', note: s.note ?? '', remind: s.remind, assigned_to: s.assignee?.id ?? '' });
    infoSaved.value = JSON.stringify(info);
}
onMounted(async () => {
    await load();
    staff.value = (await api.get('/customers/staff').catch(() => null))?.data.data ?? [];
});

/** Share of the current period used, for the bar under "Còn … ngày". */
const progress = computed(() => {
    const cur = sub.value?.periods.find((p) => p.current);
    if (!cur) return null;
    const start = new Date(`${cur.starts_on}T00:00:00`).getTime();
    const end = new Date(`${cur.ends_on}T23:59:59`).getTime();
    return Math.min(100, Math.max(0, Math.round(((Date.now() - start) / (end - start)) * 100)));
});
const leftTone = computed(() => {
    const left = sub.value?.days_left;
    if (left === null || left === undefined) return 'text-muted-foreground';
    return left < 0 ? 'text-destructive' : left <= 30 ? 'text-amber-600' : 'text-emerald-600';
});

async function act(action: 'pause' | 'resume' | 'cancel') {
    busy.value = true;
    try {
        const { data } = await api.post(`/subscriptions/${sub.value!.id}/${action}`, action === 'cancel' ? { reason: cancelReason.value || null } : {});
        toast.success({ pause: 'Đã tạm dừng: không nhắc, không tự tạo đơn gia hạn.', resume: 'Thuê bao dùng lại.', cancel: 'Đã huỷ thuê bao.' }[action]);
        cancelOpen.value = false;
        if (data.data.periods) fill(data.data);
        else await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = false;
    }
}

async function saveInfo() {
    busy.value = true;
    try {
        const { data } = await api.put(`/subscriptions/${sub.value!.id}`, { label: info.label.trim() || null, note: info.note.trim() || null, remind: info.remind, assigned_to: info.assigned_to || null });
        fill(data.data);
        toast.success('Đã lưu.');
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = false;
    }
}

const periodStatus: Record<string, { label: string; tone: 'success' | 'info' | 'muted' }> = {
    active: { label: 'Đã tính', tone: 'success' },
    pending: { label: 'Chờ xác nhận đơn', tone: 'info' },
    void: { label: 'Đã bỏ', tone: 'muted' },
};
</script>

<template>
    <ErrorState v-if="loadError" :message="loadError" />
    <div v-else-if="!sub" class="space-y-3"><Skeleton class="h-10 w-80" /><Skeleton class="h-72" /></div>
    <template v-else>
        <PageHeader :title="`${sub.product?.name ?? 'Thuê bao'}${sub.label ? ` · ${sub.label}` : ''}`" :back="{ to: '/subscriptions', label: 'Thuê bao, gia hạn' }">
            <template #description>
                <span class="font-mono">{{ sub.code }}</span> ·
                <Badge :tone="stateLabels[sub.state].tone">{{ stateLabels[sub.state].label }}</Badge>
            </template>
            <template v-if="canSell">
                <Button v-if="sub.status === 'active'" variant="outline" :disabled="busy" data-testid="pause" @click="act('pause')"><Pause /> Tạm dừng</Button>
                <Button v-if="sub.status !== 'active'" variant="outline" :disabled="busy" data-testid="resume" @click="act('resume')"><Play /> Dùng lại</Button>
                <Button v-if="sub.status !== 'cancelled'" variant="ghost" class="text-destructive" :disabled="busy" data-testid="cancel" @click="cancelOpen = true"><Ban /> Huỷ thuê bao</Button>
                <Button v-if="sub.status !== 'cancelled'" data-testid="renew" @click="renewOpen = true"><RefreshCw /> Gia hạn</Button>
            </template>
        </PageHeader>

        <div class="grid items-start gap-5 lg:grid-cols-3">
            <div class="space-y-5 lg:col-span-2">
                <div class="grid gap-3 sm:grid-cols-3" data-testid="subscription-stats">
                    <div class="rounded-lg bg-card p-4 shadow-card">
                        <p class="text-xs text-muted-foreground">Hết hạn</p>
                        <p class="text-lg font-bold tabular-nums">{{ day(sub.ends_on) }}</p>
                        <p :class="cn('text-xs font-medium', leftTone)">{{ sub.status === 'active' ? leftText(sub.days_left) : stateLabels[sub.state].label }}</p>
                        <div v-if="progress !== null" class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-primary" :style="{ width: `${progress}%` }" /></div>
                    </div>
                    <div class="rounded-lg bg-card p-4 shadow-card">
                        <p class="text-xs text-muted-foreground">Đang dùng gói</p>
                        <p class="text-lg font-bold">{{ sub.variant?.name ?? (sub.variant?.period_months ? `${sub.variant.period_months} tháng` : '—') }}</p>
                        <p class="text-xs text-muted-foreground">Giá gia hạn {{ sub.variant?.ref_price ? formatMoney(sub.variant.ref_price) : '—' }}</p>
                    </div>
                    <div class="rounded-lg bg-card p-4 shadow-card">
                        <p class="text-xs text-muted-foreground">Đã thu từ thuê bao</p>
                        <p class="text-lg font-bold tabular-nums text-blue-600">{{ formatMoney(sub.paid_total) }}</p>
                        <p class="text-xs text-muted-foreground">Dùng từ {{ day(sub.customer_since) }}</p>
                    </div>
                </div>

                <Card title="Các kỳ đã gia hạn" description="Mỗi kỳ là một đơn bán. Huỷ đơn thì kỳ đó được bỏ và ngày hết hạn lùi lại.">
                    <p v-if="!sub.periods.length" class="px-5 py-6 text-center text-sm text-muted-foreground">Chưa có kỳ nào.</p>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm" data-testid="periods">
                            <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th class="px-5 py-2 font-medium">Kỳ</th>
                                    <th class="px-3 py-2 font-medium">Gói</th>
                                    <th class="px-3 py-2 font-medium">Đơn</th>
                                    <th class="px-3 py-2 text-right font-medium">Số tiền</th>
                                    <th class="px-3 py-2 font-medium">Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="p in sub.periods" :key="p.id" :class="cn(p.status === 'void' && 'text-muted-foreground line-through decoration-slate-300', p.current && 'bg-blue-50/40')">
                                    <td class="whitespace-nowrap px-5 py-2.5 tabular-nums">
                                        {{ day(p.starts_on) }} → {{ day(p.ends_on) }}
                                        <span v-if="p.current" class="ml-1 rounded bg-blue-100 px-1.5 py-0.5 text-[11px] font-medium text-blue-700 no-underline">Hiện tại</span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2.5">{{ p.variant?.name ?? `${p.months} tháng` }}</td>
                                    <td class="px-3 py-2.5">
                                        <RouterLink v-if="p.order" :to="`/orders/${p.order.id}`" class="font-mono text-xs text-primary hover:underline">{{ p.order.number }}</RouterLink>
                                        <p v-if="p.order && Number(p.order.due) > 0 && p.status === 'active'" class="text-xs text-amber-600">Còn nợ {{ formatMoney(p.order.due) }}</p>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">{{ formatMoney(p.amount) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5"><Badge :tone="periodStatus[p.status]?.tone">{{ periodStatus[p.status]?.label }}</Badge></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <Card title="Lịch sử">
                    <ol class="divide-y" data-testid="events">
                        <li v-for="e in sub.events" :key="e.id" class="flex gap-3 px-5 py-2.5 text-sm">
                            <span class="w-28 shrink-0 text-xs text-muted-foreground">{{ formatDateTime(e.at) }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="font-medium">{{ eventLabels[e.kind] ?? e.kind }}</span>
                                <span v-if="e.note" class="text-muted-foreground"> · {{ e.note }}</span>
                            </span>
                            <span v-if="e.user" class="shrink-0 text-xs text-muted-foreground">{{ e.user }}</span>
                        </li>
                        <li v-if="!sub.events.length" class="px-5 py-6 text-center text-sm text-muted-foreground">Chưa có gì.</li>
                    </ol>
                </Card>
            </div>

            <div class="space-y-5">
                <Card title="Khách hàng">
                    <div class="flex items-center gap-3 p-5 text-sm">
                        <span class="grid size-9 place-items-center rounded-full bg-blue-50 text-blue-500"><UserRound class="size-5" /></span>
                        <div v-if="sub.customer">
                            <RouterLink :to="`/customers/${sub.customer.id}`" class="font-medium text-primary hover:underline">{{ sub.customer.name }}</RouterLink>
                            <p class="text-xs text-muted-foreground">{{ sub.customer.phone }} · {{ sub.customer.code }}</p>
                        </div>
                    </div>
                </Card>
                <Card title="Nhắc gia hạn">
                    <div class="space-y-2 p-5 text-sm">
                        <p class="flex items-start gap-2"><CalendarClock class="mt-0.5 size-4 text-muted-foreground" /><span><template v-if="sub.rules.auto_order_days !== null">Tự tạo đơn gia hạn trước {{ sub.rules.auto_order_days }} ngày.</template><template v-else>Không tự tạo đơn gia hạn.</template> Ân hạn {{ sub.rules.grace_days }} ngày.</span></p>
                        <p class="flex items-start gap-2"><BellRing class="mt-0.5 size-4 text-muted-foreground" /><span>{{ sub.rules.remind ? 'Nhắc khách theo Cấu hình › Thuê bao.' : 'Dịch vụ này tắt nhắc khách.' }}<template v-if="sub.reminders"> Kỳ này đã nhắc {{ sub.reminders }} lần.</template></span></p>
                        <RouterLink v-if="sub.pending_order" :to="`/orders/${sub.pending_order.id}`" class="block text-primary hover:underline">Đơn {{ sub.pending_order.number }} đang chờ xác nhận</RouterLink>
                    </div>
                </Card>
                <Card title="Thông tin">
                    <div class="space-y-3 p-5">
                        <FormField label="Dùng cho" for="s-label" hint="Tên miền, số máy, tài khoản… để phân biệt khi khách có nhiều thuê bao cùng dịch vụ.">
                            <Input id="s-label" v-model="info.label" maxlength="150" :disabled="!canSell" data-testid="sub-label" />
                        </FormField>
                        <FormField label="Phụ trách" for="s-staff">
                            <Select id="s-staff" v-model="info.assigned_to" :disabled="!canSell" :options="[{ value: '', label: '— Chưa chọn —' }, ...staff.map((m) => ({ value: m.id, label: m.name }))]" />
                        </FormField>
                        <FormField label="Ghi chú" for="s-note">
                            <textarea id="s-note" v-model="info.note" rows="3" maxlength="2000" :disabled="!canSell" class="w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" />
                        </FormField>
                        <Switch v-model="info.remind" :disabled="!canSell" label="Nhắc khách này" hint="Tắt khi khách tự gia hạn hoặc không muốn nhận tin." />
                        <Button v-if="canSell" class="w-full" :disabled="!infoDirty" :loading="busy" data-testid="save-info" @click="saveInfo">Lưu thông tin</Button>
                    </div>
                </Card>
            </div>
        </div>

        <RenewDialog v-model:open="renewOpen" :subscription-id="sub.id" @done="load" />
        <Dialog v-model:open="cancelOpen" title="Huỷ thuê bao" description="Không nhắc, không tự tạo đơn gia hạn nữa. Các đơn đã bán vẫn giữ nguyên. Dùng lại được khi cần.">
            <FormField label="Lý do" for="s-cancel"><Input id="s-cancel" v-model="cancelReason" maxlength="255" placeholder="VD: Khách chuyển nhà cung cấp" data-testid="cancel-reason" /></FormField>
            <template #footer>
                <Button variant="outline" @click="cancelOpen = false">Đóng</Button>
                <Button variant="destructive" :loading="busy" data-testid="cancel-confirm" @click="act('cancel')">Huỷ thuê bao</Button>
            </template>
        </Dialog>
    </template>
</template>
