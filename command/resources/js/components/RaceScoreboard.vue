<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import HelpTip from './HelpTip.vue';
import HoverCard from './HoverCard.vue';
import Icon from './Icon.vue';
import PartyMark from './PartyMark.vue';
import { num, pct } from '../lib/format';
import { partyColour } from '../lib/parties';

/**
 * One race's scoreboard: the verdict in a sentence, each condition the race is won by as its own
 * card (met or not yet), then every party's share of the votes counted so far.
 *
 * Governor: most votes AND 25% in two-thirds of the LGAs. President (in one state): most votes
 * and 25% here. Every other race: most votes, with where we lead shown as a guide.
 * `tally` is GET /tally for the race; `race` is the race from the switcher.
 */
const props = defineProps({ tally: { type: Object, required: true }, race: { type: Object, default: null } });

const TYPE = { presidential: 'President', senate: 'Senate', reps: 'House of Reps', governorship: 'Governor', assembly: 'State Assembly', lga_chair: 'LGA chairman', councillor: 'Councillor' };
const t = computed(() => props.tally);
const candidates = computed(() => t.value.candidates ?? []);
const total = computed(() => candidates.value.reduce((s, c) => s + (c.votes ?? 0), 0));
const ours = computed(() => candidates.value.find((c) => c.is_ours));
const top = computed(() => candidates.value[0]);
const counted = computed(() => total.value > 0);
const share = (c) => (total.value && c ? c.votes / total.value : 0);
const name = (c) => c?.candidate || c?.name || 'another party';

// Condition 1: the most votes.
const rival = computed(() => candidates.value.find((c) => !c.is_ours && (top.value?.is_ours ? true : c.party === top.value?.party)));
const margin = computed(() => (ours.value && rival.value ? ours.value.votes - rival.value.votes : 0));
const mostVotes = computed(() => counted.value && !!ours.value && margin.value > 0);

// Condition 2, by the race's rule.
const rule = computed(() => t.value.rule);
const areaWord = computed(() => (t.value.area_level === 'ward' ? 'ward' : 'LGA'));
const required = computed(() => t.value.lgas_required ?? 0);
const count = computed(() => t.value.lga_count ?? 0);
const met = computed(() => ours.value?.lgas_meeting_threshold ?? 0);
const spreadMet = computed(() => met.value >= required.value && required.value > 0);
const quarterMet = computed(() => share(ours.value) >= 0.25);
const ourCode = computed(() => ours.value?.party);
// For a most-votes race: where we lead, area by area.
const areasLed = computed(() => (t.value.lgas ?? []).filter((l) => {
    const v = l.votes ?? {};
    const mine = v[ourCode.value] ?? 0;
    return mine > 0 && Object.entries(v).every(([p, n]) => p === ourCode.value || n < mine);
}).length);
const areasWithResults = computed(() => (t.value.lgas ?? []).filter((l) => Object.values(l.votes ?? {}).some((n) => n > 0)).length);

const second = computed(() => {
    if (rule.value === 'spread') return { met: spreadMet.value };
    if (rule.value === 'state_quarter') return { met: quarterMet.value };
    return null;
});
const winning = computed(() => mostVotes.value && (second.value ? second.value.met : true));

/** The headline and one short line under it; the rule itself is in the help tip. */
const verdict = computed(() => {
    if (props.race?.declared_at) return { title: 'Declared', text: 'Records are locked.' };
    if (!ours.value) return { title: 'We are not on this ballot', text: 'Add our party under Setup, Races.' };
    if (!counted.value) return { title: 'No results yet', text: 'Fills in as signed results arrive.' };
    const behind = `${name(rival.value)} leads by ${num(Math.abs(margin.value))} votes`;
    const lgas = (n) => `<b>${n} more LGA${n === 1 ? '' : 's'}</b>`;
    if (rule.value === 'spread') {
        const more = Math.max(0, required.value - met.value);
        if (winning.value) return { title: 'Winning', text: 'Both win conditions are met.' };
        if (mostVotes.value) return { title: 'Ahead, but not winning yet', html: `Need 25% in ${lgas(more)}.` };
        if (spreadMet.value) return { title: 'Behind on votes', text: `${behind}.` };
        return { title: 'Behind', html: `${behind}; need 25% in ${lgas(more)}.` };
    }
    if (rule.value === 'state_quarter') {
        if (winning.value) return { title: 'Ahead here', text: 'Most votes, and over 25% here.' };
        if (mostVotes.value) return { title: 'Ahead, but under 25% here', text: `${pct(share(ours.value))} of the vote here.` };
        return { title: 'Behind', text: `${behind}.` };
    }
    if (margin.value > 0) return { title: 'Ahead', text: `${num(margin.value)} votes ahead of ${name(rival.value)}.` };
    if (margin.value === 0) return { title: 'Level', text: `Level with ${name(rival.value)}.` };
    return { title: 'Behind', text: `${behind}.` };
});
const verdictTone = computed(() => (!counted.value || !ours.value || props.race?.declared_at ? 'text-ink' : winning.value ? 'text-good' : 'text-ink'));

