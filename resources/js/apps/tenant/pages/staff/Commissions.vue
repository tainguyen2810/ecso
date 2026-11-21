<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { MessageCircle, Pencil, Plus, Store, Trophy, Wrench } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import MonthSwitch from './MonthSwitch.vue';
import { monthLabel, RULE_KINDS, ruleValue, thisMonth, type CommissionMonth, type CommissionRule, type RuleKind } from './staff';

/**
 * Nhân viên › Hoa hồng: the ways commission is paid (set once, switched on and off) and each member's figures of the
 * month so far. Returns come off by themselves. A member sees only their own figures.
 */
const route = useRoute();
const router = useRouter();
const toast = useToast();
const month = ref(typeof route.query.month === 'string' && /^\d{4}-\d{2}$/.test(route.query.month) ? route.query.month : thisMonth());
const data = ref<CommissionMonth | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const members = ref<{ id: string; name: string }[]>([]);
const categories = ref<{ id: string; name: string; parent?: { name: string } | null }[]>([]);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        data.value = (await api.get('/staff/commissions', { params: { month: month.value } })).data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
watch(month, (m) => {
    router.replace({ query: { month: m } });
    load();
});
onMounted(load);

const icons = { counter: Store, online: MessageCircle, category_unit: Wrench, store_bonus: Trophy } as const;
const who = (r: CommissionRule) => (r.user_ids === null ? 'Tất cả' : r.user_ids.map((id) => members.value.find((m) => m.id === id)?.name ?? '…').join(', ') || 'Chưa chọn ai');
const best = computed(() => Math.max(1, ...(data.value?.members ?? []).map((m) => m.commission)));

