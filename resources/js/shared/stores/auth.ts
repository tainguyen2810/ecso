import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api, ensureCsrf, setApiTenant } from '@/shared/lib/api';
import type { User } from '@/shared/types';

export const useAuthStore = defineStore('auth', () => {
    const user = ref<User | null>(null);
    const loaded = ref(false);

    async function fetchMe(): Promise<User | null> {
        try {
            const { data } = await api.get('/auth/me');
            user.value = data.data;
        } catch {
            user.value = null;
        } finally {
            loaded.value = true;
        }
        return user.value;
    }

    async function login(email: string, password: string, remember = false): Promise<User> {
        await ensureCsrf();
        const { data } = await api.post('/auth/login', { email, password, remember });
        user.value = data.data;
        return data.data;
    }

    async function register(payload: { name: string; email: string; password: string; password_confirmation: string }): Promise<User> {
        await ensureCsrf();
        const { data } = await api.post('/auth/register', payload);
        user.value = data.data;
        return data.data;
    }

    async function logout(): Promise<void> {
        try {
            await api.post('/auth/logout');
        } finally {
            user.value = null;
            setApiTenant(null);
        }
    }

    return { user, loaded, fetchMe, login, register, logout };
});
