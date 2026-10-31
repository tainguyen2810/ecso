import { onBeforeUnmount, onMounted } from 'vue';

/**
 * Two-key "g then x" navigation shortcuts (like GitHub/Linear). Ignored while typing in inputs.
 */
export function useGoShortcuts(map: Record<string, () => void>) {
    let armed = false;
    let timer: ReturnType<typeof setTimeout> | undefined;

    function handler(e: KeyboardEvent) {
        const target = e.target as HTMLElement | null;
        if (e.metaKey || e.ctrlKey || e.altKey || target?.closest('input, textarea, select, [contenteditable]')) return;

        if (armed && map[e.key]) {
            e.preventDefault();
            armed = false;
            map[e.key]!();
            return;
        }
        armed = e.key === 'g';
        clearTimeout(timer);
        if (armed) timer = setTimeout(() => (armed = false), 1000);
    }

    onMounted(() => window.addEventListener('keydown', handler));
    onBeforeUnmount(() => window.removeEventListener('keydown', handler));
}
