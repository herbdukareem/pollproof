import { computed } from 'vue';
import { races } from './races';

/**
 * One colour per party, the same on every chart and every race: ours in the campaign's navy,
 * the others from a fixed, colour-blind-checked order (blue, aqua, yellow, magenta, violet,
 * green, red), assigned by party code so a party keeps its colour wherever it appears.
 * Past seven rivals, the rest share a neutral grey; their names are always printed beside them.
 */
export const OURS = '#13213c';
export const RIVALS = ['#2a78d6', '#1baf7a', '#eda100', '#e87ba4', '#6250d6', '#008300', '#e34948'];
export const OTHER = '#a3abb8';

/** code => colour, for a list of { code|party, is_ours }. */
export function colourMap(list) {
    const seen = new Map();
    for (const p of list) seen.set(p.code ?? p.party, !!p.is_ours);
    let i = 0;
    return Object.fromEntries([...seen].sort(([a], [b]) => a.localeCompare(b)).map(([code, ours]) => [code, ours ? OURS : RIVALS[i++] ?? OTHER]));
}

/** Every party on any of the campaign's ballots, so colours don't change between races. */
const campaignColours = computed(() => colourMap(races.list.flatMap((r) => r.parties ?? [])));

export function partyColour(code, fallbackList = []) {
    return campaignColours.value[code] ?? colourMap(fallbackList)[code] ?? OTHER;
}
