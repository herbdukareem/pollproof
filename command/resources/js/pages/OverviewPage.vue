<script setup>
import { computed, ref } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import StatTile from '../components/StatTile.vue';
import AlertList from '../components/AlertList.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { hasRole, session } from '../lib/session';
import { num, pct } from '../lib/format';

const tally = useResource(() => api.get('tally'), { refreshMs: 30000 });
const alerts = useResource(() => api.get('alerts', { status: 'open' }), { refreshMs: 30000 });
const busy = ref(null);

const t = computed(() => tally.data.value);
const ours = computed(() => t.value?.candidates.find((c) => c.is_ours));
const top = computed(() => t.value?.candidates[0]);
const maxVotes = computed(() => Math.max(1, ...(t.value?.candidates.map((c) => c.votes) ?? [1])));
const ourCode = computed(() => ours.value?.party);
const openAlerts = computed(() => alerts.data.value?.data ?? []);
const high = computed(() => openAlerts.value.filter((a) => a.severity === 'high').length);
const totalVotes = computed(() => (t.value?.candidates ?? []).reduce((sum, c) => sum + (c.votes ?? 0), 0));
const ourShare = computed(() => (totalVotes.value && ours.value ? ours.value.votes / totalVotes.value : 0));

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
    <PageHeader title="Overview" :subtitle="`${session.user?.campaign?.state_name ?? ''} governorship · running totals from our agents' signed results`" :updated-at="tally.updatedAt.value" :loading="tally.loading.value" @refresh="refresh" />

    <LoadState :loading="tally.loading.value" :error="tally.error.value" :empty="!t">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatTile to="/units?status=reported" label="Units reporting" :value="num(t.units_reported)" :pct="t.units ? (t.units_reported / t.units) * 100 : 0" :note="`of ${num(t.units)} polling units`" />
            <StatTile to="#running-totals" label="Our votes" :value="num(ours?.votes)" :pct="ourShare * 100" :tone="ours?.leads ? 'good' : 'navy'" :note="ours ? `${pct(ourShare)} of votes counted · ${ours.leads ? 'leading' : 'not leading'}` : 'No party marked as ours'" />
            <StatTile to="#threshold" label="LGAs at 25% or more" :value="ours ? `${ours.lgas_meeting_threshold} of ${t.lga_count}` : '–'" :pct="t.lga_count ? ((ours?.lgas_meeting_threshold ?? 0) / t.lga_count) * 100 : 0" :tone="ours?.meets_spread ? 'good' : 'bad'" :note="`Threshold needs ${t.lgas_required}`" />
            <StatTile to="#alerts" label="Open alerts" :value="openAlerts.length" :pct="openAlerts.length ? (high / openAlerts.length) * 100 : 0" tone="bad" :note="openAlerts.length ? `${high} high priority` : 'Nothing needs action'" />
        </div>

        <div class="mt-5 grid items-start gap-5 xl:grid-cols-[1.35fr_1fr]">
            <section id="threshold" class="card scroll-mt-24 p-5 transition">
                <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                        <h2 class="card-title">25% threshold tracker</h2>
                        <p class="card-sub">Our share of valid votes in each LGA. We need at least 25% in {{ t.lgas_required }} of {{ t.lga_count }}.</p>
                    </div>
                    <span v-if="ours" class="pill" :class="ours.meets_spread ? 'bg-good-bg text-good' : 'bg-warn-bg text-warn'">{{ ours.lgas_meeting_threshold }} of {{ t.lga_count }} met · {{ ours.meets_spread ? 'threshold secured' : `needs ${t.lgas_required}` }}</span>
                </div>
                <ul>
                    <li v-for="l in t.lgas" :key="l.id" class="border-b border-[#f0f2f5] last:border-0">
                        <RouterLink :to="`/units?lga_id=${l.id}`" class="-mx-2 flex h-11 items-center gap-3 rounded-lg px-2 transition hover:bg-[#f6f7f9]" :title="`Open ${l.name} units`">
                        <span class="w-28 truncate text-sm font-semibold text-ink">{{ l.name }}</span>
                        <span class="hidden w-24 text-xs text-ink-2 sm:block">{{ l.units_reported }} / {{ l.units }} units</span>
                        <div class="relative h-2.5 flex-1 rounded-full bg-[#e9ecf1]">
                            <div class="bar-fill absolute inset-y-0 left-0 rounded-full" :class="(l.share[ourCode] ?? 0) >= t.threshold ? 'bg-navy' : 'bg-action'" :style="{ width: (l.share[ourCode] ?? 0) * 100 + '%' }"></div>
                            <div class="absolute -top-1.5 h-5.5 w-0.5 bg-navy" :style="{ left: t.threshold * 100 + '%' }"></div>
                        </div>
                        <span class="w-12 text-right text-sm font-bold tabular-nums" :class="(l.share[ourCode] ?? 0) >= t.threshold ? '' : 'text-action'">{{ pct(l.share[ourCode] ?? 0) }}</span>
                        </RouterLink>
                    </li>
                </ul>
            </section>

            <div class="flex flex-col gap-5">
                <section id="alerts" class="card scroll-mt-24 p-5 transition">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="card-title">Live alerts <span v-if="openAlerts.length" class="ml-1 rounded-full bg-action px-2 py-px align-middle text-xs text-white">{{ openAlerts.length }}</span></h2>
                        <RouterLink to="/collation" class="text-[13px] font-bold underline-offset-2 hover:underline">View all</RouterLink>
                    </div>
                    <LoadState :loading="alerts.loading.value" :error="alerts.error.value" :empty="!openAlerts.length" empty-text="No open alerts.">
                        <AlertList class="max-h-[560px] overflow-y-auto pr-1" :alerts="openAlerts" :can-resolve="hasRole('situation_room', 'dg', 'legal')" :busy="busy" @resolve="resolve" />
                    </LoadState>
                </section>
                <section id="running-totals" class="card scroll-mt-24 p-5 transition">
                    <h2 class="card-title mb-4">Running totals · {{ num(t.units_reported) }} units</h2>
                    <div v-for="c in t.candidates" :key="c.party" class="mb-3.5 last:mb-0">
                        <div class="mb-1.5 flex justify-between text-sm">
                            <span :class="c.is_ours ? 'font-bold' : ''">{{ c.name }}{{ c.is_ours ? ' (ours)' : '' }}</span>
                            <span class="font-bold">{{ num(c.votes) }}</span>
                        </div>
                        <div class="h-2.5 rounded-full bg-[#e9ecf1]">
                            <div class="bar-fill h-full rounded-full" :class="c.is_ours ? 'bg-navy' : 'bg-ink-3'" :style="{ width: (c.votes / maxVotes) * 100 + '%' }"></div>
                        </div>
                    </div>
                    <p v-if="top && top.votes" class="mt-4 text-[13px] text-ink-2">
                        {{ top.name }} leads{{ top.meets_spread ? ' and meets the 25% spread.' : ' but does not yet meet the 25% spread.' }}
                    </p>
                </section>
            </div>
        </div>
    </LoadState>
</template>
