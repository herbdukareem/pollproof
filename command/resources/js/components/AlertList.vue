<script setup>
import Badge from './Badge.vue';
import Icon from './Icon.vue';
import { time } from '../lib/format';

defineProps({ alerts: { type: Array, default: () => [] }, canResolve: Boolean, busy: Number });
defineEmits(['resolve']);
const tone = { high: 'bad', medium: 'warn', low: 'neutral' };
const bar = { high: 'bg-action', medium: 'bg-[#d99a00]', low: 'bg-ink-3' };
</script>

<template>
    <ul class="flex flex-col gap-2">
        <li v-for="a in alerts" :key="a.id" class="group flex gap-3 rounded-xl bg-[#f6f7f9] px-3.5 py-3 transition hover:bg-[#eef0f4]">
            <span class="w-1 shrink-0 rounded-full" :class="bar[a.severity]"></span>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <Badge :tone="tone[a.severity]">{{ a.severity }}</Badge>
                    <span class="ml-auto text-xs whitespace-nowrap text-ink-2">{{ time(a.updated_at) }}</span>
                </div>
                <div class="mt-1.5 text-sm leading-snug font-bold">{{ a.title }}</div>
                <p class="mt-0.5 text-[13px] leading-snug text-ink-2">{{ a.detail }}</p>
                <div v-if="canResolve" class="mt-2 flex justify-end">
                    <button class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-[12.5px] font-bold text-navy hover:bg-white disabled:opacity-50" :disabled="busy === a.id" @click="$emit('resolve', a)">
                        <Icon name="check" :size="14" /> Mark resolved
                    </button>
                </div>
            </div>
        </li>
    </ul>
</template>
