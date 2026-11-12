<script setup>
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import BulkBar from '../../components/BulkBar.vue';
import Modal from '../../components/Modal.vue';
import { useSelection } from '../../lib/useSelection';
import { hasRole } from '../../lib/session';
import PageHeader from '../../components/PageHeader.vue';
import LoadState from '../../components/LoadState.vue';
import ImportModal from '../../components/ImportModal.vue';
import AssignSupervisorModal from '../../components/AssignSupervisorModal.vue';
import SupervisorActions from '../../components/SupervisorActions.vue';
import Icon from '../../components/Icon.vue';
import FreezeNote from '../../components/FreezeNote.vue';
import SortTh from '../../components/SortTh.vue';
import { sortRows, useSortState } from '../../lib/useSort';
import { api } from '../../lib/api';
import { useResource } from '../../lib/useResource';
import { num } from '../../lib/format';

const lgaId = ref(null);
const sort = useSortState({
    default: { label: 'default order', dir: 'asc' },
    name: { label: 'LGA', dir: 'asc', value: (l) => l.name },
    supervisor: { label: 'Supervisor', dir: 'asc', value: (l) => l.supervisor?.name },
    backup: { label: 'Backup', dir: 'asc', value: (l) => l.backup?.name },
    wards: { label: 'Wards covered', dir: 'asc', value: (l) => (l.wards ? l.wards_covered / l.wards : 1) },
    agents: { label: 'Agents', dir: 'desc', value: (l) => l.agents },
}, { key: 'default', dir: 'asc' }, { onChange: () => sup.load() });
const sup = useResource(() => api.get('setup/supervisors', { lga_id: lgaId.value, ...sort.params }));
const d = computed(() => sup.data.value);
const lgaRows = computed(() => sortRows(d.value?.lgas ?? [], sort));
const openWards = computed(() => d.value?.selected?.wards.filter((w) => !w.supervisor).length ?? 0);
const totalOpen = computed(() => (d.value ? d.value.levels.wards - d.value.levels.ward_supervisors : 0));
const uploading = ref(false);
const openTags = () => window.open('/id-tags?role=supervisors', '_blank');
// The assign window: { post, holder } to fill or replace a post, or {} to add a supervisor anywhere.
const assigning = ref(null);
const message = ref('');
const error = ref('');
const selectedLga = computed(() => d.value?.lgas.find((l) => l.id === d.value.selected?.id));
const supervisorColumns = [
    ['level', 'Already filled: lga or ward', false],
    ['lga_code, ward_code', 'Already filled; leave as they are', false],
    ['name', 'Supervisor’s full name', true],
    ['phone', 'Their mobile number, e.g. 0803 123 4567', true],
    ['backup', 'Already filled: yes on the second row of each LGA, for the backup LGA supervisor', false],
];

const router = useRouter();
// The summary cards are filters (LGA and ward gaps) or shortcuts (the agent roster).
const gap = ref('');
const missingBackup = computed(() => (d.value?.lgas ?? []).filter((l) => !l.backup).length);
const levels = computed(() => d.value ? [
    { key: 'lga', count: d.value.levels.lga_supervisors, of: d.value.levels.lgas, label: 'LGA supervisors', note: missingBackup.value ? `${num(missingBackup.value)} without a backup` : 'One per LGA, with a backup', hint: 'Show LGAs missing a supervisor or backup' },
    { key: 'ward', count: d.value.levels.ward_supervisors, of: d.value.levels.wards, label: 'Ward supervisors', note: totalOpen.value ? `${num(totalOpen.value)} ${totalOpen.value === 1 ? 'ward' : 'wards'} open` : 'Every ward covered', hint: 'Show LGAs with wards that have no supervisor' },
    { key: 'agents', count: d.value.levels.agents, label: 'Polling-unit agents', note: `Plus ${num(d.value.levels.reserves)} reserves`, hint: 'Open the agent roster' },
] : []);
function openCard(key) {
    if (key === 'agents') return router.push('/setup/roster');
    gap.value = gap.value === key ? '' : key;
}
const gapLabel = { lga: 'LGAs missing a supervisor or backup', ward: 'LGAs with wards that have no supervisor' };
const shownLgas = computed(() => lgaRows.value.filter((l) => (gap.value === 'lga' ? !l.supervisor || !l.backup : gap.value === 'ward' ? l.wards_covered < l.wards : true)));
const openRoom = () => hasRole('dg') && router.push('/admin/users?role=situation_room');

