<script setup>
defineProps({ loading: Boolean, error: Object, empty: Boolean, emptyText: { type: String, default: 'Nothing here yet.' } });
</script>

<template>
    <div v-if="error" role="alert" class="rounded-xl bg-bad-bg px-4 py-3 text-sm text-bad">
        <b>Couldn't load this.</b> {{ error.status === 403 ? 'Your role cannot see this.' : error.message }}
    </div>
    <div v-else-if="loading && empty" class="px-4 py-10 text-center text-sm text-ink-2">Loading…</div>
    <div v-else-if="empty" class="px-4 py-10 text-center text-sm text-ink-2">{{ emptyText }}</div>
    <slot v-else />
</template>
