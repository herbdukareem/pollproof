<script setup>
import { computed, ref } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import StatTile from '../components/StatTile.vue';
import AlertList from '../components/AlertList.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { hasRole } from '../lib/session';
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
    <PageHeader title="Overview" subtitle="Running totals from our agents' signed results." :updated-at="tally.updatedAt.value" :loading="tally.loading.value" @refresh="refresh" />

    <LoadState :loading="tally.loading.value" :error="tally.error.value" :empty="!t">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatTile label="Units reporting" :value="num(t.units_reported)" :pct="t.units ? (t.units_reported / t.units) * 100 : 0" :note="`of ${num(t.units)} polling units`" />
            <StatTile label="Our votes" :value="num(ours?.votes)" :note="ours ? `${ours.name}${ours.leads ? ' · leading' : ''}` : 'No party marked as ours'" />
            <StatTile label="LGAs at 25% or more" :value="ours ? `${ours.lgas_meeting_threshold} of ${t.lga_count}` : '–'" :pct="t.lga_count ? ((ours?.lgas_meeting_threshold ?? 0) / t.lga_count) * 100 : 0" :tone="ours?.meets_spread ? 'good' : 'bad'" :note="`Threshold needs ${t.lgas_required}`" />
            <StatTile label="Open alerts" :value="openAlerts.length" :pct="openAlerts.length ? 100 : 0" tone="bad" :note="`${high} high priority`" />
        </div>

        <div class="mt-5 grid items-start gap-5 xl:grid-cols-[1.35fr_1fr]">
            <section class="card p-5">
                <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                        <h2 class="text-[17px] font-bold">25% threshold tracker</h2>
                        <p class="mt-1 text-[13px] text-ink-2">Our share of valid votes in each LGA. The line marks 25%.</p>
                    </div>
                    <Badge v-if="ours" :tone="ours.meets_spread ? 'good' : 'warn'">{{ ours.lgas_meeting_threshold }} of {{ t.lga_count }} met</Badge>
                </div>
                <ul>
                    <li v-for="l in t.lgas" :key="l.id" class="flex h-11 items-center gap-3 border-b border-[#f0f2f5] last:border-0">
                        <span class="w-28 truncate text-sm font-semibold">{{ l.name }}</span>
                        <span class="hidden w-24 text-xs text-ink-2 sm:block">{{ l.units_reported }} / {{ l.units }} units</span>
                        <div class="relative h-2.5 flex-1 rounded-full bg-[#e9ecf1]">
                            <div class="absolute inset-y-0 left-0 rounded-full" :class="(l.share[ourCode] ?? 0) >= t.threshold ? 'bg-navy' : 'bg-action'" :style="{ width: (l.share[ourCode] ?? 0) * 100 + '%' }"></div>
                            <div class="absolute -top-1.5 h-5.5 w-0.5 bg-navy" :style="{ left: t.threshold * 100 + '%' }"></div>
                        </div>
                        <span class="w-12 text-right text-sm font-bold" :class="(l.share[ourCode] ?? 0) >= t.threshold ? '' : 'text-action'">{{ pct(l.share[ourCode] ?? 0) }}</span>
                    </li>
                </ul>
            </section>

            <div class="flex flex-col gap-5">
                <section class="card p-5">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-[17px] font-bold">Live alerts</h2>
                        <RouterLink to="/collation" class="text-[13px] font-bold">Collation checks</RouterLink>
                    </div>
                    <LoadState :loading="alerts.loading.value" :error="alerts.error.value" :empty="!openAlerts.length" empty-text="No open alerts.">
                        <AlertList :alerts="openAlerts" :can-resolve="hasRole('situation_room', 'dg', 'legal')" :busy="busy" @resolve="resolve" />
                    </LoadState>
                </section>
                <section class="card p-5">
                    <h2 class="mb-4 text-[17px] font-bold">Running totals · {{ num(t.units_reported) }} units</h2>
                    <div v-for="c in t.candidates" :key="c.party" class="mb-3.5 last:mb-0">
                        <div class="mb-1.5 flex justify-between text-sm">
                            <span :class="c.is_ours ? 'font-bold' : ''">{{ c.name }}{{ c.is_ours ? ' (ours)' : '' }}</span>
                            <span class="font-bold">{{ num(c.votes) }}</span>
                        </div>
                        <div class="h-2.5 rounded-full bg-[#e9ecf1]">
                            <div class="h-full rounded-full" :class="c.is_ours ? 'bg-navy' : 'bg-ink-3'" :style="{ width: (c.votes / maxVotes) * 100 + '%' }"></div>
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
