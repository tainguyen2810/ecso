<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { Bot, Boxes, PackageCheck, ShoppingBag, Tags } from 'lucide-vue-next';
import Alert from '@/shared/ui/Alert.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useTenantStore } from '@/shared/stores/tenant';

/** "Cho phép" page: EzChat sent the seller here to link this store to their EzChat workspace (Đợt 7). */
const route = useRoute();
const tenant = useTenantStore();
const state = computed(() => String(route.query.state ?? ''));
const redirect = computed(() => String(route.query.redirect ?? ''));
const busy = ref(false);
const error = ref('');

async function allow() {
    busy.value = true;
    error.value = '';
    try {
        const { data } = await api.post('/ezchat-link/grant', { state: state.value, redirect: redirect.value });
        window.location.href = data.url;
    } catch (e) {
        error.value = (e as ApiError).message;
        busy.value = false;
    }
}

function cancel() {
    if (!redirect.value) return;
    const u = new URL(redirect.value);
    u.searchParams.set('error', 'access_denied');
    u.searchParams.set('state', state.value);
    window.location.href = u.toString();
}

const shares = [
    { icon: Tags, text: 'Tên, ảnh và giá bán sản phẩm, để trợ lý AI tư vấn đúng giá' },
    { icon: Boxes, text: 'Số tồn còn bán được ở kho của kênh "Chat EzChat"' },
    { icon: ShoppingBag, text: 'Đơn chốt trong chat tự tạo thành đơn ở đây và giữ hàng' },
    { icon: PackageCheck, text: 'Khách hỏi "đơn của tôi tới đâu": trả lời theo trạng thái đơn ở đây' },
];
</script>

<template>
    <div class="mx-auto max-w-lg py-6">
        <Card class="space-y-4 p-6" data-testid="ezchat-connect-page">
            <div class="flex items-center gap-3">
                <span class="grid size-12 place-items-center rounded-xl bg-violet-100 text-violet-700"><Bot class="size-6" /></span>
                <div>
                    <h1 class="text-lg font-semibold">Nối EzChat với cửa hàng này?</h1>
                    <p class="text-sm text-muted-foreground">Cửa hàng: <b class="text-foreground">{{ tenant.current?.name }}</b></p>
                </div>
            </div>
            <Alert v-if="!state || !redirect" tone="amber">Liên kết không đủ thông tin. Mở lại từ EzChat › Đặt hàng qua chat › Kết nối website.</Alert>
            <ul class="space-y-2.5 text-sm">
                <li v-for="s in shares" :key="s.text" class="flex items-start gap-2.5"><component :is="s.icon" class="mt-0.5 size-4 shrink-0 text-violet-600" />{{ s.text }}</li>
            </ul>
            <p class="text-xs text-muted-foreground">EzChat không sửa sản phẩm hay giá của bạn. Bạn ngắt kết nối bất cứ lúc nào ở Kênh bán riêng.</p>
            <Alert v-if="error" tone="red">{{ error }}</Alert>
            <div class="flex justify-end gap-2">
                <Button variant="outline" :disabled="busy" @click="cancel">Hủy</Button>
                <Button :loading="busy" :disabled="!state || !redirect || !tenant.can('settings.manage')" data-testid="ezchat-allow" @click="allow">Cho phép</Button>
            </div>
            <p v-if="!tenant.can('settings.manage')" class="text-xs text-red-600">Chỉ chủ cửa hàng hoặc quản lý được nối EzChat.</p>
        </Card>
    </div>
</template>
