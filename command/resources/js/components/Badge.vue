<script setup>
import { computed } from 'vue';

const props = defineProps({ tone: { type: String, default: 'neutral' } });
const classes = computed(() => ({
    good: 'bg-good-bg text-good',
    warn: 'bg-warn-bg text-warn',
    bad: 'bg-bad-bg text-bad',
    info: 'bg-[#e5ecf8] text-[#1d3f7a]',
    navy: 'bg-navy text-white',
    neutral: 'bg-[#eceff3] text-ink-2',
})[props.tone]);
</script>

<template>
    <span class="inline-block rounded px-1.5 py-px text-[12px] leading-[18px] font-[600] whitespace-nowrap first-letter:uppercase" :class="classes"><slot /></span>
</template>
