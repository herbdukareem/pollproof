<script setup>
import { computed, reactive, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import Pagination from '../components/Pagination.vue';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { hasRole } from '../lib/session';
import { num, time } from '../lib/format';

const route = useRoute();
const router = useRouter();
const filters = reactive({ status: route.query.status ?? '', lga_id: route.query.lga_id ? Number(route.query.lga_id) : '', search: route.query.search ?? '', page: 1, per_page: 50 });
// Keep the filters in the address, so cards elsewhere can link straight to a filtered list.
watch(() => [filters.status, filters.lga_id, filters.search], ([status, lga, search]) => {
    router.replace({ query: { ...(status ? { status } : {}), ...(lga ? { lga_id: lga } : {}), ...(search ? { search } : {}) } });
});
const structure = useResource(() => api.get('structure'));
const tally = useResource(() => api.get('tally'), { refreshMs: 60000 });
const units = useResource(() => api.get('units', filters), { refreshMs: 60000 });

let debounce;
watch(() => [filters.status, filters.lga_id], () => { filters.page = 1; units.load(); });
watch(() => filters.search, () => { clearTimeout(debounce); debounce = setTimeout(() => { filters.page = 1; units.load(); }, 300); });

const rows = computed(() => units.data.value?.data ?? []);
const meta = computed(() => units.data.value);
const tabs = [
    { value: '', key: 'all', label: 'All units', dot: 'bg-ink-3' },
    { value: 'reported', key: 'reported', label: 'Reported', dot: 'bg-navy' },
    { value: 'awaiting', key: 'awaiting', label: 'Awaiting result', dot: 'bg-[#d99a00]' },
    { value: 'no_agent', key: 'no_agent', label: 'No agent', dot: 'bg-action' },
    { value: 'flagged', key: 'flagged', label: 'Flagged', dot: 'bg-action' },
];
const counts = computed(() => meta.value?.counts ?? {});
const lgaTiles = computed(() => (tally.data.value?.lgas ?? []).map((l) => ({ ...l, pct: l.units ? Math.round((l.units_reported / l.units) * 100) : 0 })));
const tileClass = (p) => (p >= 90 ? 'border-navy bg-navy text-white' : p >= 75 ? 'border-[#6b7fa3] bg-[#6b7fa3] text-white' : 'border-[#e8a07a] bg-[#f6c9ae] text-[#5c2206]');
const irev = {
    match: ['text-good', 'Matches'],
    differs: ['text-action', 'Differs'],
    not_on_irev: ['text-action', 'Not on IReV'],
    no_agent_result: ['text-ink-2', 'On IReV'],
};
const canSeeEvidence = hasRole('legal', 'dg');

function flag(u) {
    if (u.result?.flags?.includes('overvote')) return ['bad', 'Overvote'];
    if (u.irev === 'differs') return ['bad', 'IReV differs'];
    if (!u.agent) return ['bad', 'No agent'];
    if (!u.agent_checked_in) return ['warn', 'Not checked in'];
    if (!u.result) return ['neutral', 'Awaiting'];
    if (u.result.status === 'sent_back') return ['warn', 'Sent back'];
    return ['good', u.result.status === 'approved' ? 'Verified' : 'Reported'];
}

function go(page) {
    filters.page = page;
    units.load();
}
</script>

<template>
    <PageHeader title="Units" :subtitle="counts.all != null ? `${num(counts.all)} polling units across ${lgaTiles.length} LGAs` : ''" :updated-at="units.updatedAt.value" :loading="units.loading.value" @refresh="units.load(); tally.load()" />

    <div class="mb-5 flex flex-wrap gap-2.5" role="group" aria-label="Status">
        <button
            v-for="tab in tabs"
            :key="tab.value"
            class="chip"
            :class="filters.status === tab.value ? 'chip-on' : ''"
            :aria-pressed="filters.status === tab.value"
            @click="filters.status = tab.value"
        >
            <span class="h-2.5 w-2.5 rounded-[3px]" :class="filters.status === tab.value ? 'bg-white' : tab.dot"></span>
            {{ tab.label }}
            <span class="font-extrabold">{{ counts[tab.key] != null ? num(counts[tab.key]) : '' }}</span>
        </button>
    </div>

    <div class="grid items-start gap-5 2xl:grid-cols-[380px_1fr]">
        <section class="card p-5">
            <h2 class="card-title">Reporting by LGA</h2>
            <p class="card-sub mb-4">Share of units with a signed result. Select an LGA to filter the list.</p>
            <div class="grid grid-cols-[repeat(auto-fill,minmax(104px,1fr))] gap-2">
                <button
                    v-for="l in lgaTiles"
                    :key="l.id"
                    class="flex h-[76px] flex-col items-start justify-center gap-0.5 rounded-xl border-2 px-3 text-left transition hover:-translate-y-px hover:shadow-md"
                    :class="[tileClass(l.pct), String(filters.lga_id) === String(l.id) ? 'ring-2 ring-action ring-offset-2' : '']"
                    :aria-pressed="String(filters.lga_id) === String(l.id)"
                    @click="filters.lga_id = String(filters.lga_id) === String(l.id) ? '' : l.id"
                >
                    <span class="w-full truncate text-[13px] font-semibold">{{ l.name }}</span>
                    <span class="text-xl font-extrabold">{{ l.pct }}%</span>
                </button>
            </div>
            <div class="mt-4 flex flex-wrap gap-4 text-[12.5px] text-ink-2">
                <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-[3px] bg-navy"></span>90% or more</span>
                <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-[3px] bg-[#6b7fa3]"></span>75 to 89%</span>
                <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-[3px] bg-[#f6c9ae]"></span>Below 75%, chase now</span>
            </div>
        </section>

        <section class="card min-w-0 overflow-hidden">
            <div class="flex flex-wrap gap-3 border-b border-[#eef0f3] p-4">
                <label class="sr-only" for="unit-search">Search units</label>
                <input id="unit-search" v-model="filters.search" class="field max-w-xs flex-1" placeholder="Search unit code or name" />
                <label class="sr-only" for="lga">LGA</label>
                <select id="lga" v-model="filters.lga_id" class="field max-w-[220px]">
                    <option value="">All LGAs</option>
                    <option v-for="l in structure.data.value?.lgas ?? []" :key="l.id" :value="l.id">{{ l.name }}</option>
                </select>
            </div>
            <LoadState :loading="units.loading.value" :error="units.error.value" :empty="!rows.length" empty-text="No units match.">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[820px] text-sm">
                        <thead><tr><th class="th">Unit</th><th class="th">Agent</th><th class="th">Our result</th><th class="th">IReV</th><th class="th">Flag</th><th class="th"></th></tr></thead>
                        <tbody>
                            <tr v-for="u in rows" :key="u.id">
                                <td class="td"><div class="font-bold">{{ u.code }}</div><div class="text-xs text-ink-2">{{ u.name }} · {{ u.ward }} · {{ u.lga }}</div></td>
                                <td class="td">
                                    <template v-if="u.agent"><span class="font-semibold">{{ u.agent.code }}</span> <span :class="u.agent_checked_in ? 'text-ink-2' : 'font-semibold text-action'">· {{ u.agent_checked_in ? 'present' : 'not checked in' }}</span></template>
                                    <span v-else class="font-semibold text-action">No agent</span>
                                </td>
                                <td class="td">
                                    <template v-if="u.result">{{ num(u.result.total_cast) }} cast · <span class="text-ink-2">{{ time(u.result.captured_at) }}</span></template>
                                    <span v-else class="text-ink-3">Awaiting</span>
                                </td>
                                <td class="td font-semibold" :class="u.irev ? irev[u.irev][0] : 'text-ink-3'">{{ u.irev ? irev[u.irev][1] : 'Not checked' }}</td>
                                <td class="td"><Badge :tone="flag(u)[0]">{{ flag(u)[1] }}</Badge></td>
                                <td class="td text-right"><RouterLink v-if="canSeeEvidence" :to="`/units/${u.id}/evidence`" class="inline-flex items-center gap-1 text-[13px] font-bold whitespace-nowrap hover:underline">Evidence <span aria-hidden="true">→</span></RouterLink></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :meta="meta" noun="units" :per-page-options="[25, 50, 100, 200]" :loading="units.loading.value" @page="go" @per-page="(n) => { filters.per_page = n; go(1); }" />
            </LoadState>
        </section>
    </div>
</template>
