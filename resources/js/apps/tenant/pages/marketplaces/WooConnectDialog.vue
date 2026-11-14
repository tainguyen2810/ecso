<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { ArrowRight, ExternalLink, KeyRound } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import { api, ApiError } from '@/shared/lib/api';
import WooConnectResult from './WooConnectResult.vue';
import type { MarketplaceAccountData, Warehouse, WooConnectChecks } from '@/shared/types';

/**
 * Connecting a WooCommerce store. Default: type the website, approve ECSO on WooCommerce's own screen (no keys to
 * copy). Fallback: paste REST API keys created by hand, with the steps shown here.
 */
const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ warehouses: Warehouse[] }>();
const emit = defineEmits<{ connected: [] }>();
const router = useRouter();

const mode = ref<'approve' | 'manual' | 'done'>('approve');
const form = ref({ website: '', consumer_key: '', consumer_secret: '' });
const busy = ref(false);
const error = ref<ApiError | null>(null);
const result = ref<{ account: MarketplaceAccountData; checks: WooConnectChecks } | null>(null);

watch(open, (v) => {
    if (v) {
        mode.value = 'approve';
        error.value = null;
        result.value = null;
    }
});

const site = computed(() => {
    const raw = form.value.website.trim().replace(/\/+$/, '');
    return raw === '' ? '' : /^https?:\/\//i.test(raw) ? raw : `https://${raw}`;
});
const keysPage = computed(() => (site.value ? `${site.value}/wp-admin/admin.php?page=wc-settings&tab=advanced&section=keys&create-key=1` : ''));
const defaultWarehouse = computed(() => props.warehouses.find((w) => w.is_default)?.id);

async function startApproval() {
    busy.value = true;
    error.value = null;
    try {
        const { data } = await api.post('/marketplace-accounts/woocommerce/authorize', { website: form.value.website, warehouse_id: defaultWarehouse.value });
        window.location.href = data.data.url;
    } catch (e) {
        error.value = e as ApiError;
        busy.value = false;
    }
}

async function connectWithKeys() {
    busy.value = true;
    error.value = null;
    try {
        const { data } = await api.post('/marketplace-accounts/woocommerce/keys', { ...form.value, warehouse_id: defaultWarehouse.value });
        result.value = { account: data.data, checks: data.checks };
        mode.value = 'done';
        emit('connected');
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        busy.value = false;
    }
}

function goStore() {
    open.value = false;
    if (result.value) router.push(`/marketplaces/${result.value.account.id}/${result.value.account.setup_pending ? 'setup' : 'store'}`);
}

const title = computed(() => (mode.value === 'done' ? `Đã kết nối ${result.value?.account.shop_name}` : 'Kết nối website WooCommerce'));
const description = computed(() =>
    mode.value === 'approve' ? 'Chỉ cần địa chỉ website. Không phải tạo hay sao chép khóa API.'
    : mode.value === 'manual' ? 'Làm theo 3 bước, mất khoảng 2 phút. Không cần cài plugin.'
    : 'ECSO đang lấy dữ liệu, anh có thể đóng hộp này.',
);
</script>

