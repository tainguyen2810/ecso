<script setup lang="ts">
import { ref, watch } from 'vue';
import Alert from '@/shared/ui/Alert.vue';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import Input from '@/shared/ui/Input.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';

/** "Dùng nhà cung cấp khác? Gửi yêu cầu": the shop names its provider; enough shops on one and we build it. */
const open = defineModel<boolean>('open', { required: true });
const toast = useToast();
const provider = ref('');
const note = ref('');
const error = ref<string | null>(null);
const busy = ref(false);
const suggestions = ['BKAV eHoadon', 'EasyInvoice', 'M-Invoice', 'FPT eInvoice', 'CyberBill', 'Thái Sơn E-invoice'];

watch(open, (v) => {
    if (!v) return;
    provider.value = '';
    note.value = '';
    error.value = null;
});

async function submit() {
    busy.value = true;
    error.value = null;
    try {
        await api.post('/einvoice/provider-requests', { provider: provider.value, note: note.value || null });
        toast.success('Đã gửi yêu cầu. Bên mình sẽ báo khi kết nối được.');
        open.value = false;
    } catch (e) {
        const err = e as ApiError;
        error.value = err.field('provider') ?? err.message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open" title="Dùng nhà cung cấp khác?" description="Báo cho bên mình biết. Có từ 5 shop cùng dùng là bên mình làm kết nối.">
        <form class="space-y-4" data-testid="einvoice-request" @submit.prevent="submit">
            <label class="block space-y-1 text-sm">
                <span class="text-slate-600">Nhà cung cấp hóa đơn shop đang dùng</span>
                <Input v-model="provider" placeholder="vd. BKAV eHoadon" data-testid="einvoice-request-provider" />
            </label>
            <div class="flex flex-wrap gap-1.5">
                <button v-for="s in suggestions" :key="s" type="button" class="rounded-md border border-slate-200 px-2 py-1 text-[13px] text-slate-600 hover:bg-slate-50" @click="provider = s">{{ s }}</button>
            </div>
            <label class="block space-y-1 text-sm">
                <span class="text-slate-600">Ghi chú (không bắt buộc)</span>
                <Input v-model="note" placeholder="vd. hết hạn hợp đồng tháng 3" />
            </label>
            <Alert v-if="error" tone="red">{{ error }}</Alert>
            <div class="flex justify-end gap-2">
                <Button type="button" variant="outline" @click="open = false">Thôi</Button>
                <Button type="submit" :loading="busy" :disabled="!provider.trim() || busy" data-testid="einvoice-request-submit">Gửi yêu cầu</Button>
            </div>
        </form>
    </Dialog>
</template>
