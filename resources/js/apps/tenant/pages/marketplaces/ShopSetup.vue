<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Check, CircleAlert, Loader2 } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import Switch from '@/shared/ui/Switch.vue';
import { api, ApiError } from '@/shared/lib/api';
import { cn, formatNumber } from '@/shared/lib/utils';
import { useToast } from '@/shared/composables/useToast';
import type { MarketplaceAccountData } from '@/shared/types';
import { setupActionLabel, type ShopSetupOverview, type StockSource } from './shopSetup';

/**
 * A5: after a shop is connected for the first time. 1. products are pulled, 2. SKUs are linked by the seller SKU and
 * missing products created, 3. the opening stock is chosen once, 4. sync goes on. No stock is pushed before step 4.
 */
const route = useRoute();
const router = useRouter();
const toast = useToast();
const id = String(route.params.id);

const account = ref<MarketplaceAccountData | null>(null);
const overview = ref<ShopSetupOverview | null>(null);
const step = ref(0);
const busy = ref(false);
const withoutSku = ref(true);
const source = ref<StockSource>('marketplace');
const costPercent = ref<number | null>(null);
const done = ref<{ linked?: number; created?: number; receipt?: string | null; lines?: number; quantity?: number }>({});
const switches = ref({ sync_orders: true, sync_stock: true, push_status: true, import_new_products: false, sync_prices: false });

let timer: ReturnType<typeof setTimeout> | null = null;
onBeforeUnmount(() => timer && clearTimeout(timer));

async function load() {
    const [acc, ov] = await Promise.all([api.get(`/marketplace-accounts/${id}`), api.get(`/marketplace-accounts/${id}/setup`)]);
    account.value = acc.data.data;
    overview.value = ov.data.data;
    source.value = overview.value!.stock.suggested;
}

/** Step 1 waits for the products of the shop, checking every few seconds. */
function waitForProducts() {
    if (timer) clearTimeout(timer);
    if (overview.value?.listings_synced) return;
    timer = setTimeout(async () => {
        overview.value = (await api.get(`/marketplace-accounts/${id}/setup`)).data.data;
        waitForProducts();
    }, 3000);
}

onMounted(async () => {
    await load();
    if (!overview.value?.pending) return router.replace(`/marketplaces/${id}/listings`);
    if (!overview.value.listings_synced) await api.post(`/marketplace-accounts/${id}/setup/pull`).catch(() => undefined);
    waitForProducts();
});

async function act(fn: () => Promise<void>) {
    busy.value = true;
    try {
        await fn();
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        busy.value = false;
    }
}

const link = () =>
    act(async () => {
        const { data } = await api.post(`/marketplace-accounts/${id}/setup/link`, { without_sku: withoutSku.value });
        done.value = { ...done.value, ...data.data };
        overview.value = (await api.get(`/marketplace-accounts/${id}/setup`)).data.data;
        source.value = overview.value!.stock.suggested;
        step.value = 2;
    });

const saveStock = () =>
    act(async () => {
        const { data } = await api.post(`/marketplace-accounts/${id}/setup/stock`, { source: source.value, cost_percent: source.value === 'marketplace' ? costPercent.value : null });
        done.value = { ...done.value, ...data.data };
        switches.value.sync_stock = source.value !== 'none';
        step.value = 3;
    });

const finish = () =>
    act(async () => {
        await api.post(`/marketplace-accounts/${id}/setup/finish`, { settings: switches.value });
        toast.success(`Đã bật đồng bộ cho ${account.value?.shop_name}.`);
        router.push('/marketplaces');
    });

