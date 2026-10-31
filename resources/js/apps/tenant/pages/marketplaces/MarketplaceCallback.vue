<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { CheckCircle2, XCircle } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import type { MarketplaceAccountData } from '@/shared/types';

/** Landing page of the marketplace authorization redirect: hands the parameters to the server, which checks the state. */
const route = useRoute();
const router = useRouter();
const result = ref<{ ok: true; account: MarketplaceAccountData } | { ok: false; message: string } | null>(null);

onMounted(async () => {
    const { state, ...rest } = route.query as Record<string, string>;
    try {
        const { data } = await api.post(`/marketplace-accounts/callback/${route.params.marketplace}`, { state, params: rest });
        result.value = { ok: true, account: data.data };
    } catch (e) {
        const err = e as ApiError;
        result.value = { ok: false, message: Object.values(err.body.errors ?? {})[0]?.[0] ?? err.message };
    }
});
</script>

<template>
    <div class="mx-auto mt-12 max-w-md">
        <Card>
            <div class="p-6 text-center">
                <Skeleton v-if="!result" class="mx-auto h-16 w-64" />
                <template v-else-if="result.ok">
                    <CheckCircle2 class="mx-auto size-10 text-success" />
                    <h2 class="mt-3 font-semibold">Đã kết nối {{ result.account.shop_name }}</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Hãy liên kết SKU để bắt đầu giữ tồn và đồng bộ tồn kho.</p>
                    <Button class="mt-4" @click="router.push(`/marketplaces/${result.account.id}/listings`)">Liên kết SKU</Button>
                </template>
                <template v-else>
                    <XCircle class="mx-auto size-10 text-destructive" />
                    <h2 class="mt-3 font-semibold">Không kết nối được</h2>
                    <p class="mt-1 text-sm text-muted-foreground">{{ result.message }}</p>
                    <Button class="mt-4" variant="outline" @click="router.push('/marketplaces')">Quay lại</Button>
                </template>
            </div>
        </Card>
    </div>
</template>
