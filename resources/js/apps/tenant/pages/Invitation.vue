<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { MailWarning, UserPlus } from 'lucide-vue-next';
import AuthLayout from '../layouts/AuthLayout.vue';
import Button from '@/shared/ui/Button.vue';
import Skeleton from '@/shared/ui/Skeleton.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useAuthStore } from '@/shared/stores/auth';
import { useTenantStore } from '@/shared/stores/tenant';
import { useToast } from '@/shared/composables/useToast';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const tenant = useTenantStore();
const toast = useToast();
const token = route.params.token as string;

const invite = ref<{ tenant: string; email: string; role: string } | null>(null);
const loading = ref(true);
const accepting = ref(false);
const error = ref<string | null>(null);

onMounted(async () => {
    if (!auth.loaded) await auth.fetchMe();
    try {
        invite.value = (await api.get(`/invitations/${token}`)).data.data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
});

async function accept() {
    accepting.value = true;
    try {
        const { data } = await api.post(`/invitations/${token}/accept`);
        await tenant.fetchTenants();
        await tenant.select(data.data.id);
        toast.success(`Bạn đã tham gia ${data.data.name}`);
        router.push('/');
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        accepting.value = false;
    }
}
</script>

<template>
    <AuthLayout title="Lời mời tham gia">
        <Skeleton v-if="loading" class="h-32" />
        <div v-else-if="error && !invite" class="rounded-lg border bg-card p-5 text-sm">
            <MailWarning class="mb-2 size-6 text-destructive" />{{ error }}
        </div>
        <div v-else-if="invite" class="space-y-4">
            <div class="rounded-lg border bg-card p-5 text-sm">
                <UserPlus class="mb-3 size-6 text-primary" />
                Bạn được mời tham gia <strong>{{ invite.tenant }}</strong> với vai trò <strong>{{ invite.role }}</strong>.
                <p class="mt-2 text-muted-foreground">Lời mời dành cho {{ invite.email }}.</p>
            </div>
            <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
            <template v-if="auth.user && !auth.user.email_verified">
                <p class="text-sm text-muted-foreground">Bạn cần xác minh email trước khi tham gia. Sau khi xác minh, mở lại liên kết lời mời này.</p>
                <Button class="w-full" @click="router.push({ name: 'verify-email' })">Xác minh email</Button>
            </template>
            <template v-else-if="auth.user">
                <p v-if="auth.user.email.toLowerCase() !== invite.email" class="text-sm text-destructive">
                    Bạn đang đăng nhập bằng {{ auth.user.email }}. Hãy đăng nhập đúng email được mời.
                </p>
                <Button class="w-full" :loading="accepting" :disabled="auth.user.email.toLowerCase() !== invite.email" @click="accept">Chấp nhận lời mời</Button>
            </template>
            <div v-else class="flex gap-2">
                <Button class="flex-1" @click="router.push({ name: 'login', query: { redirect: route.fullPath } })">Đăng nhập</Button>
                <Button variant="outline" class="flex-1" @click="router.push({ name: 'register', query: { redirect: route.fullPath, email: invite.email } })">Tạo tài khoản</Button>
            </div>
        </div>
    </AuthLayout>
</template>