// Ticked supervisors (LGA and ward), for bulk actions.
const picked = useSelection();
const lgaSupIds = computed(() => shownLgas.value.flatMap((l) => [l.supervisor?.id, l.backup?.id]).filter(Boolean));
const wardSupIds = computed(() => (d.value?.selected?.wards ?? []).map((w) => w.supervisor?.id).filter(Boolean));
const printPicked = () => window.open(`/id-tags?ids=${picked.list().join(',')}`, '_blank');
const confirmRemove = ref(false);
const removing = ref(false);
async function removePicked() {
    removing.value = true;
    try {
        const r = await api.post('people/bulk-unassign', { ids: picked.list() });
        done(`${r.done} ${r.done === 1 ? 'supervisor' : 'supervisors'} taken off their posts. Those posts are open now.`);
        picked.clear();
        confirmRemove.value = false;
    } catch (e) {
        error.value = e.firstError();
    } finally {
        removing.value = false;
    }
}

function select(id) {
    lgaId.value = id;
    sup.load();
}

function lgaPost(backup) {
    const l = d.value.selected;
    return { level: 'lga', lga: { id: l.id, name: l.name }, ward: null, backup };
}
function wardPost(w) {
    return { level: 'ward', lga: { id: d.value.selected.id, name: d.value.selected.name }, ward: { id: w.id, name: w.name }, backup: false };
}
function done(text) {
    message.value = text;
    error.value = '';
    sup.load();
}
function imported(summary) {
    message.value = `Uploaded: ${num(summary.lga + summary.ward)} supervisors saved${summary.rejected?.length ? `, ${summary.rejected.length} rows skipped` : ''}.`;
    sup.load();
}
</script>

