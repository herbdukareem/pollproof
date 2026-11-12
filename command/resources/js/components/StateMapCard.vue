<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { useRouter } from 'vue-router';
import Icon from './Icon.vue';
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

/**
 * One area's map entry from its totals: { units, reported, registered, accredited, cast,
 * rejected, votes: { code: n } }. `stats` rides along for the popover.
 */
function entry(a, url, linkLabel) {
    const valid = Object.values(a.votes).reduce((s, n) => s + n, 0);
    const share = valid ? (a.votes[props.ourCode] ?? 0) / valid : 0;
    const rep = a.units ? a.reported / a.units : 0;
    const line = `${num(a.reported)} of ${num(a.units)} units reported${valid ? ` · our share ${pct(share)}` : ''}`;
    const link = { ...(url ? { url, linkLabel } : {}), stats: { ...a, valid, share } };
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
    entry({ units: l.units, reported: l.units_reported, registered: l.registered ?? 0, accredited: l.accredited ?? 0, cast: l.cast ?? 0, rejected: l.rejected ?? 0, votes: l.votes ?? {} }, `/units?lga_id=${l.id}`, `Open ${l.name} units`),
])));

/** Units added up by the map's own wards, placed by GPS. */
const wardTotals = computed(() => {
    const p = points.value;
    if (!p || !adapter.value?.locate) return null;
    const codes = p.parties.map((x) => x.code);
    const head = p.fields?.length ?? 3; // the votes follow the named fields
    const out = {};
    for (const row of p.points) {
        const [lat, lng, reported, registered = 0, accredited = 0, cast = 0, rejected = 0] = row;
        const at = adapter.value.locate(lat, lng);
        if (!at) continue;
        const w = ((out[at.lga] ??= {})[at.ward] ??= { units: 0, reported: 0, registered: 0, accredited: 0, cast: 0, rejected: 0, votes: Object.fromEntries(codes.map((c) => [c, 0])) });
        w.units++;
        w.reported += reported;
        w.registered += registered;
        w.accredited += accredited;
        w.cast += cast;
        w.rejected += rejected;
        row.slice(head).forEach((n, i) => (w.votes[codes[i]] += n));
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

/**
 * The popup for an area: who it is, the voter figures, our share against the line, and the
 * votes by party. Built from DOM nodes with text only, so names are never read as HTML.
 */
function h(tag, cls, ...kids) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    for (const k of kids.flat()) if (k != null && k !== false) n.append(k instanceof Node ? k : String(k));
    return n;
}
function popover(item, where) {
    const a = item.stats;
    if (!a) return null;
    const t = props.tally.threshold;
    const row = (label, value, cls = '') => h('div', 'pp-pop-row', h('span', 'pp-pop-key', label), h('span', `pp-pop-val ${cls}`, value));
    const sorted = Object.entries(a.votes).sort((x, y) => y[1] - x[1]);
    const [lead, leadVotes] = sorted[0] ?? [];
    const tie = sorted.length > 1 && sorted[1][1] === leadVotes;
    const swatch = (code) => { const i = h('i', 'pp-pop-dot'); i.style.background = partyColor.value[code]; return i; };

    return h('div', 'pp-pop',
        h('p', 'pp-pop-sub', where.level === 'wards' ? `Ward in ${where.lga}` : 'LGA', ` · ${num(a.reported)} of ${num(a.units)} units reported`),
        h('div', 'pp-pop-total',
            h('span', null, 'Total votes cast'),
            h('strong', null, a.reported ? num(a.cast) : '–'),
            a.reported && a.units > a.reported ? h('small', null, `so far, from ${num(a.reported)} units`) : null,
        ),
        h('div', 'pp-pop-rows',
            row('Registered voters', a.registered ? num(a.registered) : '–'),
            row('Accredited', a.reported ? num(a.accredited) : '–'),
            row('Valid votes', a.valid ? num(a.valid) : '–'),
            a.rejected ? row('Rejected', num(a.rejected)) : null,
        ),
        a.valid
            ? [
                h('div', 'pp-pop-rows pp-pop-sep',
                    row('Our share', h('span', null, pct(a.share), ' ', h('em', a.share >= t ? 'pp-pop-ok' : 'pp-pop-bad', a.share >= t ? `≥ ${pct(t)}` : `< ${pct(t)}`))),
                    (() => {
                        const r = row('Leading party', tie ? 'Tied' : lead, 'pp-pop-lead');
                        if (!tie) r.lastChild.style.color = partyColor.value[lead];
                        return r;
                    })(),
                ),
                h('p', 'pp-pop-head', 'Votes by party'),
                h('div', 'pp-pop-rows', sorted.map(([code, n]) => h('div', 'pp-pop-row',
                    h('span', 'pp-pop-key pp-pop-party', swatch(code), code, code === props.ourCode ? h('small', null, 'ours') : null),
                    h('span', 'pp-pop-val', num(n), h('small', null, pct(n / a.valid))),
                ))),
            ]
            : h('p', 'pp-pop-empty', a.units ? 'No results yet.' : 'No polling units are placed here.'),
        where.openWards
            ? (() => {
                const b = h('button', 'pp-pop-wards', 'Show wards');
                b.type = 'button';
                b.title = 'Or double-click the LGA';
                b.addEventListener('click', (e) => {
                    e.stopPropagation();
                    where.openWards();
                });
                return b;
            })()
            : null,
    );
}

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
            popover,
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

// Full screen for the whole card: views, map and legend. Esc or the button leaves it.
const card = ref(null);
const full = ref(false);
const canFull = typeof document !== 'undefined' && !!(document.fullscreenEnabled || document.webkitFullscreenEnabled);
function toggleFull() {
    const fsEl = document.fullscreenElement ?? document.webkitFullscreenElement;
    if (fsEl) (document.exitFullscreen ?? document.webkitExitFullscreen)?.call(document);
    else (card.value.requestFullscreen ?? card.value.webkitRequestFullscreen)?.call(card.value);
}
const onFullChange = () => (full.value = (document.fullscreenElement ?? document.webkitFullscreenElement) === card.value);
onMounted(() => {
    document.addEventListener('fullscreenchange', onFullChange);
    document.addEventListener('webkitfullscreenchange', onFullChange);
});
onBeforeUnmount(() => {
    document.removeEventListener('fullscreenchange', onFullChange);
    document.removeEventListener('webkitfullscreenchange', onFullChange);
});
</script>

<template>
    <section id="map" ref="card" class="card scroll-mt-4 overflow-hidden px-5 pb-4" :class="full && 'pp-map-full'">
        <header class="-mx-5 mb-3 flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-b border-rule px-5 pt-4 pb-3">
            <h2 class="card-title">{{ adapter?.name ?? 'State map' }}</h2>
            <div class="flex items-center gap-2">
                <div role="tablist" aria-label="What the map shows" class="seg">
                    <button
                        v-for="v in VIEWS"
                        :key="v.key"
                        role="tab"
                        :aria-selected="metric === v.key"
                        class="seg-btn"
                        :class="metric === v.key && 'seg-on'"
                        @click="metric = v.key"
                    >{{ v.label }}</button>
                </div>
                <button
                    v-if="canFull"
                    type="button"
                    class="btn-ghost h-8 shrink-0 gap-1.5 px-2.5 text-[13px]"
                    :title="full ? 'Leave full screen (Esc)' : 'Show the map full screen'"
                    @click="toggleFull"
                >
                    <Icon :name="full ? 'shrink' : 'expand'" :size="15" />{{ full ? 'Exit full screen' : 'Full screen' }}
                </button>
            </div>
        </header>
        <p class="text-[13px] text-ink-2">{{ VIEWS.find((v) => v.key === metric).sub }} Click an area for its figures; double-click an LGA for its wards.</p>

        <p v-if="failed" class="rounded-lg bg-mist p-6 text-center text-sm text-ink-2">The map couldn't load. The tracker beside it has the same figures.</p>
        <div v-else class="pp-state-map -mx-2" :class="full ? 'min-h-0 flex-1' : 'h-[520px]'">
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
    --im-popover-width: 290px;
    --im-popover-radius: 16px;
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
/* Full screen: the card fills the screen and the map takes the space left by the header and key. */
.pp-map-full {
    display: flex;
    flex-direction: column;
    width: 100vw;
    height: 100vh;
    border-radius: 0;
    padding: 24px 28px;
    overflow: auto;
    background: #fff;
}
/* The map's toolbar, light to match the panels around it. */
.pp-state-map :deep(.inistmap-tools-overlay) {
    background: rgba(255, 255, 255, 0.94);
    border: 1px solid #dfe3ea;
    box-shadow: none;
    border-radius: 8px;
    padding: 3px;
    gap: 2px;
}
.pp-state-map :deep(.inistmap-tools-overlay button) {
    background: transparent;
    border: 0;
    color: #4b5567;
    border-radius: 6px;
    font-weight: 560;
    box-shadow: none;
}
.pp-state-map :deep(.inistmap-tools-overlay button:hover:not(:disabled)) {
    background: #f1f3f6;
    color: #13213c;
}
.pp-state-map :deep(.inistmap-tools-overlay .inistmap-zoom-level) {
    color: #8790a0;
}
/* In full screen the map fits the space both ways instead of following the width. */
.pp-map-full .pp-state-map :deep(.inistmap-svg) {
    width: 100%;
    height: 100%;
    max-height: none;
}
/* The card's button covers full screen (with the views and key), so drop the map-only one. */
.pp-state-map :deep([data-action='fullscreen']) {
    display: none;
}
/* On a phone the toolbar keeps to one row: export is for desks. */
@media (max-width: 639px) {
    .pp-state-map :deep([data-action='export-svg']),
    .pp-state-map :deep([data-action='export-png']),
    .pp-state-map :deep(.inistmap-zoom-level) {
        display: none;
    }
}
/* Let the map fill the card's height. */
.pp-state-map :deep(.inistmap-content),
.pp-state-map :deep(.inistmap-stage) {
    height: 100%;
}
/* GRID3's attribution has to stay visible (CC BY-SA); keep it small and out of the way. */
.pp-state-map :deep(.inistmap-source-label),
.pp-state-map :deep(.inistmap-ward-attribution) {
    font-size: 10px;
    padding: 3px 7px;
    opacity: 0.85;
}
</style>

<style>
/*
 * The area popup. The map library floats it on <body>, outside this component, so these
 * rules are global and keyed by the class it is given (popoverClassName: 'pp-map-popover').
 */
.pp-map-popover {
    padding: 14px 16px;
}
.pp-map-popover .inistmap-popover-title {
    font-size: 15px;
    font-weight: 800;
    letter-spacing: 0.02em;
    text-transform: uppercase;
}
.pp-map-popover .inistmap-popover-kicker,
.pp-map-popover .inistmap-popover-value {
    display: none; /* our rows carry the figures */
}
.pp-map-popover .pp-pop-sub {
    margin: 0 0 7px;
    font-size: 11.5px;
    color: #5b6578;
}
.pp-map-popover .pp-pop-total {
    display: grid;
    margin: 0 0 9px;
    padding: 9px 12px;
    border-radius: 12px;
    background: #f1f3f7;
}
.pp-map-popover .pp-pop-total span {
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: #5b6578;
}
.pp-map-popover .pp-pop-total strong {
    font-size: 26px;
    font-weight: 800;
    line-height: 1.15;
    letter-spacing: -0.02em;
    color: #13213c;
    font-variant-numeric: tabular-nums;
}
.pp-map-popover .pp-pop-total small {
    font-size: 11px;
    color: #5b6578;
}
.pp-map-popover .pp-pop-rows {
    display: grid;
    gap: 3px;
}
.pp-map-popover .pp-pop-sep {
    margin-top: 7px;
    padding-top: 7px;
    border-top: 1px solid #e3e6ec;
}
.pp-map-popover .pp-pop-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    font-size: 12.5px;
}
.pp-map-popover .pp-pop-key {
    color: #5b6578;
}
.pp-map-popover .pp-pop-val {
    font-weight: 800;
    color: #13213c;
    font-variant-numeric: tabular-nums;
    text-align: right;
}
.pp-map-popover .pp-pop-val small {
    display: inline-block;
    min-width: 38px;
    margin-left: 6px;
    font-size: 11px;
    font-weight: 600;
    color: #8a93a3;
}
.pp-map-popover .pp-pop-val em {
    margin-left: 4px;
    padding: 1px 6px;
    border-radius: 999px;
    font-size: 10.5px;
    font-style: normal;
    font-weight: 700;
}
.pp-map-popover .pp-pop-ok {
    background: #e3f4ea;
    color: #1d7a45;
}
.pp-map-popover .pp-pop-bad {
    background: #fde9dd;
    color: #b8470a;
}
.pp-map-popover .pp-pop-lead {
    font-size: 14px;
}
.pp-map-popover .pp-pop-head {
    margin: 8px 0 4px;
    padding-top: 7px;
    border-top: 1px solid #e3e6ec;
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: #8a93a3;
}
.pp-map-popover .pp-pop-party {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-weight: 600;
    color: #13213c;
}
.pp-map-popover .pp-pop-party small {
    padding: 0 5px;
    border-radius: 999px;
    background: #eef1f6;
    font-size: 10px;
    font-weight: 700;
    color: #5b6578;
}
.pp-map-popover .pp-pop-dot {
    width: 9px;
    height: 9px;
    border-radius: 3px;
}
.pp-map-popover .pp-pop-wards {
    width: 100%;
    margin-top: 10px;
    padding: 8px 12px;
    border-radius: 10px;
    background: #13213c;
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
}
.pp-map-popover .pp-pop-wards:hover {
    background: #24345a;
}
.pp-map-popover .pp-pop-empty {
    margin-top: 8px;
    font-size: 13px;
    color: #5b6578;
}
.pp-map-popover .inistmap-detail-link {
    display: block;
    margin-top: 8px;
    padding-top: 7px;
    border-top: 1px solid #e3e6ec;
    font-size: 13px;
    font-weight: 700;
    color: #13213c;
}
</style>
