<script setup>
import { computed } from 'vue';

const props = defineProps({ tone: { type: String, default: 'neutral' } });
const classes = computed(() => ({
    good: 'bg-good-bg text-good',
    warn: 'bg-warn-bg text-warn',
    bad: 'bg-bad-bg text-bad',
    info: 'bg-[#e5ecf8] text-[#1d3f7a]',
    navy: 'bg-navy text-white',
    neutral: 'bg-mist text-ink-2',
})[props.tone]);
</script>

<template>
    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11.5px] font-extrabold tracking-wide whitespace-nowrap uppercase" :class="classes"><slot /></span>
</template>
