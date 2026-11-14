<script setup>
import { computed, reactive, ref, watch } from 'vue';
import PageHeader from '../../components/PageHeader.vue';
import LoadState from '../../components/LoadState.vue';
import Badge from '../../components/Badge.vue';
import Icon from '../../components/Icon.vue';
import ImportModal from '../../components/ImportModal.vue';
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
const stepBar = { done: 'bg-good', now: 'bg-orange', todo: 'bg-[#e3e6ec]' };

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

const importing = ref(false);
const unitColumns = [
    ['lga_code', 'INEC LGA code, e.g. 01', true],
    ['lga_name', 'e.g. Agaie', true],
    ['ward_code', 'INEC ward code within the LGA, e.g. 01', true],
    ['ward_name', 'e.g. Baro', true],
    ['pu_code', 'Full INEC unit code, e.g. 01-01-001', true],
    ['pu_name', 'e.g. Baro Health Centre', true],
    ['latitude', 'Decimal degrees, e.g. 8.6012. Leave empty if unknown', false],
    ['longitude', 'Decimal degrees, e.g. 6.4221. Leave empty if unknown', false],
    ['registered_voters', 'From the INEC register, e.g. 500', false],
];

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
    <PageHeader title="Structure" :subtitle="s ? `${s.campaign.state_name}, ${num(s.totals.lgas)} LGAs, ${num(s.totals.wards)} wards, ${num(s.totals.units)} polling units` : 'LGAs, wards and polling units'" :updated-at="structure.updatedAt.value" :loading="structure.loading.value" @refresh="structure.load">
        <template #actions>
            <button class="btn-primary" :disabled="s?.frozen" :title="s?.frozen ? 'Setup is locked' : ''" @click="importing = true"><Icon name="upload" :size="16" /> Import unit list</button>
        </template>
    </PageHeader>

    <LoadState :loading="structure.loading.value && !s" :error="structure.error.value" :empty="!s">
        <FreezeNote :freeze-at="s.campaign.freeze_at" :frozen="s.frozen" />
        <p v-if="message" role="status" class="mb-4 rounded-lg bg-good-bg px-4 py-3 text-sm font-[600] text-good">{{ message }}</p>
        <p v-if="error" role="alert" class="mb-4 rounded-lg bg-bad-bg px-4 py-3 text-sm text-bad">{{ error }}</p>

        <ol class="card mb-4 grid overflow-hidden sm:grid-cols-2 xl:grid-cols-4" aria-label="Setting up the structure">
            <li v-for="(st, i) in steps" :key="st.title" class="relative border-rule px-5 pt-5 pb-4 max-xl:[&:nth-child(n+3)]:border-t max-sm:border-t max-sm:first:border-t-0 sm:[&:nth-child(even)]:border-l xl:[&:not(:first-child)]:border-l" :aria-current="st.state === 'now' ? 'step' : null">
                <span class="absolute inset-x-0 top-0 h-[3px]" :class="stepBar[st.state]"></span>
                <span class="flex items-baseline gap-2">
                    <span class="fig text-[13px] font-[650] text-ink-3">{{ i + 1 }}</span>
                    <span class="text-[14px] font-[650]">{{ st.title }}</span>
                    <Icon v-if="st.state === 'done'" name="check" :size="15" class="ml-auto self-center text-good" />
                </span>
                <span class="mt-0.5 block text-[13px]" :class="st.state === 'now' ? 'text-action' : 'text-ink-2'">{{ st.note }}</span>
            </li>
        </ol>

        <p v-if="!s.totals.units" class="card p-8 text-center text-sm text-ink-2">
            No polling units yet. Import the official list as CSV with the columns
            <code class="rounded bg-mist px-1.5 py-0.5 text-xs">lga_code, lga_name, ward_code, ward_name, pu_code, pu_name, latitude, longitude, registered_voters</code>.
        </p>

        <div v-else class="grid items-start gap-4 xl:grid-cols-[250px_1fr_320px]">
            <nav class="card p-2" aria-label="LGAs and wards">
                <div v-for="l in s.lgas" :key="l.id">
                    <button class="flex w-full items-center justify-between gap-2 rounded-md px-3 py-2 text-left text-sm" :class="openLga === l.id ? 'bg-mist font-[620]' : 'hover:bg-mist'" :aria-expanded="openLga === l.id" @click="openLga = openLga === l.id ? null : l.id">
                        <span>{{ l.name }}</span>
                        <span class="text-[12.5px] tabular-nums" :class="l.missing_gps ? 'font-[600] text-action' : 'text-ink-3'">{{ num(l.units) }}{{ l.missing_gps ? `, ${l.missing_gps} no GPS` : '' }}</span>
                    </button>
                    <div v-if="openLga === l.id" class="mb-1 ml-3 border-l border-line pl-2">
                        <button v-for="w in l.wards" :key="w.id" class="flex w-full justify-between rounded-md px-2.5 py-1.5 text-left text-[13px]" :class="wardId === w.id ? 'bg-navy font-[600] text-white' : 'hover:bg-mist'" @click="selectWard(w.id)">
                            <span>{{ w.name }}</span><span class="tabular-nums" :class="w.missing_gps && wardId !== w.id ? 'text-action' : wardId === w.id ? '' : 'text-ink-3'">{{ w.units }}</span>
                        </button>
                    </div>
                </div>
            </nav>

            <section class="card overflow-hidden">
                <header class="card-head">
                    <h2 class="card-title">{{ selectedWard ? `${selectedWard.name}, ${selectedWard.lga}` : 'Choose a ward' }}</h2>
                    <span v-if="selectedWard" class="text-[12.5px] text-ink-2 tabular-nums">{{ selectedWard.units }} polling units</span>
                </header>
                <ul v-if="s.units.length">
                    <li v-for="u in s.units" :key="u.id" class="border-t border-rule px-5 py-3 first:border-t-0">
                        <div class="flex flex-wrap items-center gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="text-sm"><span class="fig font-[650]">{{ u.code }}</span> <span class="text-ink-2">{{ u.name }}</span></div>
                                <div class="text-[12.5px] text-ink-2">{{ u.agent ? `Agent ${u.agent}` : 'No agent yet' }}{{ u.registered_voters ? `, ${num(u.registered_voters)} registered` : '' }}</div>
                            </div>
                            <a v-if="u.latitude != null" :href="`https://www.openstreetmap.org/?mlat=${u.latitude}&mlon=${u.longitude}#map=17/${u.latitude}/${u.longitude}`" target="_blank" rel="noopener" class="text-[12.5px] font-[600] text-good underline-offset-2 hover:underline">GPS set</a>
                            <Badge v-else tone="warn">No GPS</Badge>
                            <button v-if="!s.frozen" class="btn-ghost h-8 px-3 text-[13px]" @click="edit(u)">{{ u.latitude != null ? 'Edit' : 'Set location' }}</button>
                        </div>
                        <form v-if="editing === u.id" class="mt-3 grid gap-2 rounded-lg bg-mist p-3 sm:grid-cols-[1fr_1fr_auto_auto]" @submit.prevent="savePin(u)">
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

            <div class="flex flex-col gap-4">
                <section class="card p-5">
                    <h2 class="card-title-ruled flex items-baseline justify-between gap-2">Units missing GPS <span class="fig text-[17px] font-[700]" :class="s.totals.missing_gps ? 'text-action' : 'text-ink-3'">{{ num(s.totals.missing_gps) }}</span></h2>
                    <p class="mb-3 text-[13px] text-ink-2">Without a location, check-in there can't be verified. Supervisors can drop a pin from the app while standing at the unit.</p>
                    <p v-if="!s.missing.length" class="text-sm text-good">All units have a location.</p>
                    <ul class="max-h-64 overflow-y-auto">
                        <li v-for="m in s.missing" :key="m.id">
                            <button class="flex w-full justify-between gap-2 border-t border-rule py-2 text-left text-[13px] hover:text-navy" @click="goToMissing(m)"><b class="fig font-[650]">{{ m.code }}</b><span class="truncate text-ink-2">{{ m.ward }}, {{ m.lga }}</span></button>
                        </li>
                    </ul>
                </section>

                <section class="card p-5">
                    <h2 class="card-title-ruled">Check-in rules</h2>
                    <form class="flex flex-col gap-3" @submit.prevent="saveRules">
                        <div>
                            <label class="label" for="radius">Unit area radius <span class="font-[400] text-ink-2">(metres)</span></label>
                            <input id="radius" v-model.number="rules.geofence_radius_m" type="number" min="25" max="1000" class="field" :disabled="!isDg" />
                        </div>
                        <div>
                            <label class="label" for="acc">Largest GPS error accepted <span class="font-[400] text-ink-2">(metres)</span></label>
                            <input id="acc" v-model.number="rules.max_gps_accuracy_m" type="number" min="5" max="200" class="field" :disabled="!isDg" />
                        </div>
                        <label class="flex items-center gap-3 text-sm font-[600]"><input v-model="rules.require_selfie" type="checkbox" class="checkbox" :disabled="!isDg" /> Require a selfie with every check-in</label>
                        <button v-if="isDg" class="btn-navy" :disabled="busy">Save rules</button>
                        <p v-else class="text-xs text-ink-2">Only the DG can change these.</p>
                    </form>
                </section>
            </div>
        </div>
    </LoadState>

    <ImportModal
        :open="importing"
        title="Import the polling-unit list"
        subtitle="One row per polling unit, with its ward and LGA."
        endpoint="structure/import"
        template="setup/templates/units"
        template-name="polling-unit-template.csv"
        :template-note="s?.totals.units ? `It lists all ${num(s.totals.units)} units you have now, with their GPS. Correct or add rows and upload it again.` : 'The columns to fill, ready to open in Excel or Google Sheets. Add one row per polling unit.'"
        :columns="unitColumns"
        :tips="['Units are matched by pu_code, so uploading again updates them instead of adding them twice.', 'An empty latitude, longitude or voter count keeps what the unit already has.']"
        :counts="[['LGAs', 'lgas'], ['Wards', 'wards'], ['Units', 'units'], ['Without GPS', 'missing_gps']]"
        @close="importing = false"
        @done="imported"
    />
</template>
