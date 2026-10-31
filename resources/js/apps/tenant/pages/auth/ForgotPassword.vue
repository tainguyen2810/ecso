<script setup lang="ts">
import { ref } from 'vue';
import { MailCheck } from 'lucide-vue-next';
import AuthLayout from '../../layouts/AuthLayout.vue';
import Button from '@/shared/ui/Button.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import { api, ApiError, ensureCsrf } from '@/shared/lib/api';

const email = ref('');
const loading = ref(false);
const sent = ref(false);
const error = ref<ApiError | null>(null);

async function submit() {
    loading.value = true;
    error.value = null;
    try {
        await ensureCsrf();
        await api.post('/auth/forgot-password', { email: email.value });
        sent.value = true;
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <AuthLayout title="Quên mật khẩu" subtitle="Nhập email, chúng tôi sẽ gửi liên kết đặt lại mật khẩu.">
        <div v-if="sent" class="rounded-lg border bg-card p-5 text-sm">
            <MailCheck class="mb-2 size-6 text-success" />
            Nếu <strong>{{ email }}</strong> có tài khoản, bạn sẽ nhận được email hướng dẫn trong vài phút.
        </div>
        <form v-else class="space-y-4" @submit.prevent="submit">
            <FormField label="Email" for="email" :error="error?.field('email') ?? (error && !error.body.errors ? error.message : undefined)">
                <Input id="email" v-model="email" type="email" required autofocus />
            </FormField>
            <Button type="submit" class="w-full" :loading="loading">Gửi liên kết</Button>
        </form>
        <p class="mt-6 text-center text-sm"><RouterLink to="/login" class="text-primary hover:underline">Quay lại đăng nhập</RouterLink></p>
    </AuthLayout>
</template>
