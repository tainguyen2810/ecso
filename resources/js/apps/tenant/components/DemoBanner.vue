<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Loader2, TriangleAlert } from 'lucide-vue-next';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import { formatDate } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';
import { ApiError } from '@/shared/lib/api';

/**
 * A2: shown on every page of a sample store. While the fake data is being added it says so and checks again every
 * few seconds; then it offers the way back to the real store and deleting the sample store.
 */
const tenant = useTenantStore();
const router = useRouter();
const toast = useToast();
const confirmOpen = ref(false);
const deleting = ref(false);

const demo = computed(() => (tenant.current?.is_demo ? tenant.current : null));
const realStore = computed(() => tenant.tenants.find((t) => !t.is_demo) ?? null);

let timer: ReturnType<typeof setTimeout> | null = null;
function poll() {
    if (timer) clearTimeout(timer);
    if (demo.value?.demo_status !== 'preparing') return;
    timer = setTimeout(async () => {
        await tenant.refresh().catch(() => undefined);
        if (tenant.current?.demo_status === 'ready') {
            toast.success('Dữ liệu mẫu đã sẵn sàng.');
            router.go(0);
        }
        poll();
    }, 4000);
}
watch(() => demo.value?.demo_status, poll, { immediate: true });
onBeforeUnmount(() => timer && clearTimeout(timer));

async function backToReal() {
    if (!realStore.value) return router.push('/tenants');
    await tenant.select(realStore.value.id);
    router.push('/');
}

async function remove() {
    if (!demo.value) return;
    deleting.value = true;
    try {
        const id = demo.value.id;
        const back = realStore.value;
        await tenant.deleteDemo(id);
        confirmOpen.value = false;
        toast.success('Đã xóa cửa hàng mẫu.');
        if (back) {
            await tenant.select(back.id);
            router.push('/');
        } else {
            tenant.reset();
            router.push('/tenants');
        }
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        deleting.value = false;
    }
}
</script>

<template>
    <div v-if="demo" class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-900 lg:px-8" data-testid="demo-banner">
        <Loader2 v-if="demo.demo_status === 'preparing'" class="size-4 shrink-0 animate-spin" />
        <TriangleAlert v-else class="size-4 shrink-0" />
        <span class="min-w-0 flex-1">
            <b>Bạn đang xem Cửa hàng mẫu.</b>
            <template v-if="demo.demo_status === 'preparing'"> Đang thêm dữ liệu mẫu (khoảng 1 phút)…</template>
            <template v-else-if="demo.demo_status === 'failed'"> Thêm dữ liệu mẫu bị lỗi. Xóa cửa hàng mẫu rồi tạo lại.</template>
            <template v-else> Dữ liệu giả để bấm thử, không ảnh hưởng cửa hàng thật.<span v-if="demo.demo_expires_at"> Tự xóa ngày {{ formatDate(demo.demo_expires_at) }}.</span></template>
        </span>
        <span class="flex gap-2">
            <Button v-if="realStore" variant="outline" size="sm" data-testid="demo-back" @click="backToReal">Về {{ realStore.name }}</Button>
            <Button variant="outline" size="sm" data-testid="demo-delete" @click="confirmOpen = true">Xóa cửa hàng mẫu</Button>
        </span>
        <Dialog v-model:open="confirmOpen" title="Xóa Cửa hàng mẫu?">
            <p class="text-sm leading-relaxed text-slate-700">
                Toàn bộ dữ liệu giả (đơn hàng, sản phẩm, khách, sổ quỹ) bị xóa hẳn.
                <template v-if="realStore"><b>{{ realStore.name }}</b> không bị ảnh hưởng.</template>
            </p>
            <p class="mt-3 text-sm text-slate-700">Muốn xem thử lại thì tạo cửa hàng mẫu mới ở <b>Cấu hình → Cửa hàng mẫu</b>.</p>
            <template #footer>
                <Button variant="outline" @click="confirmOpen = false">Giữ lại</Button>
                <Button variant="destructive" :loading="deleting" data-testid="demo-delete-confirm" @click="remove">Xóa cửa hàng mẫu</Button>
            </template>
        </Dialog>
    </div>
</template>
