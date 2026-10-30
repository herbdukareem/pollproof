<script setup>
import { computed, ref, watch } from 'vue';
import CountUp from './CountUp.vue';
import Icon from './Icon.vue';

// A number card. With `to` it is clickable: a route opens that screen, a '#id' scrolls to that part of the page.
const props = defineProps({ label: String, value: [String, Number], note: String, pct: Number, tone: { type: String, default: 'navy' }, to: [String, Object] });
const bar = { navy: 'bg-navy', good: 'bg-good', bad: 'bg-action' };
const isAnchor = computed(() => typeof props.to === 'string' && props.to.startsWith('#'));
const tag = computed(() => (!props.to ? 'div' : isAnchor.value ? 'button' : 'RouterLink'));

// A brief glow when the number changes on a refresh, so a live change is noticed.
const flash = ref(false);
watch(() => props.value, (now, before) => {
    if (before === undefined || now === before) return;
    flash.value = false;
    requestAnimationFrame(() => (flash.value = true));
    setTimeout(() => (flash.value = false), 1600);
});

function scroll() {
    const el = document.getElementById(props.to.slice(1));
    if (!el) return;
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    el.classList.add('ring-2', 'ring-action/60');
    setTimeout(() => el.classList.remove('ring-2', 'ring-action/60'), 1400);
}
</script>

<template>
    <component
        :is="tag"
        v-bind="tag === 'RouterLink' ? { to } : tag === 'button' ? { type: 'button' } : {}"
        class="card group relative flex flex-col gap-2 p-5 text-left"
        :class="[to ? 'cursor-pointer transition hover:-translate-y-0.5 hover:border-navy/40 hover:shadow-[0_8px_24px_rgba(19,33,60,0.10)] active:translate-y-0' : '', flash ? 'value-flash' : '']"
        @click="isAnchor && scroll()"
    >
        <div class="flex items-center justify-between gap-2 text-sm text-ink-2">
            {{ label }}
            <Icon v-if="to" name="chevron" :size="16" class="text-ink-3 transition group-hover:translate-x-0.5 group-hover:text-navy" />
        </div>
        <div class="text-[30px] leading-none font-extrabold tracking-tight text-ink"><CountUp :value="value" /></div>
        <div v-if="pct != null" class="h-1.5 w-full overflow-hidden rounded-full bg-[#e9ecf1]">
            <div class="bar-fill h-full rounded-full" :class="bar[tone]" :style="{ width: Math.min(100, pct) + '%' }"></div>
        </div>
        <div v-if="note" class="text-[13px] text-ink-2">{{ note }}</div>
    </component>
</template>
