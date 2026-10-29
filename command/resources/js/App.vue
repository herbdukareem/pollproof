<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import AppShell from './components/AppShell.vue';

const route = useRoute();
const bare = computed(() => route.meta.public);
</script>

<template>
    <RouterView v-if="bare" />
    <AppShell v-else>
        <RouterView v-slot="{ Component, route: r }">
            <Transition name="page" mode="out-in">
                <div :key="r.path">
                    <component :is="Component" />
                </div>
            </Transition>
        </RouterView>
    </AppShell>
</template>
