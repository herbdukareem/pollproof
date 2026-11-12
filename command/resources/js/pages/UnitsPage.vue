<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import Pagination from '../components/Pagination.vue';
import TableBusy from '../components/TableBusy.vue';
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
const q = route.query;
const filters = reactive({
    status: q.status ?? '', lga_id: q.lga_id ? Number(q.lga_id) : '', ward_id: q.ward_id ? Number(q.ward_id) : '', search: q.search ?? '',
    agent: q.agent ?? '', result: q.result ?? '', irev: q.irev ?? '', channel: q.channel ?? '', page: 1, per_page: 50,
});
const extra = ['ward_id', 'agent', 'result', 'irev', 'channel'];
// Keep the filters in the address, so cards elsewhere can link straight to a filtered list.
watch(() => [filters.status, filters.lga_id, filters.search, ...extra.map((k) => filters[k])], () => {
    router.replace({ query: Object.fromEntries(['status', 'lga_id', 'search', ...extra].filter((k) => filters[k] !== '' && filters[k] != null).map((k) => [k, filters[k]])) });
});
const filterSets = [
    ['agent', 'Agent', [['present', 'Checked in'], ['absent', 'Not checked in'], ['none', 'No agent']]],
    ['result', 'Result', [['verified', 'Verified'], ['pending', 'Waiting for verification'], ['sent_back', 'Sent back'], ['none', 'No result yet']]],
    ['irev', 'IReV', [['match', 'Matches'], ['differs', 'Differs'], ['missing', 'Not on IReV'], ['unchecked', 'Not checked yet']]],
    ['channel', 'Sent by', [['app', 'App'], ['sms', 'SMS'], ['ussd', 'USSD']]],
];
const anyFilter = computed(() => !!(filters.search || filters.lga_id || extra.some((k) => filters[k])));
function clearFilters() {
    Object.assign(filters, { search: '', lga_id: '', ward_id: '', agent: '', result: '', irev: '', channel: '' });
}
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
watch(() => [filters.status, filters.lga_id, ...extra.map((k) => filters[k])], () => { filters.page = 1; units.load(); });
// A ward from another LGA no longer applies once the LGA changes.
watch(() => filters.lga_id, () => {
    if (filters.ward_id && !wardOptions.value.some((w) => w.value === filters.ward_id)) filters.ward_id = '';
});
const wardOptions = computed(() => (structure.data.value?.lgas ?? [])
    .filter((l) => !filters.lga_id || l.id === Number(filters.lga_id))
    .flatMap((l) => l.wards.map((w) => ({ value: w.id, label: filters.lga_id ? w.name : `${w.name} · ${l.name}`, hint: `${w.units} units` }))));
watch(() => filters.search, () => { clearTimeout(debounce); debounce = setTimeout(() => { filters.page = 1; units.load(); }, 300); });

