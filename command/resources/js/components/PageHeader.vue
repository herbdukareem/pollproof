<script setup>
import { onBeforeUnmount, watchEffect } from 'vue';
import { time } from '../lib/format';
import { page } from '../lib/page';
import Icon from './Icon.vue';

const props = defineProps({ title: String, subtitle: String, updatedAt: Date, loading: Boolean });
defineEmits(['refresh']);

// The title and subtitle live in the shell's top bar; actions are teleported beside them.
const owner = Symbol('page');
watchEffect(() => {
    page.title = props.title || '';
    page.subtitle = props.subtitle || '';
    page.owner = owner;
});
onBeforeUnmount(() => {
    if (page.owner === owner) {
        page.title = '';
        page.subtitle = '';
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
