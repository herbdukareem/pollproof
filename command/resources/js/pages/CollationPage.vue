<script setup>
import { computed, reactive, ref, watch } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import Pagination from '../components/Pagination.vue';
import { usePaged } from '../lib/usePaged';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { hasRole } from '../lib/session';
import { dateTime, localInputValue, num, signed } from '../lib/format';
import Icon from '../components/Icon.vue';

const level = ref('');
const entries = useResource(() => api.get('collation-entries', { level: level.value }), { refreshMs: 30000 });
const structure = useResource(() => api.get('structure'));
watch(level, () => entries.load());

const rank = { bad: 0, warn: 1, neutral: 2, good: 3 };
const rows = computed(() => [...(entries.data.value?.data ?? [])].sort((a, b) => rank[status(a.comparison)[0]] - rank[status(b.comparison)[0]]));
const statusFilter = ref('');
const search = ref('');
const matches = computed(() => rows.value.filter((e) =>
    (!statusFilter.value || status(e.comparison)[0] === statusFilter.value)
    && (!search.value || e.comparison.scope.toLowerCase().includes(search.value.toLowerCase()))));
const paged = usePaged(matches, { perPage: 25, resetOn: () => [statusFilter.value, search.value, level.value] });
const levelLabel = { ward: 'Ward · EC8B', lga: 'LGA · EC8C', state: 'State · EC8D' };
const parties = computed(() => structure.data.value?.parties ?? []);
const canEnter = hasRole('data_officer', 'situation_room', 'dg');

const form = reactive({ level: 'ward', scope_id: '', votes: {}, rejected: '', accredited: '', announced_at: localInputValue() });
const photo = ref(null);
const busy = ref(false);
const error = ref('');
const lastComparison = ref(null);

const scopes = computed(() => {
    const lgas = structure.data.value?.lgas ?? [];
    if (form.level === 'ward') return lgas.flatMap((l) => l.wards.map((w) => ({ id: w.id, label: `${w.name} · ${l.name}` })));
    if (form.level === 'lga') return lgas.map((l) => ({ id: l.id, label: l.name }));
    return [];
});
watch(() => form.level, () => (form.scope_id = ''));

const levels = [
    { value: '', label: 'All' },
    { value: 'ward', label: 'Wards · EC8B' },
    { value: 'lga', label: 'LGAs · EC8C' },
    { value: 'state', label: 'State · EC8D' },
];
const selected = ref(null);
const showForm = ref(true);
const picked = computed(() => rows.value.find((r) => r.id === selected.value) ?? null);
const summary = computed(() => {
    const out = { bad: 0, warn: 0, good: 0, neutral: 0 };
    for (const r of rows.value) out[status(r.comparison)[0]]++;
    return out;
});
const partyName = (code) => parties.value.find((p) => p.code === code)?.name ?? code;
const codes = (c) => [...new Set([...Object.keys(c.ours ?? {}), ...Object.keys(c.declared ?? {})])].sort();
function pick(e) {
    selected.value = e.id;
    showForm.value = false;
}

const status = (c) => {
    if (Object.keys(c.differences).length) return c.coverage_complete ? ['bad', 'Mismatch'] : ['warn', 'Differs, units missing'];
    return c.coverage_complete ? ['good', 'Match'] : ['neutral', 'Partial match'];
};

