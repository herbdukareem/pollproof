<script setup>
/**
 * A party's logo, or (until one is uploaded under Admin, Campaign) a round mark in the party's
 * colour with its code. `size` is in pixels.
 */
defineProps({
    code: { type: String, required: true },
    name: { type: String, default: '' },
    logo: { type: String, default: null },
    colour: { type: String, default: '#a3abb8' },
    size: { type: Number, default: 20 },
});
</script>

<template>
    <img v-if="logo" :src="logo" :alt="`${name || code} logo`" class="shrink-0 rounded-full border border-line bg-white object-contain" :style="{ width: `${size}px`, height: `${size}px` }" loading="lazy" />
    <span
        v-else
        class="grid shrink-0 place-items-center rounded-full font-[750] text-white"
        :style="{ width: `${size}px`, height: `${size}px`, background: colour, fontSize: `${Math.max(8, Math.round(size * (code.length > 2 ? 0.3 : 0.42)))}px` }"
        aria-hidden="true"
    >{{ code.slice(0, 4) }}</span>
</template>
