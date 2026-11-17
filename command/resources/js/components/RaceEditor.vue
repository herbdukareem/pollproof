<script setup>
import { computed, reactive, ref, watch } from 'vue';
import Modal from './Modal.vue';
import RaceTag from './RaceTag.vue';
import Icon from './Icon.vue';
import { api } from '../lib/api';
import { races, TYPE_TAG } from '../lib/races';

/**
 * Add or change a race: what kind it is, where it is fought, who is on the ballot, and its name.
 * `structure` is GET /structure (LGAs with their wards, and the campaign's parties).
 */
const props = defineProps({ open: Boolean, race: { type: Object, default: null }, structure: { type: Object, default: null } });
const emit = defineEmits(['close', 'saved']);

const TIERS = [
    { label: 'Federal', types: ['presidential', 'senate', 'reps'] },
    { label: 'State', types: ['governorship', 'assembly'] },
    { label: 'Local government', types: ['lga_chair', 'councillor'] },
];
const WHERE = {
    state: 'The whole state',
    lgas: 'The LGAs in the district',
    lgas_or_wards: 'LGAs or wards in the constituency',
    one_lga: 'One LGA',
    one_ward: 'One ward',
};
const types = computed(() => Object.fromEntries(races.types.map((t) => [t.value, t])));

const form = reactive({});
const nameTouched = ref(false);
const codeTouched = ref(false);
const byWard = ref(false);
const wardLga = ref(null);
const lgaQuery = ref('');
const busy = ref(false);
const errors = ref({});

watch(() => props.open, (open) => {
    if (!open) return;
    const r = props.race;
    const parties = props.structure?.parties ?? [];
    Object.assign(form, {
        type: r?.type ?? '',
        name: r?.name ?? '',
        code: r?.code ?? '',
        election_date: r?.election_date ?? races.currentDate ?? '',
        is_rerun: r?.is_rerun ?? false,
        announce: r?.announce ?? false,
        lga_ids: [...(r?.lga_ids ?? [])],
        ward_ids: [...(r?.ward_ids ?? [])],
        // Every party, ticked if on this ballot; a new race starts with them all.
        ballot: parties.map((p) => {
            const on = r ? r.parties.find((x) => x.id === p.id) : null;
            // photo: the saved one; file/preview: one picked here, uploaded on save; drop: remove it on save.
            return { ...p, on: r ? !!on : true, candidate: on?.candidate ?? '', photo: on?.photo ?? null, file: null, preview: null, drop: false };
        }),
    });
    nameTouched.value = !!r;
    codeTouched.value = !!r;
    byWard.value = (r?.ward_ids?.length ?? 0) > 0 && !(r?.lga_ids?.length);
    wardLga.value = r?.ward_ids?.length ? wardsById.value[r.ward_ids[0]]?.lga_id ?? null : null;
    lgaQuery.value = '';
    errors.value = {};
}, { immediate: true });

const lgas = computed(() => props.structure?.lgas ?? []);
const wardsById = computed(() => Object.fromEntries(lgas.value.flatMap((l) => l.wards.map((w) => [w.id, { ...w, lga_id: l.id, lga: l.name }]))));
const area = computed(() => types.value[form.type]?.area ?? null);
const shownLgas = computed(() => {
    const q = lgaQuery.value.trim().toLowerCase();
    return q ? lgas.value.filter((l) => `${l.name} ${l.code}`.toLowerCase().includes(q)) : lgas.value;
});
const wardsOf = computed(() => lgas.value.find((l) => l.id === wardLga.value)?.wards ?? []);
const locked = computed(() => !!props.race?.has_results);

