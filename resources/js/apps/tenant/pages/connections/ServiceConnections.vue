<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import SettingsShell from '../settings/SettingsShell.vue';
import { FileText, MessageCircle, ReceiptText, Send, ShoppingBag, Truck } from 'lucide-vue-next';
import SettingsSection from '../settings/SettingsSection.vue';
import { carrierLogo, einvoiceLogos, logoUrl } from '../settings/connectionsSummary';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import CarrierConnectDialog from '../shipments/CarrierConnectDialog.vue';
import { loadCarrierAccounts, type CarrierAccountData, type CarrierCatalogItem } from '../shipments/carriers';
import EInvoiceConnectDialog from './EInvoiceConnectDialog.vue';
import BuyEInvoiceDialog from './BuyEInvoiceDialog.vue';
import EInvoiceOrderCard from './EInvoiceOrderCard.vue';
import EInvoiceRequestDialog from './EInvoiceRequestDialog.vue';
import { loadEInvoiceSettings, loadEInvoiceShop, type EInvoiceOrder, type EInvoiceSettings, type EInvoiceShop } from './einvoice';
import { loadZalo, type ZaloSettings } from './zalo';

/**
 * Cấu hình → "Kết nối dịch vụ" (mẫu 28/11): every outside service the shop connects with its own account, as cards
 * with the service's real logo. Carriers open the same dialog as Vận chuyển; e-invoice has its own; Zalo ZNS has its
 * own page. Each connection saves on its own, so the page has no Lưu button.
 */
const tenant = useTenantStore();
const loading = ref(true);
const error = ref<string | null>(null);
const carriers = ref<CarrierCatalogItem[]>([]);
const accounts = ref<CarrierAccountData[]>([]);
const einvoice = ref<EInvoiceSettings | null>(null);
const zalo = ref<ZaloSettings | null>(null);
const shop = ref<EInvoiceShop | null>(null);

