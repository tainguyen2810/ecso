<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { Plus, Trash2 } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import CustomersNav from './CustomersNav.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatMoney } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';

/** Tích điểm và hạng khách (C5): how points are earned and spent, and the tiers by 12-month spending. */
const tenant = useTenantStore();
const toast = useToast();
const canManage = computed(() => tenant.can('promotions.manage'));

interface TierRow { id: string | null; name: string; min_spent: string | number; point_multiplier: string | number; discount_percent: string | number; customer_group_id: string; color: string; customers_count?: number }
const form = reactive({ enabled: false, earn_per: 100000 as string | number, point_value: 1000 as string | number, max_redeem_percent: 50 as string | number, expire_months: 12 as string | number, with_promotions: true });
const tiers = ref<TierRow[]>([]);
const groups = ref<{ id: string; name: string }[]>([]);
const loading = ref(true);
const saving = ref(false);
const error = ref<ApiError | null>(null);
const COLORS = ['#94a3b8', '#64748b', '#d97706', '#7c3aed', '#0ea5e9', '#e11d48'];

function fill(d: Record<string, unknown> & { tiers: Record<string, unknown>[] }) {
    Object.assign(form, { enabled: d.enabled, earn_per: d.earn_per, point_value: d.point_value, max_redeem_percent: d.max_redeem_percent, expire_months: d.expire_months, with_promotions: d.with_promotions });
    tiers.value = d.tiers.map((t) => ({
        id: t.id as string,
        name: t.name as string,
        min_spent: Number(t.min_spent),
        point_multiplier: Number(t.point_multiplier),
        discount_percent: Number(t.discount_percent),
        customer_group_id: (t.customer_group as { id: string } | null)?.id ?? '',
        color: (t.color as string | null) ?? COLORS[0]!,
        customers_count: t.customers_count as number,
    }));
}

onMounted(async () => {
    try {
        const [l, g] = await Promise.all([api.get('/loyalty'), api.get('/customer-groups').catch(() => ({ data: { data: [] } }))]);
        fill(l.data.data);
        groups.value = g.data.data;
    } finally {
        loading.value = false;
    }
});

const groupOptions = computed(() => [{ value: '', label: 'Tạo nhóm mới khi có giảm' }, ...groups.value.map((g) => ({ value: g.id, label: g.name }))]);
function addTier() {
    const last = tiers.value[tiers.value.length - 1];
    tiers.value.push({ id: null, name: '', min_spent: last ? Number(last.min_spent) * 2 || 1000000 : 0, point_multiplier: 1, discount_percent: 0, customer_group_id: '', color: COLORS[tiers.value.length % COLORS.length]! });
}

async function save() {
    saving.value = true;
    error.value = null;
    try {
        const body = {
            ...Object.fromEntries(Object.entries(form).map(([k, v]) => [k, typeof v === 'boolean' ? v : Number(v)])),
            tiers: [...tiers.value].sort((a, b) => Number(a.min_spent) - Number(b.min_spent)).map((t) => ({
                id: t.id, name: t.name.trim(), min_spent: Number(t.min_spent), point_multiplier: Number(t.point_multiplier), discount_percent: Number(t.discount_percent),
                customer_group_id: t.customer_group_id || null, color: t.color,
            })),
        };
        fill((await api.put('/loyalty', body)).data.data);
        groups.value = (await api.get('/customer-groups')).data.data;
        toast.success('Đã lưu tích điểm và hạng khách.');
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        saving.value = false;
    }
}

const example = computed(() => {
    const per = Number(form.earn_per) || 1;
    const points = Math.floor(1000000 / per);
    return `Đơn 1.000.000 ₫ được ${points} điểm, dùng lại được ${formatMoney(points * (Number(form.point_value) || 0))}.`;
});
</script>

