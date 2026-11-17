<script setup>
import { computed, ref } from 'vue';
import RaceTag from './RaceTag.vue';
import Icon from './Icon.vue';
import { num, pct } from '../lib/format';
import { fmtRaceDate, races, selectRace } from '../lib/races';
import { partyColour } from '../lib/parties';

/**
 * Every race at once. A sentence on how the day is going, then one block per race: whether we
 * are winning by its rule (not just leading on votes), every party's share on one bar with its
 * votes beside it, the margin, the rule that decides the race, and how far the count has got.
 * A block opens its race. `board` is GET /tally/board.
 */
const props = defineProps({ board: { type: Object, required: true } });

/** Where we stand by the rule that wins the race, in words a reader gets at a glance. */
function verdict(r) {
    const ours = r.ours;
    if (r.declared_at) return { key: 'declared', text: 'Declared', tone: 'neutral', icon: 'lock' };
    if (!ours) return { key: 'none', text: 'We are not on this ballot', tone: 'neutral' };
    if (!r.votes_counted) return { key: 'waiting', text: 'No results yet', tone: 'neutral' };
    if (r.standing === 'trailing') return { key: 'behind', text: 'Behind', tone: 'bad', icon: 'alert' };
    if (r.standing === 'tied') return { key: 'level', text: 'Level', tone: 'warn' };
    if (r.rule === 'spread' && r.spread && r.spread.met < r.spread.required) return { key: 'short', text: 'Leading, short on the 25% rule', tone: 'warn', icon: 'alert' };
    if (r.rule === 'state_quarter' && ours.share < 0.25) return { key: 'short', text: 'Leading, under 25% in the state', tone: 'warn', icon: 'alert' };
    return { key: 'winning', text: r.rule === 'plurality' ? 'Leading' : 'Leading, and meets the 25% rule', tone: 'good', icon: 'check' };
}
const TONE = {
    good: 'bg-good-bg text-good',
    warn: 'bg-warn-bg text-warn',
    bad: 'bg-bad-bg text-bad',
    neutral: 'bg-mist text-ink-2',
};

const rows = computed(() => (props.board.races ?? []).map((r) => {
    const rival = r.ours && r.standing !== 'trailing' ? r.candidates.find((c) => !c.is_ours) : r.candidates[0];
    return {
        ...r,
        v: verdict(r),
        rival,
        reported: r.units ? r.units_reported / r.units : 0,
        parties: r.candidates.map((c) => ({ ...c, colour: partyColour(c.party, r.candidates) })),
    };
}));

const groups = computed(() => {
    const map = new Map();
    for (const r of rows.value) {
        if (!map.has(r.election_date)) map.set(r.election_date, []);
        map.get(r.election_date).push(r);
    }
    return [...map].map(([date, list]) => ({ date, list, current: date === races.currentDate }))
        .sort((a, b) => (b.current - a.current) || a.date.localeCompare(b.date));
});

