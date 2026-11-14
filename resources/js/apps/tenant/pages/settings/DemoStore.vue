<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { Sparkles } from 'lucide-vue-next';
import PageHeader from '../../PageHeader.vue';
import Badge from '@/shared/ui/Badge.vue';
import Button from '@/shared/ui/Button.vue';
import Card from '@/shared/ui/Card.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import { ApiError } from '@/shared/lib/api';
import { formatDate } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import { demoDaysLeft } from '../setup/setup';

/** A2: make, open or delete the user's sample store from Cấu hình. */
const tenant = useTenantStore();
const router = useRouter();
const toast = useToast();
const busy = ref(false);
const confirmOpen = ref(false);

onMounted(() => tenant.fetchTenants().catch(() => undefined));
const demo = computed(() => tenant.tenants.find((t) => t.is_demo) ?? null);
const statusLabel = computed(() => ({ preparing: 'Đang thêm dữ liệu', ready: 'Sẵn sàng', failed: 'Lỗi khi thêm dữ liệu' })[demo.value?.demo_status ?? 'ready'] ?? 'Sẵn sàng');

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

const create = () =>
    act(async () => {
        const made = await tenant.createDemo();
        await tenant.select(made.id);
        toast.success('Đã tạo Cửa hàng mẫu. Dữ liệu mẫu đang được thêm vào.');
        router.push('/');
    });

const open = () =>
    act(async () => {
        await tenant.select(demo.value!.id);
        router.push('/');
    });

const remove = () =>
    act(async () => {
        const wasCurrent = tenant.current?.id === demo.value!.id;
        await tenant.deleteDemo(demo.value!.id);
        confirmOpen.value = false;
        toast.success('Đã xóa cửa hàng mẫu.');
        if (wasCurrent) {
            const real = tenant.tenants[0];
            if (real) await tenant.select(real.id);
            router.push(real ? '/' : '/tenants');
        }
    });
</script>

<template>
    <PageHeader title="Cửa hàng mẫu" description="Một doanh nghiệp riêng có sẵn 60 ngày dữ liệu giả để bấm thử mọi tính năng. Không trộn với dữ liệu thật, không kết nối được tài khoản sàn hay dịch vụ thật." :back="{ to: '/settings', label: 'Cấu hình' }" />

    <Card class="max-w-2xl" data-testid="demo-store">
        <div class="flex items-start gap-4 p-5">
            <span class="grid size-11 shrink-0 place-items-center rounded-full bg-amber-50 text-amber-600"><Sparkles class="size-5" :stroke-width="1.75" /></span>
            <div v-if="demo" class="min-w-0 flex-1">
                <p class="font-semibold">{{ demo.name }} <Badge :tone="demo.demo_status === 'failed' ? 'danger' : demo.demo_status === 'preparing' ? 'warning' : 'success'" class="ml-1">{{ statusLabel }}</Badge></p>
                <p class="mt-1 text-sm text-muted-foreground">Tự xóa ngày {{ formatDate(demo.demo_expires_at) }} (còn {{ demoDaysLeft(demo.demo_expires_at) }} ngày). Chuyển qua lại bằng ô chọn doanh nghiệp ở góc trên bên trái.</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <Button v-if="tenant.current?.id !== demo.id" :loading="busy" @click="open">Mở cửa hàng mẫu</Button>
                    <Button variant="outline" @click="confirmOpen = true">Xóa cửa hàng mẫu</Button>
                </div>
            </div>
            <div v-else class="min-w-0 flex-1">
                <p class="font-semibold">Bạn chưa có cửa hàng mẫu</p>
                <p class="mt-1 text-sm text-muted-foreground">Gồm sản phẩm, tồn kho, khách hàng, nhà cung cấp, đơn bán 60 ngày và sổ quỹ. Tự xóa sau 30 ngày.</p>
                <Button class="mt-4" :loading="busy" data-testid="demo-create" @click="create">Tạo cửa hàng mẫu</Button>
            </div>
        </div>
    </Card>

    <Dialog v-model:open="confirmOpen" title="Xóa Cửa hàng mẫu?">
        <p class="text-sm leading-relaxed text-slate-700">Toàn bộ dữ liệu giả bị xóa hẳn. Cửa hàng thật của bạn không bị ảnh hưởng.</p>
        <template #footer>
            <Button variant="outline" @click="confirmOpen = false">Giữ lại</Button>
            <Button variant="destructive" :loading="busy" @click="remove">Xóa cửa hàng mẫu</Button>
        </template>
    </Dialog>
</template>
