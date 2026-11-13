<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Pause, Play, Plus, TicketPercent, Trash2 } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import CustomersNav from '../customers/CustomersNav.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatDate, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import { describe, stateLabels, typeLabels, type PromotionData, type PromotionState } from './promotions';

/** Khuyến mãi (C4): the shop's promotions for its own channels and the POS, with how often each was used. */
const tenant = useTenantStore();
const toast = useToast();
const canManage = computed(() => tenant.can('promotions.manage'));
const rows = ref<PromotionData[]>([]);
const channels = ref<{ id: string; name: string }[]>([]);
const loading = ref(true);
const loadError = ref<string | null>(null);
const state = ref<PromotionState | ''>('');

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const [p, c] = await Promise.all([api.get('/promotions'), api.get('/sales-channels').catch(() => ({ data: { data: [] } }))]);
        rows.value = p.data.data;
        channels.value = c.data.data;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

const counts = computed(() => Object.fromEntries((Object.keys(stateLabels) as PromotionState[]).map((s) => [s, rows.value.filter((r) => r.state === s).length])));
const shown = computed(() => (state.value ? rows.value.filter((r) => r.state === state.value) : rows.value));

function where(p: PromotionData): string {
    if (!p.channel_ids.length) return 'Mọi kênh riêng';
    return p.channel_ids.map((id) => channels.value.find((c) => c.id === id)?.name ?? '?').join(', ');
}
function when(p: PromotionData): string {
    if (!p.starts_at && !p.ends_at) return 'Không thời hạn';
    return `${p.starts_at ? formatDate(p.starts_at) : '…'} – ${p.ends_at ? formatDate(p.ends_at) : '…'}`;
}
function limits(p: PromotionData): string {
    return [p.per_customer_limit ? `Mỗi khách ${p.per_customer_limit} lần` : '', p.usage_limit ? `còn ${Math.max(0, p.usage_limit - p.uses)}/${p.usage_limit} lượt` : ''].filter(Boolean).join(' · ');
}

async function toggle(p: PromotionData) {
    try {
        await api.put(`/promotions/${p.id}`, { status: p.status === 'active' ? 'paused' : 'active' });
        toast.success(p.status === 'active' ? 'Đã tạm dừng khuyến mãi.' : 'Đã bật lại khuyến mãi.');
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}

async function remove(p: PromotionData) {
    if (!window.confirm(`Xóa khuyến mãi ${p.name}?`)) return;
    try {
        await api.delete(`/promotions/${p.id}`);
        toast.success('Đã xóa khuyến mãi.');
        await load();
    } catch (e) {
        toast.error((e as ApiError).field('promotion') ?? (e as ApiError).message);
    }
}
</script>

<template>
    <PageHeader title="Khách hàng" description="Mỗi đơn nhận 1 khuyến mãi giảm tiền tự động (cái có lợi nhất cho khách), các quà tặng đủ điều kiện và 1 mã giảm giá. Đơn sàn theo khuyến mãi của sàn.">
        <Button v-if="canManage" data-testid="add-promotion" @click="$router.push('/promotions/new')"><Plus /> Tạo khuyến mãi</Button>
    </PageHeader>
    <CustomersNav />

    <div class="mb-3 flex flex-wrap gap-2 text-sm">
        <button type="button" :class="cn('rounded-full border px-3 py-1', !state && 'border-primary bg-primary/10 text-primary')" @click="state = ''">Tất cả {{ rows.length }}</button>
        <button
            v-for="(s, key) in stateLabels"
            :key="key"
            type="button"
            :class="cn('rounded-full border px-3 py-1', state === key && 'border-primary bg-primary/10 text-primary')"
            @click="state = key"
        >
            {{ s.label }} {{ counts[key] }}
        </button>
    </div>

    <Card>
        <div v-if="loading && !rows.length" class="space-y-3 p-4"><Skeleton v-for="i in 3" :key="i" class="h-10" /></div>
        <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
        <EmptyState v-else-if="!rows.length" :icon="TicketPercent" title="Chưa có khuyến mãi" description="Tạo giảm giá theo đơn, theo sản phẩm, mua X tặng Y hoặc mã giảm giá. Đơn tạo tay và bán tại quầy tự áp khi đủ điều kiện.">
            <Button v-if="canManage" @click="$router.push('/promotions/new')"><Plus /> Tạo khuyến mãi</Button>
        </EmptyState>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="promotion-table">
                <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Tên khuyến mãi</th>
                        <th class="px-4 py-2.5 font-medium">Loại</th>
                        <th class="px-4 py-2.5 font-medium">Mã</th>
                        <th class="px-4 py-2.5 font-medium">Áp dụng</th>
                        <th class="px-4 py-2.5 font-medium">Thời gian</th>
                        <th class="px-4 py-2.5 text-right font-medium">Đã dùng</th>
                        <th class="px-4 py-2.5 text-right font-medium">Đã giảm</th>
                        <th class="px-4 py-2.5 font-medium">Trạng thái</th>
                        <th v-if="canManage" class="px-4 py-2.5" />
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="p in shown" :key="p.id" class="hover:bg-muted/30">
                        <td class="px-4 py-3">
                            <RouterLink v-if="canManage" :to="`/promotions/${p.id}`" class="font-medium text-primary hover:underline">{{ p.name }}</RouterLink>
                            <span v-else class="font-medium">{{ p.name }}</span>
                            <div class="text-xs text-muted-foreground">{{ describe(p) }}<template v-if="limits(p)"> · {{ limits(p) }}</template></div>
                        </td>
                        <td class="px-4 py-3">{{ p.code ? 'Mã giảm giá' : typeLabels[p.type] }}</td>
                        <td class="px-4 py-3"><b v-if="p.code" class="font-mono">{{ p.code }}</b><span v-else class="text-muted-foreground">Tự động</span></td>
                        <td class="px-4 py-3">{{ where(p) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-muted-foreground">{{ when(p) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ formatNumber(p.uses) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(p.amount) }}</td>
                        <td class="px-4 py-3"><Badge :tone="stateLabels[p.state].tone">{{ stateLabels[p.state].label }}</Badge></td>
                        <td v-if="canManage" class="whitespace-nowrap px-4 py-3 text-right">
                            <Button v-if="p.state !== 'ended'" variant="ghost" size="sm" :aria-label="p.status === 'active' ? `Tạm dừng ${p.name}` : `Bật ${p.name}`" :title="p.status === 'active' ? 'Tạm dừng' : 'Bật lại'" @click="toggle(p)">
                                <Pause v-if="p.status === 'active'" /><Play v-else />
                            </Button>
                            <Button variant="ghost" size="sm" :aria-label="`Xóa ${p.name}`" :disabled="p.uses > 0" :title="p.uses > 0 ? 'Đã có đơn dùng, chỉ tạm dừng được' : 'Xóa'" @click="remove(p)"><Trash2 /></Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </Card>
</template>