async function load() {
    error.value = null;
    try {
        const [catalog, accs, inv, z, sh] = await Promise.all([api.get('/carriers'), loadCarrierAccounts(), loadEInvoiceSettings(), loadZalo().catch(() => null), loadEInvoiceShop().catch(() => null)]);
        zalo.value = z;
        shop.value = sh;
        carriers.value = (catalog.data.data as CarrierCatalogItem[]).filter((c) => c.carrier !== 'fake' || accs.some((a) => a.carrier === 'fake'));
        accounts.value = accs;
        einvoice.value = inv;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

const carrierOpen = ref(false);
const carrierPick = ref<string | null>(null);
function openCarrier(key: string) {
    carrierPick.value = key;
    carrierOpen.value = true;
}
const carrierAccounts = (key: string) => accounts.value.filter((a) => a.carrier === key);

const invoiceOpen = ref(false);
const invoicePick = ref<string | null>(null);
function openInvoice(key: string) {
    invoicePick.value = key;
    invoiceOpen.value = true;
}
const invoiceAccount = computed(() => (einvoice.value?.account?.status === 'disconnected' ? null : (einvoice.value?.account ?? null)));
const invoiceProviders = computed(() => einvoice.value?.providers ?? []);

const canManage = computed(() => tenant.can('settings.manage'));

const buyOpen = ref(false);
const requestOpen = ref(false);
const packages = computed(() => shop.value?.packages ?? []);
const order = computed(() => shop.value?.order ?? null);
/** "Mua gói" shows while the shop has no invoice connection, no registration running and there is something to sell. */
const canBuy = computed(() => canManage.value && !invoiceAccount.value && !order.value && packages.value.length > 0);
function buy() {
    invoiceOpen.value = false;
    buyOpen.value = true;
}
function ordered(o: EInvoiceOrder) {
    if (shop.value) shop.value.order = o;
}
const zaloOn = computed(() => !!zalo.value?.account && zalo.value.account.status !== 'disconnected');
const zaloRules = computed(() =>
    Object.entries(zalo.value?.account?.rules ?? {})
        .filter(([, r]) => r.enabled)
        .map(([k]) => zalo.value?.events[k] ?? k),
);

type State = 'ok' | 'error' | 'off' | 'soon';
function carrierState(key: string, available: boolean): State {
    const accs = carrierAccounts(key);
    if (accs.some((a) => a.status === 'error')) return 'error';
    if (accs.length) return 'ok';
    return available ? 'off' : 'soon';
}
function invoiceState(p: { provider: string; available: boolean }): State {
    if (invoiceAccount.value?.provider === p.provider) return invoiceAccount.value.status === 'connected' ? 'ok' : 'error';
    return p.available ? 'off' : 'soon';
}
const providerBlurb: Record<string, string> = {
    viettel: 'Tên đăng nhập, mật khẩu API do Viettel cấp, cùng mẫu số và ký hiệu hóa đơn.',
    vnpt: 'Cần địa chỉ trang hóa đơn của shop và tài khoản dịch vụ (webservice) do VNPT cấp.',
    misa: 'Đăng nhập bằng tài khoản meInvoice, chọn mẫu hóa đơn là xong.',
    sepay: 'Dán Client ID và Client Secret lấy ở my.sepay.vn.',
};
const dot: Record<State, string> = { ok: 'bg-emerald-500', error: 'bg-amber-500', off: 'bg-slate-300', soon: 'bg-slate-300' };
const stateText: Record<State, string> = { ok: 'text-emerald-600', error: 'text-amber-600', off: 'text-slate-500', soon: 'text-slate-500' };

const stats = computed(() => {
    const states = [...carriers.value.map((c) => carrierState(c.carrier, c.available)), ...invoiceProviders.value.map(invoiceState), zaloOn.value ? (zalo.value?.account?.status === 'connected' ? 'ok' : 'error') : 'off'];
    return { ok: states.filter((s) => s === 'ok').length, error: states.filter((s) => s === 'error').length, more: states.filter((s) => s === 'off').length };
});
const card = (s: State) => cn('flex flex-col rounded-[12px] border bg-white p-4', s === 'error' ? 'border-amber-300 ring-1 ring-amber-200' : 'border-slate-200');
const btn = 'mt-auto flex h-10 w-full items-center justify-center rounded-[10px] text-[14px] font-medium';
const btnFor = (s: State) =>
    cn(btn, s === 'ok' ? 'border border-slate-200 text-slate-800 hover:bg-slate-50' : s === 'error' ? 'bg-amber-500 text-white hover:bg-amber-600' : 'bg-blue-600 text-white hover:bg-blue-700');
</script>

<template>
    <SettingsShell title="Kết nối dịch vụ" description="Vận chuyển, hóa đơn điện tử và tin nhắn Zalo của shop. Mỗi kết nối lưu riêng khi bấm Kết nối hoặc Cài đặt.">
        <ErrorState v-if="error" :message="error" @retry="load" />
        <div v-else-if="loading" class="space-y-4"><Skeleton class="h-24" /><Skeleton class="h-56" /><Skeleton class="h-56" /></div>
        <div v-else class="space-y-6" data-testid="service-connections">
            <div class="flex flex-wrap items-center gap-x-10 gap-y-3 rounded-[14px] border border-slate-200/80 bg-white px-6 py-4 shadow-[0_1px_2px_rgba(15,23,42,0.04)]" data-testid="svc-stats">
                <div><p class="text-[14px] text-slate-500">Đang chạy</p><p class="text-[24px] font-bold text-emerald-600">{{ stats.ok }}</p></div>
                <div><p class="text-[14px] text-slate-500">Cần xem lại</p><p :class="cn('text-[24px] font-bold', stats.error ? 'text-amber-600' : 'text-slate-900')">{{ stats.error }}</p></div>
                <div><p class="text-[14px] text-slate-500">Có thể kết nối thêm</p><p class="text-[24px] font-bold text-slate-900">{{ stats.more }}</p></div>
                <p class="min-w-60 flex-1 text-[13px] text-slate-500 lg:max-w-md lg:text-right">Nhập khóa API do hãng cấp cho shop. EzChat Bán hàng không thu thêm phí kết nối.</p>
            </div>

            <SettingsSection title="Vận chuyển" description="Tạo vận đơn, in nhãn, cập nhật hành trình tự động. Shop dùng tài khoản của chính mình." :icon="Truck">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5">
                    <div v-for="c in carriers" :key="c.carrier" :class="card(carrierState(c.carrier, c.available))" :data-testid="`svc-carrier-${c.carrier}`">
                        <div class="flex h-10 items-center">
                            <img v-if="carrierLogo(c.carrier)" :src="carrierLogo(c.carrier)!" :alt="c.label" class="max-h-9 max-w-[140px] object-contain" />
                            <span v-else class="grid size-10 place-items-center rounded-[10px] bg-slate-100 text-slate-500"><Truck class="size-5" /></span>
                        </div>
                        <p class="mt-3 text-[15px] font-semibold text-slate-900">{{ c.label }}</p>
                        <p :class="cn('mt-1 flex items-start gap-1.5 text-[13px]', stateText[carrierState(c.carrier, c.available)])">
                            <span :class="cn('mt-1.5 size-1.5 shrink-0 rounded-full', dot[carrierState(c.carrier, c.available)])" />
                            <span>{{ { ok: 'Đã kết nối', error: 'Lỗi kết nối', off: 'Chưa kết nối', soon: c.note ?? 'Sắp có' }[carrierState(c.carrier, c.available)] }}</span>
                        </p>
                        <p v-if="carrierAccounts(c.carrier).length" class="mt-1 line-clamp-2 text-[13px] text-slate-500">{{ carrierAccounts(c.carrier).map((a) => a.name).join(' · ') }}</p>
                        <div class="min-h-4 flex-1" />
                        <span v-if="!c.available" :class="cn(btn, 'bg-slate-100 text-slate-500')">Sắp có</span>
                        <button v-else-if="canManage" type="button" :class="btnFor(carrierState(c.carrier, c.available))" @click="openCarrier(c.carrier)">
                            {{ { ok: 'Cài đặt', error: 'Kết nối lại', off: 'Kết nối', soon: 'Kết nối' }[carrierState(c.carrier, c.available)] }}
                        </button>
                    </div>
                </div>
            </SettingsSection>

            <SettingsSection title="Hóa đơn điện tử" description="Xuất hóa đơn cho đơn bán, gửi khách qua email. Mỗi shop dùng một nhà cung cấp; đổi nhà cung cấp thì hóa đơn cũ vẫn giữ trên đơn." :icon="FileText" tone="green">
                <div class="space-y-5">
                    <EInvoiceOrderCard v-if="order" :order="order" :can-manage="canManage" @changed="load" />
                    <div v-else-if="canBuy" class="flex flex-wrap items-center gap-5 rounded-[14px] bg-gradient-to-r from-blue-600 to-indigo-500 p-5 text-white" data-testid="einvoice-buy-banner">
                        <span class="grid size-14 shrink-0 place-items-center rounded-[12px] bg-white/15"><ReceiptText class="size-7" /></span>
                        <div class="min-w-64 flex-1">
                            <p class="text-[18px] font-semibold">Chưa có hóa đơn điện tử? Mua gói ngay tại đây</p>
                            <p class="mt-1 text-[14px] text-white/85">Chọn gói, điền mã số thuế. Bên mình đăng ký với nhà cung cấp, làm hồ sơ với thuế giúp shop và tự kết nối vào đây. Thường xong trong 1–2 ngày làm việc.</p>
                            <div class="mt-2 flex flex-wrap gap-1.5 text-[13px]">
                                <span class="rounded-md bg-white/15 px-2 py-0.5">Từ {{ Math.min(...packages.map((p) => p.invoices)).toLocaleString('vi-VN') }} hóa đơn</span>
                                <span v-if="packages.some((p) => p.signing_price !== null)" class="rounded-md bg-white/15 px-2 py-0.5">Có ký số từ xa, không cần USB token</span>
                                <span class="rounded-md bg-white/15 px-2 py-0.5">Hỗ trợ cả hộ kinh doanh</span>
                            </div>
                        </div>
                        <button type="button" class="inline-flex h-11 items-center gap-2 rounded-[10px] bg-white px-5 text-[15px] font-semibold text-blue-700 hover:bg-blue-50" data-testid="einvoice-buy-open" @click="buy">
                            <ShoppingBag class="size-4" /> Xem gói hóa đơn
                        </button>
                    </div>

                    <p v-if="canBuy || order" class="text-[14px] text-slate-500">Hoặc kết nối tài khoản shop đang dùng</p>
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <div v-for="p in invoiceProviders" :key="p.provider" :class="card(invoiceState(p))" :data-testid="`svc-einvoice-${p.provider}`">
                            <div class="flex h-10 items-center">
                                <img v-if="einvoiceLogos[p.provider]" :src="logoUrl(einvoiceLogos[p.provider])!" :alt="p.label" class="max-h-9 max-w-[130px] object-contain" />
                                <span v-else class="grid size-10 place-items-center rounded-[10px] bg-slate-100 text-slate-400"><FileText class="size-5" /></span>
                            </div>
                            <p class="mt-3 text-[15px] font-semibold text-slate-900">{{ p.label }}</p>
                            <p :class="cn('mt-1 flex items-start gap-1.5 text-[13px]', stateText[invoiceState(p)])">
                                <span :class="cn('mt-1.5 size-1.5 shrink-0 rounded-full', dot[invoiceState(p)])" />
                                <span>
                                    {{ { ok: 'Đã kết nối', error: 'Lỗi kết nối', off: invoiceAccount ? `Đang dùng ${invoiceAccount.provider_label}` : 'Chưa kết nối', soon: p.note ?? 'Sắp có' }[invoiceState(p)] }}
                                    <template v-if="invoiceAccount?.provider === p.provider && invoiceAccount.environment === 'sandbox'"> · thử nghiệm</template>
                                </span>
                            </p>
                            <p class="mt-1 line-clamp-3 text-[13px] text-slate-500">
                                <template v-if="invoiceAccount?.provider === p.provider">{{ invoiceAccount.template_label ? `Mẫu ${invoiceAccount.template_label}` : (invoiceAccount.provider_name ?? '') }}</template>
                                <template v-else>{{ providerBlurb[p.provider] ?? '' }}</template>
                            </p>
                            <div class="min-h-4 flex-1" />
                            <span v-if="!p.available" :class="cn(btn, 'bg-slate-100 text-slate-500')">Sắp có</span>
                            <button
                                v-else-if="canManage && (!invoiceAccount || invoiceAccount.provider === p.provider)"
                                type="button"
                                :class="btnFor(invoiceState(p))"
                                :data-testid="`svc-einvoice-open-${p.provider}`"
                                @click="openInvoice(p.provider)"
                            >
                                {{ invoiceState(p) === 'off' ? 'Kết nối' : 'Cài đặt' }}
                            </button>
                        </div>
                    </div>
                    <div v-if="canManage" class="flex flex-wrap items-center gap-4 rounded-[12px] border border-dashed border-slate-300 px-4 py-3" data-testid="einvoice-other">
                        <div class="min-w-60 flex-1">
                            <p class="text-[14px] font-medium text-slate-900">Dùng nhà cung cấp khác? <span class="font-normal text-slate-500">BKAV eHoadon, EasyInvoice, M-Invoice, FPT…</span></p>
                            <p class="text-[13px] text-slate-500">Báo cho bên mình biết, có từ 5 shop cùng dùng là bên mình làm kết nối.</p>
                        </div>
                        <button type="button" class="inline-flex h-9 items-center gap-2 rounded-[10px] border border-slate-200 px-3 text-[14px] text-slate-700 hover:bg-slate-50" data-testid="einvoice-other-open" @click="requestOpen = true"><Send class="size-4" /> Gửi yêu cầu</button>
                    </div>
                </div>
            </SettingsSection>

            <SettingsSection title="Tin nhắn cho khách" description="Gửi tin xác nhận đơn, giao hàng, nhắc thanh toán." :icon="MessageCircle" tone="violet">
                <div class="flex flex-wrap items-center gap-4 rounded-[12px] border border-slate-200 p-4">
                    <img :src="logoUrl('channels/zalo.webp')!" alt="Zalo" class="size-12 rounded-[12px] object-contain" />
                    <div class="min-w-0 flex-1">
                        <p class="text-[15px] font-semibold text-slate-900">Zalo ZNS</p>
                        <p v-if="zaloOn" :class="cn('mt-0.5 flex items-center gap-1.5 text-[13px]', zalo?.account?.status === 'connected' ? 'text-emerald-600' : 'text-amber-600')">
                            <span :class="cn('size-1.5 rounded-full', zalo?.account?.status === 'connected' ? 'bg-emerald-500' : 'bg-amber-500')" />
                            OA {{ zalo?.account?.oa_name ?? zalo?.account?.app_id }} · {{ zaloRules.length }} mẫu tin đang bật
                        </p>
                        <p v-else class="mt-0.5 text-[13px] text-slate-500">Báo xác nhận đơn, đang giao, đã giao qua Zalo</p>
                    </div>
                    <div v-if="zaloRules.length" class="flex flex-wrap gap-1.5">
                        <span v-for="r in zaloRules" :key="r" class="rounded-md bg-emerald-50 px-2 py-0.5 text-[13px] font-medium text-emerald-700">{{ r }}</span>
                    </div>
                    <RouterLink
                        v-if="canManage"
                        to="/settings/connections/zalo"
                        :class="cn('flex h-10 items-center rounded-[10px] px-4 text-[14px] font-medium', zaloOn ? 'border border-slate-200 text-slate-800 hover:bg-slate-50' : 'bg-blue-600 text-white hover:bg-blue-700')"
                        data-testid="svc-zalo-open"
                    >
                        {{ zaloOn ? 'Cài đặt' : 'Kết nối' }}
                    </RouterLink>
                </div>
            </SettingsSection>
        </div>

        <CarrierConnectDialog v-model:open="carrierOpen" :accounts="accounts" :initial="carrierPick" @changed="load" />
        <EInvoiceConnectDialog v-if="einvoice" v-model:open="invoiceOpen" :settings="einvoice" :initial="invoicePick" :can-buy="canBuy" @changed="load" @buy="buy" />
        <BuyEInvoiceDialog v-model:open="buyOpen" :packages="packages" :defaults="{ name: tenant.current?.name ?? '' }" @ordered="ordered" />
        <EInvoiceRequestDialog v-model:open="requestOpen" />
    </SettingsShell>
</template>
