<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { CheckCircle2, XCircle } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import WooConnectResult from './WooConnectResult.vue';
import type { MarketplaceAccountData, Warehouse, WooConnectChecks } from '@/shared/types';

/**
 * Landing page of an authorization redirect: hands the parameters to the server, which checks the state.
 * WooCommerce comes back here after "Phê duyệt" (success=1) or "Từ chối" (success=0).
 */
const route = useRoute();
const router = useRouter();
const isWoo = route.params.marketplace === 'woocommerce';
const result = ref<{ ok: true; account: MarketplaceAccountData; checks?: WooConnectChecks } | { ok: false; message: string } | null>(null);
const warehouses = ref<Warehouse[]>([]);

onMounted(async () => {
    const { state, ...rest } = route.query as Record<string, string>;
    if (isWoo && rest.success === '0') {
        result.value = { ok: false, message: 'Anh đã bấm Từ chối trên website, ECSO chưa được cấp quyền. Có thể kết nối lại bất cứ lúc nào.' };
        return;
    }
    try {
        const { data } = isWoo
            ? await api.post('/marketplace-accounts/woocommerce/complete', { state })
            : await api.post(`/marketplace-accounts/callback/${route.params.marketplace}`, { state, params: rest });
        result.value = { ok: true, account: data.data, checks: data.checks };
        if (isWoo) warehouses.value = (await api.get('/warehouses')).data.data.filter((w: Warehouse) => w.status === 'active' && w.purpose !== 'defective');
    } catch (e) {
        const err = e as ApiError;
        result.value = { ok: false, message: Object.values(err.body.errors ?? {})[0]?.[0] ?? err.message };
    }
});
</script>

<template>
    <div :class="['mx-auto mt-12', isWoo ? 'max-w-2xl' : 'max-w-md']">
        <Card>
            <div class="p-6">
                <Skeleton v-if="!result" class="mx-auto h-16 w-64" />
                <template v-else-if="result.ok && isWoo && result.checks">
                    <div class="mb-4 flex items-center gap-3">
                        <CheckCircle2 class="size-8 shrink-0 text-success" />
                        <div>
                            <h2 class="font-semibold">Đã kết nối {{ result.account.shop_name }}</h2>
                            <p class="text-sm text-muted-foreground">ECSO đang lấy dữ liệu, anh có thể chuyển sang việc khác.</p>
                        </div>
                    </div>
                    <WooConnectResult :account="result.account" :checks="result.checks" :warehouses="warehouses" />
                    <div class="mt-5 flex justify-end gap-2 border-t pt-4">
                        <Button variant="outline" @click="router.push('/marketplaces')">Xong</Button>
                        <Button @click="router.push(`/marketplaces/${result.account.id}/store`)">Mở trang gian hàng</Button>
                    </div>
                </template>
                <div v-else-if="result.ok" class="text-center">
                    <CheckCircle2 class="mx-auto size-10 text-success" />
                    <h2 class="mt-3 font-semibold">Đã kết nối {{ result.account.shop_name }}</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Hãy liên kết SKU để bắt đầu giữ tồn và đồng bộ tồn kho.</p>
                    <Button class="mt-4" @click="router.push(`/marketplaces/${result.account.id}/listings`)">Liên kết SKU</Button>
                </div>
                <div v-else class="text-center">
                    <XCircle class="mx-auto size-10 text-destructive" />
                    <h2 class="mt-3 font-semibold">Không kết nối được</h2>
                    <p class="mt-1 text-sm text-muted-foreground">{{ result.message }}</p>
                    <Button class="mt-4" variant="outline" @click="router.push('/marketplaces')">Quay lại</Button>
                </div>
            </div>
        </Card>
    </div>
</template>