/** The day in one sentence, then a line for each race that needs a look. */
const today = computed(() => rows.value.filter((r) => r.election_date === races.currentDate && !r.declared_at && r.ours));
const headline = computed(() => {
    const day = today.value;
    const n = day.length;
    if (!n) return { big: 'Every race is declared', lines: [] };
    const counted = day.filter((r) => r.votes_counted);
    if (!counted.length) return { big: `${n} race${n === 1 ? '' : 's'} today, no results yet`, lines: [{ tone: 'neutral', text: 'Each race fills in here as agents send their result sheets.' }] };
    const leading = counted.filter((r) => r.standing === 'leading').length;
    const lines = day.map((r) => {
        const v = verdict(r);
        if (v.key === 'behind') return { tone: 'bad', text: `${r.name}: behind ${r.candidates[0].name} by ${num(Math.abs(r.margin))} votes.` };
        if (v.key === 'short' && r.rule === 'spread') return { tone: 'warn', text: `${r.name}: ahead by ${num(r.margin)} votes, but 25% in only ${r.spread.met} of ${r.spread.count} LGAs. ${r.spread.required} are needed to win.` };
        if (v.key === 'short') return { tone: 'warn', text: `${r.name}: ahead by ${num(r.margin)} votes, but under 25% of the vote in this state.` };
        if (v.key === 'level') return { tone: 'warn', text: `${r.name}: level with ${r.rival?.name ?? 'another party'}.` };
        if (v.key === 'waiting') return { tone: 'neutral', text: `${r.name}: no results yet.` };
        return null;
    }).filter(Boolean);
    return { big: `Leading in ${leading} of ${n} race${n === 1 ? '' : 's'}`, lines: lines.length ? lines : [{ tone: 'good', text: 'Ahead in every race with results, and meeting each race\'s rule.' }] };
});
const sheets = computed(() => {
    const day = rows.value.filter((r) => r.election_date === races.currentDate);
    const reported = day.reduce((s, r) => s + r.units_reported, 0);
    const units = day.reduce((s, r) => s + r.units, 0);
    return { reported, units, share: units ? reported / units : 0, waiting: rows.value.reduce((s, r) => s + (r.waiting ?? 0), 0) };
});
const lineTone = { good: 'text-good', warn: 'text-warn', bad: 'text-action', neutral: 'text-ink-2' };
const lineIcon = { good: 'check', warn: 'alert', bad: 'alert', neutral: 'flag' };

/** One tooltip for every bar segment, placed over the segment pointed at. */
const tip = ref(null);
function showTip(e, p) {
    const box = e.currentTarget.getBoundingClientRect();
    tip.value = { x: box.left + box.width / 2, y: box.top, p };
}
</script>

