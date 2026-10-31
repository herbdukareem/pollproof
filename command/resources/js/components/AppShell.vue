<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PinLogo from './PinLogo.vue';
import Icon from './Icon.vue';
import NoticeBell from './NoticeBell.vue';
import AgentAppButton from './AgentAppButton.vue';
import Toaster from './Toaster.vue';
import { toast } from '../lib/toast';
import { session, signOut } from '../lib/session';
import { itemForPath, sectionsFor } from '../lib/nav';
import { page } from '../lib/page';
import { api } from '../lib/api';

const route = useRoute();
const router = useRouter();
const open = ref(false);
const menu = ref(false);
const counts = ref({});
const sections = computed(() => sectionsFor(session.user?.role));
const current = computed(() => itemForPath(route.path));
const chosen = ref(null);
const activeKey = computed(() => chosen.value ?? current.value?.section ?? sections.value[0]?.key);
const active = computed(() => sections.value.find((s) => s.key === activeKey.value) ?? sections.value[0]);
const campaign = computed(() => session.user?.campaign ?? {});
const initials = computed(() => (session.user?.name || '?').split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase());
const live = computed(() => current.value?.section === 'election');
const title = computed(() => page.title || current.value?.label || '');

const fmtDate = (d) => (d ? new Date(String(d).slice(0, 10) + 'T12:00:00').toLocaleDateString('en-NG', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }) : '');
const daysTo = (d) => (d ? Math.round((new Date(String(d).slice(0, 10) + 'T12:00:00') - new Date(new Date().toDateString() + ' 12:00')) / 86400000) : null);

/** The card at the foot of the menu, about where the campaign is in its calendar. */
const footer = computed(() => {
    const c = campaign.value;
    const days = daysTo(c.election_date);
    switch (activeKey.value) {
        case 'setup': {
            const left = daysTo(c.freeze_at);
            return {
                title: left != null && left <= 0 ? 'Setup locked' : 'Code freeze',
                text: c.freeze_at ? `${fmtDate(c.freeze_at)} · no changes to units, agents or supervisors after this date` : 'No freeze date set yet',
            };
        }
        case 'after':
            return c.declared_at
                ? { title: 'Records locked', text: `Declared ${fmtDate(c.declared_at)}. Election-day records are read-only.` }
                : { title: 'Declaration pending', text: 'Record the declared result under Close-out to lock election-day records.' };
        case 'admin':
            return { title: 'Campaign workspace', text: `${c.name ?? ''} · kept separate from every other campaign's data` };
        default:
            return {
                title: days === 0 ? 'Election day' : days > 0 ? `${days} day${days === 1 ? '' : 's'} to election day` : 'Situation room',
                text: `${c.state_name ?? ''} governorship · ${fmtDate(c.election_date)}`,
            };
    }
});

// The newest check-in waiting to be confirmed when we last looked; a newer one pops up a notice.
let seenCheckIn = null;
let seenCount = 0;
function announceCheckIns(c) {
    if (!('checkins' in c)) return;
    const newest = c.checkins_newest;
    if (seenCheckIn !== null && newest && newest.id > seenCheckIn) {
        const how = newest.channel === 'ussd' ? 'by USSD, no GPS' : newest.distance_m != null ? `${newest.distance_m} m from the unit` : 'unit has no GPS point';
        const more = c.checkins - seenCount > 1 ? ` (+${c.checkins - seenCount - 1} more)` : '';
        toast({
            icon: 'users',
            tone: 'info',
            title: `New check-in to confirm${more}`,
            body: `${newest.agent}${newest.name ? ` · ${newest.name}` : ''} at ${newest.unit}, ${how}.`,
            ...(route.path === '/agents'
                ? { action: 'Show', run: () => document.getElementById('checkins')?.scrollIntoView({ behavior: 'smooth', block: 'start' }) }
                : { action: 'Review on Agents', to: '/agents' }),
        });
        window.dispatchEvent(new CustomEvent('pp:checkins'));
    }
    seenCheckIn = Math.max(seenCheckIn ?? 0, newest?.id ?? 0);
    seenCount = c.checkins;
}

async function loadCounts() {
    try {
        counts.value = await api.get('nav-counts');
        announceCheckIns(counts.value);
    } catch {
        counts.value = {};
    }
}
let timer;
onMounted(() => {
    loadCounts();
    timer = setInterval(loadCounts, 15000);
});
onBeforeUnmount(() => clearInterval(timer));

watch(() => route.fullPath, () => {
    open.value = false;
    menu.value = false;
    chosen.value = null;
    loadCounts();
});

async function logout() {
    await signOut();
    router.push({ name: 'login' });
}
</script>

