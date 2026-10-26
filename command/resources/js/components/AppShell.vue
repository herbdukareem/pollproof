<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PinLogo from './PinLogo.vue';
import Icon from './Icon.vue';
import { session, signOut } from '../lib/session';
import { navFor } from '../lib/nav';

const route = useRoute();
const router = useRouter();
const open = ref(false);
const items = computed(() => navFor(session.user?.role));
const initials = computed(() => (session.user?.name || '?').split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase());

watch(() => route.fullPath, () => (open.value = false));

async function logout() {
    await signOut();
    router.push({ name: 'login' });
}
</script>

<template>
    <div class="flex min-h-screen bg-mist">
        <aside
            class="fixed inset-y-0 left-0 z-30 flex w-64 flex-col gap-6 bg-navy px-4 py-5 text-white transition-transform lg:static lg:translate-x-0"
            :class="open ? 'translate-x-0' : '-translate-x-full'"
        >
            <RouterLink to="/" class="flex items-center gap-2.5 px-2">
                <PinLogo :size="30" />
                <span class="text-[21px] tracking-tight"><b class="font-extrabold">Poll</b><span class="font-medium">Proof</span></span>
            </RouterLink>
            <nav aria-label="Main" class="flex flex-col gap-1">
                <RouterLink
                    v-for="item in items"
                    :key="item.name"
                    :to="item.path"
                    class="flex h-11 items-center gap-3 rounded-xl px-3 text-[15px] text-[#c9d1de] hover:bg-navy-2 hover:text-white"
                    active-class="!bg-navy-3 !text-white font-bold"
                >
                    <Icon :name="item.icon" />
                    {{ item.label }}
                </RouterLink>
            </nav>
            <div class="mt-auto rounded-xl bg-navy-2 p-3.5 text-[13px] leading-relaxed text-[#c9d1de]">
                <div class="font-bold text-white">{{ session.user?.campaign?.name }}</div>
                {{ session.user?.campaign?.state_name }} · election {{ session.user?.campaign?.election_date?.slice(0, 10) }}
            </div>
        </aside>
        <div v-if="open" class="fixed inset-0 z-20 bg-navy/40 lg:hidden" @click="open = false"></div>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex h-16 items-center gap-3 border-b border-line bg-white px-4 sm:px-7">
                <button class="btn-outline h-10 w-10 !px-0 lg:hidden" aria-label="Open menu" @click="open = true"><Icon name="menu" /></button>
                <div class="min-w-0 flex-1 truncate text-sm text-ink-2">
                    Signed in as <b class="text-ink">{{ session.user?.name }}</b> <template v-if="session.user?.role_label !== session.user?.name">· {{ session.user?.role_label }}</template>
                </div>
                <div class="hidden h-10 w-10 items-center justify-center rounded-full bg-navy text-sm font-bold text-white sm:flex" aria-hidden="true">{{ initials }}</div>
                <button class="btn-outline" @click="logout"><Icon name="logout" :size="16" /> Sign out</button>
            </header>
            <main class="flex-1 px-4 py-6 sm:px-7">
                <slot />
            </main>
        </div>
    </div>
</template>
