import "../../../css/app.css";
import { createApp } from "vue";
import { createPinia } from "pinia";
import App from "./App.vue";
import { router } from "./router";
import { setUnauthorizedHandler } from "@/shared/lib/api";
import { useAuthStore } from "@/shared/stores/auth";
import { registerServiceWorker } from "./pwa";

const app = createApp(App).use(createPinia()).use(router);
// Every form takes the Tailwind look (44px fields, 10px corners); see shared/ui/soft.ts.
app.provide("soft-fields", true);

setUnauthorizedHandler(() => {
    const auth = useAuthStore();
    auth.user = null;
    if (!router.currentRoute.value.meta.guest)
        router.push({
            name: "login",
            query: { redirect: router.currentRoute.value.fullPath },
        });
});

app.mount("#app");
registerServiceWorker();
