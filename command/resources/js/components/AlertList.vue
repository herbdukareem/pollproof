<script setup>
import Badge from './Badge.vue';
import { time } from '../lib/format';

defineProps({ alerts: { type: Array, default: () => [] }, canResolve: Boolean, busy: Number });
defineEmits(['resolve']);
const tone = { high: 'bad', medium: 'warn', low: 'neutral' };
const dot = { high: 'bg-action', medium: 'bg-[#d99a00]', low: 'bg-ink-3' };
</script>

<template>
    <ul class="flex flex-col gap-2">
        <li v-for="a in alerts" :key="a.id" class="flex gap-3 rounded-xl bg-mist px-3.5 py-3">
            <span class="w-1 shrink-0 rounded-full" :class="dot[a.severity]"></span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :tone="tone[a.severity]">{{ a.severity }}</Badge>
                    <span class="text-sm font-bold">{{ a.title }}</span>
                    <span class="ml-auto text-xs text-ink-2">{{ time(a.updated_at) }}</span>
                </div>
                <p class="mt-1 text-[13px] text-ink-2">{{ a.detail }}</p>
            </div>
            <button v-if="canResolve" class="btn-outline h-8 self-center px-3 text-xs" :disabled="busy === a.id" @click="$emit('resolve', a)">Resolve</button>
        </li>
    </ul>
</template>
