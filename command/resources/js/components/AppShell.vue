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
// On wide screens the menu can fold down to a rail of icons; remembered per browser.
const KEY = 'pp:menu-collapsed';
const collapsed = ref((() => { try { return localStorage.getItem(KEY) === '1'; } catch { return false; } })());
function toggleCollapsed() {
    collapsed.value = !collapsed.value;
    try { localStorage.setItem(KEY, collapsed.value ? '1' : '0'); } catch { /* private window: just this visit */ }
}
// The rail only applies on wide screens; the phone menu always opens full width.
const rail = computed(() => collapsed.value && !open.value);
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
            if (!c.freeze_at) return { title: 'No freeze date yet', text: 'Set one under Campaign so setup locks before election day.' };
            return left > 0
                ? { figure: left, unit: `day${left === 1 ? '' : 's'} until setup locks`, text: `On ${fmtDate(c.freeze_at)}, units, agents and supervisors stop changing.` }
                : { title: 'Setup is locked', text: `Since ${fmtDate(c.freeze_at)}. Units, agents and supervisors no longer change.` };
        }
        case 'after':
            return c.declared_at
                ? { title: 'Records locked', text: `Declared ${fmtDate(c.declared_at)}. Election-day records are read-only.` }
                : { title: 'Declaration pending', text: 'Record the declared result under Close-out to lock election-day records.' };
        case 'admin':
            return { title: c.name ?? 'Campaign', text: "This campaign's data is kept apart from every other campaign's." };
        default:
            return days > 0
                ? { figure: days, unit: `day${days === 1 ? '' : 's'} to election day`, text: `${c.state_name ?? ''} governorship, ${fmtDate(c.election_date)}` }
                : { title: days === 0 ? 'Election day' : 'Situation room', text: `${c.state_name ?? ''} governorship, ${fmtDate(c.election_date)}` };
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

