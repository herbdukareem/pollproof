import { computed, reactive } from 'vue';
import { api } from './api';

/**
 * The campaign's races and which one the command room is looking at.
 *
 * `selected` is a race id, or 'all' for the All races board. Pages that show one race at a time
 * (Units, Collation, IReV…) fall back to the race last looked at, then to the main race, so
 * switching to All races on the Overview never leaves another page with nothing to show.
 * The choice is kept per browser and carried in the address as ?race=, so a link from an alert
 * or a notification opens the race it is about.
 */
const KEY = 'pp:race';
const LAST = 'pp:race-last';
const read = (k) => { try { return localStorage.getItem(k); } catch { return null; } };
const write = (k, v) => { try { localStorage.setItem(k, v); } catch { /* private window: just this visit */ } };

export const races = reactive({
    list: [],
    types: [],
    currentDate: null,
    primaryId: null,
    loaded: false,
    error: null,
    selected: read(KEY) || 'all',
    last: Number(read(LAST)) || null,
});

let pending = null;
export function loadRaces(force = false) {
    if (pending && !force) return pending;
    pending = api.get('elections').then((r) => {
        races.list = r.races ?? [];
        races.types = r.types ?? [];
        races.currentDate = r.current_date;
        races.primaryId = r.primary_id;
        races.loaded = true;
        races.error = null;
        // A race deleted since this browser last looked.
        if (races.selected !== 'all' && !byId(races.selected)) races.selected = 'all';
        if (races.last && !byId(races.last)) races.last = null;
        return races.list;
    }).catch((e) => {
        races.error = e;
        races.loaded = true;
        pending = null;
        return [];
    });
    return pending;
}

export const byId = (id) => races.list.find((r) => String(r.id) === String(id)) ?? null;

export function selectRace(id) {
    const value = id === 'all' || id == null ? 'all' : String(id);
    races.selected = value;
    write(KEY, value);
    if (value !== 'all') {
        races.last = Number(value);
        write(LAST, value);
    }
}

/** Several races to choose between: the switcher only appears then. */
export const hasSeveral = computed(() => races.list.length > 1);

/** The races on the day the command room is working (or the next one), then the rest by date. */
export const today = computed(() => races.list.filter((r) => r.election_date === races.currentDate));

/** The race a one-race page shows. */
export const oneRace = computed(() => byId(races.selected) ?? byId(races.last) ?? byId(races.primaryId) ?? today.value[0] ?? races.list[0] ?? null);

/** The race the switcher names on a page that can show all of them: null means All races. */
export const viewRace = computed(() => (races.selected === 'all' && hasSeveral.value ? null : oneRace.value));

/** Query params for one race, or {} for a page showing every race. */
export const raceParam = (race) => (race ? { election: race.id } : {});

/** Races grouped by election day, in date order, each group in ballot order. */
export const byDate = computed(() => {
    const groups = new Map();
    for (const r of [...races.list].sort((a, b) => a.election_date.localeCompare(b.election_date) || a.sort_order - b.sort_order)) {
        if (!groups.has(r.election_date)) groups.set(r.election_date, []);
        groups.get(r.election_date).push(r);
    }
    return [...groups].map(([date, list]) => ({ date, list, current: date === races.currentDate }));
});

/** A short code for each kind of race, as printed on the race's tag. */
export const TYPE_TAG = { presidential: 'PRES', senate: 'SEN', reps: 'REPS', governorship: 'GOV', assembly: 'HOA', lga_chair: 'CHR', councillor: 'CLR' };

/** What winning the race takes, in one line. */
export function ruleText(race) {
    switch (race?.rule) {
        case 'spread': return 'Most votes, and 25% in two-thirds of the LGAs';
        case 'state_quarter': return 'Most votes nationally, and 25% in two-thirds of the states';
        default: return 'Most votes wins';
    }
}

export const fmtRaceDate = (d) => (d ? new Date(String(d).slice(0, 10) + 'T12:00:00').toLocaleDateString('en-NG', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }) : '');

/** For useResource's `watch`: null until the races are known, then the race a page shows. */
export const oneKey = () => (races.loaded ? oneRace.value?.id ?? 0 : null);
export const viewKey = () => (races.loaded ? viewRace.value?.id ?? 'all' : null);

/** Wait for the races, then the params for one race (or every race when `race` is null). */
export async function withRace(pick) {
    await loadRaces();
    return raceParam(pick());
}
