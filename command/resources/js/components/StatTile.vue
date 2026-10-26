<script setup>
defineProps({ label: String, value: [String, Number], note: String, pct: Number, tone: { type: String, default: 'navy' } });
const bar = { navy: 'bg-navy', good: 'bg-good', bad: 'bg-action' };
</script>

<template>
    <div class="card flex flex-col gap-2 p-5">
        <div class="text-sm text-ink-2">{{ label }}</div>
        <div class="text-[30px] leading-none font-extrabold tracking-tight">{{ value }}</div>
        <div v-if="pct != null" class="h-1.5 overflow-hidden rounded-full bg-[#e9ecf1]">
            <div class="h-full rounded-full" :class="bar[tone]" :style="{ width: Math.min(100, pct) + '%' }"></div>
        </div>
        <div v-if="note" class="text-[13px] text-ink-2">{{ note }}</div>
    </div>
</template>