<template>
    <PageHeader title="Khách hàng" description="Áp dụng cho đơn kênh riêng và POS có khách hàng. Điểm cộng khi đơn hoàn tất, trừ lại khi trả hàng hoặc hủy.">
        <Button v-if="canManage" :loading="saving" data-testid="loyalty-save" @click="save">Lưu</Button>
    </PageHeader>
    <CustomersNav />

    <div v-if="loading" class="space-y-3"><Skeleton v-for="i in 3" :key="i" class="h-16" /></div>
    <fieldset v-else :disabled="!canManage" class="space-y-4">
        <Card caps title="Tích điểm">
            <div class="space-y-4 p-5">
                <label class="flex items-center gap-2 text-sm font-medium"><input v-model="form.enabled" type="checkbox" class="size-4" data-testid="loyalty-enabled" /> Bật tích điểm</label>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField label="Mỗi … đồng được 1 điểm" for="l-per" hint="Tính trên tiền khách trả, không gồm phí giao" :error="error?.field('earn_per')"><Input id="l-per" v-model="form.earn_per" type="number" min="1000" data-testid="loyalty-earn-per" /></FormField>
                    <FormField label="1 điểm đổi được (₫)" for="l-value" :error="error?.field('point_value')"><Input id="l-value" v-model="form.point_value" type="number" min="0" /></FormField>
                    <FormField label="Dùng điểm tối đa (% giá trị đơn)" for="l-max" :error="error?.field('max_redeem_percent')"><Input id="l-max" v-model="form.max_redeem_percent" type="number" min="0" max="100" /></FormField>
                    <FormField label="Điểm hết hạn sau (tháng)" for="l-exp" hint="0 = không hết hạn" :error="error?.field('expire_months')"><Input id="l-exp" v-model="form.expire_months" type="number" min="0" max="120" /></FormField>
                </div>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.with_promotions" type="checkbox" class="size-4" /> Cho dùng điểm cùng lúc với khuyến mãi</label>
                <p class="text-xs text-muted-foreground">{{ example }}</p>
            </div>
        </Card>

        <Card caps title="Hạng khách">
            <p class="px-5 pt-4 text-xs text-muted-foreground">
                Xét theo tổng chi tiêu 12 tháng gần nhất. Lên hạng ngay khi đủ, xuống hạng khi xét lại mỗi đêm. Mỗi hạng gắn với một nhóm khách, nên giảm giá và chính sách giá của nhóm áp dụng cho hạng. Khách đã được xếp vào nhóm khác bằng tay (VD khách sỉ) giữ nguyên nhóm.
            </p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="tier-table">
                    <thead class="border-b text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Hạng</th>
                            <th class="px-4 py-2.5 font-medium">Chi tiêu 12 tháng từ (₫)</th>
                            <th class="px-4 py-2.5 font-medium">Giảm thêm (%)</th>
                            <th class="px-4 py-2.5 font-medium">Nhân điểm</th>
                            <th class="px-4 py-2.5 font-medium">Nhóm khách tương ứng</th>
                            <th class="px-4 py-2.5 text-right font-medium">Số khách</th>
                            <th class="px-4 py-2.5" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(t, i) in tiers" :key="t.id ?? `new-${i}`">
                            <td class="px-4 py-2">
                                <div class="flex items-center gap-2">
                                    <input v-model="t.color" type="color" class="size-7 cursor-pointer rounded border" aria-label="Màu hạng" />
                                    <Input v-model="t.name" class="w-36" placeholder="VD: Vàng" :data-testid="`tier-name-${i}`" />
                                </div>
                            </td>
                            <td class="px-4 py-2"><Input v-model="t.min_spent" type="number" min="0" class="w-40" :data-testid="`tier-min-${i}`" /></td>
                            <td class="px-4 py-2"><Input v-model="t.discount_percent" type="number" min="0" max="100" step="0.5" class="w-24" /></td>
                            <td class="px-4 py-2"><Input v-model="t.point_multiplier" type="number" min="0" step="0.1" class="w-24" /></td>
                            <td class="px-4 py-2"><Select v-model="t.customer_group_id" class="w-52" :options="groupOptions" aria-label="Nhóm khách" /></td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ t.customers_count ?? 0 }}</td>
                            <td class="px-4 py-2 text-right"><Button variant="ghost" size="sm" :aria-label="`Bỏ hạng ${t.name}`" @click="tiers.splice(i, 1)"><Trash2 /></Button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-3">
                <Button v-if="tiers.length < 10" variant="outline" size="sm" data-testid="tier-add" @click="addTier"><Plus /> Thêm hạng</Button>
            </div>
            <p v-if="error?.field('tiers') || Object.keys(error?.body.errors ?? {}).some((k) => k.startsWith('tiers.'))" class="px-5 pb-4 text-sm text-destructive">
                {{ error?.field('tiers') ?? Object.values(error?.body.errors ?? {})[0]?.[0] }}
            </p>
        </Card>
    </fieldset>
</template>
