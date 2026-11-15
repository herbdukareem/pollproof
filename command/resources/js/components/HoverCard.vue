<script setup>
import { computed } from 'vue';

/**
 * A small card over the page, beside the thing being pointed at: shown on hover or focus to
 * give detail a table row has no room for. `anchor` is that element's bounding box (or null
 * to hide). It sits below the anchor, or above it near the bottom of the screen.
 */
const props = defineProps({ anchor: { type: Object, default: null }, width: { type: Number, default: 260 } });
const style = computed(() => {
    const r = props.anchor;
    if (!r) return null;
    const w = Math.min(props.width, window.innerWidth - 24);
    const left = Math.max(12, Math.min(window.innerWidth - w - 12, r.left));
    const below = window.innerHeight - r.bottom > 150;
    return below
        ? { top: `${r.bottom + 6}px`, left: `${left}px`, width: `${w}px` }
        : { top: `${r.top - 6}px`, left: `${left}px`, width: `${w}px`, transform: 'translateY(-100%)' };
});
</script>

<template>
    <Teleport to="body">
        <Transition name="pop">
            <div v-if="anchor" class="float pointer-events-none fixed z-[57] px-3.5 py-3 text-[13px]" :style="style" role="tooltip">
                <slot />
            </div>
        </Transition>
    </Teleport>
</template>
