<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import Pagination from '../components/Pagination.vue';
import SearchSelect from '../components/SearchSelect.vue';
import SortTh from '../components/SortTh.vue';
import { useSortState } from '../lib/useSort';
import Modal from '../components/Modal.vue';
import ResultFlags from '../components/ResultFlags.vue';
import Icon from '../components/Icon.vue';
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
// Latest result first; the server sorts, so a header click reloads page 1.
const sort = useSortState({
    result: { label: 'Our result', dir: 'desc' },
    code: { label: 'Unit', dir: 'asc' },
    agent: { label: 'Agent', dir: 'asc' },
    flags: { label: 'Flag', dir: 'desc' },
}, { key: 'result', dir: 'desc' }, { mode: 'server' });
const units = useResource(() => api.get('units', { ...filters, ...sort.params }), { refreshMs: 60000 });
watch(() => [sort.key, sort.dir], () => { filters.page = 1; units.load(); });

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
    { value: 'flagged', key: 'flagged', label: 'Overvote', dot: 'bg-action' },
    { value: 'review_flagged', key: 'review_flagged', label: 'Reviewer flags', dot: 'bg-[#b4470a]' },
];
const counts = computed(() => meta.value?.counts ?? {});
const band = (p) => (p >= 90 ? 'high' : p >= 75 ? 'mid' : 'low');
const allTiles = computed(() => (tally.data.value?.lgas ?? []).map((l) => ({ ...l, pct: l.units ? Math.round((l.units_reported / l.units) * 100) : 0 })));
// The tiles can be ordered by name or by share reported, and each colour band shown or hidden from its legend.
const tileOrder = ref('name');
const tileOrders = [['name', 'A–Z'], ['low', 'Lowest first'], ['high', 'Highest first']];
const bands = [
    { key: 'high', label: '90% or more', swatch: 'bg-navy' },
    { key: 'mid', label: '75 to 89%', swatch: 'bg-[#6b7fa3]' },
    { key: 'low', label: 'Below 75%, chase now', swatch: 'bg-[#f6c9ae] ring-1 ring-[#e8a07a]' },
];
const hiddenBands = ref(new Set());
const toggleBand = (k) => {
    const next = new Set(hiddenBands.value);
    next.has(k) ? next.delete(k) : next.add(k);
    hiddenBands.value = next;
};
const bandCount = computed(() => allTiles.value.reduce((c, l) => ({ ...c, [band(l.pct)]: (c[band(l.pct)] ?? 0) + 1 }), {}));
const lgaTiles = computed(() => {
    const shown = allTiles.value.filter((l) => !hiddenBands.value.has(band(l.pct)));
    const byName = (a, b) => a.name.localeCompare(b.name);
    return [...shown].sort(tileOrder.value === 'name' ? byName : (a, b) => (tileOrder.value === 'low' ? a.pct - b.pct : b.pct - a.pct) || byName(a, b));
});
const tileClass = (p) => (p >= 90 ? 'border-navy bg-navy text-white' : p >= 75 ? 'border-[#6b7fa3] bg-[#6b7fa3] text-white' : 'border-[#e8a07a] bg-[#f6c9ae] text-[#5c2206]');
const irev = {
    match: ['text-good', 'Matches'],
    differs: ['text-action', 'Differs'],
    not_on_irev: ['text-action', 'Not on IReV'],
    no_agent_result: ['text-ink-2', 'On IReV'],
};
const canSeeEvidence = hasRole('legal', 'dg');
const canFlag = hasRole('verifier', 'situation_room', 'dg', 'legal');
const flagsFor = ref(null);

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
    <PageHeader title="Units" :subtitle="counts.all != null ? `${num(counts.all)} polling units across ${allTiles.length} LGAs` : ''" :updated-at="units.updatedAt.value" :loading="units.loading.value" @refresh="units.load(); tally.load()" />

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
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="card-title">Reporting by LGA</h2>
                    <p class="card-sub">Share of units with a signed result. Select an LGA to filter the list.</p>
                </div>
                <div class="inline-flex rounded-xl bg-mist p-1 text-[12.5px] font-semibold" role="group" aria-label="Order LGAs">
                    <button v-for="[k, label] in tileOrders" :key="k" type="button" class="rounded-lg px-2.5 py-1 transition" :class="tileOrder === k ? 'bg-white text-ink shadow-sm' : 'text-ink-2 hover:text-ink'" :aria-pressed="tileOrder === k" @click="tileOrder = k">{{ label }}</button>
                </div>
            </div>
            <p v-if="!lgaTiles.length" class="mt-4 rounded-xl bg-mist px-4 py-6 text-center text-sm text-ink-2">No LGAs in the bands you've chosen. <button class="font-bold text-navy hover:underline" @click="hiddenBands = new Set()">Show all</button></p>
            <TransitionGroup tag="div" name="tile" class="mt-4 grid grid-cols-[repeat(auto-fill,minmax(104px,1fr))] gap-2">
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
            </TransitionGroup>
            <!-- The legend is also the filter: click a band to hide or show its LGAs. -->
            <div class="mt-4 flex flex-wrap items-center gap-2 text-[12.5px]">
                <button
                    v-for="b in bands"
                    :key="b.key"
                    type="button"
                    class="flex items-center gap-1.5 rounded-full border px-2.5 py-1 font-semibold transition"
                    :class="hiddenBands.has(b.key) ? 'border-dashed border-line text-ink-3 line-through' : 'border-line bg-white text-ink-2 hover:border-navy hover:text-ink'"
                    :aria-pressed="!hiddenBands.has(b.key)"
                    :title="hiddenBands.has(b.key) ? 'Show these LGAs' : 'Hide these LGAs'"
                    @click="toggleBand(b.key)"
                >
                    <span class="h-3 w-3 rounded-[3px]" :class="[b.swatch, hiddenBands.has(b.key) ? 'opacity-40' : '']"></span>{{ b.label }} <b class="no-underline">{{ bandCount[b.key] ?? 0 }}</b>
                </button>
                <button v-if="hiddenBands.size" type="button" class="ml-1 font-bold text-navy hover:underline" @click="hiddenBands = new Set()">Show all</button>
            </div>
        </section>

        <section class="card min-w-0 overflow-hidden">
            <div class="flex flex-wrap gap-3 border-b border-[#eef0f3] p-4">
                <label class="sr-only" for="unit-search">Search units</label>
                <input id="unit-search" v-model="filters.search" class="field max-w-xs flex-1" placeholder="Search unit code or name" />
                <label class="sr-only" for="lga">LGA</label>
                <SearchSelect id="lga" v-model="filters.lga_id" class="w-full sm:w-[220px]" all-label="All LGAs" noun="LGAs" :options="(structure.data.value?.lgas ?? []).map((l) => ({ value: l.id, label: l.name }))" />
            </div>
            <LoadState :loading="units.loading.value" :error="units.error.value" :empty="!rows.length" empty-text="No units match.">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[820px] text-sm">
                        <thead><tr><SortTh :sort="sort" k="code">Unit</SortTh><SortTh :sort="sort" k="agent">Agent</SortTh><SortTh :sort="sort" k="result">Our result</SortTh><th class="th">IReV</th><SortTh :sort="sort" k="flags">Flag</SortTh><th class="th"></th></tr></thead>
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
                                <td class="td">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <Badge :tone="flag(u)[0]">{{ flag(u)[1] }}</Badge>
                                        <button v-if="u.result && (u.open_flags || canFlag)" class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-bold transition" :class="u.open_flags ? 'bg-bad-bg text-bad hover:brightness-95' : 'text-ink-3 hover:bg-mist hover:text-ink'" :disabled="!canFlag" :title="u.open_flags ? 'See the reviewer flags' : 'Flag this result with a comment'" @click="flagsFor = u">
                                            <Icon name="flag" :size="13" />{{ u.open_flags || '' }}
                                        </button>
                                    </div>
                                </td>
                                <td class="td text-right"><RouterLink v-if="canSeeEvidence" :to="`/units/${u.id}/evidence`" class="inline-flex items-center gap-1 text-[13px] font-bold whitespace-nowrap hover:underline">Evidence <span aria-hidden="true">→</span></RouterLink></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :meta="meta" :sort="sort" noun="units" :per-page-options="[25, 50, 100, 200]" :loading="units.loading.value" @page="go" @per-page="(n) => { filters.per_page = n; go(1); }" />
            </LoadState>
        </section>
    </div>

    <Modal :open="!!flagsFor" :title="flagsFor ? `${flagsFor.code} · ${flagsFor.name}` : ''" :subtitle="flagsFor ? `${flagsFor.ward} · ${flagsFor.lga} · ${num(flagsFor.result?.total_cast)} votes cast` : ''" width="max-w-2xl" @close="flagsFor = null">
        <ResultFlags v-if="flagsFor?.result" :result-id="flagsFor.result.id" @changed="(n) => { flagsFor.open_flags = n; units.load(); }" />
    </Modal>
</template>
