<script setup>
import { num } from '../lib/format';

/**
 * A row of filters across the top of a list, underlined when chosen.
 * tabs: [{ value, label, count?, act? }]. `act` turns the count orange when it is not zero:
 * use it only where a non-zero count means someone has work to do.
 */
defineProps({ tabs: { type: Array, required: true }, modelValue: { type: [String, Number], default: '' }, label: { type: String, default: 'Show' } });
const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <div class="-mt-1 mb-5 flex gap-1 overflow-x-auto border-b border-line" role="group" :aria-label="label">
        <button
            v-for="tab in tabs"
            :key="tab.value"
            type="button"
            class="relative shrink-0 px-3 pt-1 pb-2.5 text-[14px] font-[560] whitespace-nowrap transition-colors"
            :class="modelValue === tab.value ? 'text-ink' : 'text-ink-2 hover:text-ink'"
            :aria-pressed="modelValue === tab.value"
            @click="emit('update:modelValue', tab.value)"
        >
            {{ tab.label }}
            <span v-if="tab.count != null" class="ml-1 tabular-nums" :class="tab.act && tab.count ? 'font-[650] text-action' : 'text-ink-3'">{{ num(tab.count) }}</span>
            <span v-if="modelValue === tab.value" class="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-navy"></span>
        </button>
    </div>
</template>
