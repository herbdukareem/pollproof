<script setup>
import { computed, reactive, ref, watch } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import Badge from '../components/Badge.vue';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { localInputValue, num, signed, time } from '../lib/format';

const structure = useResource(() => api.get('structure'));
const parties = computed(() => structure.data.value?.parties ?? []);

const search = ref('');
const matches = ref([]);
const unit = ref(null);
let debounce;
watch(search, (q) => {
    clearTimeout(debounce);
    if (!q || unit.value?.code === q) return (matches.value = []);
    debounce = setTimeout(async () => (matches.value = (await api.get('units', { search: q })).data.slice(0, 8)), 250);
});

function pick(u) {
    unit.value = u;
    search.value = u.code;
    matches.value = [];
    result.value = null;
}

const form = reactive({ not_on_irev: false, votes: {}, accredited: '', rejected: '', seen_at: localInputValue() });
const shot = ref(null);
const busy = ref(false);
const error = ref('');
const result = ref(null);
const history = ref([]);

async function submit() {
    if (!unit.value) return (error.value = 'Choose a polling unit first.');
    busy.value = true;
    error.value = '';
    const fd = new FormData();
    fd.append('polling_unit_id', unit.value.id);
    fd.append('not_on_irev', form.not_on_irev ? '1' : '0');
    if (!form.not_on_irev) for (const p of parties.value) fd.append(`votes[${p.code}]`, form.votes[p.code] || 0);
    if (form.accredited !== '') fd.append('accredited', form.accredited);
    if (form.rejected !== '') fd.append('rejected', form.rejected);
    fd.append('seen_at', new Date(form.seen_at).toISOString());
    if (shot.value?.files?.[0]) fd.append('screenshot', shot.value.files[0]);
    try {
        const res = await api.post('irev-entries', fd);
        result.value = res.comparison;
        history.value.unshift({ code: unit.value.code, status: res.comparison.status, at: new Date() });
        form.votes = {};
        form.accredited = form.rejected = '';
        if (shot.value) shot.value.value = '';
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = false;
    }
}

const ours = computed(() => unit.value?.result ?? null);
function check(ourValue, entered) {
    if (!ours.value || entered === '' || entered == null) return null;
    return Number(entered) === Number(ourValue ?? 0);
}
const rowsFor = computed(() => [
    ...parties.value.map((p) => ({ key: p.code, label: p.name, ours: ours.value?.votes?.[p.code], model: 'vote' })),
    { key: 'accredited', label: 'Accredited voters', ours: ours.value?.accredited, model: 'accredited' },
    { key: 'rejected', label: 'Rejected ballots', ours: ours.value?.rejected, model: 'rejected' },
]);
function val(r) {
    return r.model === 'vote' ? form.votes[r.key] : form[r.model];
}
function set(r, v) {
    const n = v === '' ? '' : Number(v);
    if (r.model === 'vote') form.votes[r.key] = n;
    else form[r.model] = n;
}

const tone = { match: 'good', differs: 'bad', not_on_irev: 'warn', no_agent_result: 'neutral' };
const label = { match: 'Match', differs: 'Differs', not_on_irev: 'Not on IReV', no_agent_result: 'No agent result yet' };
</script>

