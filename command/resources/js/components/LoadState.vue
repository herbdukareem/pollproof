<script setup>
defineProps({ loading: Boolean, error: Object, empty: Boolean, emptyText: { type: String, default: 'Nothing here yet.' } });
</script>

<template>
    <div v-if="error" role="alert" class="rounded-lg bg-bad-bg px-4 py-3 text-sm text-bad">
        <b>Couldn't load this.</b> {{ error.status === 403 ? 'Your role cannot see this.' : error.message }}
    </div>
    <div v-else-if="loading && empty" class="flex flex-col gap-3 p-5" role="status" aria-label="Loading">
        <div class="skeleton h-5 w-1/3"></div>
        <div class="skeleton h-4 w-full"></div>
        <div class="skeleton h-4 w-11/12"></div>
        <div class="skeleton h-4 w-4/5"></div>
        <div class="skeleton h-4 w-2/3"></div>
    </div>
    <div v-else-if="empty" class="px-5 py-10 text-center text-[14px] text-ink-2">
        {{ emptyText }}
    </div>
    <slot v-else />
</template>
