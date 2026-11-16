<script setup>
import { computed, ref } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import AlertList from '../components/AlertList.vue';
import LoadState from '../components/LoadState.vue';
import StateMapCard from '../components/StateMapCard.vue';
import RaceBoard from '../components/RaceBoard.vue';
import RaceScoreboard from '../components/RaceScoreboard.vue';
import HelpTip from '../components/HelpTip.vue';
import { stateMapFor } from '../maps';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { hasRole, session } from '../lib/session';
import { num, pct } from '../lib/format';
import { loadRaces, raceParam, ruleText, viewKey, viewRace } from '../lib/races';

// One race, or (with several races and none chosen) the board of every race.
const race = viewRace;
const tally = useResource(async () => {
    await loadRaces();
    return race.value ? api.get('tally', raceParam(race.value)) : api.get('tally/board');
}, { refreshMs: 30000, watch: viewKey });
const alerts = useResource(async () => api.get('alerts', { status: 'open', per_page: 5, sort: 'latest', ...raceParam(race.value) }), { refreshMs: 30000, watch: viewKey });
const busy = ref(null);
// The state map, when the server names one for this campaign's state (POLLPROOF_STATE_MAP).
const mapLoader = computed(() => stateMapFor(session.user?.rules?.state_map, session.user?.campaign?.state_name));

// Every race gets the map; a race inside one LGA opens on that LGA's wards.
const showMap = computed(() => !!mapLoader.value && (t.value?.area_level !== 'ward' || !!t.value?.lga));
const isBoard = computed(() => !!tally.data.value?.races);
const t = computed(() => (isBoard.value ? null : tally.data.value));
const rule = computed(() => t.value?.rule ?? 'spread');
const areaWord = computed(() => (t.value?.area_level === 'ward' ? 'ward' : 'LGA'));
const ours = computed(() => t.value?.candidates.find((c) => c.is_ours));
const ourCode = computed(() => ours.value?.party);
// The overview shows the five newest; the count covers every open alert.
const openAlerts = computed(() => alerts.data.value?.data ?? []);
const openCount = computed(() => alerts.data.value?.total ?? 0);
const shareIn = (l) => l.share?.[ourCode.value] ?? 0;
const hasVotes = (l) => Object.values(l.votes ?? {}).some((v) => v > 0);

/** Our margin over the best other party in one area: positive where we lead. */
function marginIn(l) {
    const mine = l.votes?.[ourCode.value] ?? 0;
    const best = Math.max(0, ...Object.entries(l.votes ?? {}).filter(([p]) => p !== ourCode.value).map(([, v]) => v));
    return mine - best;
}
const isSpread = computed(() => rule.value === 'spread');
const won = (l) => (isSpread.value ? shareIn(l) >= t.value.threshold : marginIn(l) > 0);
const areaLink = (l) => (t.value?.area_level === 'ward' ? `/units?lga_id=${race.value?.lga_ids?.[0] ?? ''}&ward_id=${l.id}` : `/units?lga_id=${l.id}`);

const subtitle = computed(() => (race.value ? `${race.value.name}, ${race.value.area.toLowerCase() === 'whole state' ? session.user?.campaign?.state_name ?? '' : race.value.area}. ${ruleText(race.value)}.` : "Every race, from our agents' signed results"));

function refresh() {
    tally.load();
    alerts.load();
}