const units = computed(() => ({ reported: t.value.units_reported ?? 0, all: t.value.units ?? 0 }));
const reportedShare = computed(() => (units.value.all ? units.value.reported / units.value.all : 0));
const stage = computed(() => (reportedShare.value >= 1 ? 'Every unit is in.' : reportedShare.value >= 0.75 ? 'Most units are in; the picture is settling.' : reportedShare.value >= 0.4 ? 'About half are in; figures can still move.' : reportedShare.value > 0 ? 'Early: figures will move as more units report.' : 'No unit has reported yet.'));

/** The blocks for the 25% rule: reached, still needed up to the line, and the rest. */
const blocks = computed(() => Array.from({ length: count.value }, (_, i) => (i < met.value ? 'reached' : i < required.value ? 'needed' : 'extra')));
const leader = computed(() => Math.max(1, ...candidates.value.map((c) => c.votes)));
const points = computed(() => Math.abs(share(ours.value) - share(rival.value)) * 100);

/**
 * Any party's own "to win" report, for the card over its vote-share row: the same conditions
 * as ours, measured for that party.
 */
function reportFor(c) {
    const others = candidates.value.filter((x) => x.party !== c.party);
    const best = others.reduce((m, x) => (x.votes > (m?.votes ?? -1) ? x : m), null);
    const gap = c.votes - (best?.votes ?? 0);
    const votesMet = counted.value && gap > 0;
    const rows = [{
        label: 'Most votes',
        met: counted.value ? votesMet : null,
        value: !counted.value ? '–' : gap === 0 ? `Level with ${name(best)}` : `${gap > 0 ? '+' : '−'}${num(Math.abs(gap))} vs ${name(best)}`,
    }];
    let ruleMet = true;
    if (rule.value === 'spread') {
        const m = c.lgas_meeting_threshold ?? 0;
        ruleMet = m >= required.value && required.value > 0;
        rows.push({ label: `25% in ${required.value} LGAs`, met: counted.value ? ruleMet : null, value: ruleMet ? `${m} of ${count.value}` : `${m} of ${required.value} · ${required.value - m} to go`, blocks: { met: m } });
    } else if (rule.value === 'state_quarter') {
        ruleMet = share(c) >= 0.25;
        rows.push({ label: '25% in this state', met: counted.value ? ruleMet : null, value: pct(share(c), 1) });
    } else {
        const led = (t.value.lgas ?? []).filter((l) => {
            const v = l.votes ?? {};
            const mine = v[c.party] ?? 0;
            return mine > 0 && Object.entries(v).every(([p, n]) => p === c.party || n < mine);
        }).length;
        rows.push({ label: `${areaWord.value === 'ward' ? 'Wards' : 'LGAs'} led`, met: null, value: `${led} of ${count.value}` });
    }
    const title = !counted.value ? 'No results yet'
        : votesMet && ruleMet ? (second.value ? 'Winning' : 'Leading')
        : votesMet ? 'Ahead, not winning yet'
        : gap === 0 ? 'Level'
        : second.value && ruleMet ? 'Behind on votes' : 'Behind';
    const tone = !counted.value ? 'bg-[#eef0f4] text-ink-2' : votesMet && ruleMet ? 'bg-[#e3f4ea] text-good' : votesMet || gap === 0 ? 'bg-[#fdf3dc] text-[#7a3d0b]' : 'bg-[#fdece3] text-bad';
    return { title, tone, rows };
}

// The report card follows the row under the pointer or keyboard; a tap pins it on phones.
const hovered = ref(null);
let pinned = false;
const showFor = (c, e) => (hovered.value = { c, rect: e.currentTarget.getBoundingClientRect(), report: reportFor(c) });
function leave() {
    if (!pinned) hovered.value = null;
}
function onAway(e) {
    if (!e.target.closest?.('[data-party-row]')) unpin();
}
function unpin() {
    pinned = false;
    hovered.value = null;
    document.removeEventListener('pointerdown', onAway);
}
function tapRow(c, e) {
    if (pinned && hovered.value?.c.party === c.party) return unpin();
    pinned = true;
    showFor(c, e);
    document.addEventListener('pointerdown', onAway);
}
onBeforeUnmount(unpin);

