<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { MailOpen } from 'lucide-vue-next';
import AuthLayout from '../../layouts/AuthLayout.vue';
import Button from '@/shared/ui/Button.vue';
import { api, ApiError } from '@/shared/lib/api';
import { useAuthStore } from '@/shared/stores/auth';
import { useToast } from '@/shared/composables/useToast';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const toast = useToast();

const verifying = ref(false);
const resending = ref(false);
const error = ref<string | null>(null);

onMounted(async () => {
    const signed = route.query.url as string | undefined;
    if (auth.user?.email_verified) return router.replace('/');
    if (!signed) return;

    // Only follow signed links that point at our own verification endpoint.
    const url = new URL(signed, window.location.origin);
    if (url.origin !== window.location.origin || !url.pathname.startsWith('/api/v1/auth/email/verify/')) {
        error.value = 'Liên kết xác minh không hợp lệ.';
        return;
    }

    verifying.value = true;
    try {
        await api.get(url.pathname.replace('/api/v1', '') + url.search);
        await auth.fetchMe();
        toast.success('Email đã được xác minh.');
        router.replace('/');
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        verifying.value = false;
    }
});

async function resend() {
    resending.value = true;
    try {
        await api.post('/auth/email/verification-notification');
        toast.success('Đã gửi lại email xác minh.');
    } catch (e) {
        toast.error((e as ApiError).message);
    } finally {
        resending.value = false;
    }
}
</script>

<template>
    <AuthLayout title="Xác minh email" :subtitle="verifying ? 'Đang xác minh...' : undefined">
        <div class="rounded-lg border bg-card p-5 text-sm">
            <MailOpen class="mb-3 size-6 text-primary" />
            <p>
                Chúng tôi đã gửi liên kết xác minh tới <strong>{{ auth.user?.email }}</strong>. Mở email và bấm vào liên kết để tiếp tục.
            </p>
            <p v-if="error" class="mt-3 text-destructive">{{ error }}</p>
        </div>
        <div class="mt-4 flex gap-2">
            <Button variant="outline" class="flex-1" :loading="resending" @click="resend">Gửi lại email</Button>
            <Button variant="ghost" @click="auth.logout().then(() => router.push('/login'))">Đăng xuất</Button>
        </div>
    </AuthLayout>
</template>
