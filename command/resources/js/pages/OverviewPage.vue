<script setup>
import { computed, ref } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import AlertList from '../components/AlertList.vue';
import LoadState from '../components/LoadState.vue';
import StateMapCard from '../components/StateMapCard.vue';
import RaceBoard from '../components/RaceBoard.vue';
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

const isBoard = computed(() => !!tally.data.value?.races);
const t = computed(() => (isBoard.value ? null : tally.data.value));
const rule = computed(() => t.value?.rule ?? 'spread');
const areaWord = computed(() => (t.value?.area_level === 'ward' ? 'ward' : 'LGA'));
const areasWord = computed(() => `${areaWord.value}s`);
const ours = computed(() => t.value?.candidates.find((c) => c.is_ours));
const top = computed(() => t.value?.candidates[0]);
const maxVotes = computed(() => Math.max(1, ...(t.value?.candidates.map((c) => c.votes) ?? [1])));
const ourCode = computed(() => ours.value?.party);
// The overview shows the five newest; the count and high-priority figure cover every open alert.
const openAlerts = computed(() => alerts.data.value?.data ?? []);
const openCount = computed(() => alerts.data.value?.total ?? 0);
const high = computed(() => alerts.data.value?.counts?.severity?.high ?? 0);
const totalVotes = computed(() => (t.value?.candidates ?? []).reduce((sum, c) => sum + (c.votes ?? 0), 0));
const ourShare = computed(() => (totalVotes.value && ours.value ? ours.value.votes / totalVotes.value : 0));
const shareIn = (l) => l.share?.[ourCode.value] ?? 0;
const hasVotes = (l) => Object.values(l.votes ?? {}).some((v) => v > 0);
const label = (c) => c?.candidate || c?.name;

/** Our margin over the best other party in one area: positive where we lead. */
function marginIn(l) {
    const mine = l.votes?.[ourCode.value] ?? 0;
    const best = Math.max(0, ...Object.entries(l.votes ?? {}).filter(([p]) => p !== ourCode.value).map(([, v]) => v));
    return mine - best;
}
// The race's margin: over the runner-up where we lead, behind the leader where we don't.
const second = computed(() => t.value?.candidates.find((c) => c.party !== top.value?.party));
const margin = computed(() => {
    if (!ours.value || !top.value) return 0;
    return top.value.party === ours.value.party ? ours.value.votes - (second.value?.votes ?? 0) : ours.value.votes - top.value.votes;
});
const rival = computed(() => (top.value?.party === ourCode.value ? second.value : top.value));

/**
 * The scoreboard strip: one block per area. Under the 25% spread rule a block is ours when we
 * have a quarter of its vote, and the marker shows the two-thirds the constitution needs.
 * Otherwise a block is ours where we lead it.
 */
const isSpread = computed(() => rule.value === 'spread');
const won = (l) => (isSpread.value ? shareIn(l) >= t.value.threshold : marginIn(l) > 0);
const strip = computed(() => [...(t.value?.lgas ?? [])]
    .sort((a, b) => (hasVotes(b) - hasVotes(a)) || (isSpread.value ? shareIn(b) - shareIn(a) : marginIn(b) - marginIn(a)))
    .map((l) => ({ ...l, s: shareIn(l), m: marginIn(l), state: !hasVotes(l) ? 'none' : won(l) ? 'met' : 'below' })));
const met = computed(() => (isSpread.value ? ours.value?.lgas_meeting_threshold ?? 0 : strip.value.filter((l) => l.state === 'met').length));
const short = computed(() => Math.max(0, (t.value?.lgas_required ?? 0) - met.value));

