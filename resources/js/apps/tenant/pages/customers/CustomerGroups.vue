<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { Hand, Pencil, Plus, Sparkles, Trash2, TrendingUp, Users, UsersRound } from 'lucide-vue-next';
import CustomersFrame from './CustomersFrame.vue';
import ReportChip from '../reports/ReportChip.vue';
import { btnPrimary } from './frame';
import ReportKpi from '../reports/ReportKpi.vue';
import { priceTierLabels } from './labels';
import { paymentMethods } from '../orders/labels';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import type { CustomerGroup } from '@/shared/types';

const tenant = useTenantStore();
const toast = useToast();
const canManage = computed(() => tenant.can('customers.manage'));
const rows = ref<CustomerGroup[]>([]);
const loading = ref(true);
const loadError = ref<string | null>(null);

const tones = ['bg-slate-100 text-slate-600', 'bg-blue-50 text-blue-600', 'bg-amber-50 text-amber-600', 'bg-emerald-50 text-emerald-600', 'bg-violet-50 text-violet-600', 'bg-pink-50 text-pink-600'];
const isAuto = (g: CustomerGroup) => g.auto_min_spent != null || g.auto_min_orders != null;
const autoText = (g: CustomerGroup) =>
    [g.auto_min_spent != null ? `tổng chi tiêu từ ${formatMoney(g.auto_min_spent)}` : '', g.auto_min_orders != null ? `từ ${g.auto_min_orders} đơn` : ''].filter(Boolean).join(' và ');
const meta = ref<{ customers: number; revenue_12m: string } | null>(null);
const stats = computed(() => {
    const customers = rows.value.reduce((a, g) => a + (g.customers_count ?? 0), 0);
    const revenue = rows.value.reduce((a, g) => a + Number(g.revenue_12m ?? 0), 0);
    const total = meta.value?.customers ?? 0;
    return {
        customers,
        auto: rows.value.filter(isAuto).length,
        inactive: rows.value.filter((g) => g.is_active === false).length,
        total,
        share: total ? (customers * 100) / total : null,
        revenue,
        // The biggest-spending group, for the fourth card.
        top: [...rows.value].sort((a, b) => Number(b.revenue_12m ?? 0) - Number(a.revenue_12m ?? 0))[0] ?? null,
        allRevenue: Number(meta.value?.revenue_12m ?? 0),
    };
});
const pct = (n: number) => `${(Math.round(n * 10) / 10).toString().replace('.', ',')}%`;
const bars = ['bg-slate-400', 'bg-blue-600', 'bg-amber-500', 'bg-emerald-500', 'bg-violet-500', 'bg-pink-400'];

const dialog = ref(false);
const editing = ref<CustomerGroup | null>(null);
const form = reactive({ name: '', code: '', description: '', price_tier: 'retail', discount_percent: '0', payment_method: '', payment_terms_days: '', credit_limit: '', is_active: true, auto_min_spent: '', auto_min_orders: '' });
const saving = ref(false);
const error = ref<ApiError | null>(null);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get('/customer-groups', { params: { stats: 1 } });
        rows.value = data.data;
        meta.value = data.meta ?? null;
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

function edit(g: CustomerGroup | null) {
    editing.value = g;
    error.value = null;
    Object.assign(form, {
        name: g?.name ?? '', code: g?.code ?? '', description: g?.description ?? '', price_tier: g?.price_tier ?? 'retail',
        discount_percent: g ? String(Number(g.discount_percent)) : '0', payment_method: g?.payment_method ?? '',
        payment_terms_days: g?.payment_terms_days != null ? String(g.payment_terms_days) : '', credit_limit: g?.credit_limit != null ? String(Number(g.credit_limit)) : '', is_active: g?.is_active !== false,
        auto_min_spent: g?.auto_min_spent != null ? String(Number(g.auto_min_spent)) : '', auto_min_orders: g?.auto_min_orders != null ? String(g.auto_min_orders) : '',
    });
    dialog.value = true;
}

