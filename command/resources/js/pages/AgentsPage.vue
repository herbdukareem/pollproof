<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import Icon from '../components/Icon.vue';
import Pagination from '../components/Pagination.vue';
import SearchSelect from '../components/SearchSelect.vue';
import SortTh from '../components/SortTh.vue';
import { sortRows, ts, useSortState } from '../lib/useSort';
import AgentActions from '../components/AgentActions.vue';
import CallButton from '../components/CallButton.vue';
import { callOrCopy } from '../lib/call';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { usePaged } from '../lib/usePaged';
import { hasRole } from '../lib/session';
import { ago, dateTime, minutesSince, num, time, timeOrDate } from '../lib/format';

const route = useRoute();
const router = useRouter();
const filter = ref(route.query.status ?? '');
const search = ref(route.query.search ?? '');
const lgaId = ref(route.query.lga_id ? Number(route.query.lga_id) : '');
// Latest first: the most recent check-in or result at the top; agents with neither go last.
const statusRank = { absent: 0, outside_area: 1, present: 2, reserve: 3, deactivated: 4 };
const sort = useSortState({
    latest: { label: 'latest activity', dir: 'desc', value: (a) => a.activity },
    code: { label: 'Agent', dir: 'asc', value: (a) => a.code },
    unit: { label: 'Polling unit', dir: 'asc', value: (a) => a.polling_unit?.code },
    status: { label: 'Status', dir: 'asc', value: (a) => statusRank[a.status] },
    result: { label: 'Result', dir: 'desc', value: (a) => ts(a.result?.at) },
}, undefined, { onChange: () => agents.load() });
const agents = useResource(() => api.get('agents', { search: search.value, lga_id: lgaId.value, status: filter.value === 'deactivated' ? 'deactivated' : '', ...sort.params }), { refreshMs: 30000 });
const pending = useResource(() => api.get('check-ins'), { refreshMs: 15000 });

// Newest first; a card that arrived since the last look glows briefly so it is noticed.
const seen = ref(null);
const fresh = ref(new Set());
const queue = computed(() => [...(pending.data.value?.data ?? [])].sort((a, b) => b.id - a.id));
watch(queue, (list) => {
    const ids = list.map((c) => c.id);
    if (seen.value) {
        const arrived = ids.filter((id) => !seen.value.has(id));
        if (arrived.length) {
            fresh.value = new Set([...fresh.value, ...arrived]);
            setTimeout(() => (fresh.value = new Set([...fresh.value].filter((id) => !arrived.includes(id)))), 6000);
        }
    }
    seen.value = new Set(ids);
});
const clock = ref(Date.now());
const sameDay = (v) => new Date(v).toDateString() === new Date(clock.value).toDateString();
const waited = (v) => {
    const m = minutesSince(v, clock.value);
    return m == null ? dateTime(v) : m < 1 ? 'just now' : `${ago(v, clock.value)} ago`;
};
let tick;
const onNew = () => pending.load();
onMounted(() => {
    tick = setInterval(() => (clock.value = Date.now()), 30000);
    window.addEventListener('pp:checkins', onNew);
});
onBeforeUnmount(() => {
    clearInterval(tick);
    window.removeEventListener('pp:checkins', onNew);
});
const structure = useResource(() => api.get('structure'));

let t;
watch(search, () => { clearTimeout(t); t = setTimeout(agents.load, 300); });
watch(lgaId, () => agents.load());
watch(filter, (now, before) => (now === 'deactivated' || before === 'deactivated') && agents.load());
watch([filter, search, lgaId], ([status, q, lga]) => router.replace({ query: { ...(status ? { status } : {}), ...(q ? { search: q } : {}), ...(lga ? { lga_id: lga } : {}) } }));

const summary = computed(() => agents.data.value?.summary ?? {});
const matching = computed(() => agents.data.value?.matching ?? {});
const all = computed(() => (agents.data.value?.data ?? []).filter((a) => (filter.value ? a.status === filter.value : a.status !== 'deactivated')));
const reserves = computed(() => (agents.data.value?.data ?? []).filter((a) => a.status === 'reserve'));
const sortedAll = computed(() => sortRows(all.value, sort));
const paged = usePaged(sortedAll, { perPage: 25, resetOn: () => [filter.value, search.value, lgaId.value, sort.key, sort.dir] });
const rows = paged.rows;
const canEdit = computed(() => agents.data.value?.can_edit ?? false);
const filtered = computed(() => !!(search.value || lgaId.value));
const busy = ref(null);
const message = ref('');
const error = ref('');
const dispatch = ref({});

const tone = { present: 'good', absent: 'bad', outside_area: 'warn', reserve: 'info', deactivated: 'neutral' };
const label = { present: 'Present', absent: 'Absent', outside_area: 'Outside area', reserve: 'Reserve', deactivated: 'Deactivated' };
const statuses = [['', 'All active'], ['present', 'Present'], ['absent', 'Absent'], ['outside_area', 'Outside area'], ['reserve', 'Reserves'], ['deactivated', 'Deactivated']];

