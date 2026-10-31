<script setup>
import { computed, ref } from 'vue';
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

const levels = computed(() => d.value ? [
    { count: d.value.levels.lga_supervisors, of: d.value.levels.lgas, label: 'LGA supervisors', note: 'One per LGA, with a backup' },
    { count: d.value.levels.ward_supervisors, of: d.value.levels.wards, label: 'Ward supervisors', note: totalOpen.value ? `${num(totalOpen.value)} ${totalOpen.value === 1 ? 'ward' : 'wards'} open` : 'Every ward covered' },
    { count: d.value.levels.agents, label: 'Polling-unit agents', note: `Plus ${num(d.value.levels.reserves)} reserves` },
] : []);

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
            <button class="btn-primary" :disabled="d?.frozen" @click="uploading = true"><Icon name="upload" :size="16" /> Upload supervisor list</button>
        </template>
    </PageHeader>

    <LoadState :loading="sup.loading.value && !d" :error="sup.error.value" :empty="!d">
        <FreezeNote :frozen="d.frozen" />
        <p v-if="message" role="status" class="mb-4 rounded-xl bg-good-bg px-4 py-3 text-sm font-semibold text-good">{{ message }}</p>
        <p v-if="error" role="alert" class="mb-4 rounded-xl bg-bad-bg px-4 py-3 text-sm text-bad">{{ error }}</p>

        <div class="mb-5 grid gap-3 md:grid-cols-4">
            <div class="flex flex-col justify-center rounded-2xl bg-navy p-4 text-white">
                <div class="text-sm font-bold">Situation room</div>
                <div class="text-[13px] text-[#c9d1de]">Top of every escalation</div>
            </div>
            <div v-for="l in levels" :key="l.label" class="card p-4">
                <div class="text-[24px] leading-none font-extrabold">{{ num(l.count) }}<span v-if="l.of != null" class="text-sm font-semibold text-ink-2"> of {{ num(l.of) }}</span></div>
                <div class="mt-1 text-sm font-bold">{{ l.label }}</div>
                <div class="text-[12.5px]" :class="l.note.includes('open') ? 'font-semibold text-action' : 'text-ink-2'">{{ l.note }}</div>
            </div>
        </div>

        <div class="grid items-start gap-5 xl:grid-cols-[1.3fr_1fr]">
            <section class="card overflow-hidden">
                <h2 class="card-title px-5 pt-5 pb-3">LGA supervisors</h2>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[620px] text-sm">
                        <thead class="bg-mist"><tr><SortTh :sort="sort" k="name">LGA</SortTh><SortTh :sort="sort" k="supervisor">Supervisor</SortTh><SortTh :sort="sort" k="backup">Backup</SortTh><SortTh :sort="sort" k="wards">Wards covered</SortTh><SortTh :sort="sort" k="agents" cls="text-right">Agents</SortTh></tr></thead>
                        <tbody>
                            <tr v-for="l in lgaRows" :key="l.id" class="cursor-pointer" :class="[d.selected?.id === l.id ? 'bg-mist' : 'hover:bg-mist/60', !l.supervisor || l.wards_covered < l.wards ? 'bg-[#fff8f4]' : '']" @click="select(l.id)">
                                <td class="td font-bold whitespace-nowrap"><button class="text-left" @click.stop="select(l.id)">{{ l.name }}</button></td>
                                <td class="td"><template v-if="l.supervisor"><div class="font-semibold">{{ l.supervisor.code }} · {{ l.supervisor.name }}</div><div class="text-xs text-ink-2">{{ l.supervisor.phone }}</div></template><span v-else class="font-semibold text-action">None</span></td>
                                <td class="td text-ink-2">{{ l.backup ? `${l.backup.code} · ${l.backup.name}` : '–' }}</td>
                                <td class="td font-semibold" :class="l.wards_covered < l.wards ? 'text-action' : ''">{{ l.wards_covered }} of {{ l.wards }}</td>
                                <td class="td text-right">{{ num(l.agents) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="d.selected" class="card p-5">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="card-title ">{{ d.selected.name }} · wards</h2>
                    <span class="text-sm" :class="openWards ? 'font-semibold text-action' : 'text-ink-2'">{{ openWards ? `${openWards} open` : `${d.selected.wards.length} wards` }}</span>
                </div>

                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                    <div v-for="[backup, label, who] in [[false, 'LGA supervisor', selectedLga?.supervisor], [true, 'Backup', selectedLga?.backup]]" :key="label" class="rounded-xl border border-line p-3">
                        <div class="text-[11.5px] font-bold tracking-wide text-ink-3 uppercase">{{ label }}</div>
                        <div class="mt-1 flex items-start justify-between gap-2">
                            <div v-if="who" class="min-w-0 text-sm"><b>{{ who.name }}</b><div class="text-[12.5px] text-ink-2">{{ who.code }} · {{ who.phone }}</div></div>
                            <div v-else class="text-sm font-semibold" :class="backup ? 'text-ink-3' : 'text-action'">None</div>
                            <template v-if="!d.frozen">
                                <SupervisorActions v-if="who" :supervisor="who" @replace="assigning = { post: lgaPost(backup), holder: who }" @done="done" />
                                <button v-else class="btn-outline h-8 shrink-0 px-3 text-xs" @click="assigning = { post: lgaPost(backup) }">{{ backup ? 'Add backup' : 'Assign' }}</button>
                            </template>
                        </div>
                    </div>
                </div>

                <h3 class="mt-4 mb-2 text-sm font-bold">Ward supervisors</h3>
                <ul class="flex flex-col gap-2">
                    <li v-for="w in d.selected.wards" :key="w.id" class="rounded-xl p-3" :class="w.supervisor ? 'bg-mist' : 'bg-bad-bg'">
                        <div class="flex items-center justify-between gap-2 text-sm">
                            <div class="min-w-0">
                                <b>{{ w.name }}</b> <span class="text-xs text-ink-2">· {{ w.units }} units</span>
                                <div v-if="w.supervisor" class="text-[13px] text-ink-2">{{ w.supervisor.code }} · {{ w.supervisor.name }} · {{ w.supervisor.phone }}</div>
                                <div v-else class="text-[13px] font-bold text-bad">No supervisor assigned</div>
                            </div>
                            <template v-if="!d.frozen">
                                <div v-if="w.supervisor" class="flex shrink-0 items-center gap-1">
                                    <button class="btn-outline h-8 px-3 text-xs" @click="assigning = { post: wardPost(w), holder: w.supervisor }">Replace</button>
                                    <SupervisorActions :supervisor="w.supervisor" :ward-id="w.id" @replace="assigning = { post: wardPost(w), holder: w.supervisor }" @done="done" />
                                </div>
                                <button v-else class="btn-outline h-8 shrink-0 px-3 text-xs" @click="assigning = { post: wardPost(w) }">Assign</button>
                            </template>
                        </div>
                    </li>
                </ul>

                <div class="mt-5 rounded-xl border border-line p-4">
                    <h3 class="mb-2 text-sm font-bold">Escalation on election day</h3>
                    <div class="flex flex-wrap items-center gap-2 text-[13px] font-semibold">
                        <span class="rounded-lg bg-mist px-2.5 py-1">Agent</span>→<span class="rounded-lg bg-mist px-2.5 py-1">Ward supervisor</span>→<span class="rounded-lg bg-mist px-2.5 py-1">LGA supervisor</span>→<span class="rounded-lg bg-navy px-2.5 py-1 text-white">Situation room</span>
                    </div>
                </div>
            </section>
        </div>
    </LoadState>

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
