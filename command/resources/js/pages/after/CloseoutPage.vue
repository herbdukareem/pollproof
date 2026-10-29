<script setup>
import { computed, reactive, ref, watch } from 'vue';
import PageHeader from '../../components/PageHeader.vue';
import LoadState from '../../components/LoadState.vue';
import Badge from '../../components/Badge.vue';
import Icon from '../../components/Icon.vue';
import { api } from '../../lib/api';
import { useResource } from '../../lib/useResource';
import { hasRole, session } from '../../lib/session';
import { dateTime, localInputValue, num, signed } from '../../lib/format';

const res = useResource(() => api.get('closeout'));
const d = computed(() => res.data.value);
const isDg = hasRole('dg');
const form = reactive({ declared_at: localInputValue(), votes: {} });
const editing = ref(false);
const choice = ref('');
const busy = ref('');
const message = ref('');
const error = ref('');

watch(d, (v) => {
    if (!v) return;
    choice.value = v.retention.choice ?? 'keep_until_cases_end';
    v.rows.forEach((r) => (form.votes[r.party] = r.declared ?? ''));
    if (v.declared_at) form.declared_at = localInputValue(new Date(v.declared_at));
});

const differences = computed(() => (d.value?.rows ?? []).filter((r) => r.difference));
const kpis = computed(() => {
    if (!d.value?.declared) return [];
    const v = d.value;
    return [
        { label: 'Declared winner', value: v.winner.name, note: `${v.winner.is_ours ? 'Our candidate' : 'Not our candidate'} · margin ${num(v.winner.margin)}` },
        { label: 'Our LGAs at 25% or more', value: `${v.our_lgas_meeting_threshold} of ${v.lga_count}`, note: `Threshold needs ${v.lgas_required}` },
        { label: 'Units with our signed result', value: num(v.units_reported), note: `${v.units ? ((100 * v.units_reported) / v.units).toFixed(1) : 0}% of ${num(v.units)}` },
        { label: 'Units cited in tribunal answers', value: num(v.evidence_units), note: `${v.open_cases} open ${v.open_cases === 1 ? 'case' : 'cases'}` },
    ];
});
const options = [
    ['keep_until_cases_end', 'Keep until all tribunal cases end', 'Recommended while a petition is open'],
    ['handover_then_delete', 'Hand over to the campaign, then delete', 'Full export to your team, then removed from PollProof'],
    ['delete_now', 'Delete everything now', 'Not available while a case is open'],
];

async function act(key, fn, done) {
    busy.value = key;
    error.value = message.value = '';
    try {
        res.data.value = await fn();
        message.value = done;
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = '';
    }
}
const declare = () => {
    if (!d.value.declared && !window.confirm('Record the declaration? From then on results, collation, IReV and check-ins are read-only.')) return;
    act('declare', () => api.post('closeout/declare', { declared_at: new Date(form.declared_at).toISOString(), votes: Object.fromEntries(Object.entries(form.votes).filter(([, n]) => n !== '' && n != null).map(([k, n]) => [k, Number(n)])) }), 'Declaration recorded.').then(() => (editing.value = false));
};
const saveChoice = () => act('retention', () => api.post('closeout/retention', { choice: choice.value }), 'Choice saved. Ashlab confirms with the DG before anything is deleted.');
const printReport = () => window.print();
</script>

