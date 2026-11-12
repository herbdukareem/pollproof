<script setup>
import { useRouter } from 'vue-router';
import Icon from './Icon.vue';
import { dismiss, toasts } from '../lib/toast';

const router = useRouter();
const bar = { info: 'bg-[#1c6fc2]', warn: 'bg-[#d99a00]', good: 'bg-good', bad: 'bg-action' };

function act(t) {
    dismiss(t.id);
    if (t.run) t.run();
    else if (t.to) router.push(t.to);
}
</script>

<template>
    <div class="pointer-events-none fixed right-4 bottom-4 z-[60] flex w-[min(360px,calc(100vw-2rem))] flex-col gap-2 print:hidden" aria-live="polite">
        <TransitionGroup name="toast">
            <div v-for="t in toasts" :key="t.id" class="pointer-events-auto flex overflow-hidden float" role="status">
                <span class="w-[3px] shrink-0" :class="bar[t.tone]"></span>
                <div class="flex min-w-0 flex-1 gap-3 p-3.5">
                    <Icon :name="t.icon ?? 'bell'" :size="17" class="mt-0.5 shrink-0 text-ink-2" />
                    <div class="min-w-0 flex-1">
                        <b class="block text-[14px] font-[650]">{{ t.title }}</b>
                        <p v-if="t.body" class="mt-0.5 text-[13px] text-ink-2">{{ t.body }}</p>
                        <button v-if="t.action" class="link mt-1.5 text-[13px]" @click="act(t)">{{ t.action }}</button>
                    </div>
                    <button class="-mt-1 -mr-1 h-7 w-7 shrink-0 rounded-md text-lg leading-none text-ink-3 hover:bg-mist hover:text-ink" aria-label="Dismiss" @click="dismiss(t.id)">×</button>
                </div>
            </div>
        </TransitionGroup>
    </div>
</template>