<template>
    <PageHeader title="IReV entry" subtitle="Record what IReV shows for a unit, or that it isn't there, so it can be compared with our signed result." />

    <div class="grid gap-5 [&>*]:min-w-0 xl:grid-cols-[minmax(0,1fr)_340px]">
        <form class="card p-5" @submit.prevent="submit">
            <div class="grid gap-3 md:grid-cols-[1fr_220px]">
                <div class="relative">
                    <label class="label" for="unit">Polling unit</label>
                    <input id="unit" v-model="search" class="field" placeholder="Type a unit code" autocomplete="off" role="combobox" :aria-expanded="matches.length > 0" />
                    <ul v-if="matches.length" class="absolute z-10 mt-1 w-full overflow-hidden rounded-xl border border-line bg-white shadow-lg" role="listbox">
                        <li v-for="m in matches" :key="m.id">
                            <button type="button" class="w-full px-3 py-2 text-left text-sm hover:bg-mist" role="option" @click="pick(m)"><b>{{ m.code }}</b> · {{ m.name }} <span class="text-ink-2">· {{ m.ward }}, {{ m.lga }}</span></button>
                        </li>
                    </ul>
                </div>
                <div>
                    <label class="label" for="seen">Seen on IReV at</label>
                    <input id="seen" v-model="form.seen_at" type="datetime-local" class="field" required />
                </div>
            </div>

            <p v-if="form.not_on_irev" class="mt-4 rounded-xl bg-warn-bg px-4 py-3 text-sm text-warn"><b>Not on IReV.</b> This records that the unit's result wasn't on IReV at the time above. An alert goes to the data officer.</p>

            <div v-if="unit" class="mt-4 flex flex-wrap items-center gap-2 rounded-xl bg-mist px-4 py-3 text-sm">
                <b>{{ unit.code }} · {{ unit.name }}</b><span class="text-ink-2">{{ unit.ward }} · {{ unit.lga }}</span>
                <span class="ml-auto"><Badge :tone="ours ? 'good' : 'neutral'">{{ ours ? 'Our signed result is in' : 'No signed result yet' }}</Badge></span>
            </div>

            <div v-if="!form.not_on_irev" class="mt-4 overflow-x-auto rounded-xl border border-line">
                <table class="w-full text-sm">
                    <thead><tr><th class="th">Figure</th><th class="th text-right">Our signed</th><th class="th text-right">IReV</th><th class="th w-28">Check</th></tr></thead>
                    <tbody>
                        <tr v-for="r in rowsFor" :key="r.key" :class="check(r.ours, val(r)) === false ? 'bg-[#fff8f4]' : ''">
                            <td class="td font-semibold"><label :for="`i-${r.key}`">{{ r.label }}</label></td>
                            <td class="td text-right text-ink-2">{{ ours ? num(r.ours ?? 0) : '–' }}</td>
                            <td class="td text-right"><input :id="`i-${r.key}`" :value="val(r)" type="number" min="0" inputmode="numeric" class="field ml-auto h-9 w-24 text-right font-bold sm:w-28" :class="check(r.ours, val(r)) === false ? '!border-action' : ''" @input="set(r, $event.target.value)" /></td>
                            <td class="td text-[13px] font-bold">
                                <span v-if="check(r.ours, val(r)) === true" class="text-good">Match</span>
                                <span v-else-if="check(r.ours, val(r)) === false" class="text-action">{{ signed(Number(val(r)) - Number(r.ours ?? 0)) }}</span>
                                <span v-else class="text-ink-3">–</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <label class="label mt-4" for="shot">IReV screenshot (kept as evidence)</label>
            <input id="shot" ref="shot" type="file" accept="image/*" class="text-sm" />
            <p v-if="error" role="alert" class="mt-3 rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>
            <div class="mt-4 flex flex-wrap gap-3">
                <button class="btn-navy h-11 flex-1" :disabled="busy">{{ busy ? 'Saving…' : form.not_on_irev ? 'Save: not on IReV' : 'Save and compare' }}</button>
                <button type="button" class="btn-outline h-11" :class="form.not_on_irev ? '!bg-navy !text-white' : ''" :aria-pressed="form.not_on_irev" @click="form.not_on_irev = !form.not_on_irev">{{ form.not_on_irev ? 'Enter figures instead' : 'Mark as not on IReV' }}</button>
            </div>

            <div v-if="result" class="mt-4 rounded-xl bg-mist p-4 text-sm">
                <div class="mb-1 flex items-center gap-2"><Badge :tone="tone[result.status]">{{ label[result.status] }}</Badge></div>
                <p v-if="result.status === 'differs'">IReV minus our result: <b v-for="(d, k) in result.differences" :key="k" class="mr-2 text-action">{{ k }} {{ signed(d) }}</b></p>
                <p v-else-if="result.status === 'match'">All figures match our signed result.</p>
            </div>
        </form>

        <section class="card self-start p-5">
            <h2 class="card-title mb-2">Entered this session</h2>
            <p v-if="!history.length" class="text-sm text-ink-2">Nothing yet.</p>
            <ul>
                <li v-for="(h, i) in history" :key="i" class="flex items-center justify-between border-t border-[#eef0f3] py-2.5 text-sm first:border-0">
                    <span><b>{{ h.code }}</b> <span class="text-xs text-ink-2">· {{ time(h.at) }}</span></span><Badge :tone="tone[h.status]">{{ label[h.status] }}</Badge>
                </li>
            </ul>
        </section>
    </div>
</template>