// Full screen, for the command room's big screen. Esc or the button leaves it.
const card = ref(null);
const full = ref(false);
const canFull = typeof document !== 'undefined' && !!(document.fullscreenEnabled || document.webkitFullscreenEnabled);
function toggleFull() {
    if (document.fullscreenElement ?? document.webkitFullscreenElement) (document.exitFullscreen ?? document.webkitExitFullscreen)?.call(document);
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
const areaLabel = computed(() => (props.race?.area && props.race.area !== 'Whole state' ? props.race.area : 'Whole state'));
</script>

<template>
    <section ref="card" class="@container card p-5 sm:p-6" :class="full && 'pp-score-full'" aria-label="Where we stand">
        <div class="pp-score-inner">
        <!-- The race, how far the count has got, and the verdict. -->
        <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
            <p class="flex flex-wrap items-center gap-2.5 text-[13.5px] text-ink-2">
                <span class="rounded-md bg-navy px-2.5 py-1 text-[11.5px] font-[700] tracking-[0.08em] text-white uppercase">{{ TYPE[race?.type] ?? race?.type_label ?? 'Race' }}</span>
                {{ areaLabel }} · {{ count }} {{ areaWord }}{{ count === 1 ? '' : 's' }}
            </p>
            <div class="flex w-full items-center gap-3 @md:w-auto">
            <div class="min-w-0 flex-1 @md:w-48 @md:flex-none">
                <span class="flex items-center justify-between gap-3 text-[13px] text-ink-2">
                    <span class="inline-flex items-center gap-1.5">
                        <RouterLink to="/units?status=reported" class="hover:text-ink hover:underline">Units in</RouterLink>
                        <HelpTip label="About the count" :width="240">{{ num(units.reported) }} of {{ num(units.all) }} polling units have sent a signed result. {{ stage }}</HelpTip>
                    </span>
                    <span class="fig text-[15px] font-[750] text-ink">{{ pct(reportedShare) }}</span>
                </span>
                <span class="mt-1.5 block h-1.5 overflow-hidden rounded-full bg-[#e8ebf0]"><span class="block h-full rounded-full bg-navy transition-[width]" :style="{ width: reportedShare * 100 + '%' }"></span></span>
            </div>
                <button
                    v-if="canFull"
                    type="button"
                    class="btn-ghost h-8 w-8 shrink-0 justify-center px-0"
                    :title="full ? 'Leave full screen (Esc)' : 'Show the scoreboard full screen'"
                    :aria-label="full ? 'Leave full screen' : 'Show the scoreboard full screen'"
                    @click="toggleFull"
                ><Icon :name="full ? 'shrink' : 'expand'" :size="15" /></button>
            </div>
        </div>
        <div class="mt-3 flex items-start gap-4">
            <div class="min-w-0 flex-1">
                <h2 class="text-[26px] leading-[1.1] font-[760] tracking-[-0.02em] @lg:text-[32px]" :class="verdictTone">{{ verdict.title }}</h2>
                <p v-if="verdict.html" class="mt-1.5 text-[15px] leading-snug text-ink-2 [&_b]:font-[700] [&_b]:text-ink" v-html="verdict.html"></p>
                <p v-else class="mt-1.5 text-[15px] leading-snug text-ink-2">{{ verdict.text }}</p>
            </div>
            <!-- Our party's logo, uploaded under Admin, Campaign. -->
            <img
                v-if="ours?.logo"
                :src="ours.logo"
                :alt="`${ours.name} logo`"
                class="relative mt-3 -mb-5 h-20 w-20 shrink-0 rounded-full object-cover @lg:-mb-6 @lg:h-[88px] @lg:w-[88px]"
            />
        </div>

        <!-- Each condition the race is won by. -->
        <template v-if="ours">
            <h3 class="mt-5 mb-2.5 flex items-center gap-2 text-[12px] font-[700] tracking-[0.08em] text-ink-2 uppercase">
                {{ second ? 'To win, we need both' : 'To win' }}
                <HelpTip label="The win rule">
                    <template v-if="rule === 'spread'">A governor needs the most votes <b>and</b> at least 25% of the vote in two-thirds of the LGAs ({{ required }} of {{ count }}).</template>
                    <template v-else-if="rule === 'state_quarter'">A president needs the most votes nationally and 25% in two-thirds of the states. This shows our 25% in this state.</template>
                    <template v-else>The most votes wins this race. There is no spread rule.</template>
                </HelpTip>
            </h3>
            <div class="grid gap-3" :class="second || areasWithResults ? '@lg:grid-cols-2' : ''">
                <!-- The most votes. -->
                <div class="rounded-xl border p-4" :class="!counted ? 'border-line bg-[#fafbfc]' : mostVotes ? 'border-[#bfe3cc] bg-[#f1faf4]' : 'border-[#f4c9b0] bg-[#fdf3ec]'">
                    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5">
                        <p class="text-[14px] font-[650]" :class="!counted ? 'text-ink-2' : mostVotes ? 'text-good' : 'text-bad'">Most votes</p>
                        <span v-if="counted" class="pill" :class="mostVotes ? 'bg-good' : 'bg-action'"><Icon :name="mostVotes ? 'check' : 'alert'" :size="12" />{{ mostVotes ? 'Met' : 'Not yet' }}</span>
                    </div>
                    <template v-if="counted">
                        <p class="fig mt-2.5 text-[28px] leading-none font-[760]" :class="mostVotes ? 'text-good' : 'text-bad'">{{ margin > 0 ? '+' : margin < 0 ? '−' : '' }}{{ num(Math.abs(margin)) }}</p>
                        <p class="mt-2 text-[13px]" :class="mostVotes ? 'text-good' : 'text-bad'">{{ margin === 0 ? 'Level with' : 'vs' }} {{ name(rival) }}<template v-if="margin !== 0"> · {{ points.toFixed(1) }} pts</template></p>
                    </template>
                    <p v-else class="mt-2.5 text-[13px] text-ink-2">With the first results.</p>
                </div>

                <!-- Governor: 25% in two-thirds of the LGAs. -->
                <div v-if="rule === 'spread'" class="rounded-xl border p-4" :class="!counted ? 'border-line bg-[#fafbfc]' : spreadMet ? 'border-[#bfe3cc] bg-[#f1faf4]' : 'border-[#f0d9a3] bg-[#fdf8ea]'">
                    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5">
                        <p class="inline-flex items-center gap-1.5 text-[14px] font-[650]" :class="!counted ? 'text-ink-2' : spreadMet ? 'text-good' : 'text-[#7a3d0b]'">
                            25% in {{ required }} of {{ count }} LGAs
                            <HelpTip label="About the blocks" :width="250">
                                <span class="block">Each block is an LGA.</span>
                                <span class="mt-2 flex flex-col gap-1">
                                    <span class="inline-flex items-center gap-2"><i class="h-3 w-3 rounded-[2px] bg-navy"></i>We have 25% or more</span>
                                    <span class="inline-flex items-center gap-2"><i class="h-3 w-3 rounded-[2px] border-[1.5px] border-dashed border-[#b06a2c]"></i>Still needed</span>
                                    <span class="inline-flex items-center gap-2"><i class="h-3 w-3 rounded-[2px] bg-[#e4e7ec]"></i>Beyond the {{ required }} needed</span>
                                </span>
                                <span class="mt-2 block">The bracket marks the {{ required }} we need.</span>
                            </HelpTip>
                        </p>
                        <span v-if="counted" class="pill" :class="spreadMet ? 'bg-good' : 'bg-[#9a4a10]'"><Icon :name="spreadMet ? 'check' : 'alert'" :size="12" />{{ spreadMet ? 'Met' : 'Not yet' }}</span>
                    </div>
                    <p class="mt-2.5 flex flex-wrap items-baseline gap-x-2" :class="spreadMet ? 'text-good' : 'text-[#7a3d0b]'">
                        <span class="fig text-[28px] leading-none font-[760]">{{ met }} of {{ spreadMet ? count : required }}</span>
                        <span class="text-[13.5px] font-[600]">· {{ spreadMet ? (met > required ? `${met - required} spare` : 'just enough') : `${required - met} to go` }}</span>
                    </p>
                    <div class="mt-3" role="img" :aria-label="`${met} LGAs at 25% or more; ${required} needed of ${count}`">
                        <div class="flex gap-[3px]">
                            <span
                                v-for="(b, i) in blocks"
                                :key="i"
                                class="h-4 min-w-0 flex-1 rounded-[3px]"
                                :class="{
                                    'bg-navy': b === 'reached',
                                    'border-[1.5px] border-dashed border-[#b06a2c] bg-white/60': b === 'needed',
                                    'bg-[#e4e7ec]': b === 'extra',
                                }"
                            ></span>
                        </div>
                        <!-- The bracket under the blocks that are needed. -->
                        <div class="mt-1 h-2 rounded-b-[4px] border-x-2 border-b-2" :class="spreadMet ? 'border-good' : 'border-[#7a3d0b]'" :style="{ width: `calc(${(required / Math.max(1, count)) * 100}% - 3px)` }"></div>
                    </div>
                </div>

                <!-- President: 25% in this state. -->
                <div v-else-if="rule === 'state_quarter'" class="rounded-xl border p-4" :class="!counted ? 'border-line bg-[#fafbfc]' : quarterMet ? 'border-[#bfe3cc] bg-[#f1faf4]' : 'border-[#f0d9a3] bg-[#fdf8ea]'">
                    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5">
                        <p class="text-[14px] font-[650]" :class="quarterMet ? 'text-good' : 'text-[#7a3d0b]'">25% in this state</p>
                        <span v-if="counted" class="pill" :class="quarterMet ? 'bg-good' : 'bg-[#9a4a10]'"><Icon :name="quarterMet ? 'check' : 'alert'" :size="12" />{{ quarterMet ? 'Met' : 'Not yet' }}</span>
                    </div>
                    <p class="fig mt-2.5 text-[28px] leading-none font-[760]" :class="quarterMet ? 'text-good' : 'text-[#7a3d0b]'">{{ pct(share(ours), 1) }}</p>
                    <div class="relative mt-3.5 h-2.5 rounded-full bg-[#e4e7ec]" title="The line marks 25%">
                        <span class="absolute inset-y-0 left-0 rounded-full bg-navy" :style="{ width: Math.min(100, share(ours) * 100) + '%' }"></span>
                        <span class="absolute -top-1.5 h-[22px] w-0.5 bg-ink" style="left: 25%"></span>
                    </div>
                </div>

                <!-- Most votes wins: where we lead, as a guide to where to work. -->
                <div v-else-if="areasWithResults" class="rounded-xl border border-line bg-[#fafbfc] p-4">
                    <p class="inline-flex items-center gap-1.5 text-[14px] font-[650] text-ink-2">
                        {{ areaWord === 'ward' ? 'Wards' : 'LGAs' }} we lead
                        <HelpTip label="About this card" :width="240">Not a win rule: the race goes to the most votes overall. It shows where to put effort. Grey blocks have results but another party leads.</HelpTip>
                    </p>
                    <p class="fig mt-2.5 text-[28px] leading-none font-[760] text-ink">{{ areasLed }} of {{ count }}</p>
                    <div class="mt-3 flex gap-[3px]">
                        <span v-for="i in count" :key="i" class="h-4 min-w-0 flex-1 rounded-[3px]" :class="i <= areasLed ? 'bg-navy' : i <= areasWithResults ? 'bg-[#c9cfd9]' : 'bg-[#e4e7ec]'"></span>
                    </div>
                </div>
            </div>
        </template>

        <!-- Every party's share of the votes counted so far. -->
        <div class="mt-5 mb-2 flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="text-[12px] font-[700] tracking-[0.08em] text-ink-2 uppercase">Vote share</h3>
            <span class="text-[13px] text-ink-2 tabular-nums">{{ num(total) }} votes</span>
        </div>
        <ul class="flex flex-col gap-0.5">
            <li
                v-for="c in candidates"
                :key="c.party"
                data-party-row
                tabindex="0"
                :aria-label="`${c.candidate || c.name}: ${pct(share(c), 1)}, ${num(c.votes)} votes. ${reportFor(c).title}.`"
                class="grid cursor-default grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1.5 rounded-lg px-3 py-2 transition-colors outline-none @lg:grid-cols-[170px_minmax(0,1fr)_56px_72px] @lg:gap-x-4 focus-visible:ring-2 focus-visible:ring-navy/40"
                :class="c.is_ours ? 'bg-[#f1f3f7] hover:bg-[#e9ecf2]' : hovered?.c.party === c.party ? 'bg-[#f6f7f9]' : 'hover:bg-[#f6f7f9]'"
                @mouseenter="showFor(c, $event)"
                @mouseleave="leave"
                @focus="showFor(c, $event)"
                @blur="leave"
                @click="tapRow(c, $event)"
                @keydown.esc="unpin"
            >
                <span class="flex min-w-0 items-center gap-2">
                    <PartyMark :code="c.party" :name="c.name" :logo="c.logo" :colour="partyColour(c.party, candidates)" :size="22" />
                    <span class="truncate text-[14.5px]" :class="c.is_ours ? 'font-[720] text-ink' : 'text-ink'">{{ c.candidate || c.name }}</span>
                    <span v-if="c.is_ours" class="shrink-0 rounded-full bg-navy px-2 py-0.5 text-[10.5px] font-[700] tracking-[0.06em] text-white uppercase">Ours</span>
                </span>
                <span class="fig text-right text-[15px] font-[700] text-ink @lg:order-3">{{ pct(share(c), 1) }}</span>
                <span class="col-span-2 block h-2.5 overflow-hidden rounded-full bg-[#eceef2] @lg:order-2 @lg:col-span-1">
                    <span class="bar-fill block h-full rounded-full" :style="{ width: (c.votes / leader) * 100 + '%', background: partyColour(c.party, candidates) }"></span>
                </span>
                <span class="fig hidden text-right text-[13.5px] text-ink-2 @lg:order-4 @lg:block">{{ num(c.votes) }}</span>
            </li>
        </ul>

        <!-- One party's "to win" report, over its row. -->
        <HoverCard :anchor="hovered?.rect ?? null" :width="290">
            <template v-if="hovered">
                <div class="flex min-w-0 items-center gap-2">
                    <PartyMark :code="hovered.c.party" :name="hovered.c.name" :logo="hovered.c.logo" :colour="partyColour(hovered.c.party, candidates)" :size="24" />
                    <span class="truncate text-[14px] font-[700] text-ink">{{ hovered.c.candidate || hovered.c.name }}</span>
                    <span v-if="hovered.c.is_ours" class="shrink-0 rounded-full bg-navy px-1.5 py-px text-[10px] font-[700] tracking-[0.06em] text-white uppercase">Ours</span>
                </div>
                <div class="mt-1.5 flex items-center justify-between gap-3">
                    <span class="rounded-full px-2 py-0.5 text-[11.5px] font-[700]" :class="hovered.report.tone">{{ hovered.report.title }}</span>
                    <span class="text-[12px] text-ink-3 tabular-nums">{{ pct(share(hovered.c), 1) }} · {{ num(hovered.c.votes) }}</span>
                </div>
                <p class="mt-3 mb-1.5 text-[10.5px] font-[700] tracking-[0.08em] text-ink-3 uppercase">{{ second ? 'To win, both' : 'To win' }}</p>
                <ul class="flex flex-col gap-2">
                    <li v-for="r in hovered.report.rows" :key="r.label">
                        <div class="flex items-center gap-2">
                            <span
                                class="grid h-4 w-4 shrink-0 place-items-center rounded-full text-white"
                                :class="r.met === true ? 'bg-good' : r.met === false ? 'bg-action' : 'bg-[#c9cfd9]'"
                            ><Icon v-if="r.met !== null" :name="r.met ? 'check' : 'x'" :size="10" /></span>
                            <span class="text-[12.5px] text-ink-2">{{ r.label }}</span>
                            <span class="ml-auto text-right text-[12.5px] font-[650] text-ink tabular-nums">{{ r.value }}</span>
                        </div>
                        <div v-if="r.blocks" class="mt-1.5 ml-6 flex gap-px">
                            <span
                                v-for="i in count"
                                :key="i"
                                class="h-1.5 min-w-0 flex-1 rounded-[1px]"
                                :class="i <= r.blocks.met ? 'bg-navy' : i <= required ? 'bg-[#e7c9a4]' : 'bg-[#e4e7ec]'"
                            ></span>
                        </div>
                    </li>
                </ul>
            </template>
        </HoverCard>
        </div>
    </section>
</template>

<style scoped>
/* Full screen: the card fills the screen on white, its content centred and enlarged. */
.pp-score-full {
    display: flex;
    overflow: auto;
    border: 0;
    border-radius: 0;
    background: #fff;
    padding: 4vh 4vw;
}
.pp-score-full .pp-score-inner {
    width: 100%;
    max-width: 1080px;
    margin: auto;
    zoom: 1.25;
}
@media (min-width: 1700px) {
    .pp-score-full .pp-score-inner {
        zoom: 1.5;
    }
}
.pill {
    display: inline-flex;
    flex-shrink: 0;
    align-items: center;
    gap: 4px;
    border-radius: 9999px;
    padding: 2px 10px;
    font-size: 12.5px;
    font-weight: 700;
    color: #fff;
}
</style>
