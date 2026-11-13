<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import PageHeader from '../../PageHeader.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import CarrierConnectDialog from '../shipments/CarrierConnectDialog.vue';
import { carrierMarks, loadCarrierAccounts, type CarrierAccountData, type CarrierCatalogItem } from '../shipments/carriers';
import EInvoiceConnectDialog from './EInvoiceConnectDialog.vue';
import { loadEInvoiceSettings, providerMarks, type EInvoiceSettings } from './einvoice';

/**
 * Cấu hình → "Kết nối dịch vụ": every outside service the shop connects with its own account, in one place.
 * Carriers open the same dialog as Vận chuyển; e-invoice has its own; Zalo and Tiki come in later rounds.
 */
const tenant = useTenantStore();
const loading = ref(true);
const error = ref<string | null>(null);
const carriers = ref<CarrierCatalogItem[]>([]);
const accounts = ref<CarrierAccountData[]>([]);
const einvoice = ref<EInvoiceSettings | null>(null);

async function load() {
    error.value = null;
    try {
        const [catalog, accs, inv] = await Promise.all([api.get('/carriers'), loadCarrierAccounts(), loadEInvoiceSettings()]);
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
const invoiceProviders = computed(() => {
    const list = einvoice.value?.providers ?? [];
    // Viettel and VNPT share one "waiting for documents" row, as on the mockup.
    const waiting = list.filter((p) => !p.available && p.provider !== 'misa');
    return [
        ...list.filter((p) => p.available || p.provider === 'misa'),
        ...(waiting.length ? [{ provider: 'waiting', label: waiting.map((p) => p.label.split(' ')[0]).join(', '), fields: {}, available: false, note: 'Chờ tài liệu từ nhà cung cấp' }] : []),
    ];
});

const canManage = computed(() => tenant.can('settings.manage'));
</script>

<template>
    <PageHeader title="Kết nối dịch vụ" description="Nhập khóa API do hãng cấp cho shop. Shop dùng tài khoản của chính mình, EzSell không thu thêm phí." :back="{ to: '/settings', label: 'Cấu hình' }" />
    <ErrorState v-if="error" :message="error" @retry="load" />
    <div v-else-if="loading" class="space-y-4"><Skeleton class="h-40" /><Skeleton class="h-40" /></div>
    <div v-else class="space-y-5" data-testid="service-connections">
        <Card caps title="Vận chuyển" description="Tạo vận đơn, in nhãn, cập nhật hành trình tự động">
            <div class="divide-y">
                <div v-for="c in carriers" :key="c.carrier" class="flex flex-wrap items-center gap-3 px-5 py-3" :data-testid="`svc-carrier-${c.carrier}`">
                    <span :class="cn('grid h-9 w-11 shrink-0 place-items-center rounded-md text-[11px] font-bold text-white', carrierMarks[c.carrier]?.bg ?? 'bg-slate-500')">{{ carrierMarks[c.carrier]?.short ?? c.carrier }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">{{ c.label }}</div>
                        <div class="text-xs text-muted-foreground">
                            <template v-if="carrierAccounts(c.carrier).length">{{ carrierAccounts(c.carrier).map((a) => a.name).join(' · ') }}</template>
                            <template v-else-if="!c.available">{{ c.note }}</template>
                            <template v-else>Chưa kết nối</template>
                        </div>
                    </div>
                    <Badge v-if="carrierAccounts(c.carrier).some((a) => a.status === 'error')" tone="danger">Lỗi kết nối</Badge>
                    <Badge v-else-if="carrierAccounts(c.carrier).length" tone="success">Đã kết nối</Badge>
                    <Badge v-else-if="!c.available" tone="muted">Sắp có</Badge>
                    <Button v-if="c.available && canManage" size="sm" :variant="carrierAccounts(c.carrier).length ? 'outline' : 'default'" @click="openCarrier(c.carrier)">
                        {{ carrierAccounts(c.carrier).length ? 'Cài đặt' : 'Kết nối' }}
                    </Button>
                </div>
            </div>
        </Card>

        <Card caps title="Hóa đơn điện tử" description="Xuất hóa đơn cho đơn bán, gửi khách qua email">
            <div class="divide-y">
                <div v-for="p in invoiceProviders" :key="p.provider" class="flex flex-wrap items-center gap-3 px-5 py-3" :data-testid="`svc-einvoice-${p.provider}`">
                    <span :class="cn('grid h-9 w-11 shrink-0 place-items-center rounded-md text-[11px] font-bold text-white', providerMarks[p.provider]?.bg ?? 'bg-slate-400')">{{ providerMarks[p.provider]?.short ?? 'VT' }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">{{ p.label }}</div>
                        <div class="text-xs text-muted-foreground">
                            <template v-if="invoiceAccount?.provider === p.provider">{{ invoiceAccount.provider_name ?? 'Đã kết nối' }}<template v-if="invoiceAccount.template_label"> · {{ invoiceAccount.template_label }}</template></template>
                            <template v-else-if="!p.available">{{ p.note }}</template>
                            <template v-else-if="invoiceAccount">Đang dùng {{ invoiceAccount.provider_label }}. Mỗi shop dùng một nhà cung cấp.</template>
                            <template v-else>Chưa kết nối</template>
                        </div>
                    </div>
                    <template v-if="invoiceAccount?.provider === p.provider">
                        <Badge v-if="invoiceAccount.environment === 'sandbox'" tone="warning">thử nghiệm</Badge>
                        <Badge :tone="invoiceAccount.status === 'connected' ? 'success' : 'danger'">{{ invoiceAccount.status === 'connected' ? 'Đã kết nối' : 'Lỗi kết nối' }}</Badge>
                    </template>
                    <Badge v-else-if="!p.available" tone="muted">Sắp có</Badge>
                    <Button
                        v-if="p.available && canManage && (!invoiceAccount || invoiceAccount.provider === p.provider)"
                        size="sm"
                        :variant="invoiceAccount ? 'outline' : 'default'"
                        :data-testid="`svc-einvoice-open-${p.provider}`"
                        @click="openInvoice(p.provider)"
                    >
                        {{ invoiceAccount ? 'Cài đặt' : 'Kết nối' }}
                    </Button>
                </div>
            </div>
        </Card>

        <Card caps title="Tin nhắn cho khách">
            <div class="flex flex-wrap items-center gap-3 px-5 py-3">
                <span class="grid h-9 w-11 shrink-0 place-items-center rounded-md bg-blue-600 text-[11px] font-bold text-white">Zalo</span>
                <div class="min-w-0 flex-1">
                    <div class="font-medium">Zalo ZNS</div>
                    <div class="text-xs text-muted-foreground">Báo xác nhận đơn, đang giao, đã giao qua Zalo</div>
                </div>
                <Badge tone="muted">Sắp có</Badge>
            </div>
        </Card>

        <Card caps title="Sàn thương mại điện tử">
            <template #actions><RouterLink to="/marketplaces" class="text-sm text-primary hover:underline">Mở Kết nối sàn</RouterLink></template>
            <div class="divide-y">
                <div v-for="m in [{ k: 'S', bg: 'bg-orange-500', name: 'Shopee', note: 'Chờ Shopee duyệt app' }, { k: 'TT', bg: 'bg-slate-900', name: 'TikTok Shop', note: 'Chờ TikTok duyệt app' }, { k: 'Tiki', bg: 'bg-sky-500', name: 'Tiki', note: 'Kết nối từng shop, không chờ duyệt' }]" :key="m.name" class="flex flex-wrap items-center gap-3 px-5 py-3">
                    <span :class="cn('grid h-9 w-11 shrink-0 place-items-center rounded-md text-[11px] font-bold text-white', m.bg)">{{ m.k }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">{{ m.name }}</div>
                        <div class="text-xs text-muted-foreground">{{ m.note }}</div>
                    </div>
                    <Badge tone="muted">Sắp có</Badge>
                </div>
            </div>
        </Card>
    </div>

    <CarrierConnectDialog v-model:open="carrierOpen" :accounts="accounts" :initial="carrierPick" @changed="load" />
    <EInvoiceConnectDialog v-if="einvoice" v-model:open="invoiceOpen" :settings="einvoice" :initial="invoicePick" @changed="load" />
</template>
