<script setup lang="ts">
import { ref, watch } from 'vue';
import { ShieldCheck } from 'lucide-vue-next';
import Dialog from '@/shared/ui/Dialog.vue';
import Button from '@/shared/ui/Button.vue';
import MarketplaceLogo from '@/shared/ui/MarketplaceLogo.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useTenantStore } from '@/shared/stores/tenant';
import TikiConnectDialog from '../pages/marketplaces/TikiConnectDialog.vue';
import WooConnectDialog from '../pages/marketplaces/WooConnectDialog.vue';
import type { MarketplaceCatalogEntry, Warehouse } from '@/shared/types';

/**
 * "Kết nối thêm kênh" from the sidebar: every real marketplace in one dialog, so the menu only lists the
 * marketplaces that already have a shop. Same connect flow as the Tất cả kênh page.
 */
const open = defineModel<boolean>('open', { default: false });
const props = defineProps<{ counts: Record<string, number> }>();
const emit = defineEmits<{ connected: [] }>();

const tenant = useTenantStore();
const catalog = ref<MarketplaceCatalogEntry[]>([]);
const warehouses = ref<Warehouse[]>([]);
const busy = ref<string | null>(null);
const notice = ref<{ key: string; message: string } | null>(null);
const wooOpen = ref(false);
const tikiOpen = ref(false);

watch(open, async (v) => {
    if (!v || catalog.value.length) return;
    const [c, w] = await Promise.all([api.get('/marketplaces'), api.get('/warehouses')]);
    catalog.value = (c.data.data as MarketplaceCatalogEntry[]).filter((x) => !x.is_fake);
    warehouses.value = (w.data.data as Warehouse[]).filter((x) => x.status === 'active' && x.purpose !== 'defective');
});

const ready = (c: MarketplaceCatalogEntry) => c.connect_mode === 'store' || c.connect_mode === 'app_keys' || (c.available && c.credentials_configured && c.capabilities['shop.authorize'] === 'supported');
function line(c: MarketplaceCatalogEntry) {
    if (!ready(c)) return 'Đang làm kết nối';
    const n = props.counts[c.key] ?? 0;
    const unit = c.key === 'woocommerce' ? 'web' : 'shop';
    if (n) return `Đã có ${n} ${unit} · thêm ${unit} khác`;
    return c.key === 'woocommerce' ? 'Đơn từ web về thẳng đây' : 'Đơn và tồn kho tự đồng bộ';
}

async function connect(c: MarketplaceCatalogEntry) {
    notice.value = null;
    if (c.connect_mode === 'store') return void ((open.value = false), (wooOpen.value = true));
    if (c.connect_mode === 'app_keys') return void ((open.value = false), (tikiOpen.value = true));
    busy.value = c.key;
    try {
        const { data } = await api.post(`/marketplace-accounts/authorize/${c.key}`);
        window.location.href = data.data.url;
    } catch (e) {
        const err = e as ApiError;
        notice.value = {
            key: c.key,
            message: err.body.code === 'CREDENTIALS_MISSING' ? `Kết nối ${c.name} chưa được mở trên hệ thống. Liên hệ hỗ trợ để bật.` : err.body.code === 'UNSUPPORTED_CAPABILITY' ? `Kết nối ${c.name} sắp có.` : err.message,
        };
    } finally {
        busy.value = null;
    }
}
const done = () => emit('connected');
</script>

<template>
    <Dialog v-model:open="open" title="Kết nối kênh bán" description="Đơn và tồn kho tự đồng bộ sau khi kết nối.">
        <div class="space-y-2" data-testid="connect-channel-list">
            <div v-for="c in catalog" :key="c.key" :class="['rounded-lg border px-3 py-2.5', !ready(c) && 'opacity-60']">
                <div class="flex items-center gap-3">
                    <MarketplaceLogo :marketplace="c.key" :name="c.name" size="lg" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium">{{ c.key === 'woocommerce' ? 'Website WooCommerce' : c.name }}</p>
                        <p class="text-xs text-muted-foreground">{{ line(c) }}</p>
                    </div>
                    <Button v-if="ready(c) && tenant.can('marketplace.connect')" size="sm" variant="outline" :loading="busy === c.key" :data-testid="`connect-channel-${c.key}`" @click="connect(c)">Kết nối</Button>
                    <span v-else-if="!ready(c)" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">Sắp có</span>
                </div>
                <p v-if="notice?.key === c.key" class="mt-2 text-xs text-destructive">{{ notice.message }}</p>
            </div>
        </div>
        <p class="mt-3 flex items-center gap-1.5 text-sm text-muted-foreground"><ShieldCheck class="size-4 text-emerald-600" /> Kết nối qua trang đăng nhập chính thức của sàn, không cần mật khẩu.</p>
    </Dialog>
    <WooConnectDialog v-model:open="wooOpen" :warehouses="warehouses" @connected="done" />
    <TikiConnectDialog v-model:open="tikiOpen" :warehouses="warehouses" @connected="done" />
</template>
