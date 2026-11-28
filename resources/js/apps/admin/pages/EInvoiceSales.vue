<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Pencil, Plus, ReceiptText, Search, Trash2 } from 'lucide-vue-next';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Select from '@/shared/ui/Select.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import Switch from '@/shared/ui/Switch.vue';
import { api, ApiError } from '@/shared/lib/api';
import { formatDate, formatDateTime, formatMoney } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import { orderStatusLabels, orderSteps, providerNames, type EInvoiceOrder, type EInvoiceOrderStatus, type EInvoicePackage } from '../../tenant/pages/connections/einvoice';

/**
 * Quản trị → "Bán hóa đơn": e-invoice packages shops bought. Confirm the transfer, file the registration with the
 * provider and move the steps; when the provider gives the API account, connect it for the shop here.
 */
interface Summary {
    orders_month: number;
    connected_month: number;
    revenue_month: number;
    open: number;
    stale: number;
}
interface ProviderItem {
    provider: string;
    label: string;
    fields: Record<string, string>;
}
interface Data {
    orders: EInvoiceOrder[];
    packages: EInvoicePackage[];
    requests: { provider: string; shops: number; last_at: string }[];
    summary: Summary;
    providers: ProviderItem[];
}

const toast = useToast();
const data = ref<Data | null>(null);
const error = ref<string | null>(null);
const status = ref('open');
const q = ref('');
let timer: ReturnType<typeof setTimeout>;

async function load() {
    error.value = null;
    try {
        data.value = (await api.get('/admin/einvoice', { params: { status: status.value || undefined, q: q.value || undefined } })).data.data;
        if (selected.value) selected.value = data.value?.orders.find((o) => o.id === selected.value!.id) ?? selected.value;
    } catch (e) {
        error.value = (e as ApiError).message;
    }
}
onMounted(load);
watch(status, load);
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});

const tone = (s: EInvoiceOrderStatus) => (s === 'connected' ? 'success' : s === 'cancelled' ? 'muted' : s === 'awaiting_payment' || s === 'waiting_signature' ? 'warning' : 'info');
const pName = (p: string) => providerNames[p]?.split(' ')[0] ?? p;
const month = new Date().getMonth() + 1;

// Order detail
const open = ref(false);
const selected = ref<EInvoiceOrder | null>(null);
const busy = ref<string | null>(null);
const reference = ref('');
const next = ref<EInvoiceOrderStatus>('account_created');
const note = ref('');
const signUrl = ref('');
const env = ref<'production' | 'sandbox'>('production');
const creds = ref<Record<string, string>>({});
const reason = ref('');
const formError = ref<string | null>(null);

const nextOptions = computed(() => {
    const at = orderSteps.findIndex((s) => s.status === selected.value?.status);
    return orderSteps.filter((s, i) => i > at && ['account_created', 'waiting_signature', 'tax_approved'].includes(s.status)).map((s) => ({ value: s.status, label: s.label }));
});
watch(nextOptions, (opts) => {
    if (!opts.some((o) => o.value === next.value)) next.value = (opts[0]?.value as EInvoiceOrderStatus) ?? 'account_created';
});
const fields = computed(() => data.value?.providers.find((p) => p.provider === selected.value?.provider)?.fields ?? null);

function openOrder(o: EInvoiceOrder) {
    selected.value = o;
    reference.value = '';
    note.value = o.shop_note ?? '';
    signUrl.value = o.sign_url ?? '';
    env.value = 'production';
    creds.value = { ...(o.company?.tax_code ? { tax_code: o.company.tax_code } : {}) };
    reason.value = '';
    formError.value = null;
    open.value = true;
    next.value = (nextOptions.value[0]?.value as EInvoiceOrderStatus) ?? 'account_created';
}

