<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Icon from './Icon.vue';
import RaceTag from './RaceTag.vue';
import { api } from '../lib/api';
import { hasRole } from '../lib/session';
import { num } from '../lib/format';
import { byDate, fmtRaceDate, hasSeveral, oneRace, races, selectRace, viewRace } from '../lib/races';

/**
 * The race the page is about, in the top bar. Opens a list of every race, grouped by election
 * day, each with how we stand in it. Pages that show one race at a time leave out All races.
 */
const route = useRoute();
const router = useRouter();
const mode = computed(() => route.meta.race);
const showsAll = computed(() => mode.value === 'all');
const current = computed(() => (showsAll.value ? viewRace.value : oneRace.value));
const visible = computed(() => !!mode.value && hasSeveral.value && (!!current.value || showsAll.value));

const open = ref(false);
const query = ref('');
const btn = ref(null);
const panel = ref(null);
const search = ref(null);
const board = ref({});
const pos = ref({});
const many = computed(() => races.list.length > 7);

const groups = computed(() => {
    const q = query.value.trim().toLowerCase();
    return byDate.value
        .map((g) => ({ ...g, list: q ? g.list.filter((r) => `${r.name} ${r.code} ${r.area} ${r.type_label}`.toLowerCase().includes(q)) : g.list }))
        .filter((g) => g.list.length);
});

/** How we stand in a race, from the board: the words, the tone and how far counting has got. */
function standing(r) {
    const b = board.value[r.id];
    if (!b) return null;
    const reported = b.units ? b.units_reported / b.units : 0;
    if (r.declared_at) return { text: 'Declared', tone: 'text-ink-2', reported };
    if (!b.votes_counted) return { text: 'No results yet', tone: 'text-ink-3', reported };
    switch (b.standing) {
        case 'leading': return { text: `Ahead by ${num(b.margin)}`, tone: 'text-good', reported };
        case 'trailing': return { text: `Behind by ${num(Math.abs(b.margin))}`, tone: 'text-action', reported };
        case 'tied': return { text: 'Level', tone: 'text-warn', reported };
        default: return { text: 'Not on the ballot', tone: 'text-ink-3', reported };
    }
}
const summary = computed(() => {
    const list = Object.values(board.value).filter((b) => b.votes_counted && !b.declared_at);
    if (!list.length) return `${races.list.length} races`;
    const ahead = list.filter((b) => b.standing === 'leading').length;
    const behind = list.filter((b) => b.standing === 'trailing').length;
    return [ahead && `ahead in ${ahead}`, behind && `behind in ${behind}`].filter(Boolean).join(', ').replace(/^./, (c) => c.toUpperCase()) || `${races.list.length} races`;
});

async function loadBoard() {
    try {
        const r = await api.get('tally/board');
        board.value = Object.fromEntries((r.races ?? []).map((b) => [b.id, b]));
    } catch { /* the list still works without standings */ }
}

async function toggle() {
    if (open.value) return close();
    open.value = true;
    query.value = '';
    loadBoard();
    await nextTick();
    place();
    window.addEventListener('resize', place);
    (many.value ? search.value : panel.value?.querySelector('[aria-selected="true"]') ?? panel.value?.querySelector('[role="option"]'))?.focus();
}
function place() {
    const r = btn.value?.getBoundingClientRect();
    if (!r) return;
    const phone = window.innerWidth < 640;
    pos.value = phone ? { phone } : { top: r.bottom + 8, left: Math.max(12, Math.min(window.innerWidth - 412, r.left)), maxHeight: window.innerHeight - r.bottom - 24 };
}
function close(focusBack = false) {
    open.value = false;
    window.removeEventListener('resize', place);
    if (focusBack) btn.value?.focus();
}
function pick(id) {
    selectRace(id);
    close(true);
    // A ?race= from a link has done its job; drop it so a reload keeps this choice.
    if (route.query.race) {
        const { race, ...rest } = route.query;
        router.replace({ query: rest });
    }
}

/** Arrow keys move through the list; typing goes to the search box; Escape closes. */
function onKey(e) {
    const items = [...(panel.value?.querySelectorAll('[role="option"]') ?? [])];
    const i = items.indexOf(document.activeElement);
    if (e.key === 'Escape') {
        e.preventDefault();
        close(true);
    } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        const next = e.key === 'ArrowDown' ? (i + 1) % items.length : (i <= 0 ? items.length - 1 : i - 1);
        items[next]?.focus();
    } else if (e.key === 'Home' || e.key === 'End') {
        e.preventDefault();
        items[e.key === 'Home' ? 0 : items.length - 1]?.focus();
    } else if (e.key === 'Tab') {
        close();
    } else if (many.value && e.key.length === 1 && document.activeElement !== search.value && !e.metaKey && !e.ctrlKey) {
        search.value?.focus();
    }
}

watch(() => route.path, () => close());
onBeforeUnmount(() => window.removeEventListener('resize', place));
</script>

