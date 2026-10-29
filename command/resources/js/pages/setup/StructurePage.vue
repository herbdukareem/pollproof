<script setup>
import { computed, reactive, ref, watch } from 'vue';
import PageHeader from '../../components/PageHeader.vue';
import LoadState from '../../components/LoadState.vue';
import Badge from '../../components/Badge.vue';
import CsvUpload from '../../components/CsvUpload.vue';
import FreezeNote from '../../components/FreezeNote.vue';
import { api } from '../../lib/api';
import { useResource } from '../../lib/useResource';
import { hasRole } from '../../lib/session';
import { num } from '../../lib/format';

const wardId = ref(null);
const structure = useResource(() => api.get('setup/structure', { ward_id: wardId.value }));
const s = computed(() => structure.data.value);
const openLga = ref(null);
const editing = ref(null);
const pin = reactive({ latitude: '', longitude: '' });
const rules = reactive({ geofence_radius_m: 100, max_gps_accuracy_m: 30, require_selfie: false });
const isDg = hasRole('dg');
const message = ref('');
const error = ref('');
const busy = ref(false);

watch(s, (v) => {
    if (!v) return;
    Object.assign(rules, { geofence_radius_m: v.campaign.geofence_radius_m, max_gps_accuracy_m: v.campaign.max_gps_accuracy_m, require_selfie: v.campaign.require_selfie });
    if (!wardId.value) {
        // Start on the first ward that has units without GPS, else the first ward.
        const lga = v.lgas.find((l) => l.missing_gps) ?? v.lgas[0];
        const ward = lga?.wards.find((w) => w.missing_gps) ?? lga?.wards[0];
        if (ward) {
            openLga.value = lga.id;
            selectWard(ward.id);
        }
    }
});

const selectedWard = computed(() => s.value?.lgas.flatMap((l) => l.wards.map((w) => ({ ...w, lga: l.name }))).find((w) => w.id === wardId.value));
const steps = computed(() => {
    if (!s.value) return [];
    const t = s.value.totals;
    return [
        { title: 'Import unit list', note: t.units ? `${num(t.units)} units from the official list` : 'Upload the polling-unit CSV', state: t.units ? 'done' : 'now' },
        { title: 'Wards and LGAs', note: t.units ? `${num(t.wards)} wards in ${num(t.lgas)} LGAs` : 'Comes from the unit list', state: t.units ? 'done' : 'todo' },
        { title: 'Add GPS points', note: t.missing_gps ? `${num(t.missing_gps)} ${t.missing_gps === 1 ? 'unit still needs' : 'units still need'} a location` : 'Every unit has a location', state: !t.units ? 'todo' : t.missing_gps ? 'now' : 'done' },
        { title: 'Lock structure', note: s.value.campaign.freeze_at ? `Locks on ${new Date(s.value.campaign.freeze_at).toLocaleDateString('en-NG', { day: 'numeric', month: 'short' })}` : 'Set a freeze date under Campaign', state: s.value.frozen ? 'done' : 'todo' },
    ];
});
const stepStyle = { done: 'bg-good text-white', now: 'bg-action text-white', todo: 'bg-[#eef0f4] text-ink-2' };

function selectWard(id) {
    wardId.value = id;
    editing.value = null;
    structure.load();
}

function edit(u) {
    editing.value = u.id;
    pin.latitude = u.latitude ?? '';
    pin.longitude = u.longitude ?? '';
    error.value = '';
}

async function savePin(u) {
    busy.value = true;
    error.value = '';
    try {
        await api.patch(`polling-units/${u.id}`, { latitude: pin.latitude === '' ? null : Number(pin.latitude), longitude: pin.longitude === '' ? null : Number(pin.longitude) });
        message.value = `Saved the location of ${u.code}.`;
        editing.value = null;
        await structure.load();
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = false;
    }
}

async function saveRules() {
    busy.value = true;
    error.value = '';
    try {
        await api.patch('campaign', { ...rules });
        message.value = 'Check-in rules saved.';
        await structure.load();
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = false;
    }
}

function imported(summary) {
    message.value = `Imported ${num(summary.units)} units in ${num(summary.wards)} wards; ${num(summary.missing_gps)} without GPS.`;
    wardId.value = null;
    structure.load();
}