async function act(kind: 'payment' | 'advance' | 'connect' | 'cancel') {
    if (!selected.value) return;
    const body =
        kind === 'payment'
            ? { reference: reference.value }
            : kind === 'advance'
              ? { status: next.value, shop_note: note.value || null, sign_url: signUrl.value || null }
              : kind === 'connect'
                ? { environment: env.value, credentials: creds.value }
                : { reason: reason.value };
    busy.value = kind;
    formError.value = null;
    try {
        const res = await api.post(`/admin/einvoice/orders/${selected.value.id}/${kind}`, body);
        selected.value = res.data.data;
        toast.success({ payment: 'Đã ghi nhận tiền', advance: 'Đã chuyển bước, shop thấy ngay', connect: 'Đã kết nối hóa đơn cho shop', cancel: 'Đã hủy đơn' }[kind]);
        if (kind === 'connect' || kind === 'cancel') open.value = false;
        await load();
    } catch (e) {
        const err = e as ApiError;
        formError.value = err.field('reference') ?? err.field('status') ?? err.field('sign_url') ?? err.field('credentials') ?? err.field('reason') ?? err.field('order') ?? err.message;
    } finally {
        busy.value = null;
    }
}

// Packages
const pkgOpen = ref(false);
const pkgId = ref<string | null>(null);
const pkg = ref({ provider: 'misa', name: '', invoices: 300 as number | string, price: '' as number | string, setup_fee: 0 as number | string, signing_price: '' as number | string, note: '', active: true, featured: false, sort: 0 as number | string });
const pkgError = ref<string | null>(null);

function editPackage(p?: EInvoicePackage) {
    pkgId.value = p?.id ?? null;
    pkg.value = p
        ? { provider: p.provider, name: p.name, invoices: p.invoices, price: Number(p.price), setup_fee: Number(p.setup_fee), signing_price: p.signing_price === null ? '' : Number(p.signing_price), note: p.note ?? '', active: p.active, featured: p.featured, sort: p.sort }
        : { provider: 'misa', name: '', invoices: 300, price: '', setup_fee: 0, signing_price: '', note: 'không hết hạn', active: true, featured: false, sort: 0 };
    pkgError.value = null;
    pkgOpen.value = true;
}
async function savePackage() {
    busy.value = 'pkg';
    pkgError.value = null;
    const body = { ...pkg.value, signing_price: pkg.value.signing_price === '' ? null : pkg.value.signing_price, note: pkg.value.note || null, name: pkg.value.name || `${pkg.value.invoices} hóa đơn` };
    try {
        if (pkgId.value) await api.put(`/admin/einvoice/packages/${pkgId.value}`, body);
        else await api.post('/admin/einvoice/packages', body);
        pkgOpen.value = false;
        await load();
    } catch (e) {
        pkgError.value = (e as ApiError).message;
    } finally {
        busy.value = null;
    }
}
async function removePackage(p: EInvoicePackage) {
    if (!window.confirm(`Xóa gói ${p.name}? Gói đã có shop mua sẽ chỉ ngừng bán.`)) return;
    try {
        await api.delete(`/admin/einvoice/packages/${p.id}`);
        await load();
    } catch (e) {
        toast.error((e as ApiError).message);
    }
}
</script>

