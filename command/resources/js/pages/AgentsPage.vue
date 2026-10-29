<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import Icon from '../components/Icon.vue';
import Pagination from '../components/Pagination.vue';
import AgentActions from '../components/AgentActions.vue';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { usePaged } from '../lib/usePaged';
import { hasRole } from '../lib/session';
import { dateTime, num, time } from '../lib/format';

const route = useRoute();
const router = useRouter();
const filter = ref(route.query.status ?? '');
const search = ref(route.query.search ?? '');
const lgaId = ref(route.query.lga_id ? Number(route.query.lga_id) : '');
const agents = useResource(() => api.get('agents', { search: search.value, lga_id: lgaId.value, status: filter.value === 'deactivated' ? 'deactivated' : '' }), { refreshMs: 30000 });
const pending = useResource(() => api.get('check-ins'), { refreshMs: 30000 });
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
const paged = usePaged(all, { perPage: 25, resetOn: () => [filter.value, search.value, lgaId.value] });
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
                <select id="agent-lga" v-model="lgaId" class="field max-w-[200px]">
                    <option value="">All LGAs</option>
                    <option v-for="l in structure.data.value?.lgas ?? []" :key="l.id" :value="l.id">{{ l.name }}</option>
                </select>
                <label class="sr-only" for="agent-status">Status</label>
                <select id="agent-status" v-model="filter" class="field max-w-[180px]">
                    <option v-for="[v, text] in statuses" :key="v" :value="v">{{ text }}{{ v && v !== 'deactivated' ? ` (${num(matching[v] ?? 0)})` : '' }}</option>
                </select>
                <button v-if="filter || filtered" class="text-[13px] font-bold text-navy hover:underline" @click="() => { filter = ''; search = ''; lgaId = ''; }">Clear filters</button>
            </div>
            <LoadState :loading="agents.loading.value" :error="agents.error.value" :empty="!rows.length" :empty-text="filtered || filter ? 'No agents match these filters.' : 'No agents yet.'">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[680px] text-sm">
                        <thead class="bg-mist"><tr><th class="th">Agent</th><th class="th">Polling unit</th><th class="th">Status</th><th class="th">Result</th><th class="th text-right">Actions</th></tr></thead>
                        <tbody>
                            <tr v-for="a in rows" :key="a.id" :class="a.active === false ? 'opacity-60' : ''">
                                <td class="td"><div class="font-bold">{{ a.code }}</div><div class="text-xs text-ink-2">{{ a.name }}</div><div v-if="a.phone" class="text-xs text-ink-3">{{ a.phone }}</div></td>
                                <td class="td"><template v-if="a.polling_unit"><div class="font-semibold">{{ a.polling_unit.code }}</div><div class="text-xs text-ink-2">{{ a.polling_unit.ward }} · {{ a.polling_unit.lga }}</div></template><span v-else class="text-xs text-ink-2">{{ a.area ?? '–' }}</span></td>
                                <td class="td">
                                    <Badge :tone="tone[a.status]">{{ label[a.status] }}</Badge>
                                    <div v-if="a.check_in" class="mt-1 text-xs whitespace-nowrap text-ink-2">In {{ time(a.check_in.at) }}<template v-if="a.check_in.distance_m != null"> · {{ a.check_in.distance_m }} m</template></div>
                                </td>
                                <td class="td whitespace-nowrap text-ink-2">{{ a.result ? `Sent ${time(a.result.at)}` : '–' }}</td>
                                <td class="td">
                                    <div class="flex items-center justify-end gap-2">
                                        <template v-if="a.status === 'absent' && reserves.length">
                                            <label class="sr-only" :for="`r-${a.id}`">Reserve for {{ a.polling_unit?.code }}</label>
                                            <select :id="`r-${a.id}`" v-model="dispatch[a.id]" class="field h-8 w-36 text-xs">
                                                <option :value="undefined">Send a reserve</option>
                                                <option v-for="r in reserves" :key="r.id" :value="r.id">{{ r.code }} · {{ r.name }}</option>
                                            </select>
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
            <Pagination :meta="paged.meta.value" noun="agents" :per-page-options="[25, 50, 100]" @page="paged.setPage" @per-page="paged.setPerPage" />
        </section>

        <section class="card self-start p-5">
            <h2 class="card-title ">Check-ins to confirm</h2>
            <p class="mt-1 mb-3 text-[13px] text-ink-2">Agents who checked in outside the unit area and say they're there.</p>
            <LoadState :loading="pending.loading.value" :error="pending.error.value" :empty="!(pending.data.value?.data ?? []).length" empty-text="Nothing to confirm.">
                <ul class="flex flex-col gap-2">
                    <li v-for="c in pending.data.value.data" :key="c.id" class="rounded-xl bg-mist p-3.5">
                        <div class="flex justify-between gap-2 text-sm"><b>{{ c.polling_unit.code }}</b><span class="text-xs text-ink-2">{{ dateTime(c.captured_at) }}</span></div>
                        <p class="mt-0.5 text-[13px] text-ink-2">{{ c.agent.code }} · {{ c.distance_m != null ? `${c.distance_m} m away` : 'unit has no GPS point' }} · accuracy {{ c.accuracy_m }} m{{ c.has_selfie ? ' · selfie attached' : '' }}</p>
                        <div class="mt-2.5 flex gap-2">
                            <button class="btn-good h-8 px-3 text-xs" :disabled="busy === `c${c.id}`" @click="review(c, 'approve')">Approve</button>
                            <button class="btn-outline h-8 px-3 text-xs" :disabled="busy === `c${c.id}`" @click="review(c, 'reject')">Reject</button>
                        </div>
                    </li>
                </ul>
            </LoadState>
        </section>
    </div>
</template>
