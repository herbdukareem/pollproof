<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import PageHeader from '../../components/PageHeader.vue';
import LoadState from '../../components/LoadState.vue';
import Badge from '../../components/Badge.vue';
import Icon from '../../components/Icon.vue';
import ImportModal from '../../components/ImportModal.vue';
import AddAgentModal from '../../components/AddAgentModal.vue';
import AgentAppButton from '../../components/AgentAppButton.vue';
import ImportReport from '../../components/ImportReport.vue';
import FreezeNote from '../../components/FreezeNote.vue';
import Pagination from '../../components/Pagination.vue';
import TableBusy from '../../components/TableBusy.vue';
import BulkBar from '../../components/BulkBar.vue';
import Modal from '../../components/Modal.vue';
import { useSelection } from '../../lib/useSelection';
import SearchSelect from '../../components/SearchSelect.vue';
import SortTh from '../../components/SortTh.vue';
import { useSortState } from '../../lib/useSort';
import AgentActions from '../../components/AgentActions.vue';
import { api } from '../../lib/api';
import { useResource } from '../../lib/useResource';
import { num } from '../../lib/format';

const search = ref('');
const filter = ref(['incomplete', 'all', 'reserves', 'unassigned', 'unavailable'].includes(useRoute().query.filter) ? useRoute().query.filter : 'incomplete');
const page = ref(1);
const perPage = ref(50);
const lgaId = ref('');
// Newest agents first; the server sorts, so a header click reloads page 1.
const sort = useSortState({
    latest: { label: 'newest', dir: 'desc' },
    code: { label: 'Agent', dir: 'asc' },
    phone: { label: 'Phone', dir: 'asc' },
    unit: { label: 'Unit', dir: 'asc' },
    step: { label: 'Onboarding', dir: 'asc' },
}, undefined, { mode: 'server' });
const roster = useResource(() => api.get('setup/roster', { search: search.value, filter: filter.value, lga_id: lgaId.value, page: page.value, per_page: perPage.value, ...sort.params }));
watch(() => [sort.key, sort.dir], () => { page.value = 1; roster.load(); });
const structure = useResource(() => api.get('structure'));
const d = computed(() => roster.data.value);
const notInstalled = computed(() => (d.value ? d.value.counts.assigned - d.value.stages[1].value : 0));
const report = ref(null);
const uploading = ref(false);
const adding = ref(false);
const printTags = () => window.open(`/id-tags${lgaId.value ? `?lga_id=${lgaId.value}` : ''}`, '_blank');
const rosterColumns = [
    ['name', 'Agent’s full name', true],
    ['phone', 'Their mobile number, e.g. 0803 123 4567', true],
    ['pu_code', 'Already filled: the unit they cover. Clear it to keep them in the ward without a unit', false],
    ['reserve', 'yes for a reserve agent (keep lga_code and ward_code)', false],
    ['code', 'Leave empty for new agents; we give them an AG- code', false],
    ['lga_*, ward_*, pu_name', 'Already filled; leave as they are', false],
];
const preview = ref(null);
const busy = ref('');
const message = ref('');
const error = ref('');

let t;
watch(search, () => {
    clearTimeout(t);
    t = setTimeout(() => { page.value = 1; roster.load(); }, 300);
});
watch([filter, lgaId], () => { page.value = 1; roster.load(); });

const stepNotes = ['Not invited', 'Not installed', 'No practice result', 'Training due', 'Not confirmed', 'Ready'];
const actionFor = (a) => (a.step <= 1 ? 'Send invite' : null);

async function run(key, fn, done) {
    busy.value = key;
    error.value = message.value = '';
    try {
        const res = await fn();
        message.value = done(res);
        await roster.load();
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = '';
    }
}

// Ticked agents, kept across pages, for the bulk actions.
const picked = useSelection();
const pageIds = computed(() => (d.value?.data ?? []).map((a) => a.id));
const bulkConfirm = ref(null);
const invitePicked = () => run('bulk', () => api.post('roster/invite', { ids: picked.list() }), (r) => { picked.clear(); return `Queued ${num(r.sent)} invites.`; });
const printPicked = () => window.open(`/id-tags?ids=${picked.list().join(',')}`, '_blank');
const bulkAgents = (action) => run('bulk', () => api.post('agents/bulk', { ids: picked.list(), action }), (r) => {
    picked.clear();
    bulkConfirm.value = null;
    return `${r.done} ${r.done === 1 ? 'agent' : 'agents'} ${action === 'release' ? 'released to the reserves' : 'deactivated and signed out'}.${r.skipped ? ` ${r.skipped} skipped.` : ''}`;
});