<template>
    <div class="mb-6">
        <h1 class="text-xl font-semibold">Đơn mua hóa đơn điện tử</h1>
        <p class="text-sm text-muted-foreground">Shop mua gói trong ERP. Đội mình gửi hồ sơ cho nhà cung cấp, cập nhật từng bước; khi có tài khoản, dán khóa API là shop tự kết nối.</p>
    </div>

    <ErrorState v-if="error" :message="error" @retry="load" />
    <div v-else-if="!data" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"><Skeleton v-for="i in 4" :key="i" class="h-24" /></div>
    <template v-else>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-testid="einvoice-summary">
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Đơn tháng {{ month }}</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">{{ data.summary.orders_month }}</p>
                <p class="text-xs text-muted-foreground">{{ data.summary.connected_month }} đã kết nối</p>
            </Card>
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Doanh thu bán gói tháng {{ month }}</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">{{ formatMoney(data.summary.revenue_month) }}</p>
                <p class="text-xs text-muted-foreground">đơn đã nhận tiền, giá bán cho shop</p>
            </Card>
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Đang xử lý</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">{{ data.summary.open }}</p>
                <p class="text-xs text-muted-foreground">gồm cả đơn chờ chuyển khoản</p>
            </Card>
            <Card class="p-4">
                <p class="text-xs text-muted-foreground">Đứng quá 2 ngày</p>
                <p :class="['mt-2 text-2xl font-semibold tabular-nums', data.summary.stale ? 'text-amber-600' : '']">{{ data.summary.stale }}</p>
                <p class="text-xs text-muted-foreground">đã trả tiền, chưa đổi bước</p>
            </Card>
        </div>

        <Card class="mt-6" title="Đơn mua" data-testid="einvoice-orders">
            <template #actions>
                <div class="flex gap-2">
                    <div class="relative w-56">
                        <Search class="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                        <Input v-model="q" placeholder="Mã đơn, nội dung CK, MST, shop" class="pl-8" />
                    </div>
                    <Select
                        v-model="status"
                        class="w-44"
                        :options="[{ value: 'open', label: 'Đang xử lý' }, { value: 'awaiting_payment', label: 'Chờ chuyển khoản' }, { value: 'connected', label: 'Đã kết nối' }, { value: 'cancelled', label: 'Đã hủy' }, { value: '', label: 'Tất cả' }]"
                    />
                </div>
            </template>
            <EmptyState v-if="!data.orders.length" :icon="ReceiptText" title="Chưa có đơn" description="Shop mua ở Cấu hình → Kết nối dịch vụ → Hóa đơn điện tử." />
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-sm">
                    <thead class="border-b text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Shop</th>
                            <th class="px-4 py-2.5 font-medium">Mã số thuế</th>
                            <th class="px-4 py-2.5 font-medium">Gói</th>
                            <th class="px-4 py-2.5 text-right font-medium">Tiền</th>
                            <th class="px-4 py-2.5 font-medium">Bước</th>
                            <th class="px-4 py-2.5 font-medium">Ngày</th>
                            <th class="px-4 py-2.5" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="o in data.orders" :key="o.id" class="cursor-pointer hover:bg-slate-50" @click="openOrder(o)">
                            <td class="px-4 py-2.5">
                                <p class="font-medium">{{ o.tenant?.name ?? '—' }}</p>
                                <p class="font-mono text-xs text-muted-foreground">{{ o.number }} · {{ o.transfer_code }}</p>
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs">{{ o.company?.tax_code }}</td>
                            <td class="px-4 py-2.5">{{ pName(o.provider) }} · {{ o.invoices.toLocaleString('vi-VN') }}{{ o.signing ? ' + ký số' : '' }}{{ o.company?.type === 'household' ? ' (hộ KD)' : '' }}</td>
                            <td class="px-4 py-2.5 text-right font-semibold tabular-nums">{{ formatMoney(o.amount) }}</td>
                            <td class="px-4 py-2.5"><Badge :tone="tone(o.status)">{{ orderStatusLabels[o.status] }}</Badge></td>
                            <td class="px-4 py-2.5 text-xs text-muted-foreground">{{ formatDate(o.created_at) }}</td>
                            <td class="px-4 py-2.5 text-right"><span class="text-blue-600">Mở</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <div class="mt-6 grid gap-6 lg:grid-cols-[2fr_1fr]">
            <Card title="Gói đang bán" data-testid="einvoice-packages">
                <template #actions><Button size="sm" data-testid="einvoice-package-add" @click="editPackage()"><Plus /> Thêm gói</Button></template>
                <EmptyState v-if="!data.packages.length" :icon="ReceiptText" title="Chưa có gói" description="Thêm gói theo giá trong hợp đồng đại lý. Chưa có gói thì shop không thấy nút Mua gói." />
                <table v-else class="w-full text-sm">
                    <thead class="border-b text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Gói</th>
                            <th class="px-4 py-2.5 text-right font-medium">Giá</th>
                            <th class="px-4 py-2.5 text-right font-medium">Khởi tạo</th>
                            <th class="px-4 py-2.5 text-right font-medium">Ký số từ xa</th>
                            <th class="px-4 py-2.5" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="p in data.packages" :key="p.id" :class="!p.active && 'opacity-50'">
                            <td class="px-4 py-2.5">
                                {{ pName(p.provider) }} · {{ p.name }}
                                <Badge v-if="p.featured" tone="warning" class="ml-1">nổi bật</Badge>
                                <Badge v-if="!p.active" tone="muted" class="ml-1">ngừng bán</Badge>
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ formatMoney(p.price) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ formatMoney(p.setup_fee) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ p.signing_price === null ? '—' : formatMoney(p.signing_price) }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <Button size="icon" variant="ghost" aria-label="Sửa" @click="editPackage(p)"><Pencil /></Button>
                                <Button size="icon" variant="ghost" aria-label="Xóa" @click="removePackage(p)"><Trash2 /></Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </Card>
            <Card title="Shop xin nhà cung cấp khác">
                <EmptyState v-if="!data.requests.length" :icon="ReceiptText" title="Chưa có yêu cầu" />
                <ul v-else class="divide-y text-sm">
                    <li v-for="r in data.requests" :key="r.provider" class="flex items-center justify-between px-4 py-2.5">
                        <span>{{ r.provider }}</span>
                        <span class="text-muted-foreground">{{ r.shops }} shop</span>
                    </li>
                </ul>
            </Card>
        </div>
    </template>

    <Dialog v-model:open="open" :title="selected ? `${selected.number} · ${selected.tenant?.name ?? ''}` : ''" :description="selected ? `${providerNames[selected.provider] ?? selected.provider} · gói ${selected.invoices.toLocaleString('vi-VN')} hóa đơn · ${formatMoney(selected.amount)}` : ''" size="lg">
        <div v-if="selected" class="space-y-5" data-testid="einvoice-order-detail">
            <div class="flex flex-wrap items-center gap-2">
                <Badge :tone="tone(selected.status)">{{ orderStatusLabels[selected.status] }}</Badge>
                <span class="text-xs text-muted-foreground">Nội dung CK <b class="font-mono">{{ selected.transfer_code }}</b><template v-if="selected.payment_ref"> · mã GD {{ selected.payment_ref }}</template></span>
            </div>
            <dl class="grid gap-x-6 gap-y-2 rounded-lg bg-slate-50 p-3 text-sm sm:grid-cols-2">
                <div><dt class="text-muted-foreground">Đơn vị</dt><dd class="font-medium">{{ selected.company.name }}</dd></div>
                <div><dt class="text-muted-foreground">Mã số thuế</dt><dd><span class="font-mono">{{ selected.company.tax_code }}</span> · {{ selected.company.type === 'household' ? 'Hộ kinh doanh' : 'Công ty' }}</dd></div>
                <div><dt class="text-muted-foreground">Người đại diện</dt><dd>{{ selected.company.representative }}</dd></div>
                <div><dt class="text-muted-foreground">Liên hệ</dt><dd>{{ selected.company.email }} · {{ selected.company.phone }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-muted-foreground">Địa chỉ</dt><dd>{{ selected.company.address || '—' }}</dd></div>
                <div class="sm:col-span-2">
                    <dt class="text-muted-foreground">Các bước</dt>
                    <dd class="text-xs">
                        <span v-for="(at, st) in selected.steps" :key="st" class="mr-3">{{ orderStatusLabels[st as EInvoiceOrderStatus] }} {{ formatDateTime(at) }}</span>
                    </dd>
                </div>
            </dl>

            <form v-if="selected.status === 'awaiting_payment'" class="flex items-end gap-2" @submit.prevent="act('payment')">
                <FormField label="Đã nhận tiền? Mã giao dịch ngân hàng" for="ei-ref" class="flex-1" :hint="`Số tiền đúng ${formatMoney(selected.amount)}, nội dung ${selected.transfer_code}.`">
                    <Input id="ei-ref" v-model="reference" required data-testid="einvoice-admin-reference" />
                </FormField>
                <Button type="submit" :loading="busy === 'payment'" class="mb-5" data-testid="einvoice-admin-pay">Ghi nhận tiền</Button>
            </form>

            <template v-else-if="selected.status !== 'connected' && selected.status !== 'cancelled'">
                <form v-if="nextOptions.length" class="space-y-3 rounded-lg border p-4" @submit.prevent="act('advance')">
                    <p class="text-sm font-medium">Chuyển bước cho shop thấy</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <FormField label="Bước tiếp theo" for="ei-next">
                            <Select id="ei-next" v-model="next" :options="nextOptions" data-testid="einvoice-admin-next" />
                        </FormField>
                        <FormField label="Link ký tờ khai (nếu có)" for="ei-sign" optional>
                            <Input id="ei-sign" v-model="signUrl" placeholder="https://..." />
                        </FormField>
                    </div>
                    <FormField label="Lời nhắn cho shop" for="ei-note" optional>
                        <Input id="ei-note" v-model="note" placeholder="vd. Bấm Ký tờ khai, nhận mã trên điện thoại để ký." />
                    </FormField>
                    <div class="flex justify-end"><Button type="submit" variant="outline" :loading="busy === 'advance'" data-testid="einvoice-admin-advance">Chuyển bước</Button></div>
                </form>

                <form v-if="fields" class="space-y-3 rounded-lg border border-blue-200 bg-blue-50/40 p-4" @submit.prevent="act('connect')">
                    <p class="text-sm font-medium">Có tài khoản API rồi: kết nối cho shop</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label v-for="(label, field) in fields" :key="field" class="block space-y-1 text-sm">
                            <span class="text-muted-foreground">{{ label }}</span>
                            <Input v-model="creds[field]" :type="String(field).includes('password') || String(field).includes('secret') ? 'password' : 'text'" autocomplete="off" />
                        </label>
                    </div>
                    <Switch :model-value="env === 'sandbox'" label="Môi trường thử nghiệm" @update:model-value="(v: boolean) => (env = v ? 'sandbox' : 'production')" />
                    <div class="flex justify-end"><Button type="submit" :loading="busy === 'connect'" data-testid="einvoice-admin-connect">Kiểm tra và kết nối</Button></div>
                </form>
                <p v-else class="text-sm text-muted-foreground">Nhà cung cấp này chưa bật trên hệ thống (MISA cần EINVOICE_MISA_APP_ID), nên chưa kết nối hộ được.</p>
            </template>

            <p v-if="formError" class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ formError }}</p>

            <form v-if="selected && selected.status !== 'connected' && selected.status !== 'cancelled'" class="flex items-end gap-2 border-t pt-4" @submit.prevent="act('cancel')">
                <FormField label="Hủy đơn (lý do hiện cho shop)" for="ei-reason" class="flex-1">
                    <Input id="ei-reason" v-model="reason" placeholder="vd. Đã hoàn tiền theo yêu cầu" />
                </FormField>
                <Button type="submit" variant="ghost" class="mb-5 text-rose-600" :disabled="!reason.trim()" :loading="busy === 'cancel'">Hủy đơn</Button>
            </form>
        </div>
    </Dialog>

    <Dialog v-model:open="pkgOpen" :title="pkgId ? 'Sửa gói' : 'Thêm gói hóa đơn'" description="Giá bán cho shop, đã gồm VAT. Đổi giá không ảnh hưởng đơn đã đặt.">
        <form class="space-y-3" data-testid="einvoice-package-form" @submit.prevent="savePackage">
            <div class="grid gap-3 sm:grid-cols-2">
                <FormField label="Nhà cung cấp" for="pk-provider">
                    <Select id="pk-provider" v-model="pkg.provider" :options="Object.entries(providerNames).map(([value, label]) => ({ value, label }))" />
                </FormField>
                <FormField label="Số hóa đơn" for="pk-inv"><Input id="pk-inv" v-model="pkg.invoices" type="number" min="1" required /></FormField>
                <FormField label="Giá gói (đ)" for="pk-price"><Input id="pk-price" v-model="pkg.price" type="number" min="0" required data-testid="einvoice-package-price" /></FormField>
                <FormField label="Phí khởi tạo (đ)" for="pk-setup"><Input id="pk-setup" v-model="pkg.setup_fee" type="number" min="0" /></FormField>
                <FormField label="Ký số từ xa 1 năm (đ)" for="pk-sign" hint="Để trống nếu không bán kèm."><Input id="pk-sign" v-model="pkg.signing_price" type="number" min="0" /></FormField>
                <FormField label="Tên gói" for="pk-name" optional><Input id="pk-name" v-model="pkg.name" :placeholder="`${pkg.invoices} hóa đơn`" /></FormField>
                <FormField label="Ghi chú cho shop" for="pk-note" optional><Input id="pk-note" v-model="pkg.note" /></FormField>
                <FormField label="Thứ tự" for="pk-sort"><Input id="pk-sort" v-model="pkg.sort" type="number" min="0" /></FormField>
            </div>
            <Switch v-model="pkg.active" label="Đang bán" />
            <Switch v-model="pkg.featured" label="Nổi bật (Shop nhỏ hay chọn)" />
            <p v-if="pkgError" class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ pkgError }}</p>
        </form>
        <template #footer>
            <Button variant="ghost" @click="pkgOpen = false">Đóng</Button>
            <Button :loading="busy === 'pkg'" data-testid="einvoice-package-save" @click="savePackage">Lưu</Button>
        </template>
    </Dialog>
</template>
