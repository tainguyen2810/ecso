import { onBeforeUnmount, ref, watch, type Ref } from 'vue';

/**
 * Live pixel width of an element, so an SVG chart can draw at its real size: text stays at its own font size and the
 * chart keeps a fixed height instead of growing with the screen width. Works for elements that appear later (v-if).
 */
export function useElementWidth(el: Ref<HTMLElement | null>, fallback = 900) {
    const width = ref(fallback);
    const ro =
        typeof ResizeObserver === 'undefined'
            ? null
            : new ResizeObserver(([e]) => {
                  const w = Math.round(e?.contentRect.width ?? 0);
                  if (w > 0) width.value = w;
              });
    watch(
        el,
        (now, before) => {
            if (before) ro?.unobserve(before);
            if (now) ro?.observe(now);
        },
        { immediate: true, flush: 'post' },
    );
    onBeforeUnmount(() => ro?.disconnect());
    return width;
}