function done(text) {
    message.value = text;
    error.value = '';
    agents.load();
}
const tiles = [
    ['present', 'Present at their unit', 'bg-good'],
    ['absent', 'Absent', 'bg-action'],
    ['outside_area', 'Checked in outside area', 'bg-[#d99a00]'],
    ['reserve', 'Reserves on standby', 'bg-[#1c6fc2]'],
];

async function review(c, decision) {
    busy.value = `c${c.id}`;
    error.value = '';
    try {
        await api.post(`check-ins/${c.id}/review`, { decision });
        message.value = `${decision === 'approve' ? 'Approved' : 'Rejected'} check-in for ${c.polling_unit.code}.`;
        await Promise.all([pending.load(), agents.load()]);
    } catch (e) {
        error.value = e.firstError();
        pending.load();
    } finally {
        busy.value = null;
    }
}

async function send(agent) {
    const reserveId = dispatch.value[agent.id];
    if (!reserveId) return;
    busy.value = `a${agent.id}`;
    error.value = '';
    try {
        await api.post(`agents/${reserveId}/assign`, { polling_unit_id: agent.polling_unit.id });
        message.value = `Reserve sent to ${agent.polling_unit.code}.`;
        await agents.load();
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <PageHeader title="Agents" subtitle="Who is at their polling unit, who needs replacing, and check-ins to confirm." :updated-at="agents.updatedAt.value" :loading="agents.loading.value" @refresh="() => { agents.load(); pending.load(); }" />
    <p v-if="message" role="status" class="mb-4 rounded-xl bg-good-bg px-4 py-3 text-sm font-semibold text-good">{{ message }}</p>
    <p v-if="error" role="alert" class="mb-4 rounded-xl bg-bad-bg px-4 py-3 text-sm text-bad">{{ error }}</p>

    <div class="mb-5 grid grid-cols-2 gap-3 xl:grid-cols-4">
        <button v-for="[key, text, bar] in tiles" :key="key" class="card flex items-center gap-4 p-4 text-left transition hover:-translate-y-0.5 hover:border-navy/40 hover:shadow-[0_8px_24px_rgba(19,33,60,0.10)]" :class="filter === key ? 'ring-2 ring-navy' : ''" :title="filter === key ? 'Show all agents' : 'Show only these agents'" :aria-pressed="filter === key" @click="filter = filter === key ? '' : key">
            <span class="h-11 w-2.5 rounded-full" :class="bar"></span>
            <span><span class="block text-[26px] leading-none font-extrabold">{{ num(summary[key] ?? 0) }}</span><span class="text-[13px] text-ink-2">{{ text }}</span></span>
        </button>
    </div>

    <div class="grid gap-5 xl:grid-cols-[1fr_360px]">
        <section class="card min-w-0 self-start overflow-hidden">
            <div class="flex flex-wrap items-center gap-3 border-b border-[#eef0f3] p-4">
                <div class="relative max-w-xs min-w-[200px] flex-1">
                    <Icon name="search" :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" />
                    <label class="sr-only" for="agent-search">Search agents</label>
                    <input id="agent-search" v-model="search" class="field w-full pl-9" placeholder="Agent ID, name, phone or unit" />
                </div>
                <label class="sr-only" for="agent-lga">LGA</label>
                <SearchSelect id="agent-lga" v-model="lgaId" class="w-full sm:w-[200px]" all-label="All LGAs" noun="LGAs" :options="(structure.data.value?.lgas ?? []).map((l) => ({ value: l.id, label: l.name }))" />
                <label class="sr-only" for="agent-status">Status</label>
                <select id="agent-status" v-model="filter" class="field max-w-[180px]">
                    <option v-for="[v, text] in statuses" :key="v" :value="v">{{ text }}{{ v && v !== 'deactivated' ? ` (${num(matching[v] ?? 0)})` : '' }}</option>
                </select>
                <button v-if="filter || filtered" class="text-[13px] font-bold text-navy hover:underline" @click="() => { filter = ''; search = ''; lgaId = ''; }">Clear filters</button>
            </div>
            <LoadState :loading="agents.loading.value" :error="agents.error.value" :empty="!rows.length" :empty-text="filtered || filter ? 'No agents match these filters.' : 'No agents yet.'">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[680px] text-sm">
                        <thead class="bg-mist"><tr><SortTh :sort="sort" k="code">Agent</SortTh><SortTh :sort="sort" k="unit">Polling unit</SortTh><SortTh :sort="sort" k="status">Status</SortTh><SortTh :sort="sort" k="result">Result</SortTh><th class="th text-right">Actions</th></tr></thead>
                        <tbody>
                            <tr v-for="a in rows" :key="a.id" :class="a.active === false ? 'opacity-60' : ''">
                                <td class="td"><div class="font-bold">{{ a.code }}</div><div class="text-xs text-ink-2">{{ a.name }}</div><div v-if="a.phone"><a :href="`tel:${a.phone}`" class="text-xs text-ink-3 hover:text-navy hover:underline" title="Call (copies the number on a computer)" @click="callOrCopy($event, a.phone, a.name || a.code)">{{ a.phone }}</a></div></td>
                                <td class="td"><template v-if="a.polling_unit"><div class="font-semibold">{{ a.polling_unit.code }}</div><div class="text-xs text-ink-2">{{ a.polling_unit.ward }} · {{ a.polling_unit.lga }}</div></template><span v-else class="text-xs text-ink-2">{{ a.area ?? '–' }}</span></td>
                                <td class="td">
                                    <Badge :tone="tone[a.status]">{{ label[a.status] }}</Badge>
                                    <div v-if="a.check_in" class="mt-1 text-xs whitespace-nowrap text-ink-2">{{ sameDay(a.check_in.at) ? 'In' : 'Last in' }} {{ timeOrDate(a.check_in.at) }}<template v-if="a.check_in.distance_m != null"> · {{ a.check_in.distance_m }} m</template></div>
                                </td>
                                <td class="td whitespace-nowrap text-ink-2">{{ a.result ? `Sent ${time(a.result.at)}` : '–' }}</td>
                                <td class="td">
                                    <div class="flex items-center justify-end gap-2">
                                        <template v-if="a.status === 'absent' && reserves.length">
                                            <label class="sr-only" :for="`r-${a.id}`">Reserve for {{ a.polling_unit?.code }}</label>
                                            <SearchSelect :id="`r-${a.id}`" v-model="dispatch[a.id]" class="w-40" size="xs" placeholder="Send a reserve" noun="reserves" :options="reserves.map((r) => ({ value: r.id, label: `${r.code} · ${r.name}`, hint: r.area }))" />
                                            <button class="btn-primary h-8 px-3 text-xs" :disabled="!dispatch[a.id] || busy === `a${a.id}`" @click="send(a)">Send</button>
                                        </template>
                                        <AgentActions :agent="a" :can-edit="canEdit" can-assign @done="done" />
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </LoadState>
            <Pagination :meta="paged.meta.value" :sort="sort" noun="agents" :per-page-options="[25, 50, 100]" @page="paged.setPage" @per-page="paged.setPerPage" />
        </section>

        <section id="checkins" class="card scroll-mt-4 self-start p-5">
            <div class="flex items-center justify-between gap-2">
                <h2 class="card-title">Check-ins to confirm</h2>
                <span v-if="queue.length" class="rounded-full bg-action px-2.5 py-0.5 text-xs font-bold text-white">{{ queue.length }}</span>
            </div>
            <p class="mt-1 mb-3 text-[13px] text-ink-2">Agents who checked in outside the unit area, or by USSD with no GPS. New ones appear at the top.</p>
            <LoadState :loading="pending.loading.value" :error="pending.error.value" :empty="!queue.length" empty-text="Nothing to confirm.">
                <TransitionGroup name="alert" tag="ul" class="relative flex flex-col gap-2">
                    <li v-for="c in queue" :key="c.id" class="rounded-xl border p-3.5 transition-colors" :class="fresh.has(c.id) ? 'checkin-new border-[#1c6fc2]/40 bg-[#eef5fc]' : 'border-transparent bg-mist'">
                        <div class="flex items-start justify-between gap-2 text-sm">
                            <span class="flex flex-wrap items-center gap-1.5">
                                <b>{{ c.polling_unit.code }}</b>
                                <span v-if="fresh.has(c.id)" class="rounded-full bg-[#1c6fc2] px-1.5 py-px text-[10.5px] font-bold tracking-wide text-white uppercase">New</span>
                                <span class="rounded-full px-1.5 py-px text-[10.5px] font-bold tracking-wide uppercase" :class="c.channel === 'ussd' ? 'bg-[#fff1d6] text-[#8a5a00]' : 'bg-white text-ink-2'">{{ c.channel === 'ussd' ? 'USSD' : 'App' }}</span>
                            </span>
                            <span class="shrink-0 text-right text-xs text-ink-2" :title="dateTime(c.captured_at)">{{ waited(c.captured_at) }}</span>
                        </div>
                        <p class="mt-0.5 text-[13px] text-ink-2">{{ c.agent.code }}{{ c.agent.name ? ` · ${c.agent.name}` : '' }}</p>
                        <p v-if="c.channel === 'ussd'" class="mt-0.5 text-[13px] text-ink-2"><b class="text-ink">No GPS.</b> Call the agent to confirm they are at the unit.</p>
                        <p v-else class="mt-0.5 text-[13px] text-ink-2">{{ c.distance_m != null ? `${c.distance_m} m away` : 'Unit has no GPS point' }} · accuracy {{ c.accuracy_m }} m{{ c.has_selfie ? ' · selfie attached' : '' }}</p>
                        <div class="mt-2.5 flex flex-wrap gap-2">
                            <button class="btn-good h-8 px-3 text-xs" :disabled="busy === `c${c.id}`" @click="review(c, 'approve')">Approve</button>
                            <button class="btn-outline h-8 px-3 text-xs" :disabled="busy === `c${c.id}`" @click="review(c, 'reject')">Reject</button>
                            <CallButton v-if="c.agent.phone" :phone="c.agent.phone" :who="c.agent.name || c.agent.code" small class="ml-auto" />
                        </div>
                    </li>
                </TransitionGroup>
            </LoadState>
        </section>
    </div>
</template>
