<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import AppShell from './components/AppShell.vue';
import { progress } from './lib/progress';

const route = useRoute();
const bare = computed(() => route.meta.public);

// The page area scrolls on its own, so a new page starts at the top of it.
const toTop = () => document.getElementById('pp-main')?.scrollTo(0, 0);
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 top-0 z-[70] h-[3px]" aria-hidden="true">
        <div
            class="h-full rounded-r-full bg-action shadow-[0_0_10px_rgba(232,89,12,0.7)]"
            :style="{ width: progress.value * 100 + '%', opacity: progress.visible ? 1 : 0, transition: progress.value ? 'width 0.2s ease, opacity 0.25s ease' : 'none' }"
        ></div>
    </div>
    <RouterView v-if="bare" />
    <AppShell v-else>
        <RouterView v-slot="{ Component, route: r }">
            <Transition name="page" mode="out-in" @before-enter="toTop">
                <div :key="r.path">
                    <component :is="Component" />
                </div>
            </Transition>
        </RouterView>
    </AppShell>
</template>
