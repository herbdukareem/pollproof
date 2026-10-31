<script setup>
/**
 * Over a table while a new page, sort or filter loads: a moving bar along the top, the rows
 * dimmed, and a small "Loading" note. Put it inside a `relative` box around the table.
 */
defineProps({ busy: Boolean, label: { type: String, default: 'Loading' } });
</script>

<template>
    <Transition name="fade-quick">
        <div v-if="busy" class="absolute inset-0 z-[5] cursor-progress" role="status" :aria-label="label">
            <div class="absolute inset-x-0 top-0 h-[3px] overflow-hidden bg-navy/10"><div class="table-busy-bar h-full w-1/3 rounded-full bg-action"></div></div>
            <div class="absolute inset-0 bg-white/55 backdrop-blur-[1px]"></div>
            <div class="sticky top-16 mx-auto mt-14 flex w-fit items-center gap-2 rounded-full border border-line bg-white px-4 py-2 text-[13px] font-semibold text-ink-2 shadow-lg">
                <svg class="h-4 w-4 animate-spin text-action" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.2" stroke-width="3" /><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                {{ label }}…
            </div>
        </div>
    </Transition>
</template>
