<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../lib/api';
import { num, pct } from '../lib/format';

/**
 * The campaign's state, LGA by LGA, and ward by ward when an LGA is opened: our share against
 * the 25% line, how many units have reported, or who leads. `loader` is the state's map adapter
 * (see maps/index.js); `tally` is GET /tally. Ward figures add up units by their GPS.
 */
const props = defineProps({
    loader: { type: Function, required: true },
    tally: { type: Object, required: true },
    ourCode: { type: String, default: null },
});

const router = useRouter();
const el = ref(null);
const adapter = shallowRef(null);
const map = shallowRef(null);
const failed = ref(false);
const metric = ref('share');
const points = shallowRef(null);

const NAVY = '#13213c';
const BELOW = '#e8590c';
const NONE = '#e3e6ec';
const RAMP = ['#e9edf3', '#b8c3d4', '#7f90ab', '#475a7d', NAVY];

// Heat colours. Our share runs on two scales split at the 25% line: deep orange at 0% fading
// to pale just under the line, then pale navy at the line deepening to full navy at TOP.
const TOP = 0.6;
const BELOW_STOPS = ['#a83a05', '#e8590c', '#fbcfb2'];
const ABOVE_STOPS = ['#c5cee0', '#7f90ab', '#13213c'];
const hex = (c) => [1, 3, 5].map((i) => parseInt(c.slice(i, i + 2), 16));
const mix = (a, b, t) => '#' + hex(a).map((v, i) => Math.round(v + (hex(b)[i] - v) * t).toString(16).padStart(2, '0')).join('');
/** A colour part-way (0 to 1) along a list of stops. */
function along(stops, t) {
    const x = Math.min(1, Math.max(0, t)) * (stops.length - 1);
    const i = Math.min(stops.length - 2, Math.floor(x));
    return mix(stops[i], stops[i + 1], x - i);
}
const shareHeat = (share, line) => (share < line ? along(BELOW_STOPS, share / line) : along(ABOVE_STOPS, (share - line) / (TOP - line)));
/** The leader's colour, paler for a narrow lead and full for a lead of 30 points or more. */
const leadHeat = (color, margin) => mix('#ffffff', color, 0.3 + 0.7 * Math.min(1, margin / 0.3));
const RIVALS = ['#b5002b', '#d99a00', '#2f7d4a', '#6b4fbb', '#0e7c86', '#8a5a44', '#c2185b'];
const VIEWS = [
    { key: 'share', label: '25% line', sub: 'Our share of valid votes.' },
    { key: 'reporting', label: 'Reporting', sub: 'Polling units that have reported.' },
    { key: 'lead', label: 'Who leads', sub: 'The party with the most votes so far.' },
];

const parties = computed(() => props.tally.candidates ?? []);
const partyColor = computed(() => {
    let i = 0;
    return Object.fromEntries([...parties.value].sort((a, b) => (a.party > b.party ? 1 : -1)).map((c) => [c.party, c.is_ours ? NAVY : RIVALS[i++ % RIVALS.length]]));
});
const partyName = (code) => parties.value.find((c) => c.party === code)?.name ?? code;

/** One area's map entry from its totals: { units, reported, votes: { code: n } }. */
function entry(a, url, linkLabel) {
    const valid = Object.values(a.votes).reduce((s, n) => s + n, 0);
    const share = valid ? (a.votes[props.ourCode] ?? 0) / valid : 0;
    const rep = a.units ? a.reported / a.units : 0;
    const line = `${num(a.reported)} of ${num(a.units)} units reported${valid ? ` · our share ${pct(share)}` : ''}`;
    const link = url ? { url, linkLabel } : {};
    if (metric.value === 'reporting') {
        return { value: Math.round(rep * 1000) / 10, label: pct(rep), color: a.reported ? along(RAMP, rep) : NONE, description: `${line}.`, ...link };
    }
    if (!valid) return { value: null, label: '–', color: NONE, description: `No results yet. ${num(a.units)} units.`, ...link };
    if (metric.value === 'lead') {
        const [[lead, votes], [, next] = [null, 0]] = Object.entries(a.votes).sort((x, y) => y[1] - x[1]);
        const margin = (votes - next) / valid;
        return {
            value: Math.round((votes / valid) * 1000) / 10,
            label: lead,
            color: leadHeat(partyColor.value[lead], margin),
            description: `${partyName(lead)} leads with ${pct(votes / valid)}, ${pct(margin)} ahead of the next party. ${line}.`,
            ...link,
        };
    }
    const t = props.tally.threshold;
    return { value: Math.round(share * 1000) / 10, label: pct(share), color: shareHeat(share, t), description: `${share >= t ? 'Meets' : 'Below'} the ${pct(t)} line. ${line}.`, ...link };
}