<template>
    <div class="flex h-dvh overflow-hidden bg-mist print:block print:h-auto print:overflow-visible">
        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-[256px] shrink-0 flex-col gap-5 overflow-hidden bg-navy px-4 py-5 text-white transition-transform duration-200 lg:static lg:h-full lg:translate-x-0 print:hidden"
            :class="open ? 'translate-x-0 shadow-2xl' : '-translate-x-full'"
        >
            <RouterLink to="/" class="flex items-center gap-2.5 px-2">
                <PinLogo :size="30" />
                <span class="text-[21px] tracking-[-0.4px]"><b class="font-extrabold">Poll</b><span class="font-medium">Proof</span></span>
            </RouterLink>

            <div v-if="sections.length > 1" role="tablist" aria-label="Menu sections" class="grid gap-1 rounded-xl bg-navy-2 p-1" :style="{ gridTemplateColumns: `repeat(${sections.length}, minmax(0, 1fr))` }">
                <button
                    v-for="s in sections"
                    :key="s.key"
                    role="tab"
                    :aria-selected="s.key === activeKey"
                    :title="s.title"
                    class="h-8 rounded-lg px-1 text-[12px] font-bold transition"
                    :class="s.key === activeKey ? 'bg-white text-navy shadow-sm' : 'text-[#c9d1de] hover:text-white'"
                    @click="chosen = s.key"
                >{{ s.label }}</button>
            </div>

            <Transition name="nav" mode="out-in">
            <nav :key="activeKey" aria-label="Main" class="-mr-2 flex flex-1 flex-col gap-1 overflow-y-auto pr-2">
                <div class="px-3 pb-1 text-[11px] font-bold tracking-[0.12em] text-[#8d9bb3] uppercase">{{ active?.title }}</div>
                <RouterLink
                    v-for="item in active?.items ?? []"
                    :key="item.name"
                    :to="item.path"
                    class="group flex h-11 items-center gap-3 rounded-[10px] px-3 text-[15px] text-[#c9d1de] transition hover:bg-navy-2 hover:text-white"
                    :class="current?.name === item.name ? '!bg-navy-3 font-bold !text-white' : ''"
                >
                    <Icon :name="item.icon" class="opacity-80 group-hover:opacity-100" />
                    <span class="flex-1 truncate">{{ item.label }}</span>
                    <span v-if="item.count && counts[item.count]" class="rounded-full bg-action px-2 py-px text-xs font-bold text-white">{{ counts[item.count] > 99 ? '99+' : counts[item.count] }}</span>
                </RouterLink>
            </nav>
            </Transition>

            <div class="rounded-xl bg-navy-2 p-3.5 text-[13px] leading-relaxed text-[#c9d1de]">
                <div class="mb-0.5 font-bold text-white">{{ footer.title }}</div>
                {{ footer.text }}
            </div>
        </aside>
        <div v-if="open" class="fixed inset-0 z-30 bg-navy/40 backdrop-blur-[1px] lg:hidden" @click="open = false"></div>

        <div class="flex h-full min-w-0 flex-1 flex-col print:block print:h-auto">
            <header class="z-20 flex min-h-[68px] shrink-0 flex-wrap items-center gap-x-4 gap-y-2 border-b border-[#e3e6ec] bg-white/95 px-4 py-3 backdrop-blur sm:px-7 print:hidden">
                <button class="btn-outline h-10 w-10 !px-0 lg:hidden" aria-label="Open menu" @click="open = true"><Icon name="menu" /></button>
                <div class="min-w-0 flex-1">
                    <h1 class="truncate text-[22px] leading-tight font-extrabold tracking-[-0.3px] text-ink">{{ title }}</h1>
                    <p v-if="page.subtitle" class="truncate text-[13px] text-ink-2">{{ page.subtitle }}</p>
                </div>
                <div class="order-last flex w-full flex-wrap items-center gap-2.5 empty:hidden sm:order-none sm:w-auto">
                    <span v-if="live" class="hidden items-center gap-1.5 rounded-full bg-good-bg px-3 py-1.5 text-[13px] font-bold text-good sm:inline-flex">
                        <span class="relative flex h-2 w-2"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-good opacity-60"></span><span class="relative inline-flex h-2 w-2 rounded-full bg-good"></span></span>
                        Live
                    </span>
                    <div id="pp-actions" class="flex flex-wrap items-center gap-2.5"></div>
                </div>
                <div class="flex items-center gap-2.5 sm:order-last">
                    <AgentAppButton />
                    <NoticeBell />
                    <div class="relative">
                        <button
                            class="flex h-10 w-10 items-center justify-center rounded-full bg-navy text-sm font-bold text-white ring-offset-2 transition hover:ring-2 hover:ring-navy/30"
                            :aria-expanded="menu"
                            aria-haspopup="menu"
                            :aria-label="`Account: ${session.user?.name}`"
                            @click="menu = !menu"
                        >{{ initials }}</button>
                        <div v-if="menu" class="fixed inset-0 z-30" @click="menu = false"></div>
                        <div v-if="menu" role="menu" class="absolute right-0 z-40 mt-2 w-64 overflow-hidden rounded-2xl border border-line bg-white shadow-xl">
                            <div class="border-b border-line px-4 py-3">
                                <div class="truncate text-sm font-bold">{{ session.user?.name }}</div>
                                <div class="truncate text-[12.5px] text-ink-2">{{ session.user?.role_label }} · {{ campaign.name }}</div>
                            </div>
                            <RouterLink role="menuitem" to="/account/security" class="flex items-center gap-2.5 px-4 py-2.5 text-sm hover:bg-mist"><Icon name="key" :size="16" /> Password and two-step sign-in</RouterLink>
                            <button role="menuitem" class="flex w-full items-center gap-2.5 px-4 py-2.5 text-left text-sm text-action hover:bg-mist" @click="logout"><Icon name="logout" :size="16" /> Sign out</button>
                        </div>
                    </div>
                </div>
            </header>
            <main id="pp-main" class="min-w-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-contain px-4 py-6 sm:px-7 print:overflow-visible">
                <slot />
            </main>
            <Toaster />
        </div>
    </div>
</template>
