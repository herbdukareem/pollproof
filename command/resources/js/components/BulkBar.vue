<script setup>
/**
 * The bar that appears while rows are ticked: how many, the actions, and Clear.
 * It floats at the bottom of the window (beside the menu on wide screens), so it is in reach
 * wherever the table has scrolled to.
 */
defineProps({ count: { type: Number, default: 0 }, noun: { type: String, default: 'selected' } });
defineEmits(['clear']);
</script>

<template>
    <Teleport to="body">
        <Transition name="bulk">
            <div
                v-if="count"
                class="fixed bottom-4 left-1/2 z-[55] flex w-[min(760px,calc(100vw-2rem))] -translate-x-1/2 flex-wrap items-center gap-2 rounded-lg bg-navy px-4 py-2.5 text-white shadow-[0_16px_40px_-12px_rgba(19,33,60,0.6)] lg:left-[calc(50%+128px)] lg:w-[min(760px,calc(100vw-256px-3rem))] print:hidden"
                role="toolbar"
                :aria-label="`Actions for ${count} ${noun}`"
            >
                <b class="mr-1 text-sm font-[650] tabular-nums">{{ count }} {{ noun }}</b>
                <slot />
                <button class="ml-auto rounded-md px-2.5 py-1.5 text-[13px] font-[560] text-[#c9d1de] hover:bg-white/10 hover:text-white" @click="$emit('clear')">Clear</button>
            </div>
        </Transition>
    </Teleport>
</template>
