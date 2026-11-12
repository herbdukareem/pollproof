<script setup>
import { computed, ref } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import AlertList from '../components/AlertList.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import Icon from '../components/Icon.vue';
import StateMapCard from '../components/StateMapCard.vue';
import { stateMapFor } from '../maps';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { hasRole, session } from '../lib/session';
import { num, pct } from '../lib/format';

const tally = useResource(() => api.get('tally'), { refreshMs: 30000 });
const alerts = useResource(() => api.get('alerts', { status: 'open', per_page: 5, sort: 'latest' }), { refreshMs: 30000 });
const busy = ref(null);
// The state map, when the server names one for this campaign's state (POLLPROOF_STATE_MAP).
const mapLoader = computed(() => stateMapFor(session.user?.rules?.state_map, session.user?.campaign?.state_name));

const t = computed(() => tally.data.value);
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

/**
 * The scoreboard strip: one block per LGA, best share first, so the met LGAs fill from the left
 * and the marker shows how many the constitution needs (two-thirds of the LGAs).
 */
const strip = computed(() => [...(t.value?.lgas ?? [])]
    .sort((a, b) => (hasVotes(b) - hasVotes(a)) || shareIn(b) - shareIn(a))
    .map((l) => ({ ...l, s: shareIn(l), state: !hasVotes(l) ? 'none' : shareIn(l) >= t.value.threshold ? 'met' : 'below' })));