<template>
    <PageHeader title="Supervisors" subtitle="Who each agent calls on election day, and who that person calls." :updated-at="sup.updatedAt.value" :loading="sup.loading.value" @refresh="sup.load">
        <template #actions>
            <button class="btn-outline" :disabled="d?.frozen" @click="assigning = {}"><Icon name="plus" :size="16" /> Add supervisor</button>
            <button class="btn-outline" title="ID tags for every supervisor" @click="openTags"><Icon name="download" :size="16" /> Print ID tags</button>
            <button class="btn-primary" :disabled="d?.frozen" @click="uploading = true"><Icon name="upload" :size="16" /> Upload supervisor list</button>
        </template>
    </PageHeader>

    <LoadState :loading="sup.loading.value && !d" :error="sup.error.value" :empty="!d">
        <FreezeNote :frozen="d.frozen" />
        <p v-if="message" role="status" class="mb-4 rounded-lg bg-good-bg px-4 py-3 text-sm font-[600] text-good">{{ message }}</p>
        <p v-if="error" role="alert" class="mb-4 rounded-lg bg-bad-bg px-4 py-3 text-sm text-bad">{{ error }}</p>

        <div class="card mb-4 grid overflow-hidden sm:grid-cols-2 md:grid-cols-4" aria-label="Chain of command, top first">
            <button type="button" class="flex flex-col justify-between bg-navy px-5 py-4 text-left text-white" :class="hasRole('dg') ? 'hover:bg-navy-2' : 'cursor-default'" :title="hasRole('dg') ? 'See the situation room team' : ''" @click="openRoom">
                <span class="text-[14px] font-[650]">Situation room</span>
                <span class="mt-3 text-[13px] text-[#c9d1de]">Top of every escalation{{ hasRole('dg') ? '. See the team' : '' }}</span>
            </button>
            <button
                v-for="l in levels"
                :key="l.label"
                type="button"
                class="relative border-rule px-5 py-4 text-left transition-colors max-md:border-t sm:max-md:[&:nth-child(2)]:border-t-0 md:border-l"
                :class="gap === l.key ? 'bg-[#f3f5f8]' : 'hover:bg-[#f8f9fb]'"
                :aria-pressed="l.key === 'agents' ? undefined : gap === l.key"
                :title="l.hint"
                @click="openCard(l.key)"
            >
                <span v-if="gap === l.key" class="absolute inset-x-0 top-0 h-[3px] bg-navy"></span>
                <span class="block text-[13px] text-ink-2">{{ l.label }}</span>
                <span class="fig mt-0.5 block text-[26px] leading-none font-[700]">{{ num(l.count) }}<span v-if="l.of != null" class="text-[14px] font-[500] text-ink-2"> of {{ num(l.of) }}</span></span>
                <span class="mt-1.5 block text-[12.5px]" :class="/open|without/.test(l.note) ? 'font-[600] text-action' : 'text-ink-2'">{{ l.note }}</span>
            </button>
        </div>

        <div class="grid items-start gap-4 xl:grid-cols-[1.3fr_1fr]">
            <section class="card overflow-hidden">
                <header class="card-head">
                    <h2 class="card-title">LGA supervisors</h2>
                    <span v-if="gap" class="text-[12.5px] text-ink-2">Only {{ gapLabel[gap].charAt(0).toLowerCase() + gapLabel[gap].slice(1) }} ({{ shownLgas.length }}). <button class="link" @click="gap = ''">Show all</button></span>
                </header>
                <p v-if="!shownLgas.length" class="m-5 rounded-lg bg-good-bg px-4 py-6 text-center text-sm text-good">None. Every LGA is covered here.</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[620px] text-sm">
                        <thead><tr><th v-if="!d.frozen" class="th w-10 !pr-0"><input type="checkbox" class="checkbox" aria-label="Tick every LGA supervisor and backup listed" :checked="picked.allOn(lgaSupIds)" :indeterminate.prop="picked.someOn(lgaSupIds)" @change="picked.setMany(lgaSupIds, $event.target.checked)" /></th><SortTh :sort="sort" k="name">LGA</SortTh><SortTh :sort="sort" k="supervisor">Supervisor</SortTh><SortTh :sort="sort" k="backup">Backup</SortTh><SortTh :sort="sort" k="wards">Wards covered</SortTh><SortTh :sort="sort" k="agents" cls="text-right">Agents</SortTh></tr></thead>
                        <tbody>
                            <tr v-for="l in shownLgas" :key="l.id" class="cursor-pointer" :class="[d.selected?.id === l.id ? 'bg-mist' : 'hover:bg-mist/60', !l.supervisor || l.wards_covered < l.wards ? 'bg-[#fff8f4]' : '']" @click="select(l.id)">
                                <td v-if="!d.frozen" class="td w-10 !pr-0" @click.stop><input v-if="l.supervisor || l.backup" type="checkbox" class="checkbox" :aria-label="`Tick ${l.name}'s LGA supervisor${l.backup ? ' and backup' : ''}`" :checked="picked.allOn([l.supervisor?.id, l.backup?.id].filter(Boolean))" :indeterminate.prop="picked.someOn([l.supervisor?.id, l.backup?.id].filter(Boolean))" @change="picked.setMany([l.supervisor?.id, l.backup?.id].filter(Boolean), $event.target.checked)" /></td>
                                <td class="td font-[650] whitespace-nowrap"><button class="text-left" @click.stop="select(l.id)">{{ l.name }}</button></td>
                                <td class="td"><template v-if="l.supervisor"><div class="font-[600]">{{ l.supervisor.name }}</div><div class="text-[12.5px] text-ink-2 tabular-nums">{{ l.supervisor.code }}, {{ l.supervisor.phone }}</div></template><span v-else class="font-[600] text-action">None</span></td>
                                <td class="td text-ink-2"><template v-if="l.backup">{{ l.backup.name }}<div class="text-[12.5px] tabular-nums">{{ l.backup.code }}</div></template><template v-else>–</template></td>
                                <td class="td font-[600] tabular-nums" :class="l.wards_covered < l.wards ? 'text-action' : ''">{{ l.wards_covered }} of {{ l.wards }}</td>
                                <td class="td fig text-right">{{ num(l.agents) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <BulkBar :count="picked.count.value" :noun="picked.count.value === 1 ? 'supervisor selected' : 'supervisors selected'" @clear="picked.clear()">
                    <button class="btn-bar" @click="printPicked"><Icon name="download" :size="14" /> Print ID tags</button>
                    <button class="btn-bar" @click="confirmRemove = true"><Icon name="undo" :size="14" /> Remove from post</button>
                </BulkBar>
            </section>

            <section v-if="d.selected" class="card p-5">
                <div class="card-title-ruled flex items-baseline justify-between gap-2">
                    <h2>{{ d.selected.name }}</h2>
                    <span class="text-[13px] font-[400] tabular-nums" :class="openWards ? 'font-[600] text-action' : 'text-ink-2'">{{ openWards ? `${openWards} ${openWards === 1 ? 'ward' : 'wards'} open` : `${d.selected.wards.length} wards, all covered` }}</span>
                </div>

                <div class="grid gap-2 sm:grid-cols-2">
                    <div v-for="[backup, label, who] in [[false, 'LGA supervisor', selectedLga?.supervisor], [true, 'Backup', selectedLga?.backup]]" :key="label" class="rounded-lg border border-line p-3">
                        <label class="flex items-center gap-2 text-[12.5px] text-ink-2"><input v-if="who && !d.frozen" type="checkbox" class="checkbox" :aria-label="`Tick ${who.name}`" :checked="picked.has(who.id)" @change="picked.toggle(who.id)" />{{ label }}</label>
                        <div class="mt-1 flex items-start justify-between gap-2">
                            <div v-if="who" class="min-w-0 text-sm"><b class="font-[620]">{{ who.name }}</b><div class="text-[12.5px] text-ink-2 tabular-nums">{{ who.code }}, {{ who.phone }}</div></div>
                            <div v-else class="text-sm font-[600]" :class="backup ? 'text-ink-3' : 'text-action'">None</div>
                            <template v-if="!d.frozen">
                                <SupervisorActions v-if="who" :supervisor="who" @replace="assigning = { post: lgaPost(backup), holder: who }" @done="done" />
                                <button v-else class="btn-ghost h-8 shrink-0 px-3 text-[13px]" @click="assigning = { post: lgaPost(backup) }">{{ backup ? 'Add backup' : 'Assign' }}</button>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="mt-4 mb-2 flex items-center gap-2">
                    <h3 class="text-[14px] font-[650]">Ward supervisors</h3>
                    <label v-if="!d.frozen && wardSupIds.length" class="ml-auto flex items-center gap-2 text-[12.5px] font-[600] text-ink-2"><input type="checkbox" class="checkbox" :checked="picked.allOn(wardSupIds)" :indeterminate.prop="picked.someOn(wardSupIds)" @change="picked.setMany(wardSupIds, $event.target.checked)" /> Tick all in {{ d.selected.name }}</label>
                </div>
                <ul class="border-t border-rule">
                    <li v-for="w in d.selected.wards" :key="w.id" class="relative border-b border-rule py-2.5 pl-3 transition-colors" :class="w.supervisor && picked.has(w.supervisor.id) ? 'bg-[#eef2fb]' : ''">
                        <span class="absolute inset-y-2 left-0 w-[3px] rounded-[1px]" :class="w.supervisor ? 'bg-transparent' : 'bg-orange'"></span>
                        <div class="flex items-center justify-between gap-2 text-sm">
                            <input v-if="w.supervisor && !d.frozen" type="checkbox" class="checkbox shrink-0" :aria-label="`Tick ${w.supervisor.name}`" :checked="picked.has(w.supervisor.id)" @change="picked.toggle(w.supervisor.id)" />
                            <div class="min-w-0 flex-1">
                                <b class="font-[620]">{{ w.name }}</b> <span class="text-[12.5px] text-ink-2 tabular-nums">{{ w.units }} units</span>
                                <div v-if="w.supervisor" class="text-[13px] text-ink-2">{{ w.supervisor.name }}, <span class="tabular-nums">{{ w.supervisor.code }}, {{ w.supervisor.phone }}</span></div>
                                <div v-else class="text-[13px] font-[600] text-action">No supervisor assigned</div>
                            </div>
                            <template v-if="!d.frozen">
                                <div v-if="w.supervisor" class="flex shrink-0 items-center gap-1">
                                    <button class="btn-ghost h-8 px-3 text-[13px]" @click="assigning = { post: wardPost(w), holder: w.supervisor }">Replace</button>
                                    <SupervisorActions :supervisor="w.supervisor" :ward-id="w.id" @replace="assigning = { post: wardPost(w), holder: w.supervisor }" @done="done" />
                                </div>
                                <button v-else class="btn-ghost h-8 shrink-0 px-3 text-[13px]" @click="assigning = { post: wardPost(w) }">Assign</button>
                            </template>
                        </div>
                    </li>
                </ul>

                <p class="note mt-4 text-[13px]"><b class="font-[620] text-ink">On election day</b> an agent calls their ward supervisor first, then the LGA supervisor, then the situation room.</p>
            </section>
        </div>
    </LoadState>

    <Modal :open="confirmRemove" :title="`Take ${picked.count.value} supervisors off their posts?`" subtitle="They stay registered and can be given posts again." :busy="removing" @close="confirmRemove = false">
        <p class="text-sm text-ink-2">Their wards or LGAs show as open until someone else is assigned.</p>
        <template #footer>
            <button class="btn-ghost" :disabled="removing" @click="confirmRemove = false">Cancel</button>
            <button class="btn-primary" :disabled="removing" @click="removePicked">{{ removing ? 'Removing…' : 'Remove from post' }}</button>
        </template>
    </Modal>
    <AssignSupervisorModal :open="!!assigning" :post="assigning?.post ?? null" :holder="assigning?.holder ?? null" @close="assigning = null" @done="done" />
    <ImportModal
        :open="uploading"
        title="Upload supervisor list"
        subtitle="One row per post: each LGA (supervisor and backup) and each ward."
        endpoint="supervisors/import"
        template="setup/templates/supervisors"
        template-name="supervisor-template.csv"
        :columns="supervisorColumns"
        :tips="['Rows you leave without a name and phone are skipped, so you can fill in only what you have.', 'A new name on a filled post replaces the person there; they stay registered without a post.', 'To move an agent into a supervisor post, use Replace or Add supervisor and choose them.']"
        :counts="[['LGA supervisors', 'lga'], ['Ward supervisors', 'ward'], ['Replaced someone', 'replaced']]"
        @close="uploading = false"
        @done="imported"
    />
</template>
