import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

/**
 * A panel that drops from a top-bar button. It is drawn over the page (teleported to the body)
 * with its width in pixels, right-aligned to the button and kept 12 px inside the screen, so it
 * never squeezes into the header's flex layout or runs off the left edge of a phone.
 * Escape closes it and returns focus to the button.
 */
export function usePopover(width) {
    const open = ref(false);
    const anchor = ref(null);
    const style = ref({});

    function place() {
        const r = anchor.value?.getBoundingClientRect();
        if (!r) return;
        const w = Math.min(width, window.innerWidth - 24);
        const left = Math.max(12, Math.min(window.innerWidth - w - 12, r.right - w));
        style.value = { top: `${r.bottom + 8}px`, left: `${left}px`, width: `${w}px`, maxHeight: `${window.innerHeight - r.bottom - 20}px` };
    }
    function onKey(e) {
        if (e.key === 'Escape') {
            open.value = false;
            anchor.value?.focus();
        }
    }
    function listen(on) {
        const fn = on ? 'addEventListener' : 'removeEventListener';
        window[fn]('resize', place);
        window[fn]('keydown', onKey);
    }
    watch(open, async (o) => {
        listen(o);
        if (o) {
            await nextTick();
            place();
        }
    });
    onBeforeUnmount(() => listen(false));

    return { open, anchor, style, toggle: () => (open.value = !open.value), close: () => (open.value = false) };
}
