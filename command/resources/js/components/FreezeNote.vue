<script setup>
import { computed } from 'vue';
import Icon from './Icon.vue';

const props = defineProps({ freezeAt: String, frozen: Boolean });
const label = computed(() => (props.freezeAt ? new Date(props.freezeAt.slice(0, 10) + 'T00:00:00').toLocaleDateString('en-NG', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }) : null));
</script>

<template>
    <div v-if="frozen" class="mb-5 flex items-center gap-3 rounded-2xl bg-navy px-5 py-3.5 text-sm text-white">
        <Icon name="lock" :size="18" />
        <span><b>Setup is locked</b> since {{ label }}. Units, agents and supervisors can't change. The DG can move the date under Campaign.</span>
    </div>
    <div v-else-if="freezeAt" class="mb-5 flex items-center gap-3 rounded-2xl border border-[#f6d3b8] bg-[#fff6ef] px-5 py-3 text-sm text-ink">
        <Icon name="lock" :size="18" />
        <span><b>Setup locks on {{ label }}.</b> No changes to units, agents or supervisors after that date.</span>
    </div>
</template>
