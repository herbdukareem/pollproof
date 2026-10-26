<script setup>
import { computed, reactive, ref, watch } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { hasRole } from '../lib/session';
import { localInputValue, num, signed, titleCase } from '../lib/format';

const level = ref('');
const entries = useResource(() => api.get('collation-entries', { level: level.value }), { refreshMs: 30000 });
const structure = useResource(() => api.get('structure'));
watch(level, () => entries.load());

const rows = computed(() => entries.data.value?.data ?? []);
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
        <template #actions>
            <label class="sr-only" for="level-filter">Level</label>
            <select id="level-filter" v-model="level" class="field h-10 w-auto">
                <option value="">All levels</option>
                <option value="ward">Wards · EC8B</option>
                <option value="lga">LGAs · EC8C</option>
                <option value="state">State · EC8D</option>
            </select>
        </template>
    </PageHeader>

    <div class="grid gap-5" :class="canEnter ? 'xl:grid-cols-[1fr_400px]' : ''">
        <section class="card overflow-hidden">
            <LoadState :loading="entries.loading.value" :error="entries.error.value" :empty="!rows.length" empty-text="No collation sheets entered yet.">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead class="bg-mist"><tr><th class="th">Collation point</th><th class="th text-right">Our units</th><th class="th text-right">Declared</th><th class="th">Difference</th><th class="th">Status</th></tr></thead>
                        <tbody>
                            <tr v-for="e in rows" :key="e.id" :class="status(e.comparison)[0] === 'bad' ? 'bg-[#fff8f4]' : ''">
                                <td class="td"><div class="font-bold">{{ e.comparison.scope }}</div><div class="text-xs text-ink-2">{{ titleCase(e.comparison.level) }} · {{ e.comparison.units_reported }} of {{ e.comparison.units_in_scope }} units reported</div></td>
                                <td class="td text-right font-semibold">{{ num(e.comparison.our_total_cast) }}</td>
                                <td class="td text-right font-semibold">{{ num(e.comparison.declared_total_cast) }}</td>
                                <td class="td">
                                    <span v-for="(d, code) in e.comparison.differences" :key="code" class="mr-2 font-extrabold text-action">{{ code }} {{ signed(d) }}</span>
                                    <span v-if="!Object.keys(e.comparison.differences).length" class="text-ink-2">None</span>
                                </td>
                                <td class="td"><Badge :tone="status(e.comparison)[0]">{{ status(e.comparison)[1] }}</Badge></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </LoadState>
        </section>

        <form v-if="canEnter" class="card self-start p-5" @submit.prevent="submit">
            <h2 class="text-[17px] font-bold">Enter a declared sheet</h2>
            <p class="mt-1 mb-4 text-[13px] text-ink-2">Type the figures exactly as announced. They're checked against our units straight away.</p>
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
