<script setup>
defineProps({ loading: Boolean, error: Object, empty: Boolean, emptyText: { type: String, default: 'Nothing here yet.' } });
</script>

<template>
    <div v-if="error" role="alert" class="rounded-xl bg-bad-bg px-4 py-3 text-sm text-bad">
        <b>Couldn't load this.</b> {{ error.status === 403 ? 'Your role cannot see this.' : error.message }}
    </div>
    <div v-else-if="loading && empty" class="flex flex-col gap-3 p-5" role="status" aria-label="Loading">
        <div class="skeleton h-5 w-1/3"></div>
        <div class="skeleton h-4 w-full"></div>
        <div class="skeleton h-4 w-11/12"></div>
        <div class="skeleton h-4 w-4/5"></div>
        <div class="skeleton h-4 w-2/3"></div>
    </div>
    <div v-else-if="empty" class="fade-in flex flex-col items-center gap-2 px-4 py-12 text-center text-sm text-ink-2">
        <svg width="36" height="36" viewBox="0 0 64 64" aria-hidden="true" class="opacity-25"><path d="M32 4C19.3 4 9 14.1 9 26.6C9 42 32 60 32 60C32 60 55 42 55 26.6C55 14.1 44.7 4 32 4Z" fill="#13213c" /><path d="M21 27L29 35L44 19" fill="none" stroke="#fff" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" /></svg>
        {{ emptyText }}
    </div>
    <slot v-else />
</template>