async function save() {
    saving.value = true;
    error.value = null;
    const body = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]));
    try {
        editing.value ? await api.put(`/customer-groups/${editing.value.id}`, body) : await api.post('/customer-groups', body);
        toast.success(editing.value ? 'Đã lưu nhóm.' : 'Đã thêm nhóm.');
        dialog.value = false;
        await load();
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        saving.value = false;
    }
}

async function remove(g: CustomerGroup) {
    if (!window.confirm(`Xóa nhóm ${g.name}?`)) return;
    try {
        await api.delete(`/customer-groups/${g.id}`);
        toast.success('Đã xóa nhóm.');
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}
</script>

<template>
    <CustomersFrame page="Nhóm khách hàng" description="Điều khoản mua hàng của từng nhóm: giá áp dụng, chiết khấu, thanh toán, công nợ. Nhóm có thể tự xếp khách theo ngưỡng.">
        <button v-if="canManage" type="button" :class="btnPrimary" data-testid="add-group" @click="edit(null)"><Plus class="size-4" /> Thêm nhóm</button>
    </CustomersFrame>

    <div v-if="loading && !rows.length" class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3"><Skeleton v-for="i in 3" :key="i" class="h-72 rounded-[14px]" /></div>
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <div v-else-if="!rows.length" class="rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
        <EmptyState :icon="UsersRound" title="Chưa có nhóm khách hàng" description="Tạo nhóm như Khách lẻ, Khách sỉ, VIP để lọc khách, giao việc và ghi điều khoản mua hàng cho từng nhóm.">
            <Button v-if="canManage" @click="edit(null)"><Plus /> Thêm nhóm</Button>
        </EmptyState>
    </div>
    <template v-else>
        <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <ReportKpi label="Số nhóm" :icon="UsersRound" tone="blue" plain :value="rows.length" unit="nhóm">
                <template #chips><ReportChip tone="blue">{{ rows.length - stats.inactive }} đang dùng</ReportChip></template>
                Bấm số khách trên nhóm để xem danh sách
            </ReportKpi>
            <ReportKpi label="Khách đã có nhóm" :icon="Users" tone="violet" plain :value="stats.customers" unit="khách">
                <template v-if="stats.share !== null" #chips><ReportChip tone="plain">{{ pct(stats.share) }} tổng khách</ReportChip></template>
                {{ stats.total > stats.customers ? `${formatNumber(stats.total - stats.customers)} khách chưa xếp nhóm` : 'Mọi khách đều đã có nhóm' }}
            </ReportKpi>
            <ReportKpi label="Nhóm tự xếp khách" :icon="Sparkles" tone="green" plain :value="stats.auto" unit="nhóm">
                <template #chips><ReportChip tone="up">Chạy mỗi đêm</ReportChip></template>
                Xếp khách chưa có nhóm đạt ngưỡng vào nhóm
            </ReportKpi>
            <ReportKpi label="Doanh thu 12 tháng theo nhóm" tip="Tổng tiền các đơn 12 tháng qua của khách đang thuộc một nhóm. Không tính đơn hủy, đơn hoàn." :icon="TrendingUp" tone="amber" :value="Math.round(stats.revenue)">
                <template v-if="stats.allRevenue" #chips><ReportChip tone="amber">{{ pct((stats.revenue * 100) / stats.allRevenue) }} doanh thu khách</ReportChip></template>
                <template v-if="stats.top && Number(stats.top.revenue_12m)">Nhiều nhất: {{ stats.top.name }} {{ formatNumber(Math.round(Number(stats.top.revenue_12m))) }}</template>
                <template v-else>Chưa có đơn của khách trong nhóm</template>
            </ReportKpi>
        </div>
        <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3" data-testid="group-table">
            <div
                v-for="(g, i) in rows"
                :key="g.id"
                :class="cn('flex flex-col rounded-[14px] border border-slate-200/80 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.04)]', g.is_active === false && 'opacity-70')"
            >
                <div class="flex items-start gap-3.5">
                    <span :class="cn('grid size-11 shrink-0 place-items-center rounded-[12px]', tones[i % tones.length])"><UsersRound class="size-5" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[16px] font-semibold text-slate-900">{{ g.name }}</p>
                        <p class="text-[13px] text-slate-500">
                            <span class="font-mono">{{ g.code }}</span> ·
                            <RouterLink :to="{ path: '/customers', query: { group: g.id } }" class="text-blue-700 hover:underline">{{ formatNumber(g.customers_count) }} khách</RouterLink>
                        </p>
                    </div>
                    <span v-if="g.is_active === false" class="rounded-md bg-slate-100 px-2 py-0.5 text-[12px] font-medium text-slate-500">Ngừng sử dụng</span>
                </div>
                <p v-if="g.description" class="mt-2 line-clamp-1 text-[13px] text-slate-500">{{ g.description }}</p>
                <div v-if="stats.total" class="mt-3">
                    <div class="flex flex-wrap justify-between gap-x-3 text-[12px] text-slate-500">
                        <span>{{ pct(((g.customers_count ?? 0) * 100) / stats.total) }} số khách</span>
                        <span>Doanh thu 12 tháng <b class="font-semibold text-slate-800">{{ formatNumber(Math.round(Number(g.revenue_12m ?? 0))) }}</b></span>
                    </div>
                    <div class="mt-1.5 h-1.5 rounded-full bg-slate-100">
                        <div :class="cn('h-1.5 rounded-full', bars[i % bars.length])" :style="{ width: `${Math.max(1, ((g.customers_count ?? 0) * 100) / stats.total)}%` }" />
                    </div>
                </div>
                <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 rounded-[12px] bg-slate-50 p-4 text-[13px]">
                    <div><dt class="text-slate-500">Giá áp dụng</dt><dd class="font-semibold text-slate-900">{{ priceTierLabels[g.price_tier] }}</dd></div>
                    <div><dt class="text-slate-500">Chiết khấu</dt><dd class="font-semibold text-slate-900">{{ Number(g.discount_percent) ? `${Number(g.discount_percent)}%` : '—' }}</dd></div>
                    <div><dt class="text-slate-500">Thanh toán quen dùng</dt><dd class="font-semibold text-slate-900">{{ g.payment_method ? paymentMethods[g.payment_method] : '—' }}</dd></div>
                    <div>
                        <dt class="text-slate-500">Hạn mức nợ</dt>
                        <dd class="font-semibold text-slate-900">{{ g.credit_limit != null ? formatMoney(g.credit_limit) : '—' }}<template v-if="g.payment_terms_days != null"> · {{ g.payment_terms_days }} ngày</template></dd>
                    </div>
                </dl>
                <div class="mt-auto flex items-center gap-2 pt-3.5 text-[13px]">
                    <p :class="cn('flex min-w-0 flex-1 items-center gap-1.5', isAuto(g) ? 'text-blue-700' : 'text-slate-400')">
                        <Sparkles v-if="isAuto(g)" class="size-4 shrink-0" />
                        <Hand v-else class="size-4 shrink-0" />
                        <span class="truncate" :title="isAuto(g) ? `Tự xếp khi ${autoText(g)}` : undefined">{{ isAuto(g) ? `Tự xếp: ${autoText(g)}` : 'Xếp khách bằng tay' }}</span>
                    </p>
                    <template v-if="canManage">
                        <button
                            type="button"
                            class="grid size-8 place-items-center rounded-[8px] text-slate-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-40 disabled:hover:bg-transparent"
                            :aria-label="`Xóa ${g.name}`"
                            :disabled="!!g.customers_count"
                            :title="g.customers_count ? 'Nhóm còn khách' : 'Xóa'"
                            @click="remove(g)"
                        >
                            <Trash2 class="size-4" />
                        </button>
                        <button type="button" class="inline-flex items-center gap-1.5 rounded-[9px] border border-slate-200 px-3 py-1.5 text-slate-600 hover:border-blue-300 hover:text-blue-700" :aria-label="`Sửa ${g.name}`" @click="edit(g)">
                            <Pencil class="size-3.5" /> Sửa
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </template>

    <Dialog v-model:open="dialog" :title="editing ? 'Sửa nhóm khách hàng' : 'Thêm nhóm khách hàng'">
        <form id="group-form" class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
            <FormField label="Tên nhóm" for="g-name" :error="error?.field('name')"><Input id="g-name" v-model="form.name" required data-testid="g-name" /></FormField>
            <FormField label="Mã nhóm" for="g-code" :error="error?.field('code')" :hint="editing ? undefined : 'Để trống để tạo từ tên'"><Input id="g-code" v-model="form.code" /></FormField>
            <FormField label="Giá áp dụng" for="g-tier" :error="error?.field('price_tier')">
                <Select id="g-tier" v-model="form.price_tier" :options="Object.entries(priceTierLabels).map(([value, label]) => ({ value, label }))" />
            </FormField>
            <FormField label="Chiết khấu (%)" for="g-discount" :error="error?.field('discount_percent')"><Input id="g-discount" v-model="form.discount_percent" type="number" min="0" max="100" step="0.5" /></FormField>
            <FormField label="Hạn thanh toán (ngày)" for="g-terms" :error="error?.field('payment_terms_days')" hint="Đơn quá số ngày này thì tính là nợ quá hạn."><Input id="g-terms" v-model="form.payment_terms_days" type="number" min="0" max="365" /></FormField>
            <FormField label="Hạn mức nợ" for="g-credit" :error="error?.field('credit_limit')"><Input id="g-credit" v-model="form.credit_limit" type="number" min="0" step="any" /></FormField>
            <FormField label="Thanh toán quen dùng" for="g-pay" class="sm:col-span-2">
                <Select id="g-pay" v-model="form.payment_method" :options="[{ value: '', label: 'Không đặt' }, ...Object.entries(paymentMethods).map(([value, label]) => ({ value, label }))]" />
            </FormField>
            <fieldset class="rounded-md border px-3 py-2 sm:col-span-2">
                <legend class="px-1 text-sm font-medium">Tự xếp khách vào nhóm</legend>
                <p class="mb-2 text-xs text-muted-foreground">Mỗi đêm, khách chưa có nhóm đạt ngưỡng dưới đây được xếp vào nhóm này (đặt cả hai thì phải đạt cả hai). Khách đã được xếp nhóm bằng tay không bị đổi. Để trống để tắt.</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <FormField label="Tổng chi tiêu từ" for="g-auto-spent" :error="error?.field('auto_min_spent')"><Input id="g-auto-spent" v-model="form.auto_min_spent" type="number" min="0" step="any" data-testid="g-auto-spent" /></FormField>
                    <FormField label="Số đơn từ" for="g-auto-orders" :error="error?.field('auto_min_orders')"><Input id="g-auto-orders" v-model="form.auto_min_orders" type="number" min="1" step="1" /></FormField>
                </div>
            </fieldset>
            <label class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="form.is_active" type="checkbox" class="size-4" data-testid="g-active" /> Đang sử dụng (bỏ chọn để không xếp thêm khách mới vào nhóm; khách đang trong nhóm giữ nguyên)</label>
            <FormField label="Mô tả" for="g-desc" class="sm:col-span-2"><Input id="g-desc" v-model="form.description" /></FormField>
            <p v-if="error && !error.body.errors" class="text-sm text-destructive sm:col-span-2">{{ error.message }}</p>
        </form>
        <template #footer>
            <Button variant="outline" @click="dialog = false">Hủy</Button>
            <Button type="submit" form="group-form" :loading="saving" data-testid="g-save">Lưu</Button>
        </template>
    </Dialog>
</template>
