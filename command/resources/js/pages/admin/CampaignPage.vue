<script setup>
import { computed, reactive, ref, watch } from 'vue';
import PageHeader from '../../components/PageHeader.vue';
import LoadState from '../../components/LoadState.vue';
import Badge from '../../components/Badge.vue';
import Icon from '../../components/Icon.vue';
import FreezeNote from '../../components/FreezeNote.vue';
import { api } from '../../lib/api';
import { useResource } from '../../lib/useResource';
import { loadSession } from '../../lib/session';

const res = useResource(() => api.get('campaign'));
const d = computed(() => res.data.value);
const form = reactive({ name: '', state_name: '', election_date: '', freeze_at: '', checkin_deadline: '08:30', require_two_factor: false });
const party = reactive({ code: '', name: '', is_ours: false });
const editing = ref(null);
const edit = reactive({ name: '', is_ours: false });
const busy = ref('');
const message = ref('');
const error = ref('');

watch(d, (v) => v && Object.assign(form, {
    name: v.campaign.name,
    state_name: v.campaign.state_name,
    election_date: v.campaign.election_date?.slice(0, 10),
    freeze_at: v.campaign.freeze_at?.slice(0, 10) ?? '',
    checkin_deadline: (v.campaign.checkin_deadline ?? '08:30').slice(0, 5),
    require_two_factor: v.campaign.require_two_factor,
}));

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

const save = () => act('save', () => api.patch('campaign', { ...form, freeze_at: form.freeze_at || null }), 'Campaign saved.').then(loadSession);
const addParty = () => act('party', () => api.post('parties', { ...party }), `Added ${party.code.toUpperCase()}.`).then(() => !error.value && Object.assign(party, { code: '', name: '', is_ours: false }));
const startEdit = (p) => { editing.value = p.id; Object.assign(edit, { name: p.name, is_ours: p.is_ours }); };
const saveParty = (p) => act(`p${p.id}`, () => api.patch(`parties/${p.id}`, { ...edit }), `Saved ${p.code}.`).then(() => !error.value && (editing.value = null));
const removeParty = (p) => act(`d${p.id}`, () => api.delete(`parties/${p.id}`), `Removed ${p.code}.`);
</script>

<template>
    <PageHeader title="Campaign" subtitle="The election, the setup freeze date and the parties on the ballot." :updated-at="res.updatedAt.value" :loading="res.loading.value" @refresh="res.load" />
    <LoadState :loading="res.loading.value && !d" :error="res.error.value" :empty="!d">
        <FreezeNote :freeze-at="d.campaign.freeze_at?.slice(0, 10)" :frozen="d.frozen" />
        <p v-if="message" role="status" class="mb-4 rounded-lg bg-good-bg px-4 py-3 text-sm font-[600] text-good">{{ message }}</p>
        <p v-if="error" role="alert" class="mb-4 rounded-lg bg-bad-bg px-4 py-3 text-sm text-bad">{{ error }}</p>

        <div class="grid items-start gap-4 xl:grid-cols-2">
            <form class="card flex flex-col gap-4 p-5" @submit.prevent="save">
                <h2 class="card-title-ruled !mb-0">Election</h2>
                <div><label class="label" for="c-name">Campaign name</label><input id="c-name" v-model="form.name" class="field" required /></div>
                <div><label class="label" for="c-state">State</label><input id="c-state" v-model="form.state_name" class="field" required /></div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="label" for="c-date">Election day</label><input id="c-date" v-model="form.election_date" type="date" class="field" required /></div>
                    <div><label class="label" for="c-freeze">Setup freeze date</label><input id="c-freeze" v-model="form.freeze_at" type="date" class="field" /></div>
                </div>
                <div><label class="label" for="c-deadline">Agents must check in by</label><input id="c-deadline" v-model="form.checkin_deadline" type="time" class="field w-40" required /><p class="mt-1 text-[12.5px] text-ink-2">On election day, units without a verified check-in by this time raise an alert to the ward supervisor.</p></div>
                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-line p-3.5 text-sm hover:border-ink-3"><input v-model="form.require_two_factor" type="checkbox" class="checkbox mt-0.5" /><span><b class="font-[620]">Require two-step sign-in for all command-room staff.</b> <span class="text-ink-2">Anyone without it is asked to set it up at their next sign-in, before they can see anything.</span></span></label>
                <p class="text-[13px] text-ink-2">From the freeze date, polling units, agents and supervisors can't change, so everyone works from the same list on election day. Reserves can still be sent to units.</p>
                <div class="note text-[13px]">
                    <b class="font-[620] text-ink">Winning rule tracked on the overview:</b> {{ Math.round(d.campaign.lga_share_threshold * 100) }}% of votes in at least {{ d.campaign.lga_count_fraction > 0.66 && d.campaign.lga_count_fraction < 0.67 ? 'two-thirds' : Math.round(d.campaign.lga_count_fraction * 100) + '%' }} of LGAs, as the Constitution requires for a governorship.
                </div>
                <button class="btn-navy self-start" :disabled="busy === 'save'">Save</button>
            </form>

            <section class="card p-5">
                <h2 class="card-title-ruled">Parties on the ballot</h2>
                <p class="text-[13px] text-ink-2">Codes are what agents type in the app and in SMS results, so keep them as on the ballot. A party can't be removed once results include it.</p>
                <ul class="mt-3">
                    <li v-for="p in d.parties" :key="p.id" class="border-t border-rule py-2.5 first:border-t-0">
                        <div v-if="editing !== p.id" class="flex items-center gap-3">
                            <span class="fig w-16 font-[650]">{{ p.code }}</span>
                            <span class="flex-1 text-sm">{{ p.name }}</span>
                            <Badge v-if="p.is_ours" tone="navy">Ours</Badge>
                            <button v-if="!d.frozen" class="btn-ghost h-8 px-3 text-[13px]" @click="startEdit(p)">Edit</button>
                            <button v-if="!d.frozen && !p.has_votes" class="btn-ghost h-8 w-8 !px-0" :aria-label="`Remove ${p.code}`" :disabled="busy === `d${p.id}`" @click="removeParty(p)"><Icon name="x" :size="14" /></button>
                        </div>
                        <form v-else class="flex flex-wrap items-center gap-2" @submit.prevent="saveParty(p)">
                            <span class="fig w-16 font-[650]">{{ p.code }}</span>
                            <input v-model="edit.name" class="field h-9 flex-1" aria-label="Party name" required />
                            <label class="flex items-center gap-2 text-sm"><input v-model="edit.is_ours" type="checkbox" class="checkbox" /> Ours</label>
                            <button class="btn-navy h-9" :disabled="busy === `p${p.id}`">Save</button>
                            <button type="button" class="btn-outline h-9" @click="editing = null">Cancel</button>
                        </form>
                    </li>
                </ul>
                <form v-if="!d.frozen" class="mt-3 grid gap-2 border-t border-rule pt-3 sm:grid-cols-[90px_1fr_auto_auto]" @submit.prevent="addParty">
                    <input v-model="party.code" class="field fig h-9 uppercase placeholder:normal-case" placeholder="Code" aria-label="Party code" maxlength="10" required />
                    <input v-model="party.name" class="field h-9" placeholder="Party name" aria-label="Party name" required />
                    <label class="flex items-center gap-2 text-sm"><input v-model="party.is_ours" type="checkbox" class="checkbox" /> Ours</label>
                    <button class="btn-navy h-9" :disabled="busy === 'party'">Add party</button>
                </form>
            </section>
        </div>
    </LoadState>
</template>
