<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { AlertTriangle, Plus, Trash2 } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Input from '@/shared/ui/Input.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatMoney } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import type { FeeAlertData, FeeRuleRow } from '@/shared/types';
import { fmtPct, quote, totalPct } from '../marketplaces/pricing';

/**
 * Bảng phí sàn: one fee table per marketplace shop (+ category overrides), the actual fees from settlement data next
 * to it, and a worked example. Saved per shop with its own button (not the page's Lưu, which saves tax/pricing).
 */
const toast = useToast();
const tenant = useTenantStore();
const canEdit = computed(() => tenant.can('marketplace.connect'));
const rows = ref<FeeRuleRow[]>([]);
const alerts = ref<FeeAlertData[]>([]);
const categories = ref<{ id: string; name: string }[]>([]);
const loading = ref(true);
const busy = ref<string | null>(null);
const example = ref({ base: 454000, cost: 320000 });

async function load() {
    loading.value = true;
    try {
        const [r, f, c] = await Promise.all([api.get('/marketplace-pricing/rules'), api.get('/marketplace-pricing/fees'), api.get('/categories')]);
        rows.value = r.data.data;
        alerts.value = f.data.alerts.filter((a: FeeAlertData) => a.type === 'fee_drift');
        categories.value = c.data.data;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

async function save(row: FeeRuleRow) {
    busy.value = `save-${row.account.id}`;
    try {
        const num = (v: unknown) => (v === '' || v === null || v === undefined ? null : Number(v));
        const { data } = await api.put(`/marketplace-pricing/rules/${row.account.id}`, {
            shop: { ...row.shop, commission_pct: num(row.shop.commission_pct) ?? 0, payment_pct: num(row.shop.payment_pct) ?? 0, service_pct: num(row.shop.service_pct) ?? 0, fixed_fee: num(row.shop.fixed_fee) ?? 0, min_margin_pct: Number(row.shop.min_margin_pct) },
            categories: row.categories.filter((c) => c.category_id).map((c) => ({ category_id: c.category_id, commission_pct: num(c.commission_pct) })),
        });
        toast.success(data.data.applied ? `Đã lưu bảng phí ${row.account.shop_name}, áp dụng ${data.data.applied} giá mới.` : `Đã lưu bảng phí ${row.account.shop_name}.`);
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}

async function useActual(row: FeeRuleRow, categoryId?: string | null) {
    busy.value = `actual-${row.account.id}-${categoryId ?? ''}`;
    try {
        await api.post(`/marketplace-pricing/rules/${row.account.id}/use-actual`, { category_id: categoryId ?? null });
        toast.success('Đã dùng phí thực tế 30 ngày. Xem giá mới ở Bảng giá sàn.');
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}

async function dismiss(alert: FeeAlertData) {
    await api.post(`/marketplace-pricing/alerts/${alert.id}/dismiss`);
    alerts.value = alerts.value.filter((a) => a.id !== alert.id);
}

const addCategory = (row: FeeRuleRow) => row.categories.push({ category_id: '', category_name: null, commission_pct: row.shop.commission_pct, payment_pct: null, service_pct: null, fixed_fee: null, actual: null });
const diff = (row: FeeRuleRow) => (row.actual ? Math.round((row.actual.total_pct - totalPct(row.shop)) * 100) / 100 : null);
const examples = computed(() => rows.value.map((r) => ({ row: r, q: quote(Number(example.value.base) || 0, Number(example.value.cost) || null, r.shop) })));
const pctInput = 'h-8 w-[4.5rem] px-2 text-right tabular-nums';
</script>

<template>
    <div class="mt-6 space-y-4" data-testid="fee-table">
        <div v-for="a in alerts" :key="a.id" class="flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50/70 px-4 py-3 text-sm">
            <AlertTriangle class="mt-0.5 size-4 shrink-0 text-amber-500" />
            <div>
                <p class="font-medium">{{ a.text }}</p>
                <p class="mt-1 flex gap-3 text-sm">
                    <button v-if="canEdit" type="button" class="text-primary hover:underline" @click="useActual(rows.find((r) => r.account.id === a.account?.id)!)">Dùng số thực tế</button>
                    <RouterLink to="/settings/pricing/fees" class="text-primary hover:underline">Xem chi tiết đối soát</RouterLink>
                    <button v-if="canEdit" type="button" class="text-muted-foreground hover:underline" @click="dismiss(a)">Bỏ qua</button>
                </p>
            </div>
        </div>

        <Card title="Bảng phí sàn" description="Giá sàn = (giá gốc + phí cố định) ÷ (1 − tổng % phí), làm tròn lên, không thấp hơn giá vốn + lãi tối thiểu.">
            <template #actions><RouterLink to="/settings/pricing/fees"><Button variant="outline" size="sm">Kiểm tra phí sàn</Button></RouterLink></template>
            <div v-if="loading" class="space-y-2 p-5"><Skeleton v-for="i in 3" :key="i" class="h-10" /></div>
            <p v-else-if="!rows.length" class="p-5 text-sm text-muted-foreground">Chưa có gian hàng sàn nào. Website (WooCommerce) bán theo giá gốc nên không có bảng phí.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Gian hàng</th>
                            <th class="px-2 py-2.5 text-right font-medium">Hoa hồng</th>
                            <th class="px-2 py-2.5 text-right font-medium">Thanh toán</th>
                            <th class="px-2 py-2.5 text-right font-medium">Dịch vụ</th>
                            <th class="px-2 py-2.5 text-right font-medium">Tổng % phí</th>
                            <th class="px-2 py-2.5 text-right font-medium">Phí cố định</th>
                            <th class="px-2 py-2.5 text-right font-medium">Phí thực tế 30 ngày</th>
                            <th class="px-2 py-2.5 font-medium">Làm tròn</th>
                            <th class="px-2 py-2.5 text-right font-medium">Lãi tối thiểu</th>
                            <th class="px-2 py-2.5 font-medium">Tự áp dụng</th>
                            <th class="px-4 py-2.5" />
                        </tr>
                    </thead>
                    <tbody v-for="row in rows" :key="row.account.id" class="border-b last:border-0">
                        <tr>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <MarketplaceLogo :marketplace="row.account.marketplace" :name="row.account.marketplace_name" size="sm" />
                                    <span class="font-medium">{{ row.account.shop_name }}</span>
                                </div>
                                <p v-if="!row.saved" class="mt-0.5 text-xs text-amber-600">Số tham khảo, kiểm tra lại theo hợp đồng</p>
                                <p v-else-if="row.shop.suggested_from" class="mt-0.5 text-xs text-muted-foreground">Theo đối soát {{ row.shop.suggested_from.orders }} đơn</p>
                            </td>
                            <td class="px-2 text-right"><Input v-model="row.shop.commission_pct" :class="pctInput" :disabled="!canEdit" inputmode="decimal" :data-testid="`fee-commission-${row.account.id}`" /></td>
                            <td class="px-2 text-right"><Input v-model="row.shop.payment_pct" :class="pctInput" :disabled="!canEdit" inputmode="decimal" /></td>
                            <td class="px-2 text-right"><Input v-model="row.shop.service_pct" :class="pctInput" :disabled="!canEdit" inputmode="decimal" /></td>
                            <td class="px-2 text-right font-semibold tabular-nums">{{ fmtPct(totalPct(row.shop)) }}</td>
                            <td class="px-2 text-right"><Input v-model="row.shop.fixed_fee" class="h-8 w-24 px-2 text-right tabular-nums" :disabled="!canEdit" inputmode="numeric" /></td>
                            <td class="px-2 text-right tabular-nums">
                                <template v-if="row.actual">
                                    <span :class="Math.abs(diff(row) ?? 0) >= 0.5 ? 'font-semibold text-amber-600' : ''">{{ fmtPct(row.actual.total_pct) }}</span>
                                    <span v-if="Math.abs(diff(row) ?? 0) >= 0.5" class="ml-1 rounded-full bg-amber-100 px-1.5 text-xs text-amber-800">{{ diff(row)! > 0 ? '+' : '' }}{{ fmtPct(diff(row)!, false) }}</span>
                                    <span v-else class="ml-1 rounded-full bg-emerald-50 px-1.5 text-xs text-emerald-700">khớp</span>
                                    <button v-if="canEdit && Math.abs(diff(row) ?? 0) >= 0.1" type="button" class="block w-full text-right text-xs text-primary hover:underline" @click="useActual(row)">Dùng số này</button>
                                </template>
                                <span v-else class="text-xs text-muted-foreground">chưa có đối soát</span>
                            </td>
                            <td class="px-2"><Select :model-value="String(row.shop.rounding)" class="h-8 w-24" :disabled="!canEdit" :options="[{ value: '1', label: '1 đ' }, { value: '100', label: '100 đ' }, { value: '500', label: '500 đ' }, { value: '1000', label: '1.000 đ' }]" @update:model-value="row.shop.rounding = Number($event)" /></td>
                            <td class="px-2 text-right"><Input v-model="row.shop.min_margin_pct" :class="pctInput" :disabled="!canEdit" inputmode="decimal" /></td>
                            <td class="px-2">
                                <label class="flex items-center gap-1.5 text-xs"><input v-model="row.shop.auto_apply" type="checkbox" :disabled="!canEdit" /> giá mới</label>
                                <label class="mt-1 flex items-center gap-1.5 text-xs" title="Mỗi sáng, phí thực tế lệch quá 0,5 điểm % thì ECSO tự sửa bảng phí và báo email"><input v-model="row.shop.auto_update_fees" type="checkbox" :disabled="!canEdit" /> bảng phí</label>
                            </td>
                            <td class="px-4 text-right"><Button v-if="canEdit" size="sm" :loading="busy === `save-${row.account.id}`" :data-testid="`fee-save-${row.account.id}`" @click="save(row)">Lưu</Button></td>
                        </tr>
                        <tr v-for="(c, i) in row.categories" :key="i" class="bg-slate-50/60">
                            <td class="py-2 pl-10 pr-4">
                                <Select v-model="c.category_id" class="h-8" :disabled="!canEdit" :options="[{ value: '', label: 'Chọn danh mục' }, ...categories.map((x) => ({ value: x.id, label: x.name }))]" />
                            </td>
                            <td class="px-2 text-right"><Input v-model="c.commission_pct" :class="pctInput" :disabled="!canEdit" inputmode="decimal" /></td>
                            <td class="px-2 text-right tabular-nums text-muted-foreground">{{ fmtPct(row.shop.payment_pct) }}</td>
                            <td class="px-2 text-right tabular-nums text-muted-foreground">{{ fmtPct(row.shop.service_pct) }}</td>
                            <td class="px-2 text-right tabular-nums">{{ fmtPct(totalPct({ ...row.shop, commission_pct: c.commission_pct ?? row.shop.commission_pct })) }}</td>
                            <td class="px-2 text-right tabular-nums text-muted-foreground">{{ formatMoney(row.shop.fixed_fee) }}</td>
                            <td class="px-2 text-right tabular-nums">
                                <template v-if="c.actual">{{ fmtPct(c.actual.total_pct) }}<span class="block text-xs text-muted-foreground">{{ c.actual.orders }} đơn · hoa hồng {{ fmtPct(c.actual.commission_pct) }}</span></template>
                                <span v-else class="text-xs text-muted-foreground">—</span>
                            </td>
                            <td colspan="3" />
                            <td class="px-4 text-right"><button v-if="canEdit" type="button" class="text-muted-foreground hover:text-destructive" aria-label="Xóa quy tắc" @click="row.categories.splice(i, 1)"><Trash2 class="size-4" /></button></td>
                        </tr>
                        <tr v-if="canEdit"><td colspan="11" class="px-4 pb-3 pl-10"><button type="button" class="inline-flex items-center gap-1 text-xs text-primary hover:underline" @click="addCategory(row)"><Plus class="size-3.5" /> Quy tắc theo danh mục (hoa hồng riêng)</button></td></tr>
                    </tbody>
                </table>
            </div>
            <p class="border-t px-5 py-3 text-xs text-muted-foreground">
                Tự áp dụng “giá mới”: giá tính ra được áp dụng ngay (khi sàn chưa có API đổi giá thì vào file giá). Tắt: giá mới chờ duyệt ở <RouterLink to="/marketplaces/prices" class="text-primary hover:underline">Bảng giá sàn</RouterLink>. Giá đang khóa không bị đổi. Website (WooCommerce) bán theo giá gốc, không cộng phí sàn.
            </p>
        </Card>

        <Card v-if="rows.length" title="Ví dụ tính giá" description="Đổi giá gốc, giá vốn để xem giá niêm yết từng sàn theo bảng phí đang nhập.">
            <template #actions>
                <div class="flex items-center gap-2 text-sm">
                    <span class="text-muted-foreground">Giá gốc</span><Input v-model.number="example.base" class="h-8 w-28 text-right" inputmode="numeric" />
                    <span class="text-muted-foreground">Giá vốn</span><Input v-model.number="example.cost" class="h-8 w-28 text-right" inputmode="numeric" />
                </div>
            </template>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b bg-slate-50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <tr><th class="px-4 py-2.5 font-medium">Gian hàng</th><th class="px-4 py-2.5 text-right font-medium">Tổng % phí</th><th class="px-4 py-2.5 text-right font-medium">Giá tính ra</th><th class="px-4 py-2.5 text-right font-medium">Giá niêm yết</th><th class="px-4 py-2.5 text-right font-medium">Sàn thu</th><th class="px-4 py-2.5 text-right font-medium">Thu về</th><th class="px-4 py-2.5 text-right font-medium">Lãi sau phí</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="e in examples" :key="e.row.account.id">
                            <td class="px-4 py-2.5"><div class="flex items-center gap-2"><MarketplaceLogo :marketplace="e.row.account.marketplace" size="sm" />{{ e.row.account.shop_name }}</div></td>
                            <td class="px-4 text-right tabular-nums">{{ fmtPct(totalPct(e.row.shop)) }}</td>
                            <template v-if="e.q">
                                <td class="px-4 text-right tabular-nums text-muted-foreground">{{ formatMoney(Math.round(e.q.raw)) }}</td>
                                <td class="px-4 text-right font-semibold tabular-nums">{{ formatMoney(e.q.price) }}<span v-if="e.q.raised" class="block text-xs font-normal text-amber-600">nâng lên lãi tối thiểu</span></td>
                                <td class="px-4 text-right tabular-nums">{{ formatMoney(Math.round(e.q.fees)) }}</td>
                                <td class="px-4 text-right tabular-nums">{{ formatMoney(Math.round(e.q.net)) }}</td>
                                <td class="px-4 text-right tabular-nums" :class="(e.q.profit ?? 0) < 0 ? 'text-destructive' : 'text-success'">{{ e.q.profit === null ? '—' : `${formatMoney(Math.round(e.q.profit))} (${fmtPct(e.q.margin_pct ?? 0)})` }}</td>
                            </template>
                            <td v-else colspan="5" class="px-4 text-muted-foreground">Tổng phí phải dưới 90%.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </div>
</template>
