<script setup lang="ts">
import SettingsShell from './SettingsShell.vue';
import { computed, onMounted, ref } from 'vue';
import { AlertTriangle, Check, CircleDashed, Download, FileSpreadsheet, Info, TrendingDown, Upload, XCircle } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { cn, formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { useTenantStore } from '@/shared/stores/tenant';
import type { FeeAlertData, FeeStats } from '@/shared/types';
import { fmtPct } from '../marketplaces/pricing';

/** Kiểm tra phí sàn: alerts from the daily check, the actual fees per shop and category, and the settlement import. */
interface ShopFees {
    account: { id: string; shop_name: string; marketplace: string };
    actual: FeeStats | null;
    table: { total_pct: number } | null;
    categories: FeeStats[];
    last_import_at: string | null;
    settlements: number;
}
interface LossOrder {
    external_order_id: string;
    net: number;
    cost: number;
    loss: number;
    skus: string[];
}

const toast = useToast();
const tenant = useTenantStore();
const canEdit = computed(() => tenant.can('marketplace.connect'));
const shops = ref<ShopFees[]>([]);
const alerts = ref<FeeAlertData[]>([]);
const loading = ref(true);
const busy = ref<string | null>(null);
const expanded = ref<string | null>(null);

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/marketplace-pricing/fees');
        // A shop with neither a fee table nor settlement data has nothing to check.
        shops.value = data.data.filter((s: ShopFees) => s.table || s.settlements);
        alerts.value = data.alerts;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

const noData = computed(() => shops.value.filter((s) => !s.settlements));
const drift = (s: ShopFees) => (s.actual && s.table ? Math.round((s.actual.total_pct - s.table.total_pct) * 100) / 100 : 0);
const lossOrders = (a: FeeAlertData) => (a.data.orders as LossOrder[] | undefined) ?? [];

async function act(alert: FeeAlertData, action: 'apply' | 'dismiss') {
    busy.value = `${action}-${alert.id}`;
    try {
        await api.post(`/marketplace-pricing/alerts/${alert.id}/${action}`);
        toast.success(action === 'apply' ? 'Đã cập nhật bảng phí theo số thực tế. Xem giá mới ở Bảng giá sàn.' : 'Đã bỏ qua cảnh báo.');
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = null;
    }
}

// Import dialog
const importOpen = ref(false);
const importAccount = ref('');
const file = ref<File | null>(null);
const importError = ref<{ message: string; columns: string[] } | null>(null);
function openImport(accountId?: string) {
    importAccount.value = accountId ?? shops.value[0]?.account.id ?? '';
    file.value = null;
    importError.value = null;
    importOpen.value = true;
}
function onFile(e: Event) {
    file.value = (e.target as HTMLInputElement).files?.[0] ?? null;
    importError.value = null;
}
async function sendImport() {
    if (!file.value || !importAccount.value) return;
    busy.value = 'import';
    const body = new FormData();
    body.append('account', importAccount.value);
    body.append('file', file.value);
    try {
        const { data } = await api.post('/marketplace-pricing/settlements/import', body);
        toast.success(`Đã nhập ${formatNumber(data.data.imported)} đơn đối soát (${formatNumber(data.data.linked)} đơn khớp với EzChat Bán hàng).`);
        importOpen.value = false;
        await load();
    } catch (e) {
        const err = e as ApiError;
        importError.value = { message: err.message, columns: ((err.body as { columns?: string[] } | undefined)?.columns) ?? [] };
    } finally {
        busy.value = null;
    }
}

const guides: Record<string, { name: string; path: string }> = {
    shopee: { name: 'Shopee', path: 'Kênh Người Bán → Tài chính → Doanh thu → Đã thanh toán → Xuất báo cáo (.xlsx).' },
    tiktok: { name: 'TikTok Shop', path: 'Seller Center → Tài chính → Quyết toán → Tải xuống (.xlsx).' },
    lazada: { name: 'Lazada', path: 'Seller Center → Tài chính → Sao kê tài khoản → Xuất (.xlsx).' },
    tiki: { name: 'Tiki', path: 'Seller Center → Tài chính → Đối soát → Xuất file (.xlsx).' },
};
</script>

<template>
    <SettingsShell title="Kiểm tra phí sàn" description="So phí sàn thực thu (đối soát 30 ngày gần nhất, bỏ đơn hủy, hoàn) với bảng phí đang cài. Tự kiểm tra mỗi sáng lúc 06:00." :back="{ to: '/settings/pricing', label: 'Thuế và chính sách giá' }">
        <template #actions>
            <button type="button" class="inline-flex h-11 items-center gap-2 rounded-[12px] border border-slate-200 bg-white px-4 text-[15px] text-slate-700 hover:border-blue-300 hover:text-blue-700" @click="downloadFile('/marketplace-pricing/settlements/template', 'mau-doi-soat-san.xlsx')">
                <Download class="size-4 text-slate-500" /> Tải file mẫu
            </button>
            <button v-if="canEdit && shops.length" type="button" class="inline-flex h-11 items-center gap-2 rounded-[12px] bg-blue-600 px-5 text-[15px] font-medium text-white shadow-sm hover:bg-blue-700" data-testid="open-import" @click="openImport()">
                <Upload class="size-4" /> Nhập file đối soát
            </button>
        </template>

        <div v-if="loading && !shops.length" class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3"><Skeleton v-for="i in 3" :key="i" class="h-44 rounded-[14px]" /></div>
        <template v-else>
            <p v-if="!shops.length" class="rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)] px-6 py-8 text-center text-[14px] text-slate-500">Chưa có gian hàng sàn nào đang kết nối.</p>
            <div v-else class="mb-5 grid gap-4 md:grid-cols-2 2xl:grid-cols-3" data-testid="fee-shops">
                <div v-for="s in shops" :key="s.account.id" class="rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)] flex flex-col p-5">
                    <div class="flex items-center gap-3">
                        <MarketplaceLogo :marketplace="s.account.marketplace" size="lg" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold text-slate-900">{{ s.account.shop_name }}</p>
                            <p class="text-[13px] text-slate-500">{{ s.actual ? `${formatNumber(s.actual.orders)} đơn đối soát · 30 ngày` : 'Chưa có dữ liệu đối soát' }}</p>
                        </div>
                        <span v-if="s.actual && s.table && Math.abs(drift(s)) >= 0.5" class="inline-flex shrink-0 items-center gap-1 rounded-md bg-amber-50 px-2 py-0.5 text-[12px] font-medium text-amber-700">
                            <AlertTriangle class="size-3" /> {{ drift(s) > 0 ? 'Cao' : 'Thấp' }} hơn bảng {{ fmtPct(Math.abs(drift(s))) }}
                        </span>
                        <span v-else-if="s.actual && s.table" class="inline-flex shrink-0 items-center gap-1 rounded-md bg-emerald-50 px-2 py-0.5 text-[12px] font-medium text-emerald-700"><Check class="size-3" /> Khớp bảng phí</span>
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-3">
                        <div class="rounded-[12px] bg-slate-50 p-3.5">
                            <p class="text-[12px] text-slate-500">Bảng phí đang cài</p>
                            <p class="text-[20px] font-bold tabular-nums text-slate-900">{{ s.table ? fmtPct(s.table.total_pct) : 'Chưa cài' }}</p>
                        </div>
                        <div :class="cn('rounded-[12px] p-3.5', !s.actual ? 'bg-slate-50' : Math.abs(drift(s)) >= 0.5 ? 'bg-amber-50' : 'bg-emerald-50')">
                            <p class="text-[12px] text-slate-500">Phí thực tế</p>
                            <p :class="cn('text-[20px] font-bold tabular-nums', !s.actual ? 'text-slate-400' : Math.abs(drift(s)) >= 0.5 ? 'text-amber-600' : 'text-emerald-600')">{{ s.actual ? fmtPct(s.actual.total_pct) : '—' }}</p>
                        </div>
                    </div>
                    <p class="mt-3 text-[13px] text-slate-500">{{ s.last_import_at ? `Nhập lần cuối ${formatDateTime(s.last_import_at)}` : s.table ? 'Bảng phí đang dùng số nhập tay.' : 'Chưa cài bảng phí.' }}</p>
                    <button v-if="canEdit && !s.settlements" type="button" class="mt-3 rounded-[10px] border border-slate-200 py-2.5 text-[14px] font-medium text-slate-700 hover:border-blue-300 hover:text-blue-700" @click="openImport(s.account.id)">Nhập file đối soát</button>
                </div>
            </div>

            <section class="rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)] mb-5 overflow-hidden">
                <header class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-6 py-4">
                    <span class="grid size-9 place-items-center rounded-[10px] bg-amber-50 text-amber-600"><AlertTriangle class="size-[18px]" /></span>
                    <h2 class="text-[16px] font-semibold text-slate-900">Cảnh báo</h2>
                    <span v-if="alerts.length" class="rounded-md bg-amber-50 px-2 py-0.5 text-[12px] font-medium text-amber-700">{{ alerts.length }}</span>
                    <span class="ml-auto text-[13px] text-slate-500">Cảnh báo mới được gửi email cho người quản lý cài đặt.</span>
                </header>
                <div class="divide-y divide-slate-100">
                    <div v-for="a in alerts" :key="a.id" class="flex flex-wrap items-start gap-3 px-6 py-4" :data-testid="`alert-${a.type}`">
                        <span :class="cn('grid size-9 shrink-0 place-items-center rounded-[10px]', a.type === 'fee_drift' ? 'bg-amber-50 text-amber-600' : 'bg-red-50 text-red-500')">
                            <AlertTriangle v-if="a.type === 'fee_drift'" class="size-[18px]" />
                            <TrendingDown v-else class="size-[18px]" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[14px] font-medium text-slate-900">{{ a.text }}</p>
                            <p class="text-[13px] text-slate-500">{{ formatDateTime(a.created_at) }}</p>
                            <table v-if="a.type === 'loss_orders' && expanded === a.id" class="mt-3 w-full max-w-3xl text-[14px]">
                                <thead class="text-left text-[12px] uppercase tracking-wider text-slate-400">
                                    <tr>
                                        <th class="py-2 font-semibold">Mã đơn sàn</th>
                                        <th class="font-semibold">SKU</th>
                                        <th class="text-right font-semibold">Thực nhận</th>
                                        <th class="text-right font-semibold">Giá vốn</th>
                                        <th class="text-right font-semibold">Lỗ</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 text-slate-700">
                                    <tr v-for="o in lossOrders(a)" :key="o.external_order_id">
                                        <td class="py-2 font-mono text-[13px] text-blue-700">{{ o.external_order_id }}</td>
                                        <td class="text-[13px]">{{ o.skus.join(', ') }}</td>
                                        <td class="text-right tabular-nums">{{ formatMoney(o.net) }}</td>
                                        <td class="text-right tabular-nums">{{ formatMoney(o.cost) }}</td>
                                        <td class="text-right font-semibold tabular-nums text-red-600">{{ formatMoney(o.loss) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-if="a.type === 'fee_drift' && canEdit"
                                type="button"
                                class="rounded-[10px] bg-blue-600 px-4 py-2 text-[14px] font-medium text-white hover:bg-blue-700 disabled:opacity-60"
                                :disabled="busy === `apply-${a.id}`"
                                data-testid="alert-apply"
                                @click="act(a, 'apply')"
                            >
                                Dùng số thực tế và tính lại giá
                            </button>
                            <button v-if="a.type === 'loss_orders'" type="button" class="rounded-[10px] border border-slate-200 px-4 py-2 text-[14px] text-slate-700 hover:border-blue-300" @click="expanded = expanded === a.id ? null : a.id">
                                {{ expanded === a.id ? 'Ẩn đơn' : 'Xem đơn' }}
                            </button>
                            <button v-if="canEdit" type="button" class="rounded-[10px] px-3 py-2 text-[14px] text-slate-500 hover:bg-slate-100 disabled:opacity-60" :disabled="busy === `dismiss-${a.id}`" @click="act(a, 'dismiss')">Bỏ qua</button>
                        </div>
                    </div>
                    <div v-for="s in noData" :key="`nodata-${s.account.id}`" class="flex flex-wrap items-center gap-3 px-6 py-4">
                        <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-slate-100 text-slate-500"><CircleDashed class="size-[18px]" /></span>
                        <p class="min-w-0 flex-1 text-[14px]">
                            <span class="font-medium text-slate-900">{{ s.account.shop_name }} chưa có dữ liệu đối soát. </span>
                            <span class="text-slate-500">{{ s.table ? `Bảng phí đang dùng số nhập tay (${fmtPct(s.table.total_pct)}).` : 'Chưa cài bảng phí.' }}</span>
                        </p>
                    </div>
                    <p v-if="!alerts.length && !noData.length" class="flex items-center justify-center gap-2 px-6 py-6 text-[14px] text-slate-500"><Check class="size-4 text-emerald-500" /> Không có cảnh báo. Phí thực tế đang khớp bảng phí.</p>
                </div>
            </section>

            <section v-if="shops.some((x) => x.actual)" class="rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)] mb-5 overflow-hidden">
                <header class="border-b border-slate-100 px-6 py-4">
                    <h2 class="text-[16px] font-semibold text-slate-900">Phí thực tế theo gian hàng</h2>
                    <p class="text-[13px] text-slate-500">% tính trên giá trị hàng. Phí vận chuyển sàn trừ tách riêng, không đưa vào giá.</p>
                </header>
                <div class="overflow-x-auto">
                    <table class="w-full text-[14px]">
                        <thead class="border-b border-slate-100 text-left text-[12px] uppercase tracking-wider text-slate-400">
                            <tr>
                                <th class="px-3 py-3.5 pl-6 font-semibold">Gian hàng</th>
                                <th class="px-3 py-3.5 text-right font-semibold">Đơn</th>
                                <th class="px-3 py-3.5 text-right font-semibold">Doanh thu</th>
                                <th class="px-3 py-3.5 text-right font-semibold">Hoa hồng</th>
                                <th class="px-3 py-3.5 text-right font-semibold">Thanh toán</th>
                                <th class="px-3 py-3.5 text-right font-semibold">Dịch vụ</th>
                                <th class="px-3 py-3.5 text-right font-semibold">Phí cố định</th>
                                <th class="px-3 py-3.5 text-right font-semibold">Thực nhận</th>
                                <th class="px-3 py-3.5 text-right font-semibold">Phí thực tế</th>
                                <th class="px-3 py-3.5 pr-6 text-right font-semibold">Đang cài</th>
                            </tr>
                        </thead>
                        <tbody class="text-slate-700">
                            <template v-for="s in shops.filter((x) => x.actual)" :key="s.account.id">
                                <tr class="border-t border-slate-100">
                                    <td class="px-3 py-3 pl-6"><span class="flex items-center gap-2.5 font-medium text-slate-900"><MarketplaceLogo :marketplace="s.account.marketplace" size="sm" /> {{ s.account.shop_name }}</span></td>
                                    <td class="px-3 py-3 text-right tabular-nums">{{ formatNumber(s.actual!.orders) }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">{{ formatMoney(s.actual!.order_amount) }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums">{{ fmtPct(s.actual!.commission_pct) }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums">{{ fmtPct(s.actual!.payment_pct) }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums">{{ fmtPct(s.actual!.service_pct) }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">{{ formatMoney(s.actual!.fixed_fee) }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">{{ formatMoney(s.actual!.net_amount) }}</td>
                                    <td :class="cn('px-3 py-3 text-right font-semibold tabular-nums', Math.abs(drift(s)) >= 0.5 ? 'text-amber-600' : 'text-slate-900')">{{ fmtPct(s.actual!.total_pct) }}</td>
                                    <td class="px-3 py-3 pr-6 text-right tabular-nums text-slate-500">{{ s.table ? fmtPct(s.table.total_pct) : 'chưa cài' }}</td>
                                </tr>
                                <tr v-for="c in s.categories" :key="`${s.account.id}-${c.category_name}`" class="text-[13px] text-slate-500">
                                    <td class="py-2 pl-14 pr-3">↳ {{ c.category_name }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ formatNumber(c.orders) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ formatMoney(c.order_amount) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ fmtPct(c.commission_pct) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ fmtPct(c.payment_pct) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ fmtPct(c.service_pct) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ formatMoney(c.fixed_fee) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ formatMoney(c.net_amount) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ fmtPct(c.total_pct) }}</td>
                                    <td class="px-3 py-2 pr-6"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-[14px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)] p-6">
                <h2 class="text-[16px] font-semibold text-slate-900">Lấy file đối soát ở đâu</h2>
                <div class="mt-4 grid gap-3 md:grid-cols-2 2xl:grid-cols-4">
                    <div v-for="(g, key) in guides" :key="key" class="rounded-[12px] bg-slate-50 p-4 text-[14px]">
                        <p class="mb-1.5 flex items-center gap-2 font-semibold text-slate-900"><MarketplaceLogo :marketplace="key" size="sm" /> {{ g.name }}</p>
                        <p class="text-slate-600">{{ g.path }}</p>
                        <p v-for="s in shops.filter((x) => x.account.marketplace === key)" :key="s.account.id" class="mt-1.5 text-[13px] text-slate-500">
                            {{ s.account.shop_name }}: {{ s.last_import_at ? `nhập lần cuối ${formatDateTime(s.last_import_at)} · ${formatNumber(s.settlements)} đơn` : 'chưa nhập' }}
                        </p>
                    </div>
                </div>
                <p class="mt-4 flex items-start gap-2 rounded-[10px] bg-blue-50 p-3 text-[13px] text-blue-800">
                    <Info class="mt-0.5 size-4 shrink-0" /> Khi sàn duyệt API, EzChat Bán hàng tự lấy dữ liệu này mỗi ngày, không cần nhập file. Nếu file của sàn không đọc được, hãy chép số vào file mẫu.
                </p>
            </section>
        </template>

        <Dialog v-model:open="importOpen" size="lg" title="Nhập file đối soát" description="File .xlsx hoặc .csv xuất từ Seller Center. Nhập lại cùng đơn sẽ ghi đè số cũ.">
            <div class="space-y-4">
                <label class="block text-sm">
                    <span class="mb-1 block font-medium">Gian hàng</span>
                    <Select v-model="importAccount" :options="shops.map((s) => ({ value: s.account.id, label: s.account.shop_name }))" data-testid="import-account" />
                </label>
                <label class="flex cursor-pointer items-center gap-3 rounded-md border bg-muted/40 p-4 hover:bg-muted">
                    <FileSpreadsheet class="size-6 text-primary" />
                    <span class="flex-1 text-sm">{{ file ? file.name : 'Chọn file .xlsx hoặc .csv' }}</span>
                    <input type="file" accept=".csv,.xlsx" class="hidden" data-testid="settlement-file" @change="onFile" />
                </label>
                <div v-if="importError" class="rounded-md border border-rose-200 bg-rose-50/70 p-3 text-sm text-rose-900">
                    <div class="flex items-start gap-2"><XCircle class="mt-0.5 size-4 shrink-0 text-rose-500" /> {{ importError.message }}</div>
                    <p v-if="importError.columns.length" class="mt-2 text-xs">Các cột tìm thấy: {{ importError.columns.join(', ') }}</p>
                </div>
                <p class="text-xs text-muted-foreground">
                    Cần cột mã đơn hàng, giá trị hàng và ít nhất một cột phí. Tên cột của từng sàn chưa được đối chiếu với file thật, nếu file bị từ chối hãy chép số vào file mẫu.
                    <button type="button" class="inline-flex items-center gap-1 text-primary hover:underline" @click="downloadFile('/marketplace-pricing/settlements/template', 'mau-doi-soat-san.xlsx')"><Download class="size-3.5" /> Tải file mẫu</button>
                </p>
            </div>
            <template #footer>
                <Button variant="outline" @click="importOpen = false">Hủy</Button>
                <Button :disabled="!file || !importAccount" :loading="busy === 'import'" data-testid="import-submit" @click="sendImport">Nhập file</Button>
            </template>
        </Dialog>
    </SettingsShell>
</template>
