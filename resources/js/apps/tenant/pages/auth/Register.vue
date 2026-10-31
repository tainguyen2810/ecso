<script setup lang="ts">
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AuthLayout from '../../layouts/AuthLayout.vue';
import Button from '@/shared/ui/Button.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import { ApiError } from '@/shared/lib/api';
import { useAuthStore } from '@/shared/stores/auth';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const form = reactive({ name: '', email: (route.query.email as string) ?? '', password: '', password_confirmation: '' });
const loading = ref(false);
const error = ref<ApiError | null>(null);

async function submit() {
    loading.value = true;
    error.value = null;
    try {
        await auth.register(form);
        router.push((route.query.redirect as string) ?? { name: 'verify-email' });
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <AuthLayout title="Tạo tài khoản" subtitle="Bắt đầu miễn phí, không cần thẻ thanh toán.">
        <form class="space-y-4" @submit.prevent="submit">
            <FormField label="Họ và tên" for="name" :error="error?.field('name')">
                <Input id="name" v-model="form.name" autocomplete="name" required autofocus />
            </FormField>
            <FormField label="Email" for="email" :error="error?.field('email')">
                <Input id="email" v-model="form.email" type="email" autocomplete="email" required :invalid="!!error?.field('email')" />
            </FormField>
            <FormField label="Mật khẩu" for="password" :error="error?.field('password')" hint="Tối thiểu 8 ký tự, có chữ và số.">
                <Input id="password" v-model="form.password" type="password" autocomplete="new-password" required :invalid="!!error?.field('password')" />
            </FormField>
            <FormField label="Nhập lại mật khẩu" for="password_confirmation">
                <Input id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" required />
            </FormField>
            <Button type="submit" class="w-full" :loading="loading">Tạo tài khoản</Button>
        </form>
        <p class="mt-6 text-center text-sm text-muted-foreground">
            Đã có tài khoản? <RouterLink to="/login" class="font-medium text-primary hover:underline">Đăng nhập</RouterLink>
        </p>
    </AuthLayout>
</template>
