<script setup>
import { onBeforeUnmount, watchEffect } from 'vue';
import { time } from '../lib/format';
import { page } from '../lib/page';
import Icon from './Icon.vue';

// back: show a Back button; the path to use when the page was opened directly (no previous page).
const props = defineProps({ title: String, subtitle: String, updatedAt: Date, loading: Boolean, back: { type: String, default: '' } });
defineEmits(['refresh']);

// The title and subtitle live in the shell's top bar; actions are teleported beside them.
const owner = Symbol('page');
watchEffect(() => {
    page.title = props.title || '';
    page.subtitle = props.subtitle || '';
    page.back = props.back || '';
    page.owner = owner;
});
onBeforeUnmount(() => {
    if (page.owner === owner) {
        page.title = '';
        page.subtitle = '';
        page.back = '';
        page.owner = null;
    }
});
</script>

<template>
    <Teleport defer to="#pp-actions">
        <slot name="actions" />
        <button v-if="updatedAt !== undefined" class="btn-ghost" :disabled="loading" :title="updatedAt ? 'Refresh now' : 'Refresh'" @click="$emit('refresh')">
            <Icon name="refresh" :size="16" :class="loading ? 'animate-spin' : ''" />
            <span>{{ updatedAt ? 'Updated ' + time(updatedAt) : 'Refresh' }}</span>
        </button>
    </Teleport>
</template>
