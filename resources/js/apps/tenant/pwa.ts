import { ref } from 'vue';

/**
 * "Add to home screen" (B6). Chrome and Edge fire `beforeinstallprompt` once, early: it is caught here at import so
 * the menu and the banner can offer the install later. iPhone Safari has no such event; the menu shows the two
 * manual steps instead.
 */
type InstallEvent = Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }> };

const deferred = ref<InstallEvent | null>(null);
const canInstall = ref(false);
const standalone = ref(isStandalone());
const isIos = ref(typeof navigator !== 'undefined' && /iphone|ipad|ipod/i.test(navigator.userAgent));

function isStandalone(): boolean {
    if (typeof window === 'undefined') return false;
    return window.matchMedia?.('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true;
}

if (typeof window !== 'undefined') {
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferred.value = e as InstallEvent;
        canInstall.value = true;
    });
    window.addEventListener('appinstalled', () => {
        deferred.value = null;
        canInstall.value = false;
        standalone.value = true;
    });
}

async function install(): Promise<boolean> {
    const e = deferred.value;
    if (!e) return false;
    await e.prompt();
    const { outcome } = await e.userChoice;
    deferred.value = null;
    canInstall.value = false;
    return outcome === 'accepted';
}

/** The banner waits 30 days after "Để sau". */
export const BANNER_SNOOZE_MS = 30 * 24 * 3600 * 1000;
const snoozeKey = 'ecso.pwa.snoozed-at';

export function bannerSnoozed(now = Date.now(), read: () => string | null = () => localStorage.getItem(snoozeKey)): boolean {
    try {
        const at = Number(read() ?? 0);
        return at > 0 && now - at < BANNER_SNOOZE_MS;
    } catch {
        return false;
    }
}

export function snoozeBanner(): void {
    try {
        localStorage.setItem(snoozeKey, String(Date.now()));
    } catch {
        // Private mode: asked again next visit.
    }
}

export function usePwaInstall() {
    return { canInstall, isIos, standalone, install };
}

/** Registers the service worker that makes the app installable (no offline cache yet). */
export function registerServiceWorker(): void {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator) || !window.isSecureContext) return;
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}