/** The big figure and the line under it, by what wins this race. */
const hero = computed(() => {
    const T = t.value;
    if (!ours.value) return { fig: '–', words: 'We are not on this ballot', verdict: { tone: 'text-ink-2', text: 'Add our party to the ballot under Setup, Races.' } };
    if (rule.value === 'spread') {
        return {
            fig: met.value,
            words: `of ${T.lga_count} LGAs give us 25% or more`,
            verdict: !totalVotes.value ? { tone: 'text-ink-2', text: `The constitution needs ${T.lgas_required}. No results are in yet.` }
                : ours.value.meets_spread ? { tone: 'text-good', text: `That clears the ${T.lgas_required} the constitution requires.` }
                    : { tone: 'text-action', text: `The constitution requires ${T.lgas_required}. We are ${short.value} short.` },
        };
    }
    if (rule.value === 'state_quarter') {
        return {
            fig: pct(ourShare.value),
            words: `of the vote in ${session.user?.campaign?.state_name ?? 'this state'}`,
            verdict: !totalVotes.value ? { tone: 'text-ink-2', text: 'A president needs 25% in two-thirds of the states. No results are in yet.' }
                : ourShare.value >= 0.25 ? { tone: 'text-good', text: 'This state counts toward the two-thirds a president needs.' }
                    : { tone: 'text-action', text: `Under the 25% this state needs to count toward the two-thirds. ${pct(0.25 - ourShare.value)} to go.` },
        };
    }
    if (!totalVotes.value) return { fig: '–', words: 'No results yet', verdict: { tone: 'text-ink-2', text: 'Most votes wins. The margin shows here with the first results.' } };
    return {
        fig: (margin.value > 0 ? '+' : margin.value < 0 ? '−' : '') + num(Math.abs(margin.value)),
        words: margin.value > 0 ? `votes ahead of ${label(rival.value)}` : margin.value < 0 ? `votes behind ${label(rival.value)}` : 'votes: level',
        verdict: margin.value > 0 ? { tone: 'text-good', text: `We lead with ${pct(ourShare.value)} of the votes counted. Most votes wins.` }
            : { tone: 'text-action', text: `${label(top.value)} leads with ${pct(top.value.votes / totalVotes.value)}. We have ${pct(ourShare.value)}.` },
    };
});
const legend = computed(() => (isSpread.value
    ? [['bg-navy', '25% or more'], ['bg-orange', 'Below 25%'], ['bg-[#dfe3ea]', 'No results yet']]
    : [['bg-navy', 'We lead'], ['bg-orange', 'Another party leads'], ['bg-[#dfe3ea]', 'No results yet']]));