const inviteAll = () => run('invite', () => api.post('roster/invite', {}), (r) => `Queued ${num(r.sent)} invites.`);
const inviteOne = (a) => run(`i${a.id}`, () => api.post('roster/invite', { ids: [a.id] }), () => `Invite queued for ${a.code}.`);
const previewAssign = () => run('preview', async () => (preview.value = await api.post('roster/auto-assign', { apply: false })), (r) => (r.count ? '' : 'Nobody to match: every agent registered in a ward already has a unit, or the ward has no free unit.'));
const applyAssign = () => run('apply', () => api.post('roster/auto-assign', { apply: true }), (r) => { preview.value = null; return `Assigned ${num(r.count)} agents to units.`; });

function imported(summary) {
    report.value = summary;
    message.value = `Imported ${num(summary.rows - summary.rejected.length)} of ${num(summary.rows)} rows.`;
    roster.load();
}

const go = (p) => { page.value = p; roster.load(); };
const setPerPage = (n) => { perPage.value = n; go(1); };
function changed(text) {
    message.value = text;
    error.value = '';
    roster.load();
}
</script>

<template>
    <PageHeader title="Agent roster" :subtitle="d ? `${num(d.counts.assigned)} assigned · ${num(d.counts.reserves)} reserves${d.counts.unassigned ? ` · ${num(d.counts.unassigned)} waiting for a unit` : ''}` : 'Polling-unit agents and reserves'" :updated-at="roster.updatedAt.value" :loading="roster.loading.value" @refresh="roster.load">
        <template #actions>
            <button class="btn-outline" :disabled="d?.frozen" @click="adding = true"><Icon name="plus" :size="16" /> Add agent</button>
            <button class="btn-outline" :disabled="d?.frozen" @click="uploading = true"><Icon name="upload" :size="16" /> Upload agent list</button>
            <button class="btn-outline" :title="lgaId ? 'ID tags for agents in this LGA' : 'ID tags for every agent'" @click="printTags"><Icon name="download" :size="16" /> Print ID tags</button>
            <button class="btn-primary" :disabled="!notInstalled || busy === 'invite'" @click="inviteAll"><Icon name="send" :size="16" /> Send invites to {{ num(notInstalled) }}</button>
        </template>
    </PageHeader>

    <LoadState :loading="roster.loading.value && !d" :error="roster.error.value" :empty="!d">
        <FreezeNote :frozen="d.frozen" />
        <p v-if="message" role="status" class="mb-4 rounded-xl bg-good-bg px-4 py-3 text-sm font-semibold text-good">{{ message }}</p>
        <p v-if="error" role="alert" class="mb-4 rounded-xl bg-bad-bg px-4 py-3 text-sm text-bad">{{ error }}</p>

        <section class="card mb-5 p-5">
            <h2 class="mb-3 text-[15px] font-bold">Onboarding</h2>
            <div class="grid gap-4 sm:grid-cols-5">
                <div v-for="st in d.stages" :key="st.label">
                    <div class="flex items-baseline justify-between"><span class="text-sm text-ink-2">{{ st.label }}</span><b>{{ num(st.value) }}</b></div>
                    <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-[#e9ecf1]"><div class="bar-fill h-full rounded-full bg-navy" :style="{ width: (d.counts.assigned ? (100 * st.value) / d.counts.assigned : 0) + '%' }"></div></div>
                </div>
            </div>
        </section>

        <div class="grid items-start gap-5 xl:grid-cols-[1fr_330px]">
            <section class="card overflow-hidden">
                <div class="flex flex-wrap gap-3 p-4">
                    <label class="sr-only" for="q">Search roster</label>
                    <input id="q" v-model="search" class="field h-10 max-w-xs" placeholder="Search name, phone, agent ID or unit" />
                    <label class="sr-only" for="f">Show</label>
                    <select id="f" v-model="filter" class="field h-10 w-auto">
                        <option value="incomplete">Not fully onboarded</option>
                        <option value="all">All agents</option>
                        <option value="reserves">Reserves</option>
                        <option value="unassigned">Waiting for a unit</option>
                        <option value="unavailable">Said they can't make it</option>
                    </select>
                    <label class="sr-only" for="roster-lga">LGA</label>
                    <SearchSelect id="roster-lga" v-model="lgaId" class="w-full sm:w-[200px]" all-label="All LGAs" noun="LGAs" :options="(structure.data.value?.lgas ?? []).map((l) => ({ value: l.id, label: l.name }))" />
                    <span class="self-center text-sm text-ink-2">{{ num(d.total) }} match</span>
                </div>
                <LoadState :empty="!d.data.length" :empty-text="filter === 'incomplete' ? 'Everyone assigned is fully onboarded.' : 'Nobody here.'">
                    <div class="relative overflow-x-auto">
                        <TableBusy :busy="roster.busy.value" />
                        <table class="w-full min-w-[760px] text-sm">
                            <thead class="bg-mist"><tr><th class="th w-10 !pr-0"><input type="checkbox" class="checkbox" aria-label="Tick every agent on this page" :checked="picked.allOn(pageIds)" :indeterminate.prop="picked.someOn(pageIds)" @change="picked.setMany(pageIds, $event.target.checked)" /></th><SortTh :sort="sort" k="code">Agent</SortTh><SortTh :sort="sort" k="phone">Phone</SortTh><SortTh :sort="sort" k="unit">Unit</SortTh><SortTh :sort="sort" k="step">Onboarding</SortTh><th class="th text-right">Actions</th></tr></thead>
                            <tbody>
                                <tr v-for="a in d.data" :key="a.id" :class="picked.has(a.id) ? '!bg-[#eef2fb]' : ''">
                                    <td class="td w-10 !pr-0"><input type="checkbox" class="checkbox" :aria-label="`Tick ${a.code}`" :checked="picked.has(a.id)" @change="picked.toggle(a.id)" /></td>
                                    <td class="td"><div class="font-bold">{{ a.code }}</div><div class="text-xs text-ink-2">{{ a.name }}</div></td>
                                    <td class="td whitespace-nowrap text-ink-2">{{ a.phone }}</td>
                                    <td class="td">
                                        <template v-if="a.polling_unit"><div class="font-semibold">{{ a.polling_unit.code }}</div><div class="text-xs text-ink-2">{{ a.place }}</div></template>
                                        <template v-else><Badge :tone="a.is_reserve ? 'info' : 'warn'">{{ a.is_reserve ? 'Reserve' : 'No unit' }}</Badge><div v-if="a.place" class="mt-0.5 text-xs text-ink-2">{{ a.place }}</div></template>
                                    </td>
                                    <td class="td">
                                        <div class="flex items-center gap-1" :aria-label="`${a.step} of 5 steps done`">
                                            <span v-for="i in 5" :key="i" class="h-2 w-5 rounded-full" :class="i <= a.step ? 'bg-good' : 'bg-[#e1e4ea]'"></span>
                                        </div>
                                        <div class="mt-1 text-xs font-semibold" :class="a.unavailable ? 'text-action' : a.step === 5 ? 'text-good' : 'text-[#b4470a]'">{{ a.unavailable ? "Can't make it" : stepNotes[a.step] }}{{ a.quiz_score != null && a.step < 4 ? ` · quiz ${a.quiz_score}/10` : '' }}</div>
                                    </td>
                                    <td class="td">
                                        <div class="flex items-center justify-end gap-2">
                                            <button v-if="actionFor(a)" class="btn-outline h-8 px-3 text-xs" :disabled="busy === `i${a.id}`" @click="inviteOne(a)">{{ a.step ? 'Resend invite' : 'Send invite' }}</button>
                                            <AgentActions :agent="{ ...a, active: true }" can-edit :can-assign="!d.frozen" @done="changed" />
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <BulkBar :count="picked.count.value" :noun="picked.count.value === 1 ? 'agent selected' : 'agents selected'" @clear="picked.clear()">
                        <button class="btn-bar" :disabled="busy === 'bulk'" @click="invitePicked"><Icon name="send" :size="14" /> Send invite</button>
                        <button class="btn-bar" @click="printPicked"><Icon name="download" :size="14" /> Print ID tags</button>
                        <button class="btn-bar" :disabled="busy === 'bulk' || d.frozen" @click="bulkConfirm = 'release'"><Icon name="undo" :size="14" /> Release to reserves</button>
                        <button class="btn-bar" :disabled="busy === 'bulk'" @click="bulkConfirm = 'deactivate'"><Icon name="power" :size="14" /> Deactivate</button>
                    </BulkBar>
                    <Pagination :meta="d" :sort="sort" noun="agents" :per-page-options="[25, 50, 100, 200]" :loading="roster.loading.value" @page="go" @per-page="setPerPage" />
                </LoadState>
            </section>

            <div class="flex flex-col gap-5">
                <ImportReport :report="report" :counts="[['Rows in file', 'rows', ''], ['Assigned to a unit', 'assigned', ''], ['Kept as reserves', 'reserves', 'text-good'], ['Waiting for a unit', 'unassigned', ''], ['New people', 'created', ''], ['Updated', 'updated', '']]" />
                <AgentAppButton card />
                <section class="card p-5">
                    <h2 class="text-[15px] font-bold">Auto-assign</h2>
                    <p class="mt-1 text-[13px] text-ink-2">Match agents waiting for a unit to free units in the ward they registered in. Reserves are kept back for election-day gaps.</p>
                    <button class="btn-outline mt-3 w-full" :disabled="busy === 'preview' || d.frozen" @click="previewAssign">Preview assignments</button>
                    <template v-if="preview && preview.count">
                        <ul class="mt-3 max-h-56 overflow-y-auto rounded-xl bg-mist p-3 text-[13px]">
                            <li v-for="p in preview.pairs" :key="p.agent" class="flex justify-between gap-2 py-0.5"><span><b>{{ p.agent }}</b> {{ p.name }}</span><span>→ {{ p.unit }}</span></li>
                        </ul>
                        <p class="mt-2 text-xs text-ink-2">{{ num(preview.count) }} to assign{{ preview.still_unassigned ? `, ${num(preview.still_unassigned)} still without a free unit in their ward` : '' }}.</p>
                        <button class="btn-navy mt-3 w-full" :disabled="busy === 'apply'" @click="applyAssign">Assign {{ num(preview.count) }} agents</button>
                    </template>
                </section>
                <section class="card p-5 text-[13px] text-ink-2">
                    <h2 class="mb-1 text-[15px] font-bold text-ink">CSV columns</h2>
                    <p><code class="text-ink">name, phone</code> are required. Add <code class="text-ink">pu_code</code> to assign a unit, or <code class="text-ink">lga_code, ward_code</code> with <code class="text-ink">reserve</code> = yes for a reserve. Rows are matched on phone, so upload a corrected file to fix mistakes.</p>
                </section>
            </div>
        </div>
    </LoadState>
    <ImportModal
        :open="uploading"
        title="Upload agent list"
        subtitle="One row per polling unit. Add or change agents, then upload."
        endpoint="roster/import"
        template="setup/templates/agents"
        template-name="agent-roster-template.csv"
        :columns="rosterColumns"
        :tips="['Rows you leave without a name and phone are skipped.', 'Agents are matched by phone, so uploading a corrected file updates them instead of adding them twice.', 'To add reserves, add rows at the bottom with lga_code, ward_code and reserve = yes.']"
        :counts="[['Assigned to a unit', 'assigned'], ['Reserves', 'reserves'], ['Waiting for a unit', 'unassigned'], ['New people', 'created'], ['Updated', 'updated']]"
        @close="uploading = false"
        @done="imported"
    />
    <AddAgentModal :open="adding" @close="adding = false" @done="(m) => { message = m; roster.load(); }" />
    <Modal :open="!!bulkConfirm" :title="bulkConfirm === 'deactivate' ? `Deactivate ${picked.count.value} agents?` : `Release ${picked.count.value} agents to the reserves?`" :busy="busy === 'bulk'" @close="bulkConfirm = null">
        <p class="text-sm text-ink-2">{{ bulkConfirm === 'deactivate' ? 'They are signed out of the app at once and their units are left without an agent.' : 'Their units are left without an agent until someone is assigned. They stay as reserves.' }}</p>
        <template #footer>
            <button class="btn-ghost" :disabled="busy === 'bulk'" @click="bulkConfirm = null">Cancel</button>
            <button class="btn-primary" :disabled="busy === 'bulk'" @click="bulkAgents(bulkConfirm)">{{ busy === 'bulk' ? 'Working…' : bulkConfirm === 'deactivate' ? 'Deactivate' : 'Release' }}</button>
        </template>
    </Modal>
</template>
