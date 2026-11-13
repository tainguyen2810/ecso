<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { CheckCircle2 } from 'lucide-vue-next';
import Alert from '@/shared/ui/Alert.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import { api, ApiError } from '@/shared/lib/api';
import type { MarketplaceAccountData, Warehouse } from '@/shared/types';

/** Kết nối shop Tiki (C8) with the shop's own In-house app: Client ID and Client Secret from open.tiki.vn. */
const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ warehouses: Warehouse[] }>();
const emit = defineEmits<{ connected: [] }>();

const form = ref({ client_id: '', client_secret: '' });
const busy = ref(false);
const error = ref<ApiError | null>(null);
const account = ref<MarketplaceAccountData | null>(null);
const defaultWarehouse = computed(() => props.warehouses.find((w) => w.is_default)?.id);

watch(open, (v) => {
    if (v) {
        form.value = { client_id: '', client_secret: '' };
        error.value = null;
        account.value = null;
    }
});

async function connect() {
    busy.value = true;
    error.value = null;
    try {
        const { data } = await api.post('/marketplace-accounts/tiki/connect', { ...form.value, warehouse_id: defaultWarehouse.value });
        account.value = data.data;
        emit('connected');
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open" title="Kết nối shop Tiki" description="Làm 3 bước ở open.tiki.vn, rồi dán 2 mã vào đây.">
        <div class="space-y-4" data-testid="tiki-dialog">
            <ol class="list-decimal space-y-1 pl-5 text-sm">
                <li>Đăng nhập <a href="https://open.tiki.vn" target="_blank" rel="noopener" class="text-primary hover:underline">open.tiki.vn</a> bằng tài khoản Seller, tạo ứng dụng loại <b>Inhouse</b>.</li>
                <li>Chép <b>Client ID</b> và <b>Client Secret</b>.</li>
                <li>Trong Seller Center → Ứng dụng tích hợp, bấm kết nối ứng dụng vừa tạo.</li>
            </ol>
            <FormField label="Client ID" :error="error?.field('client_id')">
                <Input v-model="form.client_id" autocomplete="off" data-testid="tiki-client-id" />
            </FormField>
            <FormField label="Client Secret" :error="error?.field('client_secret')">
                <Input v-model="form.client_secret" type="password" autocomplete="new-password" data-testid="tiki-client-secret" />
            </FormField>
            <Alert v-if="account" tone="green" :icon="CheckCircle2" data-testid="tiki-connected">Đã kết nối: {{ account.shop_name }} (Tiki)</Alert>
            <Alert v-else-if="error && !error.body.errors" tone="red">{{ error.message }}</Alert>
            <p class="text-xs text-muted-foreground">
                Đã xác minh: cấp token và đọc thông tin shop. Kéo đơn Tiki, sản phẩm và đẩy tồn kho sẽ bật sau khi chạy thử với shop thật của bạn; trong lúc chờ, cập nhật tồn trên Seller Center.
            </p>
        </div>
        <template #footer>
            <Button variant="outline" @click="open = false">{{ account ? 'Đóng' : 'Hủy' }}</Button>
            <Button v-if="!account" :loading="busy" :disabled="!form.client_id || !form.client_secret" data-testid="tiki-connect" @click="connect">Kết nối</Button>
        </template>
    </Dialog>
</template>