const regions = computed(() => Object.fromEntries((props.tally.lgas ?? []).map((l) => [
    l.name,
    entry({ units: l.units, reported: l.units_reported, votes: l.votes ?? {} }, `/units?lga_id=${l.id}`, `Open ${l.name} units`),
])));

/** Units added up by the map's own wards, placed by GPS. */
const wardTotals = computed(() => {
    const p = points.value;
    if (!p || !adapter.value?.locate) return null;
    const codes = p.parties.map((x) => x.code);
    const out = {};
    for (const [lat, lng, reported, ...votes] of p.points) {
        const at = adapter.value.locate(lat, lng);
        if (!at) continue;
        const w = ((out[at.lga] ??= {})[at.ward] ??= { units: 0, reported: 0, votes: Object.fromEntries(codes.map((c) => [c, 0])) });
        w.units++;
        w.reported += reported;
        votes.forEach((n, i) => (w.votes[codes[i]] += n));
    }
    return out;
});
const wards = computed(() => {
    const totals = wardTotals.value;
    if (!totals) return null;
    const lgaId = Object.fromEntries((props.tally.lgas ?? []).map((l) => [l.name.toLowerCase(), l.id]));
    return Object.fromEntries(Object.entries(totals).map(([lga, ws]) => [lga, Object.fromEntries(Object.entries(ws).map(([ward, a]) => {
        const id = lgaId[lga.toLowerCase()];
        return [ward, entry(a, id ? `/units?lga_id=${id}` : null, `Open ${lga} units`)];
    }))]));
});

// The 25% legend: the same two scales as the map, with the line marked.
const linePos = computed(() => `${(props.tally.threshold / TOP) * 100}%`);
const shareScale = computed(() => {
    const at = (props.tally.threshold / TOP) * 100;
    const stops = (list, from, to) => list.map((c, i) => `${c} ${from + ((to - from) * i) / (list.length - 1)}%`);
    return `linear-gradient(to right, ${[...stops(BELOW_STOPS, 0, at), ...stops(ABOVE_STOPS, at, 100)].join(', ')})`;
});

const counts = computed(() => {
    const t = props.tally;
    const lgas = t.lgas ?? [];
    const has = (l) => Object.values(l.votes ?? {}).some((v) => v > 0);
    const leads = {};
    for (const l of lgas.filter(has)) {
        const [code] = Object.entries(l.votes).sort((x, y) => y[1] - x[1])[0];
        leads[code] = (leads[code] ?? 0) + 1;
    }
    return {
        met: lgas.filter((l) => has(l) && (l.share?.[props.ourCode] ?? 0) >= t.threshold).length,
        below: lgas.filter((l) => has(l) && (l.share?.[props.ourCode] ?? 0) < t.threshold).length,
        none: lgas.filter((l) => !has(l)).length,
        leads,
    };
});

async function loadPoints() {
    try {
        points.value = await api.get('tally/points');
    } catch {
        // The LGA view works without them; wards just show no figures.
    }
}

onMounted(async () => {
    try {
        [adapter.value] = await Promise.all([props.loader(), loadPoints()]);
        await nextTick();
        if (!el.value) return;
        map.value = adapter.value.mount(el.value, {
            title: adapter.value.name,
            regions: regions.value,
            wards: wards.value ?? {},
            onOpen: (to) => router.push(to),
        });
        if ((await map.value.ready) === null) failed.value = true;
    } catch {
        failed.value = true;
    }
});
// The tally refreshes every 30 s; fetch the unit points along with it.
watch(() => props.tally, loadPoints);
watch([regions, wards], ([r, w]) => map.value?.update(r, w ?? undefined));
onBeforeUnmount(() => map.value?.destroy());
</script>