<template>
    <div v-if="visible" class="relative min-w-0">
        <button
            ref="btn"
            class="group flex h-11 w-full max-w-full min-w-0 items-center sm:w-auto gap-2.5 rounded-[10px] border bg-white py-1 pr-2.5 pl-1.5 text-left transition-colors"
            :class="open ? 'border-navy ring-2 ring-navy/10' : 'border-line hover:border-ink-3'"
            aria-haspopup="listbox"
            :aria-expanded="open"
            :aria-label="`Race: ${current ? current.name : 'All races'}. Change race`"
            @click="toggle"
        >
            <RaceTag :race="current" size="lg" />
            <span class="min-w-0 leading-tight">
                <span class="block truncate text-[14px] font-[650] text-ink">{{ current ? current.name : 'All races' }}</span>
                <span class="block truncate text-[12px] text-ink-2">{{ current ? current.area : `${races.list.length} races, every one at a glance` }}</span>
            </span>
            <Icon name="chevron" :size="15" class="ml-auto shrink-0 rotate-90 text-ink-3 transition-transform group-hover:text-ink" :class="open && '-rotate-90'" />
        </button>

        <Teleport to="body">
            <div v-if="open" class="fixed inset-0 z-[55]" :class="pos.phone && 'bg-navy/30'" @click="close()"></div>
            <Transition :name="pos.phone ? 'sheet' : 'pop'">
                <div
                    v-if="open"
                    ref="panel"
                    class="fixed z-[56] flex flex-col overflow-hidden"
                    :class="pos.phone ? 'inset-x-0 bottom-0 max-h-[82dvh] rounded-t-[16px] bg-white shadow-[0_-12px_32px_-10px_rgba(19,33,60,0.28)]' : 'float w-[400px]'"
                    :style="pos.phone ? null : { top: pos.top + 'px', left: pos.left + 'px', maxHeight: Math.min(560, pos.maxHeight) + 'px' }"
                    @keydown="onKey"
                >
                    <div v-if="pos.phone" class="mx-auto mt-2 h-1 w-10 rounded-full bg-line" aria-hidden="true"></div>
                    <div class="flex items-baseline justify-between gap-3 px-4 pt-3 pb-2">
                        <span class="text-[13.5px] font-[650] text-ink">Choose a race</span>
                        <span class="text-[12.5px] text-ink-2">{{ summary }}</span>
                    </div>
                    <div v-if="many" class="px-3 pb-2">
                        <label class="relative block">
                            <span class="sr-only">Find a race</span>
                            <Icon name="search" :size="15" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" />
                            <input ref="search" v-model="query" class="field h-9 pl-8" placeholder="Find a race, LGA or code" />
                        </label>
                    </div>

                    <div role="listbox" aria-label="Races" class="overflow-y-auto overscroll-contain px-2 pb-2">
                        <button
                            v-if="showsAll && !query"
                            role="option"
                            :aria-selected="!current"
                            class="race-row"
                            :class="!current && 'race-row-on'"
                            @click="pick('all')"
                        >
                            <RaceTag :race="null" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[14px] font-[620]">All races</span>
                                <span class="block truncate text-[12.5px] text-ink-2">Where we stand in every race</span>
                            </span>
                            <Icon v-if="!current" name="check" :size="16" class="shrink-0 text-navy" />
                        </button>

                        <section v-for="g in groups" :key="g.date" class="mt-1">
                            <h3 v-if="byDate.length > 1" class="flex items-center gap-2 px-2.5 pt-2.5 pb-1 text-[12px] font-[600] text-ink-2">
                                {{ fmtRaceDate(g.date) }}
                                <span v-if="g.current" class="rounded bg-[#eceff3] px-1.5 py-px text-[11px] text-ink-2">Election day</span>
                            </h3>
                            <button
                                v-for="r in g.list"
                                :key="r.id"
                                role="option"
                                :aria-selected="current?.id === r.id"
                                class="race-row"
                                :class="current?.id === r.id && 'race-row-on'"
                                @click="pick(r.id)"
                            >
                                <RaceTag :race="r" />
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-baseline gap-2">
                                        <span class="truncate text-[14px] font-[620]">{{ r.name }}</span>
                                        <span v-if="r.is_rerun" class="shrink-0 text-[11.5px] font-[600] text-warn">Re-run</span>
                                    </span>
                                    <span class="block truncate text-[12.5px] text-ink-2">{{ r.area }}</span>
                                </span>
                                <span class="w-[104px] shrink-0 text-right">
                                    <template v-if="standing(r)">
                                        <span class="block text-[12.5px] font-[620] tabular-nums" :class="standing(r).tone">{{ standing(r).text }}</span>
                                        <span class="mt-1 ml-auto block h-[3px] w-16 overflow-hidden rounded-full bg-[#e8ebf0]" :title="`${Math.round(standing(r).reported * 100)}% of units in`">
                                            <span class="block h-full bg-navy" :style="{ width: standing(r).reported * 100 + '%' }"></span>
                                        </span>
                                    </template>
                                    <span v-else class="ml-auto block h-3 w-16 animate-pulse rounded bg-mist"></span>
                                </span>
                            </button>
                        </section>
                        <p v-if="!groups.length" class="px-3 py-6 text-center text-[13.5px] text-ink-2">No race matches "{{ query }}".</p>
                    </div>

                    <RouterLink v-if="hasRole('dg', 'situation_room')" to="/setup/races" class="flex items-center gap-2 border-t border-rule px-4 py-3 text-[13px] font-[600] text-navy hover:bg-mist" @click="close()">
                        <Icon name="settings" :size="15" /> Add or change races
                    </RouterLink>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>

