<script setup>
import { onBeforeUnmount, ref } from 'vue';
import HoverCard from './HoverCard.vue';

/**
 * A small "?" that explains the thing beside it, so the page itself can stay short.
 * The explanation shows on hover or keyboard focus, and on tap for phones; Escape or a tap
 * elsewhere hides it. `label` names what it explains, for screen readers.
 */
defineProps({ label: { type: String, default: 'What this means' }, width: { type: Number, default: 280 } });

const btn = ref(null);
const anchor = ref(null);
let pinned = false;

const show = () => (anchor.value = btn.value?.getBoundingClientRect() ?? null);
function hide() {
    if (!pinned) anchor.value = null;
}
function onDocument(e) {
    if (btn.value && !btn.value.contains(e.target)) close();
}
function onKey(e) {
    if (e.key === 'Escape') close();
}
function close() {
    pinned = false;
    anchor.value = null;
    document.removeEventListener('pointerdown', onDocument);
    document.removeEventListener('keydown', onKey);
}
function tap() {
    if (pinned) return close();
    pinned = true;
    show();
    document.addEventListener('pointerdown', onDocument);
    document.addEventListener('keydown', onKey);
}
onBeforeUnmount(close);
</script>

<template>
    <button
        ref="btn"
        type="button"
        class="inline-grid h-[18px] w-[18px] shrink-0 cursor-help place-items-center rounded-full border border-current text-[11px] leading-none font-[700] opacity-55 transition-opacity hover:opacity-100 focus-visible:opacity-100"
        :aria-label="label"
        :aria-expanded="!!anchor"
        @mouseenter="show"
        @mouseleave="hide"
        @focus="show"
        @blur="hide"
        @click.stop="tap"
    >?</button>
    <HoverCard :anchor="anchor" :width="width"><slot /></HoverCard>
</template>
