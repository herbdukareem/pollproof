<script setup>
defineProps({ size: { type: Number, default: 28 }, pin: { type: String, default: '#FFFFFF' }, check: { type: String, default: '#E8590C' } });
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 64 64" aria-hidden="true">
        <path d="M32 4C19.3 4 9 14.1 9 26.6C9 42 32 60 32 60C32 60 55 42 55 26.6C55 14.1 44.7 4 32 4Z" :fill="pin" />
        <path d="M21 27L29 35L44 19" fill="none" :stroke="check" stroke-width="6.5" stroke-linecap="round" stroke-linejoin="round" />
    </svg>
</template>
