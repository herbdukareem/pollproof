<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';

// Shows a value like "1,289" or "21 of 25", counting the first number up to it.
const props = defineProps({ value: [String, Number], duration: { type: Number, default: 800 } });
const shown = ref('');
const reduce = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
const fmt = new Intl.NumberFormat('en-NG');
let frame;
let last = 0;

function parse(v) {
    const m = String(v ?? '').match(/^(\D*?)(-?[\d,]+)(.*)$/s);
    return m ? { pre: m[1], n: Number(m[2].replace(/,/g, '')), post: m[3], commas: m[2].includes(',') || Number(m[2].replace(/,/g, '')) >= 1000 } : null;
}

function run(v) {
    cancelAnimationFrame(frame);
    const p = parse(v);
    if (!p || reduce || !Number.isFinite(p.n)) {
        shown.value = String(v ?? '');
        if (p) last = p.n;
        return;
    }
    const from = last;
    const start = performance.now();
    const tick = (now) => {
        const t = Math.max(0, Math.min(1, (now - start) / props.duration));
        const eased = 1 - Math.pow(1 - t, 3);
        const n = Math.round(from + (p.n - from) * eased);
        shown.value = p.pre + (p.commas ? fmt.format(n) : String(n)) + p.post;
        if (t < 1) frame = requestAnimationFrame(tick);
        else last = p.n;
    };
    frame = requestAnimationFrame(tick);
}

watch(() => props.value, run, { immediate: true });
onBeforeUnmount(() => cancelAnimationFrame(frame));
</script>

<template>
    <span class="tabular-nums">{{ shown }}</span>
</template>