<template>
    <Dialog v-model:open="open" :title="title" :description="description" size="lg">
        <div v-if="mode === 'approve'" class="space-y-4" data-testid="woo-approve">
            <FormField label="Địa chỉ website bán hàng" :error="error?.field('website')">
                <Input v-model="form.website" placeholder="https://tenmien.vn" autofocus data-testid="woo-website" @keydown.enter="startApproval" />
            </FormField>
            <ol class="space-y-3 text-sm">
                <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-blue-50 text-xs font-semibold text-blue-600">1</span><span>Bấm <b>Tiếp tục</b>, ECSO mở trang đăng nhập quản trị website của anh.</span></li>
                <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-blue-50 text-xs font-semibold text-blue-600">2</span><span>Đăng nhập (nếu chưa) và bấm <b>Phê duyệt</b> để cho ECSO quyền đọc/ghi sản phẩm, đơn hàng.</span></li>
                <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-blue-50 text-xs font-semibold text-blue-600">3</span><span>Tự quay về ECSO. ECSO tự đăng ký nhận đơn mới và lấy danh sách sản phẩm.</span></li>
            </ol>
            <p v-if="error && !error.field('website')" class="text-sm text-destructive">{{ error.message }}</p>
            <p class="border-t border-dashed pt-3 text-xs text-muted-foreground">
                Website chặn phê duyệt hoặc dùng plugin bảo mật?
                <button type="button" class="text-primary hover:underline" data-testid="woo-manual" @click="mode = 'manual'; error = null">Nhập khóa API thủ công</button>
                (có hướng dẫn từng bước).
            </p>
        </div>

        <div v-else-if="mode === 'manual'" class="space-y-5" data-testid="woo-manual-form">
            <section class="flex gap-3">
                <span class="grid size-6 shrink-0 place-items-center rounded-full bg-blue-50 text-xs font-semibold text-blue-600">1</span>
                <div class="space-y-2 text-sm">
                    <p class="font-medium">Tạo khóa API trên WordPress</p>
                    <p class="text-muted-foreground">
                        Đăng nhập trang quản trị website → <b class="text-foreground">WooCommerce → Cài đặt → Nâng cao → REST API → Thêm khóa</b>.
                        Mô tả: <code class="rounded bg-muted px-1">ECSO</code>, Quyền: <b class="text-foreground">Đọc/Ghi</b> → Tạo khóa API.
                    </p>
                    <a v-if="keysPage" :href="keysPage" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-xs text-primary hover:bg-muted">
                        <ExternalLink class="size-3.5" /> Mở trang tạo khóa trên website
                    </a>
                </div>
            </section>
            <section class="flex gap-3">
                <span class="grid size-6 shrink-0 place-items-center rounded-full bg-blue-50 text-xs font-semibold text-blue-600">2</span>
                <div class="flex-1 space-y-3">
                    <p class="text-sm font-medium">Dán thông tin vào đây</p>
                    <FormField label="Địa chỉ website" :error="error?.field('website')"><Input v-model="form.website" placeholder="https://tenmien.vn" /></FormField>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <FormField label="Consumer key" :error="error?.field('consumer_key')"><Input v-model="form.consumer_key" placeholder="ck_..." autocomplete="off" class="font-mono" data-testid="woo-ck" /></FormField>
                        <FormField label="Consumer secret" :error="error?.field('consumer_secret')"><Input v-model="form.consumer_secret" type="password" placeholder="cs_..." autocomplete="off" class="font-mono" data-testid="woo-cs" /></FormField>
                    </div>
                </div>
            </section>
            <section class="flex gap-3">
                <span class="grid size-6 shrink-0 place-items-center rounded-full bg-blue-50 text-xs font-semibold text-blue-600">3</span>
                <div class="text-sm">
                    <p class="font-medium">Kiểm tra và kết nối</p>
                    <p class="text-muted-foreground">ECSO thử gọi website, đăng ký webhook nhận đơn, rồi lấy danh sách sản phẩm.</p>
                    <p v-if="error && !error.field('website') && !error.field('consumer_key') && !error.field('consumer_secret')" class="mt-2 text-destructive">{{ error.message }}</p>
                </div>
            </section>
        </div>

        <WooConnectResult v-else-if="result" :account="result.account" :checks="result.checks" :warehouses="warehouses" />

        <template #footer>
            <template v-if="mode === 'approve'">
                <Button variant="outline" @click="open = false">Hủy</Button>
                <Button :loading="busy" :disabled="!form.website.trim()" data-testid="woo-continue" @click="startApproval">Tiếp tục <ArrowRight /></Button>
            </template>
            <template v-else-if="mode === 'manual'">
                <Button variant="ghost" class="mr-auto" @click="mode = 'approve'; error = null">Quay lại</Button>
                <Button variant="outline" @click="open = false">Hủy</Button>
                <Button :loading="busy" :disabled="!form.website || !form.consumer_key || !form.consumer_secret" data-testid="woo-connect-keys" @click="connectWithKeys"><KeyRound /> Kết nối</Button>
            </template>
            <template v-else-if="result">
                <Button variant="outline" @click="goStore">Mở trang gian hàng</Button>
                <Button @click="open = false">Xong</Button>
            </template>
        </template>
    </Dialog>
</template>
