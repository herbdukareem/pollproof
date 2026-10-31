<script setup>
import { computed, reactive, ref } from 'vue';
import PageHeader from '../../components/PageHeader.vue';
import LoadState from '../../components/LoadState.vue';
import CsvUpload from '../../components/CsvUpload.vue';
import ImportReport from '../../components/ImportReport.vue';
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
const report = ref(null);
const form = reactive({ key: null, name: '', phone: '' });
const busy = ref(false);
const message = ref('');
const error = ref('');

const levels = computed(() => d.value ? [
    { count: d.value.levels.lga_supervisors, of: d.value.levels.lgas, label: 'LGA supervisors', note: 'One per LGA, with a backup' },
    { count: d.value.levels.ward_supervisors, of: d.value.levels.wards, label: 'Ward supervisors', note: totalOpen.value ? `${num(totalOpen.value)} ${totalOpen.value === 1 ? 'ward' : 'wards'} open` : 'Every ward covered' },
    { count: d.value.levels.agents, label: 'Polling-unit agents', note: `Plus ${num(d.value.levels.reserves)} reserves` },
] : []);

function select(id) {
    lgaId.value = id;
    form.key = null;
    sup.load();
}

function startAssign(key) {
    Object.assign(form, { key, name: '', phone: '' });
    error.value = '';
}

async function assign(payload) {
    busy.value = true;
    error.value = message.value = '';
    try {
        const res = await api.post('supervisors', { name: form.name, phone: form.phone, ...payload });
        message.value = `${form.name} is now ${res.code}${res.replaced ? `, replacing ${res.replaced}` : ''}.`;
        form.key = null;
        await sup.load();
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = false;
    }
}

function imported(summary) {
    report.value = summary;
    message.value = `Imported ${num(summary.lga + summary.ward)} supervisors.`;
    sup.load();
}
</script>

<template>
    <PageHeader title="Supervisors" subtitle="Who each agent calls on election day, and who that person calls." :updated-at="sup.updatedAt.value" :loading="sup.loading.value" @refresh="sup.load">
        <template #actions>
            <CsvUpload endpoint="supervisors/import" label="Upload supervisor list" columns="name, phone, level (lga or ward), lga_code, ward_code, backup" :disabled="d?.frozen" @done="imported" />
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

                <template v-if="!d.frozen">
                    <button v-if="form.key !== 'lga'" class="mt-3 text-sm font-bold text-navy underline-offset-2 hover:underline" @click="startAssign('lga')">{{ d.lgas.find((l) => l.id === d.selected.id)?.supervisor ? 'Replace LGA supervisor' : 'Assign LGA supervisor' }}</button>
                    <form v-else class="mt-3 grid gap-2 rounded-xl bg-mist p-3" @submit.prevent="assign({ level: 'lga', lga_id: d.selected.id })">
                        <b class="text-sm">LGA supervisor for {{ d.selected.name }}</b>
                        <input v-model="form.name" class="field h-9" placeholder="Full name" aria-label="Full name" required />
                        <input v-model="form.phone" class="field h-9" placeholder="Phone, e.g. 0803 123 4567" aria-label="Phone" inputmode="tel" required />
                        <div class="flex gap-2"><button class="btn-navy h-9" :disabled="busy">Save</button><button type="button" class="btn-outline h-9" @click="form.key = null">Cancel</button></div>
                    </form>
                </template>

                <ul class="mt-3 flex flex-col gap-2">
                    <li v-for="w in d.selected.wards" :key="w.id" class="rounded-xl p-3" :class="w.supervisor ? 'bg-mist' : 'bg-bad-bg'">
                        <div class="flex items-center justify-between gap-2 text-sm">
                            <div>
                                <b>{{ w.name }}</b> <span class="text-xs text-ink-2">· {{ w.units }} units</span>
                                <div v-if="w.supervisor" class="text-[13px] text-ink-2">{{ w.supervisor.code }} · {{ w.supervisor.name }} · {{ w.supervisor.phone }}</div>
                                <div v-else class="text-[13px] font-bold text-bad">No supervisor assigned</div>
                            </div>
                            <button v-if="!d.frozen && form.key !== w.id" class="btn-outline h-8 shrink-0 px-3 text-xs" @click="startAssign(w.id)">{{ w.supervisor ? 'Replace' : 'Assign' }}</button>
                        </div>
                        <form v-if="form.key === w.id" class="mt-2 grid gap-2 sm:grid-cols-[1fr_1fr_auto]" @submit.prevent="assign({ level: 'ward', ward_id: w.id })">
                            <input v-model="form.name" class="field h-9" placeholder="Full name" aria-label="Full name" required />
                            <input v-model="form.phone" class="field h-9" placeholder="Phone" aria-label="Phone" inputmode="tel" required />
                            <button class="btn-navy h-9" :disabled="busy">Save</button>
                        </form>
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
        <div class="mt-5 max-w-md"><ImportReport :report="report" :counts="[['Rows in file', 'rows', ''], ['LGA supervisors', 'lga', ''], ['Ward supervisors', 'ward', ''], ['Replaced someone', 'replaced', '']]" /></div>
    </LoadState>
</template>
