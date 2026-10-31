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

const form = reactive({ email: '', password: '', remember: true });
const loading = ref(false);
const error = ref<ApiError | null>(null);

async function submit() {
    loading.value = true;
    error.value = null;
    try {
        const user = await auth.login(form.email, form.password, form.remember);
        const redirect = (route.query.redirect as string | undefined) ?? (user.is_super_admin ? '/admin' : '/');
        // The admin console is a separate SPA, so leave this app with a full navigation.
        if (redirect.startsWith('/admin')) window.location.href = redirect;
        else router.push(redirect);
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <AuthLayout title="Đăng nhập" subtitle="Chào mừng trở lại. Đăng nhập để quản lý cửa hàng của bạn.">
        <form class="space-y-4" @submit.prevent="submit">
            <FormField label="Email" for="email" :error="error?.field('email')">
                <Input id="email" v-model="form.email" type="email" autocomplete="email" required autofocus :invalid="!!error?.field('email')" />
            </FormField>
            <FormField label="Mật khẩu" for="password" :error="error?.field('password')">
                <Input id="password" v-model="form.password" type="password" autocomplete="current-password" required />
            </FormField>
            <div class="flex items-center justify-between text-sm">
                <label class="flex items-center gap-2"><input v-model="form.remember" type="checkbox" class="rounded border-input" /> Ghi nhớ</label>
                <RouterLink to="/forgot-password" class="text-primary hover:underline">Quên mật khẩu?</RouterLink>
            </div>
            <p v-if="error && !error.body.errors" class="text-sm text-destructive">{{ error.message }}</p>
            <Button type="submit" class="w-full" :loading="loading">Đăng nhập</Button>
        </form>
        <p class="mt-6 text-center text-sm text-muted-foreground">
            Chưa có tài khoản? <RouterLink to="/register" class="font-medium text-primary hover:underline">Đăng ký</RouterLink>
        </p>
    </AuthLayout>
</template>
