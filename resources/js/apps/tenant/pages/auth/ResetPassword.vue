<script setup lang="ts">
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AuthLayout from '../../layouts/AuthLayout.vue';
import Button from '@/shared/ui/Button.vue';
import FormField from '@/shared/ui/FormField.vue';
import Input from '@/shared/ui/Input.vue';
import { api, ApiError, ensureCsrf } from '@/shared/lib/api';
import { useToast } from '@/shared/composables/useToast';

const route = useRoute();
const router = useRouter();
const toast = useToast();

const form = reactive({
    token: (route.query.token as string) ?? '',
    email: (route.query.email as string) ?? '',
    password: '',
    password_confirmation: '',
});
const loading = ref(false);
const error = ref<ApiError | null>(null);

async function submit() {
    loading.value = true;
    error.value = null;
    try {
        await ensureCsrf();
        await api.post('/auth/reset-password', form);
        toast.success('Đã đặt lại mật khẩu. Vui lòng đăng nhập.');
        router.push({ name: 'login' });
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <AuthLayout title="Đặt lại mật khẩu">
        <form class="space-y-4" @submit.prevent="submit">
            <FormField label="Email" for="email" :error="error?.field('email')">
                <Input id="email" v-model="form.email" type="email" required />
            </FormField>
            <FormField label="Mật khẩu mới" for="password" :error="error?.field('password')">
                <Input id="password" v-model="form.password" type="password" autocomplete="new-password" required />
            </FormField>
            <FormField label="Nhập lại mật khẩu" for="password_confirmation">
                <Input id="password_confirmation" v-model="form.password_confirmation" type="password" required />
            </FormField>
            <Button type="submit" class="w-full" :loading="loading">Đặt lại mật khẩu</Button>
        </form>
    </AuthLayout>
</template>
