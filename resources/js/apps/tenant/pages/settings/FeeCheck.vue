<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { AlertTriangle, ChevronLeft, CircleDashed, Download, FileSpreadsheet, TrendingDown, Upload, XCircle } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError, downloadFile } from '@/shared/lib/api';
import { formatDateTime, formatMoney, formatNumber } from '@/shared/lib/utils';
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
        toast.success(`Đã nhập ${formatNumber(data.data.imported)} đơn đối soát (${formatNumber(data.data.linked)} đơn khớp với ECSO).`);
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
    <RouterLink to="/settings/pricing" class="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ChevronLeft class="size-4" /> Thuế và chính sách giá</RouterLink>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Kiểm tra phí sàn</h1>
            <p class="text-sm text-muted-foreground">Phí thực tế sàn đã thu, tính từ dữ liệu đối soát 30 ngày gần nhất (bỏ đơn hủy, hoàn). Tự kiểm tra mỗi sáng lúc 06:00.</p>
        </div>
        <Button v-if="canEdit && shops.length" data-testid="open-import" @click="openImport()"><Upload /> Nhập file đối soát</Button>
    </div>

    <div v-if="loading && !shops.length" class="space-y-3"><Skeleton v-for="i in 3" :key="i" class="h-20" /></div>
    <template v-else>
        <Card class="mb-4">
            <div class="border-b px-4 py-3">
                <h2 class="font-semibold">Cảnh báo</h2>
                <p class="text-xs text-muted-foreground">Cảnh báo mới được gửi email cho người quản lý cài đặt.</p>
            </div>
            <div class="divide-y">
                <div v-for="a in alerts" :key="a.id" class="flex flex-wrap items-start gap-3 px-4 py-3" :data-testid="`alert-${a.type}`">
                    <AlertTriangle v-if="a.type === 'fee_drift'" class="mt-0.5 size-5 shrink-0 text-amber-500" />
                    <TrendingDown v-else class="mt-0.5 size-5 shrink-0 text-rose-500" />
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium">{{ a.text }}</div>
                        <div class="text-xs text-muted-foreground">{{ formatDateTime(a.created_at) }}</div>
                        <table v-if="a.type === 'loss_orders' && expanded === a.id" class="mt-2 w-full max-w-2xl text-sm">
                            <thead class="text-left text-xs text-muted-foreground">
                                <tr><th class="py-1 font-medium">Mã đơn sàn</th><th class="font-medium">SKU</th><th class="text-right font-medium">Thực nhận</th><th class="text-right font-medium">Giá vốn</th><th class="text-right font-medium">Lỗ</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="o in lossOrders(a)" :key="o.external_order_id" class="border-t">
                                    <td class="py-1 font-mono text-xs">{{ o.external_order_id }}</td>
                                    <td class="text-xs">{{ o.skus.join(', ') }}</td>
                                    <td class="text-right tabular-nums">{{ formatMoney(o.net) }}</td>
                                    <td class="text-right tabular-nums">{{ formatMoney(o.cost) }}</td>
                                    <td class="text-right tabular-nums text-rose-600">{{ formatMoney(o.loss) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex gap-2">
                        <template v-if="a.type === 'fee_drift' && canEdit">
                            <Button size="sm" :loading="busy === `apply-${a.id}`" data-testid="alert-apply" @click="act(a, 'apply')">Dùng số thực tế và tính lại giá</Button>
                        </template>
                        <Button v-if="a.type === 'loss_orders'" size="sm" variant="outline" @click="expanded = expanded === a.id ? null : a.id">{{ expanded === a.id ? 'Ẩn đơn' : 'Xem đơn' }}</Button>
                        <Button v-if="canEdit" size="sm" variant="ghost" :loading="busy === `dismiss-${a.id}`" @click="act(a, 'dismiss')">Bỏ qua</Button>
                    </div>
                </div>
                <div v-for="s in noData" :key="`nodata-${s.account.id}`" class="flex flex-wrap items-center gap-3 px-4 py-3">
                    <CircleDashed class="size-5 shrink-0 text-muted-foreground" />
                    <div class="min-w-0 flex-1 text-sm">
                        <span class="font-medium">{{ s.account.shop_name }} chưa có dữ liệu đối soát. </span>
                        <span class="text-muted-foreground">{{ s.table ? `Bảng phí đang dùng số nhập tay (${fmtPct(s.table.total_pct)}).` : 'Chưa cài bảng phí.' }}</span>
                    </div>
                    <Button v-if="canEdit" size="sm" variant="outline" @click="openImport(s.account.id)">Nhập file</Button>
                </div>
                <div v-if="!alerts.length && !noData.length" class="px-4 py-6 text-center text-sm text-muted-foreground">Không có cảnh báo. Phí thực tế đang khớp bảng phí.</div>
            </div>
        </Card>

        <Card class="mb-4">
            <div class="border-b px-4 py-3">
                <h2 class="font-semibold">Phí thực tế theo gian hàng</h2>
                <p class="text-xs text-muted-foreground">% tính trên giá trị hàng. Phí vận chuyển sàn trừ tách riêng, không đưa vào giá.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/40 text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Gian hàng</th>
                            <th class="px-3 py-2 text-right font-medium">Đơn đối soát</th>
                            <th class="px-3 py-2 text-right font-medium">Doanh thu</th>
                            <th class="px-3 py-2 text-right font-medium">Hoa hồng</th>
                            <th class="px-3 py-2 text-right font-medium">Thanh toán</th>
                            <th class="px-3 py-2 text-right font-medium">Dịch vụ</th>
                            <th class="px-3 py-2 text-right font-medium">Phí cố định</th>
                            <th class="px-3 py-2 text-right font-medium">Thực nhận</th>
                            <th class="px-3 py-2 text-right font-medium">Phí thực tế</th>
                            <th class="px-4 py-2 text-right font-medium">Đang cài</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="s in shops" :key="s.account.id">
                            <tr class="border-t">
                                <td class="px-4 py-2"><span class="flex items-center gap-2 font-medium"><MarketplaceLogo :marketplace="s.account.marketplace" size="sm" /> {{ s.account.shop_name }}</span></td>
                                <template v-if="s.actual">
                                    <td class="px-3 py-2 text-right tabular-nums">{{ formatNumber(s.actual.orders) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ formatMoney(s.actual.order_amount) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ fmtPct(s.actual.commission_pct) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ fmtPct(s.actual.payment_pct) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ fmtPct(s.actual.service_pct) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ formatMoney(s.actual.fixed_fee) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ formatMoney(s.actual.net_amount) }}</td>
                                    <td class="px-3 py-2 text-right font-semibold tabular-nums" :class="Math.abs(drift(s)) >= 0.5 ? 'text-amber-600' : ''">{{ fmtPct(s.actual.total_pct) }}</td>
                                </template>
                                <td v-else colspan="8" class="px-3 py-2 text-muted-foreground">Chưa nhập file đối soát.</td>
                                <td class="px-4 py-2 text-right tabular-nums text-muted-foreground">{{ s.table ? fmtPct(s.table.total_pct) : 'chưa cài' }}</td>
                            </tr>
                            <tr v-for="c in s.categories" :key="`${s.account.id}-${c.category_name}`" class="border-t border-dashed text-muted-foreground">
                                <td class="py-1.5 pl-11 pr-4">↳ {{ c.category_name }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums">{{ formatNumber(c.orders) }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums">{{ formatMoney(c.order_amount) }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums">{{ fmtPct(c.commission_pct) }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums">{{ fmtPct(c.payment_pct) }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums">{{ fmtPct(c.service_pct) }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums">{{ formatMoney(c.fixed_fee) }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums">{{ formatMoney(c.net_amount) }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums">{{ fmtPct(c.total_pct) }}</td>
                                <td class="px-4 py-1.5"></td>
                            </tr>
                        </template>
                        <tr v-if="!shops.length"><td colspan="10" class="px-4 py-6 text-center text-muted-foreground">Chưa có gian hàng sàn nào đang kết nối.</td></tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <Card>
            <div class="border-b px-4 py-3">
                <h2 class="font-semibold">Lấy file đối soát ở đâu</h2>
                <p class="text-xs text-muted-foreground">Khi sàn duyệt API, ECSO sẽ tự lấy dữ liệu này mỗi ngày, không cần nhập file. Nếu file của sàn không đọc được, hãy dùng file mẫu của ECSO.</p>
            </div>
            <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="(g, key) in guides" :key="key" class="rounded-md border p-3 text-sm">
                    <div class="mb-1 flex items-center gap-2 font-medium"><MarketplaceLogo :marketplace="key" size="sm" /> {{ g.name }}</div>
                    <p class="text-muted-foreground">{{ g.path }}</p>
                    <p v-for="s in shops.filter((x) => x.account.marketplace === key)" :key="s.account.id" class="mt-1 text-xs text-muted-foreground">
                        {{ s.account.shop_name }}: {{ s.last_import_at ? `nhập lần cuối ${formatDateTime(s.last_import_at)} · ${formatNumber(s.settlements)} đơn` : 'chưa nhập' }}
                    </p>
                </div>
            </div>
        </Card>
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
</template>
