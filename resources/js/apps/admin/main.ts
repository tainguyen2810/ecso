import '../../../css/app.css';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { router } from './router';
import { setUnauthorizedHandler } from '@/shared/lib/api';

setUnauthorizedHandler(() => {
    window.location.href = '/login?redirect=/admin';
});

createApp(App).use(createPinia()).use(router).mount('#app');
