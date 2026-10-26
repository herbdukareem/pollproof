<script setup>
import { computed, reactive, ref, watch } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import Badge from '../components/Badge.vue';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { localInputValue, num, signed } from '../lib/format';

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

const tone = { match: 'good', differs: 'bad', not_on_irev: 'warn', no_agent_result: 'neutral' };
const label = { match: 'Match', differs: 'Differs', not_on_irev: 'Not on IReV', no_agent_result: 'No agent result yet' };
</script>

<template>
    <PageHeader title="IReV entry" subtitle="Record what IReV shows for a unit, or that it isn't there, so it can be compared with our signed result." />

    <div class="grid gap-5 xl:grid-cols-[1fr_340px]">
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

            <label class="mt-4 flex items-center gap-2.5 text-sm font-semibold">
                <input v-model="form.not_on_irev" type="checkbox" class="h-4.5 w-4.5 accent-navy" />
                This unit's result is not on IReV
            </label>

            <div v-if="!form.not_on_irev" class="mt-4 overflow-hidden rounded-xl border border-line">
                <div v-for="p in parties" :key="p.code" class="flex items-center justify-between gap-3 border-b border-[#eef0f3] px-3 py-2">
                    <label :for="`i-${p.code}`" class="text-sm font-semibold">{{ p.name }}</label>
                    <input :id="`i-${p.code}`" v-model.number="form.votes[p.code]" type="number" min="0" class="field h-9 w-28 text-right font-bold" />
                </div>
                <div class="flex items-center justify-between gap-3 border-b border-[#eef0f3] px-3 py-2">
                    <label for="i-acc" class="text-sm font-semibold">Accredited voters</label>
                    <input id="i-acc" v-model.number="form.accredited" type="number" min="0" class="field h-9 w-28 text-right font-bold" />
                </div>
                <div class="flex items-center justify-between gap-3 px-3 py-2">
                    <label for="i-rej" class="text-sm font-semibold">Rejected ballots</label>
                    <input id="i-rej" v-model.number="form.rejected" type="number" min="0" class="field h-9 w-28 text-right font-bold" />
                </div>
            </div>

            <label class="label mt-4" for="shot">IReV screenshot (kept as evidence)</label>
            <input id="shot" ref="shot" type="file" accept="image/*" class="text-sm" />
            <p v-if="error" role="alert" class="mt-3 rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>
            <button class="btn-primary mt-4 h-11" :disabled="busy">{{ busy ? 'Saving…' : 'Save and compare' }}</button>

            <div v-if="result" class="mt-4 rounded-xl bg-mist p-4 text-sm">
                <div class="mb-1 flex items-center gap-2"><Badge :tone="tone[result.status]">{{ label[result.status] }}</Badge></div>
                <p v-if="result.status === 'differs'">IReV minus our result: <b v-for="(d, k) in result.differences" :key="k" class="mr-2 text-action">{{ k }} {{ signed(d) }}</b></p>
                <p v-else-if="result.status === 'match'">All figures match our signed result.</p>
            </div>
        </form>

        <section class="card self-start p-5">
            <h2 class="mb-2 text-[16px] font-bold">Entered this session</h2>
            <p v-if="!history.length" class="text-sm text-ink-2">Nothing yet.</p>
            <ul>
                <li v-for="(h, i) in history" :key="i" class="flex items-center justify-between border-t border-[#eef0f3] py-2.5 text-sm first:border-0">
                    <b>{{ h.code }}</b><Badge :tone="tone[h.status]">{{ label[h.status] }}</Badge>
                </li>
            </ul>
        </section>
    </div>
</template>
