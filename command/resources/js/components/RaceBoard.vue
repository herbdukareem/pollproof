<script setup>
import { computed } from 'vue';
import RaceTag from './RaceTag.vue';
import Icon from './Icon.vue';
import { num, pct } from '../lib/format';
import { fmtRaceDate, races, selectRace } from '../lib/races';

/**
 * Every race at once: a sentence saying how the day is going, then one row per race with how we
 * stand, our share against the nearest rival, and how far the count has got. A row opens its race.
 * `board` is GET /tally/board.
 */
const props = defineProps({ board: { type: Object, required: true } });

const rows = computed(() => (props.board.races ?? []).map((r) => {
    const ours = r.ours;
    const rival = r.candidates.find((c) => !c.is_ours) ?? null;
    const counted = r.votes_counted > 0;
    let status;
    if (r.declared_at) status = { text: 'Declared', tone: 'text-ink-2' };
    else if (!ours) status = { text: 'We are not on this ballot', tone: 'text-ink-3' };
    else if (!counted) status = { text: 'No results yet', tone: 'text-ink-3' };
    else if (r.standing === 'leading') status = { text: `Ahead by ${num(r.margin)}`, tone: 'text-good' };
    else if (r.standing === 'trailing') status = { text: `Behind by ${num(Math.abs(r.margin))}`, tone: 'text-action' };
    else status = { text: 'Level', tone: 'text-warn' };
    // The line that decides the race beyond the most votes, where there is one.
    let rule = null;
    if (counted && ours && r.rule === 'spread' && r.spread) {
        rule = { met: r.spread.met >= r.spread.required, text: `25% in ${r.spread.met} of ${r.spread.count} LGAs, ${r.spread.required} needed` };
    } else if (counted && ours && r.rule === 'state_quarter') {
        rule = { met: ours.share >= 0.25, text: ours.share >= 0.25 ? '25% in this state' : 'Under 25% in this state' };
    }
    return { ...r, rival, counted, status, rule, reported: r.units ? r.units_reported / r.units : 0 };
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

/** The day in one sentence, and the race that most needs attention under it. */
const headline = computed(() => {
    const day = rows.value.filter((r) => r.election_date === races.currentDate && !r.declared_at && r.ours);
    const counted = day.filter((r) => r.counted);
    if (!day.length) return { big: 'Every race is declared', small: 'The results are under Close-out.' };
    if (!counted.length) return { big: `${day.length} race${day.length === 1 ? '' : 's'} today`, small: 'No results are in yet. Each race fills in here as agents send their sheets.' };
    const ahead = counted.filter((r) => r.standing === 'leading').length;
    const behind = counted.filter((r) => r.standing === 'trailing').sort((a, b) => a.margin - b.margin);
    const big = `Ahead in ${ahead} of ${day.length} race${day.length === 1 ? '' : 's'}`;
    let small;
    if (behind.length === 1) small = `Behind in ${behind[0].name} by ${num(Math.abs(behind[0].margin))} votes.`;
    else if (behind.length) small = `Behind in ${behind.map((r) => r.name).slice(0, -1).join(', ')} and ${behind.at(-1).name}. Furthest behind in ${behind[0].name}, by ${num(Math.abs(behind[0].margin))}.`;
    else small = counted.length < day.length ? `${day.length - counted.length} still have no results.` : 'Not behind anywhere.';
    return { big, small, tone: behind.length ? 'text-action' : 'text-good' };
});
const toVerify = computed(() => rows.value.reduce((s, r) => s + (r.waiting ?? 0), 0));
const unitsIn = computed(() => {
    const day = rows.value.filter((r) => r.election_date === races.currentDate);
    return { reported: day.reduce((s, r) => s + r.units_reported, 0), units: day.reduce((s, r) => s + r.units, 0) };
});

/** Our share and the rival's from either end of one bar; the gap between is everyone else. */
const share = (c) => c?.share ?? 0;
</script>

<template>
    <section class="card overflow-hidden" aria-label="Every race">
        <div class="grid gap-5 border-b border-rule p-5 sm:p-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
            <div>
                <p class="text-[30px] leading-[1.1] font-[740] tracking-[-0.02em] text-ink sm:text-[36px]">{{ headline.big }}</p>
                <p class="mt-2 max-w-[62ch] text-[15px] leading-snug font-[560]" :class="headline.tone ?? 'text-ink-2'">{{ headline.small }}</p>
            </div>
            <dl class="flex gap-8">
                <div>
                    <dt class="stat-label">Result sheets in</dt>
                    <dd class="stat-fig mt-1.5">{{ num(unitsIn.reported) }}<span class="ml-1 text-[14px] font-[500] text-ink-3">of {{ num(unitsIn.units) }}</span></dd>
                </div>
                <RouterLink to="/verify" class="group">
                    <dt class="stat-label group-hover:text-ink">To verify</dt>
                    <dd class="stat-fig mt-1.5" :class="toVerify ? 'text-action' : ''">{{ num(toVerify) }}</dd>
                </RouterLink>
            </dl>
        </div>

        <div v-for="g in groups" :key="g.date">
            <h2 v-if="groups.length > 1" class="flex items-center gap-2 border-b border-rule bg-[#fafbfc] px-5 py-2 text-[13px] font-[620] text-ink-2">
                {{ fmtRaceDate(g.date) }}
                <span v-if="g.current" class="rounded bg-navy px-1.5 py-px text-[11px] font-[650] text-white">Election day</span>
            </h2>
            <!-- Column names, for the wide layout only. -->
            <div class="hidden grid-cols-[minmax(0,1.5fr)_minmax(0,1.05fr)_minmax(0,1.4fr)_minmax(0,0.8fr)_20px] gap-5 border-b border-rule px-5 py-2 text-[12.5px] text-ink-2 lg:grid" aria-hidden="true">
                <span>Race</span><span>Where we stand</span><span>Us against the nearest rival</span><span>Units in</span><span></span>
            </div>
            <ul>
                <li v-for="r in g.list" :key="r.id" class="border-b border-rule last:border-b-0">
                    <button
                        class="grid w-full grid-cols-[minmax(0,1fr)_auto] items-center gap-x-5 gap-y-3 px-5 py-4 text-left transition-colors hover:bg-[#f8f9fb] focus-visible:bg-[#f8f9fb] lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1.05fr)_minmax(0,1.4fr)_minmax(0,0.8fr)_20px]"
                        :class="r.declared_at && 'opacity-70'"
                        :aria-label="`Open ${r.name}: ${r.status.text}`"
                        @click="selectRace(r.id)"
                    >
                        <span class="flex min-w-0 items-center gap-3">
                            <RaceTag :race="r" />
                            <span class="min-w-0">
                                <span class="flex items-baseline gap-2">
                                    <span class="truncate text-[15px] font-[650] text-ink">{{ r.name }}</span>
                                    <span v-if="r.is_rerun" class="shrink-0 text-[12px] font-[600] text-warn">Re-run</span>
                                </span>
                                <span class="block truncate text-[13px] text-ink-2">{{ r.area }}</span>
                            </span>
                        </span>

                        <span class="text-right lg:text-left">
                            <span class="block text-[15px] font-[680] tabular-nums" :class="r.status.tone">{{ r.status.text }}</span>
                            <span v-if="r.rule" class="mt-0.5 hidden items-center gap-1 text-[12.5px] lg:flex" :class="r.rule.met ? 'text-ink-2' : 'text-action'">
                                <Icon v-if="r.rule.met" name="check" :size="13" class="text-good" />{{ r.rule.text }}
                            </span>
                        </span>

                        <span class="col-span-2 lg:col-span-1">
                            <span v-if="r.rule" class="mb-2 flex items-center gap-1 text-[12.5px] lg:hidden" :class="r.rule.met ? 'text-ink-2' : 'text-action'">
                                <Icon v-if="r.rule.met" name="check" :size="13" class="text-good" />{{ r.rule.text }}
                            </span>
                            <template v-if="r.counted && r.ours">
                                <span class="flex h-2.5 overflow-hidden rounded-[2px] bg-[#e8ebf0]">
                                    <span class="bar-fill h-full bg-navy" :style="{ width: share(r.ours) * 100 + '%' }"></span>
                                    <span class="flex-1"></span>
                                    <span class="bar-fill h-full bg-[#9aa3b2]" :style="{ width: share(r.rival) * 100 + '%' }"></span>
                                </span>
                                <span class="mt-1.5 flex justify-between gap-3 text-[12.5px] tabular-nums">
                                    <span class="font-[640] text-ink">Us {{ pct(share(r.ours)) }}</span>
                                    <span v-if="r.rival" class="truncate text-ink-2">{{ r.rival.candidate || r.rival.name }} {{ pct(share(r.rival)) }}</span>
                                </span>
                            </template>
                            <span v-else class="block text-[12.5px] text-ink-3">Shares appear with the first results</span>
                        </span>

                        <span class="col-span-2 flex items-center gap-3 lg:col-span-1 lg:block">
                            <span class="block text-[13.5px] font-[600] text-ink tabular-nums">{{ num(r.units_reported) }}<span class="font-[450] text-ink-3"> of {{ num(r.units) }}</span></span>
                            <span class="block h-[3px] flex-1 overflow-hidden rounded-full bg-[#e8ebf0] lg:mt-1.5"><span class="block h-full bg-navy" :style="{ width: r.reported * 100 + '%' }"></span></span>
                            <span v-if="r.waiting" class="shrink-0 text-[12.5px] text-ink-2 lg:mt-1 lg:block">{{ num(r.waiting) }} to verify</span>
                        </span>

                        <Icon name="chevron" :size="16" class="hidden text-ink-3 lg:block" />
                    </button>
                </li>
            </ul>
        </div>
    </section>
</template>