async function goToMissing(m) {
    const lga = s.value.lgas.find((l) => l.name === m.lga);
    const ward = lga?.wards.find((w) => w.name === m.ward);
    if (ward) {
        openLga.value = lga.id;
        selectWard(ward.id);
    }
}
</script>

<template>
    <PageHeader title="Structure" :subtitle="s ? `${s.campaign.state_name} · ${num(s.totals.lgas)} LGAs · ${num(s.totals.wards)} wards · ${num(s.totals.units)} polling units` : 'LGAs, wards and polling units'" :updated-at="structure.updatedAt.value" :loading="structure.loading.value" @refresh="structure.load">
        <template #actions>
            <CsvUpload endpoint="structure/import" label="Import unit list" columns="lga_code, lga_name, ward_code, ward_name, pu_code, pu_name, latitude, longitude, registered_voters" :disabled="s?.frozen" @done="imported" />
        </template>
    </PageHeader>

    <LoadState :loading="structure.loading.value && !s" :error="structure.error.value" :empty="!s">
        <FreezeNote :freeze-at="s.campaign.freeze_at" :frozen="s.frozen" />
        <p v-if="message" role="status" class="mb-4 rounded-xl bg-good-bg px-4 py-3 text-sm font-semibold text-good">{{ message }}</p>
        <p v-if="error" role="alert" class="mb-4 rounded-xl bg-bad-bg px-4 py-3 text-sm text-bad">{{ error }}</p>

        <ol class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <li v-for="(st, i) in steps" :key="st.title" class="card flex items-center gap-3 p-4" :class="st.state === 'now' ? '!border-action' : ''">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-extrabold" :class="stepStyle[st.state]">{{ i + 1 }}</span>
                <span><span class="block text-sm font-bold">{{ st.title }}</span><span class="text-[12.5px]" :class="st.state === 'now' ? 'text-action' : 'text-ink-2'">{{ st.note }}</span></span>
            </li>
        </ol>

        <p v-if="!s.totals.units" class="card p-8 text-center text-sm text-ink-2">
            No polling units yet. Import the official list as CSV with the columns
            <code class="rounded bg-mist px-1.5 py-0.5 text-xs">lga_code, lga_name, ward_code, ward_name, pu_code, pu_name, latitude, longitude, registered_voters</code>.
        </p>

        <div v-else class="grid items-start gap-5 xl:grid-cols-[260px_1fr_330px]">
            <nav class="card p-2" aria-label="LGAs and wards">
                <div v-for="l in s.lgas" :key="l.id">
                    <button class="flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2.5 text-left text-sm" :class="openLga === l.id ? 'bg-mist font-bold' : 'hover:bg-mist'" :aria-expanded="openLga === l.id" @click="openLga = openLga === l.id ? null : l.id">
                        <span>{{ l.name }}</span>
                        <span class="text-xs" :class="l.missing_gps ? 'font-bold text-action' : 'text-ink-2'">{{ num(l.units) }}{{ l.missing_gps ? ` · ${l.missing_gps} no GPS` : '' }}</span>
                    </button>
                    <div v-if="openLga === l.id" class="mb-1 ml-3 border-l-2 border-line pl-2">
                        <button v-for="w in l.wards" :key="w.id" class="flex w-full justify-between rounded-lg px-2.5 py-1.5 text-left text-[13px]" :class="wardId === w.id ? 'bg-navy font-bold text-white' : 'hover:bg-mist'" @click="selectWard(w.id)">
                            <span>{{ w.name }}</span><span :class="w.missing_gps && wardId !== w.id ? 'text-action' : ''">{{ w.units }}</span>
                        </button>
                    </div>
                </div>
            </nav>

            <section class="card overflow-hidden">
                <div class="px-5 pt-5 pb-3">
                    <h2 class="card-title ">{{ selectedWard ? `${selectedWard.lga} · ${selectedWard.name}` : 'Choose a ward' }}</h2>
                    <p v-if="selectedWard" class="text-[13px] text-ink-2">{{ selectedWard.units }} polling units</p>
                </div>
                <ul v-if="s.units.length">
                    <li v-for="u in s.units" :key="u.id" class="border-t border-[#eef0f3] px-5 py-3">
                        <div class="flex flex-wrap items-center gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-bold">{{ u.code }} <span class="font-medium text-ink-2">· {{ u.name }}</span></div>
                                <div class="text-xs text-ink-2">{{ u.agent ? `Agent ${u.agent}` : 'No agent yet' }}{{ u.registered_voters ? ` · ${num(u.registered_voters)} registered` : '' }}</div>
                            </div>
                            <a v-if="u.latitude != null" :href="`https://www.openstreetmap.org/?mlat=${u.latitude}&mlon=${u.longitude}#map=17/${u.latitude}/${u.longitude}`" target="_blank" rel="noopener" class="text-xs font-semibold text-good underline-offset-2 hover:underline">GPS set</a>
                            <Badge v-else tone="warn">No GPS</Badge>
                            <button v-if="!s.frozen" class="btn-outline h-8 px-3 text-xs" @click="edit(u)">{{ u.latitude != null ? 'Edit' : 'Set location' }}</button>
                        </div>
                        <form v-if="editing === u.id" class="mt-3 grid gap-2 rounded-xl bg-mist p-3 sm:grid-cols-[1fr_1fr_auto_auto]" @submit.prevent="savePin(u)">
                            <label class="sr-only" :for="`lat-${u.id}`">Latitude</label>
                            <input :id="`lat-${u.id}`" v-model="pin.latitude" class="field h-9" inputmode="decimal" placeholder="Latitude, e.g. 9.6139" />
                            <label class="sr-only" :for="`lng-${u.id}`">Longitude</label>
                            <input :id="`lng-${u.id}`" v-model="pin.longitude" class="field h-9" inputmode="decimal" placeholder="Longitude, e.g. 6.5569" />
                            <button class="btn-navy h-9" :disabled="busy">Save</button>
                            <button type="button" class="btn-outline h-9" @click="editing = null">Cancel</button>
                            <p class="text-xs text-ink-2 sm:col-span-4">Best set by a supervisor standing at the unit, from the app. Type coordinates here only if they come from a reliable survey.</p>
                        </form>
                    </li>
                </ul>
            </section>

            <div class="flex flex-col gap-5">
                <section class="card p-5">
                    <h2 class="text-[15px] font-bold">Units missing GPS · {{ num(s.totals.missing_gps) }}</h2>
                    <p class="mt-1 mb-3 text-[13px] text-ink-2">Without a location, check-in there can't be verified. Supervisors can drop a pin from the app while standing at the unit.</p>
                    <p v-if="!s.missing.length" class="text-sm text-good">All units have a location.</p>
                    <ul class="max-h-64 overflow-y-auto">
                        <li v-for="m in s.missing" :key="m.id">
                            <button class="flex w-full justify-between gap-2 border-t border-[#eef0f3] py-2 text-left text-[13px] hover:text-navy" @click="goToMissing(m)"><b>{{ m.code }}</b><span class="truncate text-ink-2">{{ m.ward }} · {{ m.lga }}</span></button>
                        </li>
                    </ul>
                </section>

                <section class="card p-5">
                    <h2 class="text-[15px] font-bold">Check-in rules</h2>
                    <form class="mt-3 flex flex-col gap-3" @submit.prevent="saveRules">
                        <div>
                            <label class="label" for="radius">Unit area radius (metres)</label>
                            <input id="radius" v-model.number="rules.geofence_radius_m" type="number" min="25" max="1000" class="field" :disabled="!isDg" />
                        </div>
                        <div>
                            <label class="label" for="acc">Largest GPS error accepted (metres)</label>
                            <input id="acc" v-model.number="rules.max_gps_accuracy_m" type="number" min="5" max="200" class="field" :disabled="!isDg" />
                        </div>
                        <label class="flex items-center gap-3 text-sm font-semibold"><input v-model="rules.require_selfie" type="checkbox" class="h-5 w-5 accent-[#13213c]" :disabled="!isDg" /> Require a selfie with every check-in</label>
                        <button v-if="isDg" class="btn-navy" :disabled="busy">Save rules</button>
                        <p v-else class="text-xs text-ink-2">Only the DG can change these.</p>
                    </form>
                </section>
            </div>
        </div>
    </LoadState>
</template>