const rows = computed(() => units.data.value?.data ?? []);
const meta = computed(() => units.data.value);
// Statuses that need someone to act show their count in orange.
const tabs = [
    { value: '', key: 'all', label: 'All units' },
    { value: 'reported', key: 'reported', label: 'Reported' },
    { value: 'awaiting', key: 'awaiting', label: 'Awaiting result' },
    { value: 'no_agent', key: 'no_agent', label: 'No agent', act: true },
    { value: 'flagged', key: 'flagged', label: 'Overvote', act: true },
    { value: 'review_flagged', key: 'review_flagged', label: 'Reviewer flags', act: true },
];
const counts = computed(() => meta.value?.counts ?? {});
const band = (p) => (p >= 90 ? 'high' : p >= 75 ? 'mid' : 'low');
const allTiles = computed(() => (tally.data.value?.lgas ?? []).map((l) => ({ ...l, pct: l.units ? Math.round((l.units_reported / l.units) * 100) : 0 })));
// The tiles can be ordered by name or by share reported, and each colour band shown or hidden from its legend.
const tileOrder = ref('name');
const tileOrders = [['name', 'A–Z'], ['low', 'Lowest first'], ['high', 'Highest first']];
const bands = [
    { key: 'high', label: '90% or more', swatch: 'bg-navy' },
    { key: 'mid', label: '75 to 89%', swatch: 'bg-[#53668c]' },
    { key: 'low', label: 'Below 75%, chase now', swatch: 'bg-orange' },
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
const tileClass = (p) => (p >= 90 ? 'bg-navy text-white hover:bg-navy-3' : p >= 75 ? 'bg-[#53668c] text-white hover:bg-[#465a80]' : 'bg-orange text-white hover:bg-action');
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
    <PageHeader title="Units" :subtitle="counts.all != null ? `${num(counts.all)} polling units in ${allTiles.length} LGAs` : ''" :updated-at="units.updatedAt.value" :loading="units.loading.value" @refresh="units.load(); tally.load()" />

    <div class="-mt-1 mb-5 flex gap-1 overflow-x-auto border-b border-line" role="group" aria-label="Status">
        <button
            v-for="tab in tabs"
            :key="tab.value"
            class="relative shrink-0 px-3 pt-1 pb-2.5 text-[14px] font-[560] whitespace-nowrap transition-colors"
            :class="filters.status === tab.value ? 'text-ink' : 'text-ink-2 hover:text-ink'"
            :aria-pressed="filters.status === tab.value"
            @click="filters.status = tab.value"
        >
            {{ tab.label }}
            <span class="ml-1 tabular-nums" :class="tab.act && counts[tab.key] ? 'font-[650] text-action' : 'text-ink-3'">{{ counts[tab.key] != null ? num(counts[tab.key]) : '' }}</span>
            <span v-if="filters.status === tab.value" class="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-navy"></span>
        </button>
    </div>

    <div class="grid items-start gap-4 2xl:grid-cols-[380px_1fr]">
        <section class="card">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-rule px-5 pt-4 pb-3">
                <h2 class="card-title">Reporting by LGA</h2>
                <div class="seg" role="group" aria-label="Order LGAs">
                    <button v-for="[k, label] in tileOrders" :key="k" type="button" class="seg-btn" :class="tileOrder === k && 'seg-on'" :aria-pressed="tileOrder === k" @click="tileOrder = k">{{ label }}</button>
                </div>
            </header>
            <div class="px-5 pt-3 pb-4">
                <p class="text-[13px] text-ink-2">Share of units with a signed result. Pick an LGA to list only its units.</p>
                <p v-if="!lgaTiles.length" class="mt-4 rounded-lg bg-mist px-4 py-6 text-center text-sm text-ink-2">No LGAs in the bands you've chosen. <button class="font-[620] text-navy hover:underline" @click="hiddenBands = new Set()">Show all</button></p>
                <TransitionGroup tag="div" name="tile" class="mt-3 grid grid-cols-[repeat(auto-fill,minmax(84px,1fr))] gap-1.5 sm:grid-cols-[repeat(auto-fill,minmax(96px,1fr))]">
                    <button
                        v-for="l in lgaTiles"
                        :key="l.id"
                        class="flex h-[52px] flex-col items-start justify-center rounded-md px-2.5 text-left transition-colors sm:h-[60px]"
                        :class="[tileClass(l.pct), String(filters.lga_id) === String(l.id) ? 'ring-2 ring-orange ring-offset-2' : '']"
                        :aria-pressed="String(filters.lga_id) === String(l.id)"
                        @click="filters.lga_id = String(filters.lga_id) === String(l.id) ? '' : l.id"
                    >
                        <span class="w-full truncate text-[12.5px] font-[540] opacity-90">{{ l.name }}</span>
                        <span class="fig text-[20px] leading-tight font-[700]">{{ l.pct }}%</span>
                    </button>
                </TransitionGroup>
                <!-- The key is also the filter: click a band to hide or show its LGAs. -->
                <div class="mt-3 flex flex-wrap items-center gap-x-1 gap-y-1 text-[12.5px]">
                    <button
                        v-for="b in bands"
                        :key="b.key"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded px-1.5 py-1 transition-colors hover:bg-mist"
                        :class="hiddenBands.has(b.key) ? 'text-ink-3 line-through' : 'text-ink-2'"
                        :aria-pressed="!hiddenBands.has(b.key)"
                        :title="hiddenBands.has(b.key) ? 'Show these LGAs' : 'Hide these LGAs'"
                        @click="toggleBand(b.key)"
                    >
                        <span class="h-2.5 w-2.5 rounded-[2px]" :class="[b.swatch, hiddenBands.has(b.key) ? 'opacity-30' : '']"></span>{{ b.label }} <span class="tabular-nums font-[620] text-ink">{{ bandCount[b.key] ?? 0 }}</span>
                    </button>
                    <button v-if="hiddenBands.size" type="button" class="ml-1 font-[620] text-navy hover:underline" @click="hiddenBands = new Set()">Show all</button>
                </div>
            </div>
        </section>

        <section class="card min-w-0 overflow-hidden">
            <div class="flex flex-col gap-2.5 border-b border-rule p-4">
                <div class="flex flex-wrap gap-2.5">
                    <label class="sr-only" for="unit-search">Search units</label>
                    <div class="relative max-w-xs flex-1">
                        <Icon name="search" :size="15" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" />
                        <input id="unit-search" v-model="filters.search" class="field pl-9" placeholder="Unit code or name" />
                    </div>
                    <label class="sr-only" for="lga">LGA</label>
                    <SearchSelect id="lga" v-model="filters.lga_id" class="w-full sm:w-[190px]" all-label="All LGAs" noun="LGAs" :options="(structure.data.value?.lgas ?? []).map((l) => ({ value: l.id, label: l.name }))" />
                    <label class="sr-only" for="ward">Ward</label>
                    <SearchSelect id="ward" v-model="filters.ward_id" class="w-full sm:w-[210px]" all-label="All wards" noun="wards" :options="wardOptions" />
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <label v-for="[key, label, opts] in filterSets" :key="key" class="relative">
                        <span class="sr-only">{{ label }}</span>
                        <select v-model="filters[key]" class="field h-8 w-auto pr-8 text-[13px]" :class="filters[key] ? 'border-navy font-[600] text-navy' : 'text-ink-2'">
                            <option value="">{{ label }}: any</option>
                            <option v-for="[v, text] in opts" :key="v" :value="v">{{ label }}: {{ text }}</option>
                        </select>
                    </label>
                    <button v-if="anyFilter" class="text-[13px] font-[620] text-navy hover:underline" @click="clearFilters">Clear filters</button>
                    <span class="ml-auto text-[13px] text-ink-2 tabular-nums">{{ num(meta?.total ?? 0) }} {{ (meta?.total ?? 0) === 1 ? 'unit' : 'units' }}</span>
                </div>
            </div>
            <LoadState :loading="units.loading.value" :error="units.error.value" :empty="!rows.length" empty-text="No units match these filters.">
                <div class="relative overflow-x-auto">
                    <TableBusy :busy="units.busy.value" />
                    <table class="w-full min-w-[820px] text-[14px]">
                        <thead><tr><SortTh :sort="sort" k="code">Unit</SortTh><SortTh :sort="sort" k="agent">Agent</SortTh><SortTh :sort="sort" k="result">Our result</SortTh><th class="th">IReV</th><SortTh :sort="sort" k="flags">Status</SortTh><th class="th"><span class="sr-only">Evidence</span></th></tr></thead>
                        <tbody>
                            <tr v-for="u in rows" :key="u.id">
                                <td class="td">
                                    <div class="fig text-[15px] font-[650] whitespace-nowrap">{{ u.code }}</div>
                                    <div class="text-[12.5px] text-ink-2">{{ u.name }}<span class="text-ink-3">, {{ u.ward }}, {{ u.lga }}</span></div>
                                </td>
                                <td class="td">
                                    <template v-if="u.agent">
                                        <div class="font-[560] whitespace-nowrap tabular-nums">{{ u.agent.code }}</div>
                                        <div class="text-[12.5px] whitespace-nowrap" :class="u.agent_checked_in ? 'text-good' : 'font-[600] text-action'">{{ u.agent_checked_in ? 'Checked in' : 'Not checked in' }}</div>
                                    </template>
                                    <span v-else class="font-[600] text-action">No agent</span>
                                </td>
                                <td class="td">
                                    <template v-if="u.result">
                                        <div class="tabular-nums"><span class="font-[620]">{{ num(u.result.total_cast) }}</span> <span class="text-ink-2">cast</span></div>
                                        <div class="text-[12.5px] text-ink-3 tabular-nums">at {{ time(u.result.captured_at) }}</div>
                                    </template>
                                    <span v-else class="text-ink-3">Awaiting</span>
                                </td>
                                <td class="td text-[13.5px]" :class="u.irev ? irev[u.irev][0] : 'text-ink-3'">{{ u.irev ? irev[u.irev][1] : 'Not checked' }}</td>
                                <td class="td">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <Badge :tone="flag(u)[0]">{{ flag(u)[1] }}</Badge>
                                        <button v-if="u.result && (u.open_flags || canFlag)" class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-[620] transition-colors" :class="u.open_flags ? 'bg-bad-bg text-bad hover:brightness-95' : 'text-ink-3 hover:bg-mist hover:text-ink'" :disabled="!canFlag" :title="u.open_flags ? 'See the reviewer flags' : 'Flag this result with a comment'" @click="flagsFor = u">
                                            <Icon name="flag" :size="13" />{{ u.open_flags || '' }}
                                        </button>
                                    </div>
                                </td>
                                <td class="td text-right"><RouterLink v-if="canSeeEvidence" :to="`/units/${u.id}/evidence`" class="rounded-md px-2 py-1 text-[13px] font-[600] whitespace-nowrap text-navy transition-colors hover:bg-mist">Evidence</RouterLink></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :meta="meta" :sort="sort" noun="units" :per-page-options="[25, 50, 100, 200]" :loading="units.loading.value" @page="go" @per-page="(n) => { filters.per_page = n; go(1); }" />
            </LoadState>
        </section>
    </div>

    <Modal :open="!!flagsFor" :title="flagsFor ? `${flagsFor.code} ${flagsFor.name}` : ''" :subtitle="flagsFor ? `${flagsFor.ward}, ${flagsFor.lga}. ${num(flagsFor.result?.total_cast)} votes cast.` : ''" width="max-w-2xl" @close="flagsFor = null">
        <ResultFlags v-if="flagsFor?.result" :result-id="flagsFor.result.id" @changed="(n) => { flagsFor.open_flags = n; units.load(); }" />
    </Modal>
</template>
