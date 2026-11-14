<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { ChevronRight, Plus } from 'lucide-vue-next';
import AuthLayout from '../layouts/AuthLayout.vue';
import Button from '@/shared/ui/Button.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { initials } from '@/shared/lib/utils';
import { useTenantStore } from '@/shared/stores/tenant';

const tenant = useTenantStore();
const router = useRouter();

const loading = ref(true);

onMounted(async () => {
    try {
        await tenant.fetchTenants();
        // A2: the first business is made in the setup steps.
        if (tenant.tenants.length === 0) router.replace('/setup');
    } finally {
        loading.value = false;
    }
});

async function open(id: string) {
    await tenant.select(id);
    router.push('/');
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
    <AuthLayout title="Chọn doanh nghiệp" subtitle="Bạn có thể chuyển đổi bất cứ lúc nào.">
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
                        <span class="block text-xs text-muted-foreground">{{ t.is_demo ? 'Cửa hàng mẫu · dữ liệu giả' : (roleNames[t.role ?? ''] ?? t.role) }}</span>
                    </span>
                    <ChevronRight class="size-4 text-muted-foreground" />
                </button>
            </div>

            <Button variant="outline" class="w-full" data-testid="new-tenant" @click="router.push('/setup?new=1')"><Plus /> Tạo doanh nghiệp mới</Button>
        </template>
    </AuthLayout>
</template>
