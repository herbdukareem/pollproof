<script setup>
import { time } from '../lib/format';
import Icon from './Icon.vue';

defineProps({ title: String, subtitle: String, updatedAt: Date, loading: Boolean });
defineEmits(['refresh']);
</script>

<template>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-[26px] font-extrabold tracking-tight text-ink">{{ title }}</h1>
            <p v-if="subtitle" class="mt-1 text-sm text-ink-2">{{ subtitle }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <slot name="actions" />
            <button v-if="updatedAt !== undefined" class="btn-outline" :disabled="loading" @click="$emit('refresh')">
                <Icon name="refresh" :size="16" :class="loading ? 'animate-spin' : ''" />
                <span>{{ updatedAt ? 'Updated ' + time(updatedAt) : 'Refresh' }}</span>
            </button>
        </div>
    </div>
</template>