/** Pick a kind of race: clear an area that no longer fits it. */
function pickType(t) {
    if (locked.value && t !== props.race.type) return;
    const was = area.value;
    form.type = t;
    const now = types.value[t]?.area;
    if (was !== now) {
        if (now === 'state') { form.lga_ids = []; form.ward_ids = []; }
        if (now === 'one_lga') { form.lga_ids = form.lga_ids.slice(0, 1); form.ward_ids = []; }
        if (now === 'one_ward') { form.lga_ids = []; form.ward_ids = form.ward_ids.slice(0, 1); }
        if (now === 'lgas') form.ward_ids = [];
        byWard.value = now === 'one_ward' || (now === 'lgas_or_wards' && byWard.value);
    }
}
function toggleLga(id) {
    if (area.value === 'one_lga') { form.lga_ids = [id]; return; }
    form.lga_ids = form.lga_ids.includes(id) ? form.lga_ids.filter((x) => x !== id) : [...form.lga_ids, id];
}
function toggleWard(id) {
    if (area.value === 'one_ward') { form.ward_ids = [id]; return; }
    form.ward_ids = form.ward_ids.includes(id) ? form.ward_ids.filter((x) => x !== id) : [...form.ward_ids, id];
}
function setByWard(on) {
    byWard.value = on;
    if (on) form.lga_ids = []; else form.ward_ids = [];
}
const allShown = computed(() => shownLgas.value.length && shownLgas.value.every((l) => form.lga_ids.includes(l.id)));
function toggleAllShown() {
    const ids = shownLgas.value.map((l) => l.id);
    form.lga_ids = allShown.value ? form.lga_ids.filter((x) => !ids.includes(x)) : [...new Set([...form.lga_ids, ...ids])];
}

/** The area in words, for the suggested name: "Bida", "Bida and Gbako", "Ceniyan ward". */
const areaWords = computed(() => {
    if (area.value === 'state') return '';
    const l = form.lga_ids.map((id) => lgas.value.find((x) => x.id === id)?.name).filter(Boolean);
    const w = form.ward_ids.map((id) => wardsById.value[id]?.name).filter(Boolean);
    const names = [...l, ...w];
    if (!names.length) return '';
    return names.length === 1 ? names[0] : names.length === 2 ? `${names[0]} and ${names[1]}` : `${names[0]} and ${names.length - 1} more`;
});
const shortLabel = { presidential: 'Presidential', senate: 'Senate', reps: 'Reps', governorship: 'Governorship', assembly: 'Assembly', lga_chair: 'Chairman', councillor: 'Councillor' };
const suggestedName = computed(() => (form.type ? `${shortLabel[form.type]}${areaWords.value ? ` (${areaWords.value})` : ''}${form.is_rerun ? ' re-run' : ''}` : ''));
const suggestedCode = computed(() => {
    if (!form.type) return '';
    const base = TYPE_TAG[form.type];
    const first = areaWords.value.split(' ')[0]?.replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 5);
    const code = first ? `${base}-${first}` : base;
    // Not one another race already has.
    const taken = new Set(races.list.filter((r) => r.id !== props.race?.id).map((r) => r.code));
    let out = code;
    for (let i = 2; taken.has(out); i++) out = `${code}${i}`;
    return out;
});
watch(suggestedName, (v) => { if (!nameTouched.value) form.name = v; });
watch(suggestedCode, (v) => { if (!codeTouched.value) form.code = v; });

// A candidate's photo: picked here, shown at once, uploaded when the race is saved.
const photoInput = ref(null);
const photoFor = ref(null);
const photoError = ref('');
function pickPhoto(p) {
    photoFor.value = p;
    photoError.value = '';
    photoInput.value.value = '';
    photoInput.value.click();
}
function onPhoto(e) {
    const file = e.target.files?.[0];
    const p = photoFor.value;
    if (!file || !p) return;
    if (file.size > 5 * 1024 * 1024) {
        photoError.value = `${p.code}: use a photo under 5 MB.`;
        return;
    }
    if (p.preview) URL.revokeObjectURL(p.preview);
    Object.assign(p, { file, preview: URL.createObjectURL(file), drop: false });
}
function clearPhoto(p) {
    if (p.preview) URL.revokeObjectURL(p.preview);
    Object.assign(p, { file: null, preview: null, drop: !!p.photo });
}
const shownPhoto = (p) => p.preview ?? (p.drop ? null : p.photo);

const ourOn = computed(() => form.ballot?.some((p) => p.is_ours && p.on));
const areaReady = computed(() => ({
    state: true,
    lgas: form.lga_ids?.length > 0,
    lgas_or_wards: form.lga_ids?.length > 0 || form.ward_ids?.length > 0,
    one_lga: form.lga_ids?.length === 1,
    one_ward: form.ward_ids?.length === 1,
})[area.value] ?? false);
const ready = computed(() => form.type && areaReady.value && form.name?.trim() && form.code?.trim() && form.election_date && ourOn.value && form.ballot.filter((p) => p.on).length >= 2);