const steps = ['Lấy sản phẩm', 'Ghép sản phẩm', 'Tồn kho ban đầu', 'Bật đồng bộ'];
const name = computed(() => account.value?.marketplace_name ?? 'sàn');
const toCreate = computed(() => (overview.value ? overview.value.counts.create + (withoutSku.value ? overview.value.counts.create_without_sku : 0) : 0));
const stockOptions = computed(() => [
    { value: 'marketplace' as const, title: `Lấy tồn trên ${name.value} làm tồn ban đầu`, text: `Hợp với shop mới dùng EzSell. Tạo 1 phiếu nhập tồn đầu kỳ cho ${formatNumber(overview.value?.stock.from_shop_lines ?? 0)} SKU đang có 0 trong kho, tổng ${formatNumber(overview.value?.stock.from_shop_quantity ?? 0)} cái. SKU đã có tồn giữ nguyên.` },
    { value: 'ezsell' as const, title: `Giữ tồn EzSell, đẩy lên ${name.value}`, text: `Hợp khi đã nhập kho trong EzSell (hoặc đã chuyển từ Sapo, KiotViet). Tồn trên sàn sẽ đổi theo EzSell (${formatNumber(overview.value?.stock.ezsell_quantity ?? 0)} cái).` },
    { value: 'none' as const, title: 'Chưa đồng bộ tồn', text: 'Chỉ kéo đơn. Bật đẩy tồn sau ở trang Kết nối sàn khi đã kiểm kho xong.' },
]);
const switchRows = [
    { key: 'sync_orders', title: 'Kéo đơn tự động', text: 'Đơn mới về EzSell, đối soát lại định kỳ.' },
    { key: 'sync_stock', title: 'Đẩy tồn lên sàn', text: 'Mỗi khi tồn EzSell đổi: bán ở kênh khác, nhập hàng, kiểm kho.' },
    { key: 'push_status', title: 'Đẩy trạng thái đơn', text: 'Xác nhận, chuẩn bị hàng, in vận đơn từ EzSell.' },
    { key: 'import_new_products', title: 'Tự tạo sản phẩm khi shop đăng sản phẩm mới', text: 'Tắt: vào "Sản phẩm mới chờ duyệt" để xem trước.' },
    { key: 'sync_prices', title: 'Đồng bộ giá', text: 'Giữ tắt nếu bạn chỉnh giá trên sàn.' },
] as const;
</script>

