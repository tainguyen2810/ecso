import { ref } from 'vue';
import { api } from '@/shared/lib/api';

/**
 * "Tin nhắn" (EzChat inbox inside this app): whether it can open for this business and the person signed in,
 * and the menu badge (chats with unread messages). Shared by the menu, the phone menu and the page itself.
 */
export const inboxAvailable = ref(false);
export const inboxUnread = ref<number | null>(null);

let timer: ReturnType<typeof setInterval> | undefined;
let loadedFor: string | null = null;

export async function refreshInbox(): Promise<void> {
    try {
        const { data } = await api.get('/ezchat-inbox');
        inboxAvailable.value = !!data.data.available;
        inboxUnread.value = data.data.unread ?? null;
    } catch {
        // The menu works without it.
    }
}

/** Starts (or restarts, on another business) the badge: now, every minute and when the tab comes back. */
export function watchInbox(tenantId: string | undefined): void {
    if (!tenantId || loadedFor === tenantId) return;
    loadedFor = tenantId;
    inboxAvailable.value = false;
    inboxUnread.value = null;
    void refreshInbox();
    clearInterval(timer);
    timer = setInterval(() => {
        if (document.visibilityState === 'visible') void refreshInbox();
    }, 60_000);
}

if (typeof document !== 'undefined') {
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && loadedFor) void refreshInbox();
    });
}

export const unreadLabel = (n: number | null) => (n === null || n <= 0 ? '' : n > 99 ? '99+' : String(n));