const stripTitle = computed(() => (isSpread.value ? null : `We lead in ${met.value} of ${t.value?.lga_count ?? 0} ${areasWord.value}`));
const blockClass = { met: 'bg-navy hover:bg-navy-3', below: 'bg-orange hover:bg-action', none: 'bg-[#dfe3ea] hover:bg-[#cdd3dc]' };
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
        <!-- The scoreboard: where we stand by the rule that wins this race, then the three figures behind it. -->
        <section class="card grid lg:grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)]" aria-label="Where we stand">
            <div class="p-5 sm:p-6">
                <p class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span class="fig text-[52px] leading-[0.95] font-[750] tracking-[-0.02em]" :class="!isSpread && rule !== 'state_quarter' && margin < 0 ? 'text-action' : 'text-ink'">{{ hero.fig }}</span>
                    <span class="text-[17px] leading-snug font-[600] text-ink">{{ hero.words }}</span>
                </p>
                <p class="mt-1.5 text-[14px] font-[560]" :class="hero.verdict.tone">{{ hero.verdict.text }}</p>

                <p v-if="stripTitle && totalVotes" class="mt-5 text-[13px] font-[600] text-ink-2">{{ stripTitle }}</p>
                <div class="relative pb-8" :class="stripTitle && totalVotes ? 'mt-2' : 'mt-6'">
                    <div class="flex gap-[3px]">
                        <RouterLink
                            v-for="l in strip"
                            :key="l.id"
                            :to="areaLink(l)"
                            class="h-9 min-w-0 flex-1 rounded-[2px] transition-colors"
                            :class="blockClass[l.state]"
                            :title="`${l.name}: ${l.state === 'none' ? 'no results yet' : isSpread ? pct(l.s) : (l.m > 0 ? 'ahead by ' : 'behind by ') + num(Math.abs(l.m))}`"
                            :aria-label="`${l.name}, ${l.state === 'none' ? 'no results yet' : pct(l.s) + ' our share'}`"
                        ></RouterLink>
                    </div>
                    <!-- The line the constitution draws: two-thirds of the LGAs. -->
                    <div v-if="isSpread && t.lga_count" class="pointer-events-none absolute top-[-6px] bottom-0" :style="{ left: `calc(${(t.lgas_required / t.lga_count) * 100}% - 2px)` }">
                        <div class="h-[48px] w-0.5 bg-ink"></div>
                        <span class="absolute top-[48px] left-0 mt-1 -translate-x-1/2 text-[12px] font-[650] whitespace-nowrap text-ink tabular-nums">{{ t.lgas_required }} needed</span>
                    </div>
                </div>
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-[12.5px] text-ink-2" :class="!isSpread && '-mt-5'">
                    <span v-for="[c, l] in legend" :key="l" class="inline-flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-[2px]" :class="c"></i>{{ l }}</span>
                </div>
            </div>

            <dl class="grid border-t border-line sm:grid-cols-3 lg:grid-cols-1 lg:border-t-0 lg:border-l">
                <RouterLink to="/units?status=reported" class="group flex items-baseline justify-between gap-4 border-b border-rule px-5 py-4 transition-colors hover:bg-[#f8f9fb] sm:border-r sm:border-b-0 lg:border-r-0 lg:border-b">
                    <div>
                        <dt class="text-[13px] text-ink-2">Units reporting</dt>
                        <dd class="mt-0.5 text-[12.5px] text-ink-3 tabular-nums">of {{ num(t.units) }} polling units</dd>
                    </div>
                    <dd class="fig text-[28px] leading-none font-[700] text-ink">{{ num(t.units_reported) }}</dd>
                </RouterLink>
                <a href="#running-totals" class="group flex items-baseline justify-between gap-4 border-b border-rule px-5 py-4 transition-colors hover:bg-[#f8f9fb] sm:border-r sm:border-b-0 lg:border-r-0 lg:border-b">
                    <div>
                        <dt class="text-[13px] text-ink-2">Our votes</dt>
                        <dd class="mt-0.5 text-[12.5px]" :class="ours?.leads ? 'text-good' : 'text-ink-3'">{{ ours ? `${pct(ourShare)} of votes counted, ${ours.leads ? 'leading' : 'not leading'}` : 'Not on this ballot' }}</dd>
                    </div>
                    <dd class="fig text-[28px] leading-none font-[700] text-ink">{{ num(ours?.votes) }}</dd>
                </a>
                <RouterLink to="/alerts" class="group flex items-baseline justify-between gap-4 px-5 py-4 transition-colors hover:bg-[#f8f9fb]">
                    <div>
                        <dt class="text-[13px] text-ink-2">Open alerts</dt>
                        <dd class="mt-0.5 text-[12.5px]" :class="high ? 'text-action' : 'text-ink-3'">{{ openCount ? `${high} high priority` : 'Nothing needs action' }}</dd>
                    </div>
                    <dd class="fig text-[28px] leading-none font-[700]" :class="openCount ? 'text-action' : 'text-ink'">{{ num(openCount) }}</dd>
                </RouterLink>
            </dl>
        </section>

        <div class="mt-4 grid items-start gap-4 [&>*]:min-w-0 xl:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
            <section id="threshold" class="card scroll-mt-4">
                <header class="card-head">
                    <h2 class="card-title">{{ isSpread ? `Our share in each ${areaWord}` : `Each ${areaWord}` }}</h2>
                    <span class="text-[12.5px] text-ink-2">{{ isSpread ? 'The line marks 25%' : 'Our share, and how far ahead or behind' }}</span>
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

            <div class="flex min-w-0 flex-col gap-4">
                <StateMapCard v-if="mapLoader && t.area_level !== 'ward'" :loader="mapLoader" :tally="t" :our-code="ourCode" :election="race?.id" />
                <section id="alerts" class="card scroll-mt-4">
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
                <section id="running-totals" class="card scroll-mt-4">
                    <header class="card-head">
                        <h2 class="card-title">Running totals</h2>
                        <span class="text-[12.5px] text-ink-2 tabular-nums">from {{ num(t.units_reported) }} units</span>
                    </header>
                    <div class="px-5 py-4">
                        <div v-for="c in t.candidates" :key="c.party" class="mb-3.5 last:mb-0">
                            <div class="mb-1.5 flex items-baseline justify-between gap-3 text-[14px]">
                                <span class="min-w-0" :class="c.is_ours ? 'font-[650]' : 'text-ink-2'">
                                    {{ c.candidate || c.name }}<span v-if="c.is_ours" class="ml-1.5 rounded bg-[#eceff3] px-1.5 py-px text-[11.5px] font-[600] text-ink-2">ours</span>
                                    <span v-if="c.candidate" class="block text-[12.5px] font-[450] text-ink-3">{{ c.name }}</span>
                                </span>
                                <span class="fig shrink-0 font-[680]">{{ num(c.votes) }} <span class="ml-1 inline-block w-10 text-right text-[12.5px] font-[500] text-ink-3">{{ totalVotes ? pct(c.votes / totalVotes) : '' }}</span></span>
                            </div>
                            <div class="h-1.5 rounded-[1px] bg-[#e8ebf0]">
                                <div class="bar-fill h-full rounded-[1px]" :class="c.is_ours ? 'bg-navy' : 'bg-[#9aa3b2]'" :style="{ width: (c.votes / maxVotes) * 100 + '%' }"></div>
                            </div>
                        </div>
                        <p v-if="top && top.votes && isSpread" class="mt-4 text-[13px] text-ink-2">
                            {{ label(top) }} leads{{ top.meets_spread ? ' and meets the 25% spread.' : ' but does not yet meet the 25% spread.' }}
                        </p>
                    </div>
                </section>
            </div>
        </div>
        </template>
    </LoadState>
</template>