<template>
    <PageHeader title="Thiết lập shop vừa kết nối" :back="{ to: '/marketplaces', label: 'Kết nối sàn' }">
        <template #description>
            <span v-if="account" class="inline-flex items-center gap-2"><MarketplaceLogo :marketplace="account.marketplace" :name="account.marketplace_name" size="sm" /> {{ account.shop_name }}</span>
        </template>
    </PageHeader>

    <Skeleton v-if="!overview" class="h-64" />
    <div v-else data-testid="shop-setup">
        <ol class="mb-5 flex items-center gap-2 text-sm">
            <li v-for="(label, i) in steps" :key="label" :class="cn('flex flex-1 items-center gap-2', i === step ? 'font-semibold text-foreground' : 'text-muted-foreground')">
                <span :class="cn('grid size-6 shrink-0 place-items-center rounded-full text-xs font-bold', i < step ? 'bg-emerald-600 text-white' : i === step ? 'bg-primary text-white' : 'bg-slate-200 text-slate-600')">
                    <Check v-if="i < step" class="size-3.5" />
                    <template v-else>{{ i + 1 }}</template>
                </span>
                <span class="hidden truncate sm:inline">{{ label }}</span>
                <span v-if="i < steps.length - 1" class="h-0.5 flex-1 bg-slate-200" />
            </li>
        </ol>

        <!-- 1. Pull -->
        <Card v-if="step === 0" class="max-w-3xl">
            <div class="p-5">
                <div v-if="!overview.listings_synced" class="flex items-center gap-3"><Loader2 class="size-5 animate-spin text-primary" /> <b>Đang lấy sản phẩm từ {{ name }}…</b></div>
                <div v-else class="flex items-center gap-3"><Check class="size-5 text-success" /> <b>Đã lấy {{ formatNumber(overview.counts.total) }} SKU từ {{ name }}.</b></div>
                <p class="mt-2 text-sm text-muted-foreground">Trong lúc chưa thiết lập xong, EzSell <b>chưa đẩy tồn</b> lên shop, nên tồn trên sàn không bị ghi đè. Đơn vẫn được kéo về bình thường.</p>
            </div>
            <div class="flex justify-end border-t bg-slate-50 px-5 py-3"><Button :disabled="!overview.listings_synced" data-testid="shop-setup-next" @click="step = 1">Tiếp tục</Button></div>
        </Card>

        <!-- 2. Link -->
        <template v-else-if="step === 1">
            <div class="mb-4 grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4"><p class="text-xs text-muted-foreground">Khớp mã SKU</p><p class="my-1 text-2xl font-bold">{{ formatNumber(overview.counts.matched) }} SKU</p><p class="text-xs text-muted-foreground">Mã SKU trên sàn trùng mã trong kho EzSell. Sẽ ghép luôn.</p></div>
                <div class="rounded-xl border border-blue-200 bg-blue-50 p-4"><p class="text-xs text-muted-foreground">Chưa có trong EzSell</p><p class="my-1 text-2xl font-bold">{{ formatNumber(toCreate) }} SKU</p><p class="text-xs text-muted-foreground">Tạo sản phẩm mới, giá = giá đang bán trên sàn, mã = SKU người bán.</p></div>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4"><p class="text-xs text-muted-foreground">Để ghép tay sau</p><p class="my-1 text-2xl font-bold">{{ formatNumber(overview.counts.later + (withoutSku ? 0 : overview.counts.create_without_sku)) }} SKU</p><p class="text-xs text-muted-foreground">Ghép ở trang Liên kết SKU, hoặc bỏ qua.</p></div>
            </div>
            <Card>
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase text-muted-foreground"><tr><th class="px-4 py-2.5">Sản phẩm trên sàn</th><th class="px-4 py-2.5">SKU người bán</th><th class="px-4 py-2.5">Trong EzSell</th><th class="px-4 py-2.5">Sẽ làm</th></tr></thead>
                    <tbody>
                        <tr v-for="(r, i) in overview.preview" :key="i" class="border-t">
                            <td class="px-4 py-2.5">{{ r.title }}</td>
                            <td class="px-4 py-2.5 font-semibold text-primary">{{ r.seller_sku ?? '—' }}</td>
                            <td class="px-4 py-2.5" :class="!r.internal && 'text-muted-foreground'">{{ r.internal ?? 'Chưa có' }}</td>
                            <td class="px-4 py-2.5"><Badge :tone="setupActionLabel(r.action, withoutSku).tone">{{ setupActionLabel(r.action, withoutSku).label }}</Badge></td>
                        </tr>
                    </tbody>
                </table>
            </Card>
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <label v-if="overview.counts.create_without_sku" class="flex items-center gap-2 text-sm"><input v-model="withoutSku" type="checkbox" data-testid="shop-setup-without-sku" /> Tạo luôn {{ overview.counts.create_without_sku }} SKU không có mã (EzSell đặt mã theo ID trên sàn)</label>
                <span v-else />
                <Button :loading="busy" data-testid="shop-setup-link" @click="link">Ghép {{ formatNumber(overview.counts.matched) }}, tạo {{ formatNumber(toCreate) }} SKU</Button>
            </div>
        </template>

        <!-- 3. Opening stock -->
        <template v-else-if="step === 2">
            <p v-if="done.linked !== undefined" class="mb-3 text-sm text-muted-foreground">Đã ghép {{ formatNumber(done.linked ?? 0) }} SKU, tạo {{ formatNumber(done.created ?? 0) }} sản phẩm mới.</p>
            <div class="grid gap-3 sm:grid-cols-3" role="radiogroup" data-testid="shop-setup-stock">
                <button
                    v-for="o in stockOptions"
                    :key="o.value"
                    type="button"
                    role="radio"
                    :aria-checked="source === o.value"
                    :class="cn('relative rounded-xl border p-4 text-left', source === o.value ? 'border-blue-300 bg-blue-50/50 ring-2 ring-blue-100' : 'bg-card hover:bg-muted/50')"
                    @click="source = o.value"
                >
                    <span :class="cn('absolute right-3 top-3 size-5 rounded-full border', source === o.value ? 'border-primary bg-primary shadow-[inset_0_0_0_4px_white]' : 'border-slate-300')" />
                    <span class="block pr-6 text-sm font-semibold">{{ o.title }}</span>
                    <span class="mt-1 block text-xs leading-relaxed text-muted-foreground">{{ o.text }}</span>
                    <span v-if="overview.stock.suggested === o.value" class="mt-2 inline-block rounded-full bg-emerald-100 px-2.5 py-0.5 text-[11px] font-bold text-emerald-700">Gợi ý</span>
                </button>
            </div>
            <Card v-if="overview.stock.rows.length" class="mt-4">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase text-muted-foreground"><tr><th class="px-4 py-2.5">SKU</th><th class="px-4 py-2.5 text-right">Tồn trên sàn</th><th class="px-4 py-2.5 text-right">Tồn EzSell hiện tại</th><th class="px-4 py-2.5 text-right">Sau khi làm</th></tr></thead>
                    <tbody>
                        <tr v-for="r in overview.stock.rows" :key="r.sku" class="border-t">
                            <td class="px-4 py-2.5 font-semibold text-primary">{{ r.sku }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ formatNumber(r.marketplace) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ formatNumber(r.on_hand) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums font-semibold">{{ formatNumber(source === 'marketplace' && r.on_hand === 0 ? r.marketplace : r.on_hand) }}</td>
                        </tr>
                    </tbody>
                </table>
            </Card>
            <div v-if="source === 'marketplace'" class="mt-4 max-w-md">
                <label class="text-sm font-medium" for="cost-percent">Giá vốn cho phiếu nhập đầu kỳ</label>
                <select id="cost-percent" v-model="costPercent" class="mt-1.5 block h-9 w-full rounded-md border bg-card px-3 text-sm">
                    <option :value="null">Để trống, nhập sau</option>
                    <option v-for="p in [50, 60, 70, 80]" :key="p" :value="p">= {{ p }}% giá bán</option>
                </select>
                <p class="mt-1 text-xs text-muted-foreground">Có giá vốn thì báo cáo lãi lỗ có số ngay.</p>
            </div>
            <div class="mt-4 flex justify-end"><Button :loading="busy" data-testid="shop-setup-stock-save" @click="saveStock">Tiếp tục</Button></div>
        </template>

        <!-- 4. Switch on -->
        <div v-else class="grid gap-4 lg:grid-cols-[1fr_360px]">
            <Card>
                <div v-for="r in switchRows" :key="r.key" class="border-b px-5 py-1 last:border-0">
                    <Switch v-model="switches[r.key]" :label="r.title" :hint="r.text" />
                </div>
            </Card>
            <Card class="p-5">
                <p class="font-semibold">Đã sẵn sàng</p>
                <ul class="mt-2 space-y-2 text-sm">
                    <li class="flex items-center gap-2"><Check class="size-4 text-success" /> {{ formatNumber(done.linked ?? 0) }} SKU đã ghép</li>
                    <li class="flex items-center gap-2"><Check class="size-4 text-success" /> {{ formatNumber(done.created ?? 0) }} sản phẩm mới đã tạo</li>
                    <li v-if="done.receipt" class="flex items-center gap-2"><Check class="size-4 text-success" /> Nhập tồn đầu kỳ {{ formatNumber(done.quantity ?? 0) }} cái ({{ done.receipt }})</li>
                    <li v-if="overview.counts.unlinked_left" class="flex items-center gap-2"><CircleAlert class="size-4 text-amber-500" /> {{ formatNumber(overview.counts.unlinked_left) }} SKU chưa ghép, để sau</li>
                </ul>
                <Button class="mt-4 w-full" :loading="busy" data-testid="shop-setup-finish" @click="finish">Bật đồng bộ và xong</Button>
            </Card>
        </div>
    </div>
</template>