async function save() {
    busy.value = true;
    errors.value = {};
    const body = {
        type: form.type, name: form.name.trim(), code: form.code.trim().toUpperCase(), election_date: form.election_date,
        is_rerun: form.is_rerun, announce: form.announce, lga_ids: form.lga_ids, ward_ids: form.ward_ids,
        parties: form.ballot.filter((p) => p.on).map((p) => ({ id: p.id, candidate: p.candidate?.trim() || null })),
    };
    try {
        let saved = props.race ? await api.patch(`elections/${props.race.id}`, body) : await api.post('elections', body);
        // Then the photos picked or removed, now the race (and its ballot) exists.
        for (const p of form.ballot.filter((x) => x.on)) {
            if (p.file) {
                const fd = new FormData();
                fd.append('photo', p.file);
                saved = await api.post(`elections/${saved.id}/parties/${p.id}/photo`, fd);
            } else if (p.drop) {
                saved = await api.delete(`elections/${saved.id}/parties/${p.id}/photo`);
            }
        }
        emit('saved', saved);
    } catch (e) {
        errors.value = Object.keys(e.errors ?? {}).length ? Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k.split('.')[0], v[0]])) : { form: e.message };
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Modal :open="open" :title="race ? `Change ${race.name}` : 'Add a race'" subtitle="Agents see each race on their unit's ballot and send one result sheet for each." width="max-w-2xl" :busy="busy" @close="emit('close')">
        <form id="race-form" class="flex flex-col gap-6" @submit.prevent="save">
            <!-- 1. The kind of race, by tier of government. -->
            <fieldset>
                <legend class="label">What is the election for?</legend>
                <p v-if="locked" class="mb-2 text-[12.5px] text-ink-2">Results are already in for this race, so its kind can no longer change.</p>
                <div class="flex flex-col gap-3">
                    <div v-for="tier in TIERS" :key="tier.label">
                        <div class="mb-1.5 text-[12px] font-[600] text-ink-2">{{ tier.label }}</div>
                        <div class="grid gap-2 sm:grid-cols-3">
                            <button
                                v-for="t in tier.types"
                                :key="t"
                                type="button"
                                class="flex items-center gap-2.5 rounded-lg border px-2.5 py-2 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-45"
                                :class="form.type === t ? 'border-navy bg-[#eef1f6] ring-1 ring-navy' : 'border-line hover:border-ink-3'"
                                :aria-pressed="form.type === t"
                                :disabled="locked && t !== race.type"
                                @click="pickType(t)"
                            >
                                <RaceTag :race="{ type: t, type_label: types[t]?.label }" size="sm" />
                                <span class="min-w-0">
                                    <span class="block truncate text-[13.5px] font-[620] text-ink">{{ types[t]?.label }}</span>
                                    <span class="block truncate text-[12px] text-ink-2">{{ WHERE[types[t]?.area] }}</span>
                                </span>
                            </button>
                        </div>
                    </div>
                </div>
                <p v-if="errors.type" class="mt-1.5 text-[12.5px] text-bad">{{ errors.type }}</p>
            </fieldset>

            <!-- 2. Where it is fought. -->
            <fieldset v-if="form.type">
                <legend class="label">Where is it fought?</legend>
                <p v-if="area === 'state'" class="note">The whole state. Every polling unit has this race on its ballot.</p>

                <template v-else>
                    <div v-if="area === 'lgas_or_wards'" class="seg mb-2.5">
                        <button type="button" class="seg-btn px-3 py-1.5" :class="!byWard && 'seg-on'" @click="setByWard(false)">Whole LGAs</button>
                        <button type="button" class="seg-btn px-3 py-1.5" :class="byWard && 'seg-on'" @click="setByWard(true)">Particular wards</button>
                    </div>

                    <!-- Whole LGAs: a checklist (or one choice for a chairman). -->
                    <div v-if="!byWard && area !== 'one_ward'">
                        <div class="mb-2 flex items-center gap-2">
                            <label v-if="lgas.length > 8" class="relative flex-1">
                                <span class="sr-only">Find an LGA</span>
                                <Icon name="search" :size="15" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" />
                                <input v-model="lgaQuery" class="field h-9 pl-8" placeholder="Find an LGA" />
                            </label>
                            <button v-if="area !== 'one_lga'" type="button" class="btn-ghost h-9 shrink-0" @click="toggleAllShown">{{ allShown ? 'Clear' : lgaQuery ? 'Tick these' : 'Tick all' }}</button>
                        </div>
                        <div class="grid max-h-[220px] grid-cols-2 gap-1 overflow-y-auto rounded-lg border border-line p-1.5 sm:grid-cols-3">
                            <button
                                v-for="l in shownLgas"
                                :key="l.id"
                                type="button"
                                class="flex items-center gap-2 rounded-md px-2 py-1.5 text-left text-[13.5px] transition-colors"
                                :class="form.lga_ids.includes(l.id) ? 'bg-navy text-white' : 'text-ink hover:bg-mist'"
                                :aria-pressed="form.lga_ids.includes(l.id)"
                                @click="toggleLga(l.id)"
                            >
                                <Icon :name="form.lga_ids.includes(l.id) ? 'check' : 'plus'" :size="14" class="shrink-0 opacity-70" />
                                <span class="truncate">{{ l.name }}</span>
                            </button>
                        </div>
                        <p class="mt-1.5 text-[12.5px] text-ink-2">{{ form.lga_ids.length ? `${form.lga_ids.length} of ${lgas.length} LGAs` : area === 'one_lga' ? 'Choose the LGA.' : 'Tick each LGA in the district.' }}</p>
                    </div>

                    <!-- Particular wards: pick the LGA, then its wards. -->
                    <div v-else class="grid gap-2 sm:grid-cols-[200px_minmax(0,1fr)]">
                        <div class="max-h-[240px] overflow-y-auto rounded-lg border border-line p-1.5">
                            <button
                                v-for="l in lgas"
                                :key="l.id"
                                type="button"
                                class="flex w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-left text-[13.5px] transition-colors"
                                :class="wardLga === l.id ? 'bg-mist font-[620] text-ink' : 'text-ink-2 hover:bg-mist'"
                                @click="wardLga = l.id"
                            >
                                <span class="truncate">{{ l.name }}</span>
                                <span v-if="l.wards.filter((w) => form.ward_ids.includes(w.id)).length" class="fig rounded bg-navy px-1.5 text-[11px] font-[650] text-white">{{ l.wards.filter((w) => form.ward_ids.includes(w.id)).length }}</span>
                            </button>
                        </div>
                        <div class="max-h-[240px] overflow-y-auto rounded-lg border border-line p-1.5">
                            <p v-if="!wardLga" class="px-2 py-6 text-center text-[13px] text-ink-2">Choose an LGA to see its wards.</p>
                            <button
                                v-for="w in wardsOf"
                                :key="w.id"
                                type="button"
                                class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-[13.5px] transition-colors"
                                :class="form.ward_ids.includes(w.id) ? 'bg-navy text-white' : 'text-ink hover:bg-mist'"
                                :aria-pressed="form.ward_ids.includes(w.id)"
                                @click="toggleWard(w.id)"
                            >
                                <Icon :name="form.ward_ids.includes(w.id) ? 'check' : 'plus'" :size="14" class="shrink-0 opacity-70" />
                                <span class="flex-1 truncate">{{ w.name }}</span>
                                <span class="text-[12px] tabular-nums opacity-70">{{ w.units }} units</span>
                            </button>
                        </div>
                        <p class="text-[12.5px] text-ink-2 sm:col-span-2">{{ form.ward_ids.length ? `${form.ward_ids.length} ward${form.ward_ids.length === 1 ? '' : 's'} chosen` : area === 'one_ward' ? 'Choose the ward.' : 'Tick each ward in the constituency.' }}</p>
                    </div>
                </template>
                <p v-if="errors.area" class="mt-1.5 text-[12.5px] text-bad">{{ errors.area }}</p>
            </fieldset>

            <!-- 3. Who is on the ballot. -->
            <fieldset v-if="form.type">
                <legend class="label">Who is on the ballot?</legend>
                <ul class="divide-y divide-rule rounded-lg border border-line">
                    <li v-for="p in form.ballot" :key="p.id" class="flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2">
                        <label class="flex min-w-[150px] flex-1 items-center gap-2.5 text-[14px]">
                            <input v-model="p.on" type="checkbox" class="checkbox" />
                            <span class="fig w-12 font-[700] text-ink">{{ p.code }}</span>
                            <span class="truncate text-ink-2">{{ p.name }}</span>
                            <span v-if="p.is_ours" class="rounded bg-[#eceff3] px-1.5 py-px text-[11.5px] font-[600] text-ink-2">ours</span>
                        </label>
                        <span v-if="p.on" class="flex items-center gap-2">
                            <span class="relative shrink-0">
                                <button
                                    type="button"
                                    class="group grid h-9 w-9 place-items-center overflow-hidden rounded-full focus-visible:ring-2 focus-visible:ring-navy/40 focus-visible:outline-none"
                                    :class="shownPhoto(p) ? '' : 'border border-dashed border-ink-3 text-ink-3 hover:border-ink hover:text-ink'"
                                    :title="shownPhoto(p) ? `Change the ${p.code} candidate's photo` : `Add the ${p.code} candidate's photo`"
                                    :aria-label="shownPhoto(p) ? `Change the ${p.code} candidate's photo` : `Add the ${p.code} candidate's photo`"
                                    @click="pickPhoto(p)"
                                >
                                    <img v-if="shownPhoto(p)" :src="shownPhoto(p)" alt="" class="h-full w-full object-cover" />
                                    <Icon v-else name="image" :size="15" />
                                </button>
                                <button
                                    v-if="shownPhoto(p)"
                                    type="button"
                                    class="absolute -top-1 -right-1 grid h-4 w-4 place-items-center rounded-full bg-ink text-white hover:bg-bad"
                                    :aria-label="`Remove the ${p.code} candidate's photo`"
                                    title="Remove photo"
                                    @click="clearPhoto(p)"
                                ><Icon name="x" :size="9" /></button>
                            </span>
                            <input v-model="p.candidate" class="field h-9 sm:w-[220px]" :placeholder="p.is_ours ? 'Our candidate\'s name' : 'Candidate (optional)'" maxlength="120" :aria-label="`${p.code} candidate`" />
                        </span>
                    </li>
                </ul>
                <p v-if="!ourOn" class="mt-1.5 text-[12.5px] text-action">Our party must be on the ballot.</p>
                <p v-else-if="errors.parties" class="mt-1.5 text-[12.5px] text-bad">{{ errors.parties }}</p>
                <p v-else-if="photoError" class="mt-1.5 text-[12.5px] text-bad">{{ photoError }}</p>
                <p v-else class="mt-1.5 text-[12.5px] text-ink-2">Each race has its own candidates. Names show on the overview and the agent's result sheet; photos on the overview.</p>
                <input ref="photoInput" type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="onPhoto" />
            </fieldset>

            <!-- 4. Its name, code and day. -->
            <fieldset v-if="form.type" class="grid gap-3 sm:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)_minmax(0,1fr)]">
                <legend class="sr-only">Name and date</legend>
                <label class="flex flex-col gap-1.5 text-sm font-[600]">Name
                    <input v-model="form.name" class="field" maxlength="80" required @input="nameTouched = true" />
                    <span v-if="errors.name" class="text-[12.5px] font-normal text-bad">{{ errors.name }}</span>
                </label>
                <label class="flex flex-col gap-1.5 text-sm font-[600]">Short code
                    <input v-model="form.code" class="field fig uppercase" maxlength="12" required @input="codeTouched = true" />
                    <span class="text-[12px] font-normal" :class="errors.code ? 'text-bad' : 'text-ink-2'">{{ errors.code ?? 'Agents text this code' }}</span>
                </label>
                <label class="flex flex-col gap-1.5 text-sm font-[600]">Election day
                    <input v-model="form.election_date" type="date" class="field" required />
                    <span v-if="errors.election_date" class="text-[12.5px] font-normal text-bad">{{ errors.election_date }}</span>
                </label>
            </fieldset>

            <fieldset v-if="form.type" class="flex flex-col gap-2.5">
                <legend class="sr-only">Options</legend>
                <label class="flex items-start gap-2.5 text-[14px]">
                    <input v-model="form.is_rerun" type="checkbox" class="checkbox mt-0.5" />
                    <span><span class="font-[600]">This is a re-run or by-election</span><span class="block text-[12.5px] text-ink-2">Marked as such wherever the race appears.</span></span>
                </label>
                <label class="flex items-start gap-2.5 text-[14px]">
                    <input v-model="form.announce" type="checkbox" class="checkbox mt-0.5" />
                    <span><span class="font-[600]">Speak this race's news aloud</span><span class="block text-[12.5px] text-ink-2">The lead changing hands and the count passing each quarter, on screens with sound on.</span></span>
                </label>
            </fieldset>

            <p v-if="errors.form" role="alert" class="rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad">{{ errors.form }}</p>
        </form>
        <template #footer>
            <button class="btn-ghost" :disabled="busy" @click="emit('close')">Cancel</button>
            <button class="btn-primary" form="race-form" :disabled="!ready || busy">{{ busy ? 'Saving…' : race ? 'Save changes' : 'Add race' }}</button>
        </template>
    </Modal>
</template>