async function resolve(alert) {
    busy.value = alert.id;
    try {
        await api.patch(`alerts/${alert.id}`, { status: 'resolved' });
        await alerts.load();
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <PageHeader title="Overview" :subtitle="subtitle" :updated-at="tally.updatedAt.value" :loading="tally.loading.value" @refresh="refresh" />

    <LoadState :loading="tally.loading.value" :error="tally.error.value" :empty="!tally.data.value">
        <template v-if="isBoard">
            <RaceBoard :board="tally.data.value" />
            <section id="alerts" class="card mt-4 scroll-mt-4">
                <header class="card-head">
                    <h2 class="card-title">Live alerts, every race</h2>
                    <RouterLink to="/alerts" class="text-[13px] font-[600] text-navy hover:underline">{{ openCount > openAlerts.length ? `See all ${num(openCount)}` : 'Alerts page' }}</RouterLink>
                </header>
                <div class="px-5 py-2">
                    <LoadState :loading="alerts.loading.value" :error="alerts.error.value" :empty="!openAlerts.length" empty-text="No open alerts.">
                        <AlertList compact :alerts="openAlerts" :can-resolve="hasRole('situation_room', 'dg', 'legal')" :busy="busy" @resolve="resolve" />
                    </LoadState>
                </div>
            </section>
        </template>

        <template v-else-if="t">
        <!--
            Masonry: two columns that stack their own cards, so a short card leaves no gap.
            Left: the scoreboard, then each area. Right: the map, then alerts. On narrow screens the
            columns dissolve (display: contents) into one list: scoreboard, map, areas, alerts.
        -->
        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
            <div class="contents xl:flex xl:min-w-0 xl:flex-col xl:gap-4">
                <RaceScoreboard class="order-1 min-w-0 xl:order-none" :tally="t" :race="race" />
            <section id="threshold" class="card order-3 min-w-0 scroll-mt-4 xl:order-none">
                <header class="card-head">
                    <h2 class="card-title inline-flex items-center gap-2">
                        {{ isSpread ? `Our share in each ${areaWord}` : `Each ${areaWord}` }}
                        <HelpTip label="About this list">{{ isSpread ? 'Navy bars are at 25% or more; orange are below. The line marks 25%.' : 'Our share in each area, and our votes ahead (+) or behind (−) the leading other party.' }} Click a row for its units.</HelpTip>
                    </h2>
                </header>
                <ul class="px-3 py-1.5">
                    <li v-for="l in t.lgas" :key="l.id">
                        <RouterLink :to="areaLink(l)" class="flex h-10 items-center gap-3 rounded-md px-2 transition-colors hover:bg-[#f6f7f9]" :title="`Open ${l.name} units`">
                            <span class="w-28 truncate text-[14px] font-[560] text-ink">{{ l.name }}</span>
                            <span class="hidden w-20 text-[12.5px] text-ink-3 tabular-nums sm:block">{{ l.units_reported }} of {{ l.units }}</span>
                            <div class="relative h-1.5 flex-1 rounded-[1px] bg-[#e8ebf0]">
                                <div class="bar-fill absolute inset-y-0 left-0 rounded-[1px]" :class="won(l) ? 'bg-navy' : 'bg-orange'" :style="{ width: shareIn(l) * 100 + '%' }"></div>
                                <div v-if="isSpread" class="absolute -top-1.5 h-[18px] w-px bg-ink" :style="{ left: t.threshold * 100 + '%' }"></div>
                            </div>
                            <span class="fig w-11 text-right text-[14px] font-[680]" :class="won(l) ? 'text-ink' : 'text-action'">{{ pct(shareIn(l)) }}</span>
                            <span v-if="!isSpread" class="fig hidden w-16 text-right text-[13px] font-[600] sm:block" :class="!hasVotes(l) ? 'text-ink-3' : marginIn(l) > 0 ? 'text-good' : 'text-action'">{{ hasVotes(l) ? (marginIn(l) > 0 ? '+' : marginIn(l) < 0 ? '−' : '') + num(Math.abs(marginIn(l))) : '–' }}</span>
                        </RouterLink>
                    </li>
                </ul>
            </section>
            </div>

            <div class="contents xl:flex xl:min-w-0 xl:flex-col xl:gap-4">
                <StateMapCard v-if="showMap" :key="race?.id ?? 'map'" class="order-2 min-w-0 xl:order-none" :loader="mapLoader" :tally="t" :our-code="ourCode" :election="race?.id" />
            <section id="alerts" class="card order-4 min-w-0 scroll-mt-4 xl:order-none">
                <header class="card-head">
                    <h2 class="card-title">Live alerts</h2>
                    <RouterLink to="/alerts" class="text-[13px] font-[600] text-navy hover:underline">{{ openCount > openAlerts.length ? `See all ${num(openCount)}` : 'Alerts page' }}</RouterLink>
                </header>
                <div class="px-5 py-2">
                    <LoadState :loading="alerts.loading.value" :error="alerts.error.value" :empty="!openAlerts.length" empty-text="No open alerts for this race.">
                        <AlertList compact :alerts="openAlerts" :can-resolve="hasRole('situation_room', 'dg', 'legal')" :busy="busy" @resolve="resolve" />
                    </LoadState>
                </div>
            </section>
            </div>
        </div>
        </template>
    </LoadState>
</template>
