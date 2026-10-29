<script setup>
import { onBeforeUnmount, onMounted, watch } from 'vue';
import Icon from './Icon.vue';

// A dialog over the page. Closes on Escape or a click outside, unless it is busy saving.
const props = defineProps({ open: Boolean, title: String, subtitle: String, busy: Boolean, width: { type: String, default: 'max-w-lg' } });
const emit = defineEmits(['close']);
const close = () => !props.busy && emit('close');
const onKey = (e) => e.key === 'Escape' && props.open && close();
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
watch(() => props.open, (o) => document.body.classList.toggle('overflow-hidden', o));
</script>

<template>
    <Teleport to="body">
        <Transition name="modal">
            <div v-if="open" class="fixed inset-0 z-[60] flex items-end justify-center bg-navy/45 p-0 backdrop-blur-[2px] sm:items-center sm:p-6" @mousedown.self="close">
                <div role="dialog" aria-modal="true" :aria-label="title" class="modal-panel flex max-h-[92dvh] w-full flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl" :class="width">
                    <div class="flex items-start gap-3 border-b border-line px-5 py-4">
                        <div class="min-w-0 flex-1">
                            <h2 class="text-[17px] font-bold text-ink">{{ title }}</h2>
                            <p v-if="subtitle" class="mt-0.5 text-[13px] text-ink-2">{{ subtitle }}</p>
                        </div>
                        <button class="-mr-1 flex h-8 w-8 items-center justify-center rounded-lg text-ink-2 hover:bg-mist" aria-label="Close" :disabled="busy" @click="close"><Icon name="x" :size="18" /></button>
                    </div>
                    <div class="overflow-y-auto px-5 py-4"><slot /></div>
                    <div v-if="$slots.footer" class="flex flex-wrap justify-end gap-2 border-t border-line bg-[#fafbfc] px-5 py-3"><slot name="footer" /></div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
