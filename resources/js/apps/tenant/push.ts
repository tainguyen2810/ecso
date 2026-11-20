import { ref } from 'vue';
import { api } from '@/shared/lib/api';

/**
 * "Báo đơn mới trên máy này" (Đợt 8): Web Push for the store's new orders. On iPhone it works only once the app is
 * added to the home screen (iOS 16.4+); Android Chrome and computers work in the browser too.
 */
export type PushState = 'unsupported' | 'needs-install' | 'denied' | 'off' | 'on';

const state = ref<PushState>('off');
const busy = ref(false);
/** "Bản tin 8h sáng" for this phone (Đợt 15). */
const brief = ref(true);

function supported(): boolean {
    return typeof window !== 'undefined' && window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

function iosOutsideApp(): boolean {
    const ios = /iphone|ipad|ipod/i.test(navigator.userAgent);
    const standalone = window.matchMedia?.('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true;
    return ios && !standalone;
}

/** The application server key as the bytes PushManager wants. */
export function keyBytes(base64url: string): Uint8Array {
    const b64 = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(b64);
    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
}

async function current(): Promise<PushSubscription | null> {
    const reg = await navigator.serviceWorker.getRegistration('/');
    return reg ? reg.pushManager.getSubscription() : null;
}

async function refresh(): Promise<void> {
    if (!supported()) {
        state.value = typeof navigator !== 'undefined' && /iphone|ipad|ipod/i.test(navigator.userAgent) ? 'needs-install' : 'unsupported';
        return;
    }
    if (iosOutsideApp()) {
        state.value = 'needs-install';
        return;
    }
    if (Notification.permission === 'denied') {
        state.value = 'denied';
        return;
    }
    const sub = await current().catch(() => null);
    if (!sub) {
        state.value = 'off';
        return;
    }
    const { data } = await api.get('/push', { params: { endpoint: sub.endpoint } });
    state.value = data.data.subscribed ? 'on' : 'off';
    brief.value = data.data.morning_brief ?? true;
}

async function enable(): Promise<void> {
    busy.value = true;
    try {
        if ((await Notification.requestPermission()) !== 'granted') {
            state.value = Notification.permission === 'denied' ? 'denied' : 'off';
            return;
        }
        const reg = (await navigator.serviceWorker.getRegistration('/')) ?? (await navigator.serviceWorker.register('/sw.js'));
        await navigator.serviceWorker.ready;
        const { data } = await api.get('/push');
        const sub = (await reg.pushManager.getSubscription())
            ?? (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(data.data.public_key) as BufferSource }));
        await api.post('/push/subscriptions', { endpoint: sub.endpoint, device: navigator.userAgent.slice(0, 300) });
        state.value = 'on';
    } finally {
        busy.value = false;
    }
}

async function disable(): Promise<void> {
    busy.value = true;
    try {
        const sub = await current();
        if (sub) {
            await api.delete('/push/subscriptions', { data: { endpoint: sub.endpoint } });
            await sub.unsubscribe().catch(() => false);
        }
        state.value = 'off';
    } finally {
        busy.value = false;
    }
}

async function setBrief(on: boolean): Promise<void> {
    const sub = await current();
    if (!sub) return;
    await api.patch('/push/subscriptions', { endpoint: sub.endpoint, morning_brief: on });
    brief.value = on;
}

async function test(): Promise<void> {
    const sub = await current();
    if (sub) await api.post('/push/test', { endpoint: sub.endpoint });
}

export function usePush() {
    return { state, busy, brief, refresh, enable, disable, setBrief, test };
}
