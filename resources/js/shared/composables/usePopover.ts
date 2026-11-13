import { onBeforeUnmount, ref, type Ref } from 'vue';

/**
 * A panel under an anchor, drawn in <body> (Teleport) so a dialog or a scrolling card does not clip it. It opens
 * below the anchor, or above when there is no room, and closes on a click outside or Esc.
 */
export function usePopover(anchor: Ref<HTMLElement | null>, panel: Ref<HTMLElement | null>, onClose?: () => void) {
    const open = ref(false);
    const style = ref<Record<string, string>>({});

    function place() {
        const a = anchor.value?.getBoundingClientRect();
        if (!a) return;
        const height = panel.value?.offsetHeight ?? 360;
        const width = panel.value?.offsetWidth ?? 280;
        const below = window.innerHeight - a.bottom;
        const top = below < height + 12 && a.top > height + 12 ? a.top - height - 6 : a.bottom + 6;
        const left = Math.max(8, Math.min(a.left, window.innerWidth - width - 8));
        style.value = { position: 'fixed', top: `${Math.round(top)}px`, left: `${Math.round(left)}px` };
    }

    function onDown(e: MouseEvent) {
        const t = e.target as Node;
        if (anchor.value?.contains(t) || panel.value?.contains(t)) return;
        close();
    }
    function onKey(e: KeyboardEvent) {
        if (e.key === 'Escape') close();
    }

    function show() {
        if (open.value) return;
        open.value = true;
        place();
        requestAnimationFrame(place);
        document.addEventListener('mousedown', onDown, true);
        document.addEventListener('keydown', onKey);
        window.addEventListener('scroll', place, true);
        window.addEventListener('resize', place);
    }
    function close() {
        if (!open.value) return;
        open.value = false;
        detach();
        onClose?.();
    }
    function detach() {
        document.removeEventListener('mousedown', onDown, true);
        document.removeEventListener('keydown', onKey);
        window.removeEventListener('scroll', place, true);
        window.removeEventListener('resize', place);
    }
    onBeforeUnmount(detach);

    return { open, style, show, close, place };
}