const met = computed(() => ours.value?.lgas_meeting_threshold ?? 0);
const short = computed(() => Math.max(0, (t.value?.lgas_required ?? 0) - met.value));
const verdict = computed(() => {
    if (!ours.value) return { tone: 'text-ink-2', text: 'Mark one party as ours under Setup to track the rule.' };
    if (!totalVotes.value) return { tone: 'text-ink-2', text: `The constitution needs ${t.value.lgas_required}. No results are in yet.` };
    return ours.value.meets_spread
        ? { tone: 'text-good', text: `That clears the ${t.value.lgas_required} the constitution requires.` }
        : { tone: 'text-action', text: `The constitution requires ${t.value.lgas_required}. We are ${short.value} short.` };
});
const blockClass = { met: 'bg-navy hover:bg-navy-3', below: 'bg-orange hover:bg-action', none: 'bg-[#dfe3ea] hover:bg-[#cdd3dc]' };

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
    <PageHeader title="Overview" :subtitle="`${session.user?.campaign?.state_name ?? ''} governorship, from our agents' signed results`" :updated-at="tally.updatedAt.value" :loading="tally.loading.value" @refresh="refresh" />

    <LoadState :loading="tally.loading.value" :error="tally.error.value" :empty="!t">
        <!-- The scoreboard: where we stand against the 25% rule, then the three figures behind it. -->
        <section class="card grid lg:grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)]" aria-label="Where we stand">
            <div class="p-5 sm:p-6">
                <p class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span class="fig text-[52px] leading-[0.95] font-[750] tracking-[-0.02em] text-ink">{{ ours ? met : '–' }}</span>
                    <span class="text-[17px] leading-snug font-[600] text-ink">of {{ t.lga_count }} LGAs give us 25% or more</span>
                </p>
                <p class="mt-1.5 text-[14px] font-[560]" :class="verdict.tone">{{ verdict.text }}</p>

                <div class="relative mt-6 pb-8">
                    <div class="flex gap-[3px]">
                        <RouterLink
                            v-for="l in strip"
                            :key="l.id"
                            :to="`/units?lga_id=${l.id}`"
                            class="h-9 min-w-0 flex-1 rounded-[2px] transition-colors"
                            :class="blockClass[l.state]"
                            :title="`${l.name}: ${l.state === 'none' ? 'no results yet' : pct(l.s)}`"
                            :aria-label="`${l.name}, ${l.state === 'none' ? 'no results yet' : pct(l.s) + ' our share'}`"
                        ></RouterLink>
                    </div>
                    <!-- The line the constitution draws: two-thirds of the LGAs. -->
                    <div v-if="t.lga_count" class="pointer-events-none absolute top-[-6px] bottom-0" :style="{ left: `calc(${(t.lgas_required / t.lga_count) * 100}% - 2px)` }">
                        <div class="h-[48px] w-0.5 bg-ink"></div>
                        <span class="absolute top-[48px] left-0 mt-1 -translate-x-1/2 text-[12px] font-[650] whitespace-nowrap text-ink tabular-nums">{{ t.lgas_required }} needed</span>
                    </div>
                </div>
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-[12.5px] text-ink-2">
                    <span class="inline-flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-[2px] bg-navy"></i>25% or more</span>
                    <span class="inline-flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-[2px] bg-orange"></i>Below 25%</span>
                    <span class="inline-flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-[2px] bg-[#dfe3ea]"></i>No results yet</span>
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
                        <dd class="mt-0.5 text-[12.5px]" :class="ours?.leads ? 'text-good' : 'text-ink-3'">{{ ours ? `${pct(ourShare)} of votes counted, ${ours.leads ? 'leading' : 'not leading'}` : 'No party marked as ours' }}</dd>
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
                <header class="flex flex-wrap items-baseline justify-between gap-2 border-b border-rule px-5 pt-4 pb-3">
                    <h2 class="card-title">Our share in each LGA</h2>
                    <span class="text-[12.5px] text-ink-2">The line marks 25%</span>
                </header>
                <ul class="px-3 py-1.5">
                    <li v-for="l in t.lgas" :key="l.id">
                        <RouterLink :to="`/units?lga_id=${l.id}`" class="flex h-10 items-center gap-3 rounded-md px-2 transition-colors hover:bg-[#f6f7f9]" :title="`Open ${l.name} units`">
                            <span class="w-28 truncate text-[14px] font-[560] text-ink">{{ l.name }}</span>
                            <span class="hidden w-20 text-[12.5px] text-ink-3 tabular-nums sm:block">{{ l.units_reported }} of {{ l.units }}</span>
                            <div class="relative h-1.5 flex-1 rounded-[1px] bg-[#e8ebf0]">
                                <div class="bar-fill absolute inset-y-0 left-0 rounded-[1px]" :class="shareIn(l) >= t.threshold ? 'bg-navy' : 'bg-orange'" :style="{ width: shareIn(l) * 100 + '%' }"></div>
                                <div class="absolute -top-1.5 h-[18px] w-px bg-ink" :style="{ left: t.threshold * 100 + '%' }"></div>
                            </div>
                            <span class="fig w-11 text-right text-[14px] font-[680]" :class="shareIn(l) >= t.threshold ? 'text-ink' : 'text-action'">{{ pct(shareIn(l)) }}</span>
                        </RouterLink>
                    </li>
                </ul>
            </section>

            <div class="flex min-w-0 flex-col gap-4">
                <StateMapCard v-if="mapLoader" :loader="mapLoader" :tally="t" :our-code="ourCode" />
                <section id="alerts" class="card scroll-mt-4">
                    <header class="flex items-baseline justify-between gap-3 border-b border-rule px-5 pt-4 pb-3">
                        <h2 class="card-title">Live alerts</h2>
                        <RouterLink to="/alerts" class="text-[13px] font-[600] text-navy hover:underline">{{ openCount > openAlerts.length ? `See all ${num(openCount)}` : 'Alerts page' }}</RouterLink>
                    </header>
                    <div class="px-5 py-2">
                        <LoadState :loading="alerts.loading.value" :error="alerts.error.value" :empty="!openAlerts.length" empty-text="No open alerts.">
                            <AlertList compact :alerts="openAlerts" :can-resolve="hasRole('situation_room', 'dg', 'legal')" :busy="busy" @resolve="resolve" />
                        </LoadState>
                    </div>
                </section>
                <section id="running-totals" class="card scroll-mt-4">
                    <header class="flex items-baseline justify-between gap-3 border-b border-rule px-5 pt-4 pb-3">
                        <h2 class="card-title">Running totals</h2>
                        <span class="text-[12.5px] text-ink-2 tabular-nums">from {{ num(t.units_reported) }} units</span>
                    </header>
                    <div class="px-5 py-4">
                        <div v-for="c in t.candidates" :key="c.party" class="mb-3.5 last:mb-0">
                            <div class="mb-1.5 flex items-baseline justify-between gap-3 text-[14px]">
                                <span :class="c.is_ours ? 'font-[650]' : 'text-ink-2'">{{ c.name }}<span v-if="c.is_ours" class="ml-1.5 rounded bg-[#eceff3] px-1.5 py-px text-[11.5px] font-[600] text-ink-2">ours</span></span>
                                <span class="fig font-[680]">{{ num(c.votes) }} <span class="ml-1 inline-block w-10 text-right text-[12.5px] font-[500] text-ink-3">{{ totalVotes ? pct(c.votes / totalVotes) : '' }}</span></span>
                            </div>
                            <div class="h-1.5 rounded-[1px] bg-[#e8ebf0]">
                                <div class="bar-fill h-full rounded-[1px]" :class="c.is_ours ? 'bg-navy' : 'bg-[#9aa3b2]'" :style="{ width: (c.votes / maxVotes) * 100 + '%' }"></div>
                            </div>
                        </div>
                        <p v-if="top && top.votes" class="mt-4 text-[13px] text-ink-2">
                            {{ top.name }} leads{{ top.meets_spread ? ' and meets the 25% spread.' : ' but does not yet meet the 25% spread.' }}
                        </p>
                    </div>
                </section>
            </div>
        </div>
    </LoadState>
</template>
