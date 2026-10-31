<script setup>
import { computed } from 'vue';

/** A table header that sorts the table when clicked. `sort` comes from useSortState(). */
const props = defineProps({ sort: { type: Object, required: true }, k: { type: String, required: true }, cls: { type: String, default: '' } });
const on = computed(() => props.sort.key === props.k);
const aria = computed(() => (on.value ? (props.sort.dir === 'asc' ? 'ascending' : 'descending') : 'none'));
</script>

<template>
    <th class="th" :class="cls" :aria-sort="aria">
        <button type="button" class="group -mx-1 inline-flex items-center gap-1 rounded px-1 uppercase transition-colors hover:text-ink focus-visible:outline-2 focus-visible:outline-navy" :class="on ? 'text-ink' : ''" :title="on ? 'Click to reverse, again for latest first' : 'Sort by this column'" @click="sort.toggle(k)">
            <slot />
            <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true" class="shrink-0 transition-opacity" :class="on ? 'opacity-100' : 'opacity-30 group-hover:opacity-70'">
                <path d="M6 1.5 9 5H3z" :fill="on && sort.dir === 'asc' ? 'currentColor' : on ? 'transparent' : 'currentColor'" :stroke="on && sort.dir !== 'asc' ? 'none' : 'none'" />
                <path d="M6 10.5 3 7h6z" :fill="on && sort.dir === 'desc' ? 'currentColor' : on ? 'transparent' : 'currentColor'" />
            </svg>
        </button>
    </th>
</template>