<template>
    <section id="map" class="card scroll-mt-4 overflow-hidden p-5 transition">
        <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="card-title">{{ adapter?.name ?? 'State map' }}</h2>
                <p class="card-sub">{{ VIEWS.find((v) => v.key === metric).sub }} Tap an LGA to see its wards.</p>
            </div>
            <div role="tablist" aria-label="What the map shows" class="inline-flex rounded-xl bg-mist p-1 text-[12.5px] font-semibold">
                <button
                    v-for="v in VIEWS"
                    :key="v.key"
                    role="tab"
                    :aria-selected="metric === v.key"
                    class="rounded-lg px-2.5 py-1.5 transition"
                    :class="metric === v.key ? 'bg-white shadow-sm' : 'text-ink-2 hover:text-ink'"
                    @click="metric = v.key"
                >{{ v.label }}</button>
            </div>
        </div>

        <p v-if="failed" class="rounded-xl bg-mist p-6 text-center text-sm text-ink-2">The map couldn't load. The tracker beside it has the same figures.</p>
        <div v-else class="pp-state-map -mx-2 h-[440px]">
            <div ref="el" class="h-full"></div>
        </div>

        <div class="mt-3 text-[12.5px] text-ink-2">
            <template v-if="metric === 'share'">
                <div class="relative max-w-[340px] pt-4">
                    <div class="h-3 rounded-full" :style="{ background: shareScale }"></div>
                    <span class="absolute top-0 -translate-x-1/2 text-[11px] font-bold text-ink" :style="{ left: linePos }">{{ pct(tally.threshold) }}</span>
                    <span class="absolute top-3.5 h-4 w-0.5 -translate-x-1/2 bg-ink" :style="{ left: linePos }"></span>
                    <div class="mt-1 flex justify-between text-[11px]"><span>0%</span><span>{{ pct(TOP) }}+</span></div>
                </div>
                <p class="mt-1.5">{{ counts.met }} LGAs at the line or above, {{ counts.below }} below<template v-if="counts.none">, {{ counts.none }} with no results yet</template>. Darker means further from the line.</p>
            </template>
            <template v-else-if="metric === 'reporting'">
                <div class="max-w-[340px]">
                    <div class="h-3 rounded-full" :style="{ background: `linear-gradient(to right, ${RAMP.join(', ')})` }"></div>
                    <div class="mt-1 flex justify-between text-[11px]"><span>0%</span><span>100% of units reported</span></div>
                </div>
            </template>
            <template v-else>
                <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                    <span v-for="c in parties" :key="c.party" class="inline-flex items-center gap-1.5">
                        <i class="h-3 w-7 rounded-sm" :style="{ background: `linear-gradient(to right, ${leadHeat(partyColor[c.party], 0)}, ${partyColor[c.party]})` }"></i>{{ c.name }}{{ c.is_ours ? ' (ours)' : '' }} · {{ counts.leads[c.party] ?? 0 }}
                    </span>
                </div>
                <p class="mt-1.5">Paler is a narrow lead; full colour is 30 points or more ahead.</p>
            </template>
        </div>
        <p v-if="adapter?.credit" class="mt-2 text-[11px] leading-snug text-ink-3">{{ adapter.credit }}</p>
    </section>
</template>

<style scoped>
/* Fit the map library to the card: our colours, no second frame, room under its toolbar. */
.pp-state-map :deep(.inistmap) {
    --im-ink: #13213c;
    --im-muted: #5b6578;
    --im-line: #e3e6ec;
    --im-accent: #13213c;
    height: 100%;
}
.pp-state-map :deep(.inistmap-shell) {
    height: 100%;
    border: 0;
    box-shadow: none;
    background: transparent;
}
.pp-state-map :deep(.inistmap-stage) {
    background: transparent;
    padding-top: 56px;
}
/* On a phone the toolbar keeps to one row: export is for desks. */
@media (max-width: 639px) {
    .pp-state-map :deep([data-action='export-svg']),
    .pp-state-map :deep([data-action='export-png']),
    .pp-state-map :deep(.inistmap-zoom-level) {
        display: none;
    }
}
/* GRID3's attribution has to stay visible (CC BY-SA); keep it small and out of the way. */
.pp-state-map :deep(.inistmap-source-label),
.pp-state-map :deep(.inistmap-ward-attribution) {
    font-size: 10px;
    padding: 3px 7px;
    opacity: 0.85;
}
</style>
