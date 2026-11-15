<script setup>
import { computed } from 'vue';
import Icon from './Icon.vue';
import { TYPE_TAG } from '../lib/races';

/**
 * A race's short code on a square, coloured by the tier of government it elects:
 * federal (President, Senate, Reps), state (Governor, Assembly) or local (Chairman, Councillor).
 * No race means All races.
 */
const props = defineProps({ race: { type: Object, default: null }, size: { type: String, default: 'md' } });
const TIER = { presidential: 'federal', senate: 'federal', reps: 'federal', governorship: 'state', assembly: 'state', lga_chair: 'local', councillor: 'local' };
const tier = computed(() => TIER[props.race?.type] ?? 'state');
const bg = { federal: 'bg-tier-federal', state: 'bg-tier-state', local: 'bg-tier-local' };
const dims = computed(() => (props.size === 'lg' ? 'h-8 w-[42px] text-[11px]' : props.size === 'sm' ? 'h-5 min-w-[34px] px-1 text-[10px]' : 'h-7 w-[38px] text-[10.5px]'));
</script>

<template>
    <span
        v-if="race"
        class="fig inline-flex shrink-0 items-center justify-center rounded-md font-[760] tracking-[0.02em] text-white"
        :class="[dims, bg[tier]]"
        :title="`${race.type_label}${race.is_rerun ? ' re-run' : ''}`"
        aria-hidden="true"
    >{{ TYPE_TAG[race.type] ?? race.code }}</span>
    <span v-else class="inline-flex shrink-0 items-center justify-center rounded-md bg-mist text-navy" :class="dims" aria-hidden="true"><Icon name="grid" :size="size === 'sm' ? 12 : 16" /></span>
</template>