async function submit() {
    busy.value = true;
    error.value = '';
    const fd = new FormData();
    fd.append('level', form.level);
    if (form.level !== 'state') fd.append('scope_id', form.scope_id);
    for (const p of parties.value) fd.append(`votes[${p.code}]`, form.votes[p.code] || 0);
    if (form.rejected !== '') fd.append('rejected', form.rejected);
    if (form.accredited !== '') fd.append('accredited', form.accredited);
    fd.append('announced_at', new Date(form.announced_at).toISOString());
    if (photo.value?.files?.[0]) fd.append('photo', photo.value.files[0]);
    try {
        const res = await api.post('collation-entries', fd);
        lastComparison.value = res.comparison;
        form.votes = {};
        form.rejected = form.accredited = '';
        if (photo.value) photo.value.value = '';
        await entries.load();
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <PageHeader title="Collation checks" subtitle="Every declared sheet compared with our signed unit results." :updated-at="entries.updatedAt.value" :loading="entries.loading.value" @refresh="entries.load">
        <template v-if="canEnter" #actions>
            <button class="btn-primary" @click="showForm = true; selected = null"><Icon name="plus" :size="16" /> Enter a sheet</button>
        </template>
    </PageHeader>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div role="tablist" aria-label="Level" class="inline-flex max-w-full overflow-x-auto rounded-xl border border-line bg-white p-1">
            <button
                v-for="l in levels"
                :key="l.value"
                role="tab"
                :aria-selected="level === l.value"
                class="h-8 rounded-lg px-3.5 text-[13px] font-bold whitespace-nowrap transition"
                :class="level === l.value ? 'bg-navy text-white' : 'text-ink-2 hover:text-ink'"
                @click="level = l.value"
            >{{ l.label }}</button>
        </div>
        <div class="flex flex-wrap gap-2" role="group" aria-label="Filter by result">
            <button class="pill bg-bad-bg text-bad ring-navy transition hover:brightness-95" :class="statusFilter === 'bad' ? 'ring-2' : ''" :aria-pressed="statusFilter === 'bad'" @click="statusFilter = statusFilter === 'bad' ? '' : 'bad'">{{ summary.bad }} mismatch{{ summary.bad === 1 ? '' : 'es' }}</button>
            <button v-if="summary.warn" class="pill bg-warn-bg text-warn ring-navy transition hover:brightness-95" :class="statusFilter === 'warn' ? 'ring-2' : ''" :aria-pressed="statusFilter === 'warn'" @click="statusFilter = statusFilter === 'warn' ? '' : 'warn'">{{ summary.warn }} differ, units missing</button>
            <button class="pill bg-mist text-ink-2 ring-1 ring-line ring-inset transition hover:brightness-95" :class="statusFilter === 'neutral' ? '!ring-2 !ring-navy' : ''" :aria-pressed="statusFilter === 'neutral'" @click="statusFilter = statusFilter === 'neutral' ? '' : 'neutral'">{{ summary.neutral }} partial</button>
            <button class="pill bg-good-bg text-good ring-navy transition hover:brightness-95" :class="statusFilter === 'good' ? 'ring-2' : ''" :aria-pressed="statusFilter === 'good'" @click="statusFilter = statusFilter === 'good' ? '' : 'good'">{{ summary.good }} match</button>
        </div>
    </div>

    <div class="grid items-start gap-5 xl:grid-cols-[1fr_400px]">
        <section class="card min-w-0 overflow-hidden">
            <div class="flex flex-wrap items-center gap-3 border-b border-[#eef0f3] p-4">
                <div class="relative w-full max-w-xs">
                    <Icon name="search" :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" />
                    <input v-model="search" class="field w-full pl-9" placeholder="Ward or LGA name" aria-label="Search collation points" />
                </div>
                <button v-if="statusFilter || search" class="text-[13px] font-bold text-navy hover:underline" @click="() => { statusFilter = ''; search = ''; }">Clear filters</button>
            </div>
            <LoadState :loading="entries.loading.value" :error="entries.error.value" :empty="!paged.rows.value.length" :empty-text="rows.length ? 'No collation points match.' : 'No collation sheets entered yet.'">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead><tr><th class="th">Collation point</th><th class="th text-right">Our units</th><th class="th text-right">Declared</th><th class="th">Difference</th><th class="th">Status</th></tr></thead>
                        <tbody>
                            <tr
                                v-for="e in paged.rows.value"
                                :key="e.id"
                                class="cursor-pointer"
                                :class="[selected === e.id ? '!bg-[#eef2fb]' : status(e.comparison)[0] === 'bad' ? 'bg-[#fff8f4]' : '']"
                                tabindex="0"
                                @click="pick(e)"
                                @keydown.enter="pick(e)"
                            >
                                <td class="td"><div class="font-bold">{{ e.comparison.scope }}</div><div class="text-xs text-ink-2">{{ levelLabel[e.comparison.level] }} · {{ e.comparison.units_reported }} of {{ e.comparison.units_in_scope }} units reported</div></td>
                                <td class="td text-right font-semibold">{{ num(e.comparison.our_total_cast) }}</td>
                                <td class="td text-right font-semibold">{{ num(e.comparison.declared_total_cast) }}</td>
                                <td class="td">
                                    <span v-for="(d, code) in e.comparison.differences" :key="code" class="mr-2 font-extrabold text-action">{{ code }} {{ signed(d) }}</span>
                                    <span v-if="!Object.keys(e.comparison.differences).length" class="text-ink-3">None</span>
                                </td>
                                <td class="td"><Badge :tone="status(e.comparison)[0]">{{ status(e.comparison)[1] }}</Badge></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :meta="paged.meta.value" noun="collation points" @page="paged.setPage" @per-page="paged.setPerPage" />
                <div class="border-t border-[#eef0f3] px-4 py-2.5 text-[12.5px] text-ink-3">Figures are total votes cast. Select a row to see it party by party.</div>
            </LoadState>
        </section>

        <aside v-if="picked && !showForm" class="card overflow-hidden" :class="status(picked.comparison)[0] === 'bad' ? 'border-2 border-action' : ''">
            <div class="flex flex-col gap-2 p-5">
                <div class="flex items-center gap-2">
                    <Badge :tone="status(picked.comparison)[0]">{{ status(picked.comparison)[1] }}</Badge>
                    <span class="text-xs text-ink-2">Announced {{ dateTime(picked.announced_at) }}</span>
                    <button class="ml-auto rounded-lg p-1 text-ink-2 hover:bg-mist" aria-label="Close" @click="selected = null; showForm = canEnter"><Icon name="x" :size="16" /></button>
                </div>
                <h2 class="text-lg font-extrabold">{{ picked.comparison.scope }}</h2>
                <p class="text-[13.5px] leading-relaxed text-ink-2">
                    <template v-if="Object.keys(picked.comparison.differences).length">
                        The declared sheet differs from the {{ picked.comparison.units_reported }} signed unit results beneath it<template v-if="!picked.comparison.coverage_complete">, though {{ picked.comparison.units_in_scope - picked.comparison.units_reported }} units haven't reported yet</template>.
                    </template>
                    <template v-else-if="picked.comparison.coverage_complete">The declared sheet matches every signed unit result beneath it.</template>
                    <template v-else>No differences so far, but {{ picked.comparison.units_in_scope - picked.comparison.units_reported }} of {{ picked.comparison.units_in_scope }} units haven't reported yet.</template>
                </p>
            </div>
            <table class="w-full text-sm">
                <thead><tr><th class="th">Party</th><th class="th text-right">Ours</th><th class="th text-right">Declared</th><th class="th text-right">Diff</th></tr></thead>
                <tbody>
                    <tr v-for="code in codes(picked.comparison)" :key="code" :class="picked.comparison.differences[code] ? 'bg-[#fff8f4]' : ''">
                        <td class="td font-semibold">{{ partyName(code) }}</td>
                        <td class="td text-right">{{ num(picked.comparison.ours?.[code] ?? 0) }}</td>
                        <td class="td text-right">{{ num(picked.comparison.declared?.[code] ?? 0) }}</td>
                        <td class="td text-right font-extrabold" :class="picked.comparison.differences[code] ? 'text-action' : 'text-ink-3'">{{ picked.comparison.differences[code] ? signed(picked.comparison.differences[code]) : '0' }}</td>
                    </tr>
                    <tr class="font-bold">
                        <td class="td">Total votes cast</td>
                        <td class="td text-right">{{ num(picked.comparison.our_total_cast) }}</td>
                        <td class="td text-right">{{ num(picked.comparison.declared_total_cast) }}</td>
                        <td class="td text-right" :class="picked.comparison.declared_total_cast !== picked.comparison.our_total_cast ? 'text-action' : 'text-ink-3'">{{ signed(picked.comparison.declared_total_cast - picked.comparison.our_total_cast) }}</td>
                    </tr>
                </tbody>
            </table>
            <div v-if="picked.comparison.our_accredited && picked.comparison.declared_total_cast > picked.comparison.our_accredited" class="m-4 rounded-xl bg-bad-bg px-4 py-3 text-[13px] text-bad">
                <b>Votes cast exceed accreditation.</b> The sheet declares {{ num(picked.comparison.declared_total_cast) }} votes cast; our units recorded {{ num(picked.comparison.our_accredited) }} accredited voters.
            </div>
            <div v-else class="h-2"></div>
        </aside>

        <form v-else-if="canEnter" class="card self-start p-5" @submit.prevent="submit">
            <h2 class="card-title">Enter a declared sheet</h2>
            <p class="card-sub mb-4">Type the figures exactly as announced. They're checked against our units straight away.</p>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label" for="c-level">Level</label>
                    <select id="c-level" v-model="form.level" class="field"><option value="ward">Ward · EC8B</option><option value="lga">LGA · EC8C</option><option value="state">State · EC8D</option></select>
                </div>
                <div>
                    <label class="label" for="c-time">Announced at</label>
                    <input id="c-time" v-model="form.announced_at" type="datetime-local" class="field" required />
                </div>
            </div>
            <div v-if="form.level !== 'state'" class="mt-3">
                <label class="label" for="c-scope">Collation centre</label>
                <select id="c-scope" v-model="form.scope_id" class="field" required>
                    <option value="" disabled>Choose…</option>
                    <option v-for="s in scopes" :key="s.id" :value="s.id">{{ s.label }}</option>
                </select>
            </div>
            <div class="mt-4 overflow-hidden rounded-xl border border-line">
                <div v-for="p in parties" :key="p.code" class="flex items-center justify-between gap-3 border-b border-[#eef0f3] px-3 py-2">
                    <label :for="`v-${p.code}`" class="text-sm font-semibold">{{ p.name }}</label>
                    <input :id="`v-${p.code}`" v-model.number="form.votes[p.code]" type="number" min="0" inputmode="numeric" class="field h-9 w-28 text-right font-bold" />
                </div>
                <div class="flex items-center justify-between gap-3 border-b border-[#eef0f3] px-3 py-2">
                    <label for="c-rej" class="text-sm font-semibold">Rejected ballots</label>
                    <input id="c-rej" v-model.number="form.rejected" type="number" min="0" class="field h-9 w-28 text-right font-bold" />
                </div>
                <div class="flex items-center justify-between gap-3 px-3 py-2">
                    <label for="c-acc" class="text-sm font-semibold">Accredited (if declared)</label>
                    <input id="c-acc" v-model.number="form.accredited" type="number" min="0" class="field h-9 w-28 text-right font-bold" />
                </div>
            </div>
            <label class="label mt-4" for="c-photo">Photo of the sheet (optional)</label>
            <input id="c-photo" ref="photo" type="file" accept="image/*" class="text-sm" />
            <p v-if="error" role="alert" class="mt-3 rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>
            <button class="btn-primary mt-4 h-11 w-full" :disabled="busy">{{ busy ? 'Checking…' : 'Save and compare' }}</button>

            <div v-if="lastComparison" class="mt-4 rounded-xl p-3.5 text-sm" :class="Object.keys(lastComparison.differences).length ? 'bg-bad-bg text-bad' : 'bg-good-bg text-good'">
                <b>{{ lastComparison.scope }}:</b>
                <template v-if="Object.keys(lastComparison.differences).length">
                    declared minus our units
                    <span v-for="(d, code) in lastComparison.differences" :key="code" class="ml-1 font-extrabold">{{ code }} {{ signed(d) }}</span>.
                    The alert has gone to the legal team and collation agent.
                </template>
                <template v-else>matches our signed results.</template>
            </div>
        </form>
    </div>
</template>