async function toggle(r: CommissionRule) {
    try {
        await api.put(`/staff/commission-rules/${r.id}`, { is_active: !r.is_active });
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}

// Add or edit a rule.
const open = ref(false);
const editingId = ref<string | null>(null);
const form = ref({ name: '', kind: 'counter' as RuleKind, rate: 1, amount: 50000, category_id: '', threshold: 500000000, everyone: true, user_ids: [] as string[] });
const saving = ref(false);
async function ensureLists() {
    if (!members.value.length) members.value = (await api.get('/staff/members').catch(() => null))?.data?.data ?? [];
    if (!categories.value.length) categories.value = (await api.get('/categories').catch(() => null))?.data?.data ?? [];
}
watch(() => data.value?.manage, (m) => m && ensureLists());
function edit(r?: CommissionRule) {
    ensureLists();
    editingId.value = r?.id ?? null;
    form.value = r
        ? { name: r.name, kind: r.kind, rate: r.rate, amount: r.amount, category_id: r.category_id ?? '', threshold: r.threshold, everyone: r.user_ids === null, user_ids: r.user_ids ?? [] }
        : { name: '', kind: 'counter', rate: 1, amount: 50000, category_id: '', threshold: 500000000, everyone: true, user_ids: [] };
    open.value = true;
}
async function save() {
    saving.value = true;
    try {
        const f = form.value;
        const body = { name: f.name || RULE_KINDS[f.kind].label, kind: f.kind, rate: f.rate, amount: f.amount, category_id: f.category_id || null, threshold: f.threshold, user_ids: f.everyone ? null : f.user_ids };
        if (editingId.value) await api.put(`/staff/commission-rules/${editingId.value}`, body);
        else await api.post('/staff/commission-rules', body);
        toast.success('Đã lưu cách tính hoa hồng.');
        open.value = false;
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        saving.value = false;
    }
}
async function remove() {
    if (!editingId.value) return;
    try {
        await api.delete(`/staff/commission-rules/${editingId.value}`);
        open.value = false;
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}
</script>

<template>
    <PageHeader title="Hoa hồng" description="Tự tính từ đơn đã giao của kênh riêng (quầy, chat, web). Hàng khách trả tự trừ lại.">
        <MonthSwitch v-model="month" />
        <Button v-if="data?.manage" data-testid="rule-add" @click="edit()"><Plus /> Thêm cách tính</Button>
    </PageHeader>

    <div v-if="loading && !data" class="space-y-3"><Skeleton class="h-64" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <div v-else-if="data" :class="['grid grid-cols-[minmax(0,1fr)] items-start gap-4', data.manage && 'xl:grid-cols-[minmax(0,1fr)_400px]']">
        <Card v-if="data.manage" class="min-w-0">
            <div class="border-b px-4 py-3 font-semibold">Cách tính hoa hồng</div>
            <ul class="divide-y" data-testid="rules">
                <li v-for="r in data.rules" :key="r.id" class="flex items-center gap-3 px-4 py-3.5">
                    <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><component :is="icons[r.kind]" class="size-5" /></span>
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">{{ r.name }}</div>
                        <div class="text-xs text-muted-foreground">{{ RULE_KINDS[r.kind].hint }}<template v-if="r.category"> · {{ r.category }}</template></div>
                    </div>
                    <div class="text-right text-sm">
                        <div class="font-semibold">{{ ruleValue(r, formatMoney) }}</div>
                        <div class="text-xs text-muted-foreground">Áp cho: {{ who(r) }}</div>
                    </div>
                    <button type="button" class="grid size-8 place-items-center rounded-lg text-muted-foreground hover:bg-muted" title="Sửa" @click="edit(r)"><Pencil class="size-4" /></button>
                    <button type="button" role="switch" :aria-checked="r.is_active" :class="['relative h-6 w-11 shrink-0 rounded-full transition', r.is_active ? 'bg-primary' : 'bg-muted']" @click="toggle(r)">
                        <span :class="['absolute top-0.5 size-5 rounded-full bg-white shadow transition', r.is_active ? 'left-[22px]' : 'left-0.5']" />
                    </button>
                </li>
                <li v-if="!data.rules.length" class="px-4 py-8 text-center text-sm text-muted-foreground">Chưa có cách tính nào. Bấm Thêm cách tính, VD: 1% doanh số bán tại quầy.</li>
            </ul>
            <p class="m-4 rounded-lg bg-muted/50 p-3 text-xs text-muted-foreground">Chỉ tính đơn đã giao (đơn tại quầy tính khi bán xong). Đơn khách trả hàng tự trừ lại hoa hồng. Đơn sàn không tính hoa hồng.</p>
        </Card>

        <Card class="min-w-0">
            <div class="border-b px-4 py-3 font-semibold">Tạm tính {{ monthLabel(month).toLowerCase() }}<span v-if="month === thisMonth()" class="font-normal text-muted-foreground"> · đến hôm nay</span></div>
            <ul class="divide-y" data-testid="commission-members">
                <li v-for="m in data.members" :key="m.id" class="space-y-1.5 px-4 py-3">
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="font-medium">{{ m.name }}</span>
                        <span class="font-semibold tabular-nums text-emerald-700">{{ formatMoney(m.commission) }}</span>
                    </div>
                    <div class="flex justify-between text-xs text-muted-foreground">
                        <span>Doanh số {{ m.sales ? formatNumber(m.sales) : '—' }}</span>
                        <span v-if="m.target_pct !== null">{{ m.target_pct }}% mục tiêu</span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full bg-emerald-500" :style="{ width: `${m.target_pct ?? Math.round((m.commission / best) * 100)}%` }" /></div>
                    <ul v-if="m.items.length" class="space-y-0.5 pt-1 text-xs text-muted-foreground">
                        <li v-for="i in m.items" :key="i.rule" class="flex justify-between gap-2"><span>{{ i.name }} · {{ i.unit === 'units' ? `${formatNumber(i.base)} sản phẩm` : formatNumber(i.base) }}</span><span class="tabular-nums">{{ formatMoney(i.amount) }}</span></li>
                    </ul>
                </li>
                <li v-if="!data.members.length" class="px-4 py-8 text-center text-sm text-muted-foreground">Chưa có nhân viên.</li>
            </ul>
            <p v-if="data.manage" class="border-t px-4 py-3 text-xs text-muted-foreground">Doanh số tháng của cửa hàng (kênh riêng): {{ formatMoney(data.store_revenue) }}. Nhân viên tự xem số của mình ở trang này.</p>
        </Card>
    </div>

    <Dialog v-model:open="open" :title="editingId ? 'Sửa cách tính hoa hồng' : 'Thêm cách tính hoa hồng'" size="md">
        <div class="space-y-4 text-sm">
            <label class="block"><span class="mb-1 block font-medium">Kiểu tính</span>
                <Select v-model="form.kind" :options="(Object.keys(RULE_KINDS) as RuleKind[]).map((k) => ({ value: k, label: RULE_KINDS[k].label }))" />
                <span class="mt-1 block text-xs text-muted-foreground">{{ RULE_KINDS[form.kind].hint }}</span>
            </label>
            <label class="block"><span class="mb-1 block font-medium">Tên</span><Input v-model="form.name" :placeholder="RULE_KINDS[form.kind].label" maxlength="100" /></label>
            <div class="grid grid-cols-2 gap-3">
                <label v-if="form.kind !== 'category_unit'" class="block"><span class="mb-1 block font-medium">Tỷ lệ (%)</span><Input v-model.number="form.rate" type="number" step="0.1" min="0" max="100" /></label>
                <template v-else>
                    <label class="block"><span class="mb-1 block font-medium">Tiền mỗi sản phẩm (đ)</span><Input v-model.number="form.amount" type="number" min="0" step="1000" /></label>
                    <label class="block"><span class="mb-1 block font-medium">Danh mục</span>
                        <Select v-model="form.category_id" :options="[{ value: '', label: 'Chọn danh mục' }, ...categories.map((c) => ({ value: c.id, label: c.parent ? `${c.parent.name} › ${c.name}` : c.name }))]" />
                    </label>
                </template>
                <label v-if="form.kind === 'store_bonus'" class="block"><span class="mb-1 block font-medium">Khi doanh số tháng vượt (đ)</span><Input v-model.number="form.threshold" type="number" min="0" step="1000000" /></label>
            </div>
            <div>
                <label class="flex items-center gap-2 font-medium"><input v-model="form.everyone" type="checkbox" class="size-4" /> Áp cho tất cả nhân viên</label>
                <div v-if="!form.everyone" class="mt-2 grid max-h-40 gap-1.5 overflow-y-auto rounded-lg border p-2 sm:grid-cols-2">
                    <label v-for="m in members" :key="m.id" class="flex items-center gap-2"><input v-model="form.user_ids" type="checkbox" :value="m.id" class="size-4" /> {{ m.name }}</label>
                </div>
            </div>
        </div>
        <template #footer>
            <Button v-if="editingId" variant="ghost" class="mr-auto text-rose-700" @click="remove">Xoá</Button>
            <Button variant="outline" @click="open = false">Huỷ</Button>
            <Button :loading="saving" data-testid="rule-save" @click="save">Lưu</Button>
        </template>
    </Dialog>
</template>
