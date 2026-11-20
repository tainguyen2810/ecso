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
/** Đợt 17: "Đăng nhập bằng EzChat" when the server has it on (see app.blade.php). */
const ezchatSso = typeof document !== 'undefined' && !!document.querySelector('meta[name="ezchat-sso"]');
const ssoError = (route.query.sso_error as string | undefined) ?? null;
const ssoHref = `/sso/ezchat/start?redirect=${encodeURIComponent((route.query.redirect as string | undefined) ?? '/')}`;
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
        <template v-if="ezchatSso">
            <a :href="ssoHref" class="flex h-10 w-full items-center justify-center gap-2 rounded-md border bg-white text-sm font-medium shadow-xs transition hover:bg-slate-50" data-testid="ezchat-sso">
                <span class="grid size-5 place-items-center rounded bg-gradient-to-br from-fuchsia-500 to-violet-600 text-[11px] font-bold text-white">Ez</span>
                Đăng nhập bằng EzChat
            </a>
            <p v-if="ssoError" class="mt-2 text-sm text-destructive" data-testid="ezchat-sso-error">{{ ssoError }}</p>
            <div class="my-5 flex items-center gap-3 text-xs text-muted-foreground"><span class="h-px flex-1 bg-border" />hoặc dùng email<span class="h-px flex-1 bg-border" /></div>
        </template>
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