// Back to where they came from (keeping its filters and page), or to the page's parent if opened directly.
function goBack() {
    if (window.history.state?.back) router.back();
    else router.push(page.back);
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
            class="fixed inset-y-0 left-0 z-40 flex w-[248px] shrink-0 flex-col gap-6 overflow-hidden bg-navy pt-5 pb-3 text-white transition-[transform,width,padding] duration-200 lg:static lg:h-full lg:translate-x-0 print:hidden"
            :class="[open ? 'translate-x-0 shadow-2xl' : '-translate-x-full', rail ? 'px-3 lg:w-[76px] lg:px-2.5' : 'px-3']"
        >
            <RouterLink to="/" class="flex items-center gap-2.5" :class="rail ? 'px-2 lg:justify-center lg:px-0' : 'px-2'" title="PollProof home">
                <PinLogo :size="28" />
                <span class="text-[20px] tracking-[-0.02em]" :class="rail && 'lg:hidden'"><b class="font-[800]">Poll</b><span class="font-[480]">Proof</span></span>
            </RouterLink>

            <!-- The campaign's phases, in order, and Admin: each has its own menu. -->
            <div
                v-if="sections.length > 1"
                role="tablist"
                aria-label="Menu sections"
                class="flex border-b border-white/10 px-2"
                :class="rail ? 'gap-4 lg:flex-col lg:gap-0 lg:border-0 lg:px-0' : 'gap-4'"
            >
                <button
                    v-for="s in sections"
                    :key="s.key"
                    role="tab"
                    :aria-selected="s.key === activeKey"
                    :title="s.title"
                    class="relative -mb-px pb-2.5 text-[13.5px] font-[560] transition-colors"
                    :class="[s.key === activeKey ? 'text-white' : 'text-[#93a0b8] hover:text-white', rail && 'lg:mb-0 lg:rounded-md lg:py-1.5 lg:text-[11px]', rail && s.key === activeKey && 'lg:bg-white/10']"
                    @click="chosen = s.key"
                >
                    {{ s.label }}
                    <span v-if="s.key === activeKey" class="absolute inset-x-0 bottom-0 h-0.5 rounded-full bg-orange" :class="rail && 'lg:hidden'"></span>
                </button>
            </div>

            <Transition name="nav" mode="out-in">
            <nav :key="activeKey" aria-label="Main" class="-mt-2 -mr-2 flex flex-1 flex-col gap-0.5 overflow-y-auto pr-2">
                <div class="px-3 pb-1.5 text-[12.5px] font-[500] text-[#8d9bb3]" :class="rail && 'lg:hidden'">{{ active?.title }}</div>
                <RouterLink
                    v-for="item in active?.items ?? []"
                    :key="item.name"
                    :to="item.path"
                    class="group relative flex h-10 items-center gap-3 rounded-md px-3 text-[14.5px] text-[#c3cbd9] transition-colors hover:bg-white/[0.06] hover:text-white"
                    :class="[current?.name === item.name ? 'bg-white/[0.08] font-[620] !text-white' : '', rail && 'lg:justify-center lg:px-0']"
                    :title="rail ? item.label + (item.count && counts[item.count] ? ` (${counts[item.count]})` : '') : undefined"
                    :aria-label="rail ? item.label : undefined"
                    :aria-current="current?.name === item.name ? 'page' : undefined"
                >
                    <span v-if="current?.name === item.name" class="absolute inset-y-2 left-0 w-0.5 rounded-full bg-orange"></span>
                    <Icon :name="item.icon" :size="18" class="shrink-0 opacity-75 group-hover:opacity-100" />
                    <span class="flex-1 truncate" :class="rail && 'lg:hidden'">{{ item.label }}</span>
                    <span
                        v-if="item.count && counts[item.count]"
                        class="fig rounded bg-action px-1.5 text-[12px] leading-5 font-[650] text-white"
                        :class="rail && 'lg:absolute lg:top-0.5 lg:right-0.5 lg:px-1 lg:text-[10px] lg:leading-4'"
                    >{{ counts[item.count] > 99 ? '99+' : counts[item.count] }}</span>
                </RouterLink>
            </nav>
            </Transition>

            <div class="border-t border-white/10 px-3 pt-4 text-[12.5px] leading-snug text-[#93a0b8]" :class="rail && 'lg:hidden'">
                <template v-if="footer.figure != null">
                    <div class="flex items-baseline gap-2 text-white">
                        <span class="fig text-[28px] leading-none font-[700]">{{ footer.figure }}</span>
                        <span class="text-[13.5px] font-[560]">{{ footer.unit }}</span>
                    </div>
                    <p class="mt-1.5">{{ footer.text }}</p>
                </template>
                <template v-else>
                    <div class="text-[13.5px] font-[620] text-white">{{ footer.title }}</div>
                    <p class="mt-1">{{ footer.text }}</p>
                </template>
            </div>

            <button
                class="-mt-3 hidden h-9 items-center gap-3 rounded-md px-3 text-[13px] font-[540] text-[#8d9bb3] transition-colors hover:bg-white/[0.06] hover:text-white lg:flex"
                :class="rail && 'justify-center !px-0'"
                :title="collapsed ? 'Expand the menu' : 'Collapse the menu'"
                :aria-label="collapsed ? 'Expand the menu' : 'Collapse the menu'"
                :aria-expanded="!collapsed"
                @click="toggleCollapsed"
            >
                <Icon name="sidebar" class="shrink-0 transition-transform" :class="collapsed && 'rotate-180'" />
                <span v-if="!rail">Collapse menu</span>
            </button>
        </aside>
        <div v-if="open" class="fixed inset-0 z-30 bg-navy/40 backdrop-blur-[1px] lg:hidden" @click="open = false"></div>

        <div class="flex h-full min-w-0 flex-1 flex-col print:block print:h-auto">
            <header class="z-20 flex min-h-16 shrink-0 flex-wrap items-center gap-x-3 gap-y-2 border-b border-line bg-white px-4 py-2.5 sm:px-7 print:hidden">
                <button class="btn-ghost h-9 w-9 !px-0 lg:hidden" aria-label="Open menu" @click="open = true"><Icon name="menu" :size="18" /></button>
                <button v-if="page.back" class="btn-ghost h-9 shrink-0 gap-1 px-2.5" title="Back to the previous page" @click="goBack">
                    <Icon name="chevron" :size="16" class="rotate-180" /><span class="hidden sm:inline">Back</span>
                </button>
                <div class="min-w-0 flex-1">
                    <h1 class="truncate text-[20px] leading-tight font-[720] tracking-[-0.015em] text-ink">{{ title }}</h1>
                    <p v-if="page.subtitle" class="hidden truncate text-[13px] text-ink-2 sm:block">{{ page.subtitle }}</p>
                </div>
                <div class="order-last flex w-full flex-wrap items-center gap-2 empty:hidden sm:order-none sm:w-auto">
                    <span v-if="live" class="hidden items-center gap-1.5 pr-1 text-[13px] font-[600] text-good sm:inline-flex" title="Figures refresh by themselves">
                        <span class="h-2 w-2 rounded-full bg-good"></span>Live
                    </span>
                    <div id="pp-actions" class="flex flex-wrap items-center gap-2"></div>
                </div>
                <div class="flex items-center gap-2 sm:order-last">
                    <AgentAppButton />
                    <NoticeBell />
                    <div class="relative">
                        <button
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-navy text-[13px] font-[650] text-white ring-offset-2 transition hover:ring-2 hover:ring-navy/30"
                            :aria-expanded="menu"
                            aria-haspopup="menu"
                            :aria-label="`Account: ${session.user?.name}`"
                            @click="menu = !menu"
                        >{{ initials }}</button>
                        <div v-if="menu" class="fixed inset-0 z-30" @click="menu = false"></div>
                        <div v-if="menu" role="menu" class="absolute right-0 z-40 mt-2 w-64 overflow-hidden rounded-[10px] border border-line bg-white shadow-[0_12px_32px_rgba(19,33,60,0.14)]">
                            <div class="border-b border-line px-4 py-3">
                                <div class="truncate text-sm font-bold">{{ session.user?.name }}</div>
                                <div class="truncate text-[12.5px] text-ink-2">{{ session.user?.role_label }}, {{ campaign.name }}</div>
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
