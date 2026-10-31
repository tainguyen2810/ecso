<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { Building2, ChevronRight, Plus } from 'lucide-vue-next';
import AuthLayout from '../layouts/AuthLayout.vue';
import Button from '@/shared/ui/Button.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { ApiError } from '@/shared/lib/api';
import { initials } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';

const tenant = useTenantStore();
const router = useRouter();
const toast = useToast();

const loading = ref(true);
const creating = ref(false);
const showForm = ref(false);
const name = ref('');
const error = ref<ApiError | null>(null);

onMounted(async () => {
    try {
        await tenant.fetchTenants();
        showForm.value = tenant.tenants.length === 0;
    } finally {
        loading.value = false;
    }
});

async function open(id: string) {
    await tenant.select(id);
    router.push('/');
}

async function create() {
    creating.value = true;
    error.value = null;
    try {
        const created = await tenant.create(name.value);
        toast.success(`Đã tạo ${created.name}`);
        router.push('/');
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        creating.value = false;
    }
}

const roleNames: Record<string, string> = {
    TENANT_OWNER: 'Chủ doanh nghiệp',
    TENANT_ADMIN: 'Quản trị',
    MANAGER: 'Quản lý',
    WAREHOUSE_STAFF: 'Nhân viên kho',
    ORDER_STAFF: 'Nhân viên đơn',
    ACCOUNTANT: 'Kế toán',
    VIEWER: 'Chỉ xem',
};
</script>

<template>
    <AuthLayout
        :title="showForm && !tenant.tenants.length ? 'Tạo doanh nghiệp đầu tiên' : 'Chọn doanh nghiệp'"
        :subtitle="showForm && !tenant.tenants.length ? 'Mỗi doanh nghiệp có shop, kho, nhân viên và dữ liệu riêng biệt.' : 'Bạn có thể chuyển đổi bất cứ lúc nào.'"
    >
        <div v-if="loading" class="space-y-2">
            <Skeleton v-for="i in 2" :key="i" class="h-16" />
        </div>
        <template v-else>
            <div v-if="tenant.tenants.length" class="mb-4 space-y-2">
                <button
                    v-for="t in tenant.tenants"
                    :key="t.id"
                    class="flex w-full items-center gap-3 rounded-lg border bg-card p-3 text-left shadow-xs transition hover:border-primary/40 hover:shadow-sm"
                    @click="open(t.id)"
                >
                    <span class="flex size-10 items-center justify-center rounded-md bg-primary/10 text-sm font-semibold text-primary">{{ initials(t.name) }}</span>
                    <span class="flex-1">
                        <span class="block text-sm font-semibold">{{ t.name }}</span>
                        <span class="block text-xs text-muted-foreground">{{ roleNames[t.role ?? ''] ?? t.role }}</span>
                    </span>
                    <ChevronRight class="size-4 text-muted-foreground" />
                </button>
            </div>

            <form v-if="showForm" class="space-y-4 rounded-lg border bg-card p-5" @submit.prevent="create">
                <div class="flex items-center gap-2 text-sm font-medium"><Building2 class="size-4 text-primary" /> Doanh nghiệp mới</div>
                <FormField label="Tên doanh nghiệp" for="tenant-name" :error="error?.field('name')">
                    <Input id="tenant-name" v-model="name" placeholder="Ví dụ: Cửa hàng Hoa Mai" required autofocus />
                </FormField>
                <Button type="submit" class="w-full" :loading="creating">Tạo doanh nghiệp</Button>
            </form>
            <Button v-else variant="outline" class="w-full" @click="showForm = true"><Plus /> Tạo doanh nghiệp mới</Button>
        </template>
    </AuthLayout>
</template>
