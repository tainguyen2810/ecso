import '../../../css/app.css';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { router } from './router';
import { setUnauthorizedHandler } from '@/shared/lib/api';
import { useAuthStore } from '@/shared/stores/auth';

const app = createApp(App).use(createPinia()).use(router);

setUnauthorizedHandler(() => {
    const auth = useAuthStore();
    auth.user = null;
    if (!router.currentRoute.value.meta.guest) router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } });
});

app.mount('#app');