<template>
    <section class="card overflow-hidden" aria-label="Every race">
        <!-- How the day is going. -->
        <div class="grid gap-5 border-b border-rule p-5 sm:p-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
            <div>
                <p class="text-[30px] leading-[1.1] font-[740] tracking-[-0.02em] text-ink sm:text-[34px]">{{ headline.big }}</p>
                <ul class="mt-3 flex max-w-[78ch] flex-col gap-1.5">
                    <li v-for="(l, k) in headline.lines" :key="k" class="flex items-start gap-2 text-[14.5px] leading-snug font-[560]" :class="lineTone[l.tone]">
                        <Icon :name="lineIcon[l.tone]" :size="15" class="mt-[3px] shrink-0" />{{ l.text }}
                    </li>
                </ul>
            </div>
            <dl class="flex gap-8 lg:pt-1">
                <div>
                    <dt class="stat-label">Result sheets in, all races</dt>
                    <dd class="stat-fig mt-1.5">{{ num(sheets.reported) }}<span class="ml-1 text-[14px] font-[500] text-ink-3">of {{ num(sheets.units) }}</span></dd>
                    <dd class="mt-1 text-[12.5px] text-ink-2">{{ pct(sheets.share) }} counted</dd>
                </div>
                <RouterLink to="/verify" class="group">
                    <dt class="stat-label group-hover:text-ink">To verify</dt>
                    <dd class="stat-fig mt-1.5" :class="sheets.waiting ? 'text-action' : ''">{{ num(sheets.waiting) }}</dd>
                    <dd class="mt-1 text-[12.5px] text-ink-2 group-hover:underline">Open the queue</dd>
                </RouterLink>
            </dl>
        </div>

        <div v-for="g in groups" :key="g.date">
            <h2 v-if="groups.length > 1" class="flex items-center gap-2 border-b border-rule bg-[#fafbfc] px-5 py-2 text-[13px] font-[620] text-ink-2">
                {{ fmtRaceDate(g.date) }}
                <span v-if="g.current" class="rounded bg-navy px-1.5 py-px text-[11px] font-[650] text-white">Election day</span>
            </h2>
            <ul>
                <li v-for="r in g.list" :key="r.id" class="border-b border-rule last:border-b-0">
                    <article class="group relative px-5 py-5 transition-colors hover:bg-[#fafbfc] sm:px-6" :class="r.declared_at && 'opacity-75'">
                        <div class="grid gap-x-8 gap-y-4 lg:grid-cols-[minmax(0,1fr)_220px]">
                            <div class="min-w-0">
                                <!-- The race, and the verdict by its rule. -->
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                    <RaceTag :race="r" size="lg" />
                                    <div class="min-w-0">
                                        <h3 class="flex flex-wrap items-baseline gap-x-2">
                                            <button class="text-left text-[16.5px] font-[680] text-ink after:absolute after:inset-0 hover:underline focus-visible:underline" :aria-label="`Open ${r.name}`" @click="selectRace(r.id)">{{ r.name }}</button>
                                            <span v-if="r.is_rerun" class="text-[12px] font-[600] text-warn">Re-run</span>
                                        </h3>
                                        <p class="text-[13px] text-ink-2">{{ r.area }}</p>
                                    </div>
                                    <span class="pill ml-auto" :class="TONE[r.v.tone]"><Icon v-if="r.v.icon" :name="r.v.icon" :size="14" />{{ r.v.text }}</span>
                                </div>

                                <!-- Every party's share on one bar, most votes first. -->
                                <div v-if="r.votes_counted" class="mt-4">
                                    <div class="relative z-[1] flex h-4 gap-[2px] overflow-hidden rounded-[4px]" role="img" :aria-label="r.parties.map((p) => `${p.name} ${pct(p.share)}`).join(', ')">
                                        <span
                                            v-for="p in r.parties.filter((x) => x.votes > 0)"
                                            :key="p.party"
                                            class="bar-fill h-full min-w-[3px]"
                                            :style="{ width: p.share * 100 + '%', background: p.colour }"
                                            @mouseenter="showTip($event, p)"
                                            @mouseleave="tip = null"
                                        ></span>
                                    </div>
                                    <ul class="mt-2.5 flex flex-wrap gap-x-5 gap-y-1.5">
                                        <li v-for="p in r.parties" :key="p.party" class="flex items-center gap-2 text-[13.5px]">
                                            <i class="h-2.5 w-2.5 shrink-0 rounded-[2px]" :style="{ background: p.colour }"></i>
                                            <img v-if="p.photo" :src="p.photo" :alt="p.candidate || p.name" class="h-5 w-5 shrink-0 rounded-full bg-[#eceef2] object-cover" loading="lazy" />
                                            <span :class="p.is_ours ? 'font-[680] text-ink' : 'text-ink-2'">{{ p.candidate || p.name }}</span>
                                            <span v-if="p.is_ours" class="rounded bg-[#eceff3] px-1.5 py-px text-[11px] font-[600] text-ink-2">ours</span>
                                            <span class="fig font-[650] text-ink">{{ pct(p.share) }}</span>
                                            <span class="fig text-[12.5px] text-ink-3">{{ num(p.votes) }}</span>
                                        </li>
                                    </ul>
                                </div>
                                <div v-else class="mt-4">
                                    <div class="h-4 rounded-[4px] border border-dashed border-line bg-[repeating-linear-gradient(135deg,#f4f6f9_0,#f4f6f9_6px,#fff_6px,#fff_12px)]" aria-hidden="true"></div>
                                    <p class="mt-2.5 text-[13px] text-ink-2">On the ballot: {{ r.parties.map((p) => p.name + (p.is_ours ? ' (ours)' : '')).join(', ') }}. Shares appear with the first results.</p>
                                </div>

                                <!-- The margin and the rule, in plain words. -->
                                <dl v-if="r.votes_counted && r.ours" class="mt-3 flex flex-wrap gap-x-6 gap-y-1.5 text-[13.5px]">
                                    <div class="flex gap-1.5">
                                        <dt class="text-ink-2">{{ r.margin >= 0 ? 'Ahead of' : 'Behind' }} {{ r.rival?.name ?? 'the next party' }} by</dt>
                                        <dd class="fig font-[680]" :class="r.margin >= 0 ? 'text-good' : 'text-action'">{{ num(Math.abs(r.margin)) }} votes</dd>
                                    </div>
                                    <div v-if="r.rule === 'spread' && r.spread" class="flex items-center gap-2">
                                        <dt class="text-ink-2">25% in</dt>
                                        <dd class="flex items-center gap-2">
                                            <span class="fig font-[680]" :class="r.spread.met >= r.spread.required ? 'text-good' : 'text-warn'">{{ r.spread.met }} of {{ r.spread.count }} LGAs</span>
                                            <span class="relative block h-1.5 w-28 rounded-full bg-[#e8ebf0]" :title="`${r.spread.required} LGAs needed`">
                                                <span class="absolute inset-y-0 left-0 rounded-full" :class="r.spread.met >= r.spread.required ? 'bg-good' : 'bg-[#d99a00]'" :style="{ width: (r.spread.met / r.spread.count) * 100 + '%' }"></span>
                                                <span class="absolute -top-1 h-3.5 w-0.5 bg-ink" :style="{ left: (r.spread.required / r.spread.count) * 100 + '%' }"></span>
                                            </span>
                                            <span class="text-[12.5px] text-ink-2">{{ r.spread.required }} needed</span>
                                        </dd>
                                    </div>
                                    <div v-else-if="r.rule === 'state_quarter'" class="flex gap-1.5">
                                        <dt class="text-ink-2">Our share in this state</dt>
                                        <dd class="fig font-[680]" :class="r.ours.share >= 0.25 ? 'text-good' : 'text-warn'">{{ pct(r.ours.share) }}, 25% needed</dd>
                                    </div>
                                    <div v-else class="flex gap-1.5 text-ink-2"><dt>Rule:</dt><dd>most votes wins</dd></div>
                                </dl>
                            </div>

                            <!-- How far the count has got. -->
                            <div class="relative z-[1] flex flex-col gap-1 lg:border-l lg:border-rule lg:pl-6">
                                <span class="text-[12.5px] text-ink-2">Units counted</span>
                                <span class="text-[15px] font-[650] text-ink tabular-nums">{{ num(r.units_reported) }}<span class="font-[450] text-ink-3"> of {{ num(r.units) }}</span><span class="ml-2 text-[13px] font-[600] text-ink-2">{{ pct(r.reported) }}</span></span>
                                <span class="mt-0.5 block h-1.5 overflow-hidden rounded-full bg-[#e8ebf0]"><span class="block h-full rounded-full bg-navy" :style="{ width: r.reported * 100 + '%' }"></span></span>
                                <RouterLink v-if="r.waiting" to="/verify" class="mt-1 text-[13px] font-[600] text-action hover:underline" @click="selectRace(r.id)">{{ num(r.waiting) }} waiting to verify</RouterLink>
                                <span v-else-if="r.units_reported" class="mt-1 text-[13px] text-good">All verified</span>
                                <span class="mt-auto hidden items-center gap-1 pt-2 text-[13px] font-[600] text-navy group-hover:flex">Open race <Icon name="chevron" :size="14" /></span>
                            </div>
                        </div>
                    </article>
                </li>
            </ul>
        </div>

        <Teleport to="body">
            <div v-if="tip" class="float pointer-events-none fixed z-[57] -translate-x-1/2 -translate-y-full px-3 py-2 text-[13px]" :style="{ left: tip.x + 'px', top: tip.y - 8 + 'px' }" role="tooltip">
                <div class="flex items-center gap-2 font-[650] text-ink"><i class="h-2.5 w-2.5 rounded-[2px]" :style="{ background: tip.p.colour }"></i>{{ tip.p.name }}<span v-if="tip.p.is_ours" class="text-[11px] font-[600] text-ink-2">ours</span></div>
                <div v-if="tip.p.candidate" class="text-ink-2">{{ tip.p.candidate }}</div>
                <div class="mt-0.5 text-ink-2 tabular-nums">{{ num(tip.p.votes) }} votes, {{ pct(tip.p.share, 1) }}</div>
            </div>
        </Teleport>
    </section>
</template>