<template>
    <PageHeader title="Close-out" subtitle="Final figures, the campaign report, and what happens to the data." :updated-at="res.updatedAt.value" :loading="res.loading.value" @refresh="res.load">
        <template #actions>
            <button v-if="d?.declared" class="btn-primary print:hidden" @click="printReport"><Icon name="download" :size="16" /> Campaign report (PDF)</button>
        </template>
    </PageHeader>

    <LoadState :loading="res.loading.value && !d" :error="res.error.value" :empty="!d">
        <p v-if="message" role="status" class="mb-4 rounded-xl bg-good-bg px-4 py-3 text-sm font-semibold text-good print:hidden">{{ message }}</p>
        <p v-if="error" role="alert" class="mb-4 rounded-xl bg-bad-bg px-4 py-3 text-sm text-bad print:hidden">{{ error }}</p>

        <div v-if="d.declared" class="mb-5 flex items-center gap-3 rounded-2xl bg-navy px-5 py-3.5 text-sm text-white">
            <Icon name="lock" :size="18" />
            <span><b>Records locked.</b> Election-day data is read-only since the declaration on {{ dateTime(d.declared_at) }}.</span>
        </div>
        <div class="mb-6 hidden print:block">
            <h1 class="text-2xl font-extrabold">{{ session.user?.campaign?.name }}: campaign report</h1>
            <p class="text-sm">{{ session.user?.campaign?.state_name }} governorship election · generated {{ dateTime(new Date()) }}</p>
        </div>

        <div v-if="kpis.length" class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div v-for="k in kpis" :key="k.label" class="card p-4">
                <div class="text-sm text-ink-2">{{ k.label }}</div>
                <div class="mt-1 text-[24px] leading-tight font-extrabold">{{ k.value }}</div>
                <div class="text-[13px] text-ink-2">{{ k.note }}</div>
            </div>
        </div>

        <div class="grid items-start gap-5 xl:grid-cols-[1.4fr_1fr]">
            <section class="card overflow-hidden">
                <div class="flex flex-wrap items-baseline justify-between gap-2 px-5 pt-5 pb-3">
                    <div>
                        <h2 class="card-title ">Declared result against our signed records</h2>
                        <p v-if="d.declared" class="text-[13px] text-ink-2">{{ differences.length ? `Differences: ${differences.map((r) => `${r.party} ${signed(r.difference)}`).join(', ')}.` : 'The declared figures match our records for every party.' }}</p>
                        <p v-else class="text-[13px] text-ink-2">No declaration recorded yet. Our running totals are shown.</p>
                    </div>
                    <button v-if="isDg && d.declared && !editing" class="btn-outline h-8 px-3 text-xs print:hidden" @click="editing = true">Correct the declared figures</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[520px] text-sm">
                        <thead class="bg-mist"><tr><th class="th">Party</th><th class="th text-right">Declared</th><th class="th text-right">Our records</th><th class="th text-right">Difference</th></tr></thead>
                        <tbody>
                            <tr v-for="r in d.rows" :key="r.party">
                                <td class="td"><b>{{ r.name }}</b><span v-if="r.is_ours" class="text-ink-2"> (ours)</span></td>
                                <td class="td text-right">
                                    <input v-if="isDg && (!d.declared || editing)" v-model.number="form.votes[r.party]" type="number" min="0" class="field ml-auto h-9 w-32 text-right print:hidden" :aria-label="`Declared votes for ${r.name}`" />
                                    <span v-else class="font-semibold">{{ num(r.declared) }}</span>
                                </td>
                                <td class="td text-right">{{ num(r.ours) }}</td>
                                <td class="td text-right font-bold" :class="r.difference ? 'text-action' : 'text-ink-2'">{{ r.difference == null ? '–' : signed(r.difference) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="px-5 py-3 text-[13px] text-ink-2">Our records cover {{ num(d.units_reported) }} of {{ num(d.units) }} units.{{ d.units - d.units_reported ? ` The other ${num(d.units - d.units_reported)} had no agent result.` : '' }}</p>
                <form v-if="isDg && (!d.declared || editing)" class="flex flex-wrap items-end gap-3 border-t border-[#eef0f3] px-5 py-4 print:hidden" @submit.prevent="declare">
                    <div><label class="label" for="dat">Declared at</label><input id="dat" v-model="form.declared_at" type="datetime-local" class="field h-10" required /></div>
                    <button class="btn-navy h-10" :disabled="busy === 'declare'">{{ d.declared ? 'Save correction' : 'Record the declaration' }}</button>
                    <button v-if="editing" type="button" class="btn-outline h-10" @click="editing = false">Cancel</button>
                    <p v-if="!d.declared" class="w-full text-xs text-ink-2">Recording the declaration locks results, collation, IReV and check-ins. The tribunal workspace stays open.</p>
                </form>
            </section>

            <section class="card p-5">
                <h2 class="card-title ">What happens to the data</h2>
                <p class="mt-1 text-[13px] text-ink-2">
                    Stored: {{ num(d.stored.results) }} signed results, {{ num(d.stored.photos) }} EC8A, collation and IReV images, {{ num(d.stored.check_ins) }} check-ins, {{ num(d.stored.incidents) }} incidents and {{ num(d.stored.audit_entries) }} audit entries.
                </p>
                <fieldset class="mt-4 flex flex-col gap-2" :disabled="!isDg">
                    <legend class="sr-only">Choose one</legend>
                    <label v-for="[value, title, note] in options" :key="value" class="flex gap-3 rounded-xl border p-3.5 text-sm" :class="[choice === value ? 'border-navy bg-mist' : 'border-line', value === 'delete_now' && d.open_cases ? 'opacity-50' : '']">
                        <input v-model="choice" type="radio" name="retention" :value="value" class="mt-0.5 h-4 w-4 accent-[#13213c]" :disabled="value === 'delete_now' && d.open_cases > 0" />
                        <span><b class="block">{{ title }}</b><span class="text-[13px] text-ink-2">{{ note }}</span></span>
                    </label>
                </fieldset>
                <p class="mt-3 text-xs text-ink-2">Deletion is blocked while a tribunal case is open. Both the DG and Ashlab must confirm before anything is deleted.</p>
                <div class="mt-3 flex items-center gap-3 print:hidden">
                    <button v-if="isDg" class="btn-navy" :disabled="busy === 'retention'" @click="saveChoice">Save choice</button>
                    <Badge v-if="d.retention.choice" tone="info">Saved {{ dateTime(d.retention.set_at) }}</Badge>
                </div>
            </section>
        </div>
    </LoadState>
</template>
