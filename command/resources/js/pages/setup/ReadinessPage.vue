<script setup>
import { computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import PageHeader from '../../components/PageHeader.vue';
import LoadState from '../../components/LoadState.vue';
import Badge from '../../components/Badge.vue';
import FreezeNote from '../../components/FreezeNote.vue';
import SortTh from '../../components/SortTh.vue';
import { sortRows, useSortState } from '../../lib/useSort';
import { api } from '../../lib/api';
import { useResource } from '../../lib/useResource';
import { num } from '../../lib/format';

const sort = useSortState({
    default: { label: 'default order', dir: 'asc' },
    name: { label: 'LGA', dir: 'asc', value: (l) => l.name },
    units: { label: 'Units', dir: 'desc', value: (l) => l.units },
    tested: { label: 'Practice', dir: 'desc', value: (l) => l.tested },
    trained: { label: 'Trained', dir: 'desc', value: (l) => l.trained },
    confirmed: { label: 'Confirmed', dir: 'desc', value: (l) => l.confirmed },
    status: { label: 'Status', dir: 'asc', value: (l) => ({ not_ready: 0, at_risk: 1, ready: 2 })[l.status] ?? 1 },
}, { key: 'default', dir: 'asc' }, { onChange: () => report.load() });
const report = useResource(() => api.get('readiness', sort.params));
const r = computed(() => report.data.value);
const showAll = ref(false);
const lgas = computed(() => {
    const all = sortRows(r.value?.lgas ?? [], sort);
    return showAll.value ? all : all.slice(0, 8);
});
const busy = ref('');
const message = ref('');
const error = ref('');

const notes = {
    assigned: (r) => (r.gaps.units_without_agent ? `${num(r.gaps.units_without_agent)} units still need an agent` : 'Every unit has a named agent'),
    installed: (r) => `${num(r.gaps.not_installed)} not yet installed`,
    tested: () => 'Practice submission received',
    trained: () => 'Passed the 10-question quiz',
    confirmed: (r) => `${num(r.gaps.not_confirmed)} not confirmed${r.gaps.unavailable ? `, ${r.gaps.unavailable} said no` : ''}`,
};
const statusTone = { ready: 'good', at_risk: 'warn', not_ready: 'bad' };
const statusLabel = { ready: 'Ready', at_risk: 'At risk', not_ready: 'Not ready' };

const gaps = computed(() => {
    if (!r.value) return [];
    const g = r.value.gaps;
    return [
        { title: 'Agents not confirmed', count: g.not_confirmed, tone: 'text-action', note: 'No YES to the confirmation message yet.', action: 'Send confirmation SMS', group: 'not_confirmed' },
        { title: 'App not installed', count: g.not_installed, tone: 'text-action', note: 'They get the install link and their agent ID.', action: 'Send install link', group: 'not_installed' },
        { title: 'Practice or quiz not done', count: g.not_trained, tone: 'text-[#7a4f00]', note: 'App installed but training not finished.', action: 'Send reminder', group: 'not_trained' },
        { title: 'Reserves free', count: g.reserves_free, tone: 'text-good', note: `${num(g.reserves_trained)} trained, ready to replace absentees.`, to: '/setup/roster' },
        { title: 'Units without GPS', count: g.units_without_gps, tone: 'text-[#7a4f00]', note: "Check-in there can't be verified.", to: '/setup/structure' },
        { title: 'Wards without a supervisor', count: g.wards_without_supervisor, tone: 'text-[#7a4f00]', note: 'Agents there have nobody to call.', to: '/setup/supervisors' },
    ];
});

async function remind(group) {
    busy.value = group;
    error.value = message.value = '';
    try {
        const res = await api.post('readiness/remind', { group });
        message.value = `Queued ${num(res.sent)} SMS.`;
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = '';
    }
}
</script>

<template>
    <PageHeader title="Readiness" subtitle="From every unit having an agent to every agent confirmed for election day." :updated-at="report.updatedAt.value" :loading="report.loading.value" @refresh="report.load" />
    <LoadState :loading="report.loading.value" :error="report.error.value" :empty="!r">
        <FreezeNote :freeze-at="r.freeze_at" />
        <p v-if="message" role="status" class="mb-4 rounded-lg bg-good-bg px-4 py-3 text-sm font-[600] text-good">{{ message }}</p>
        <p v-if="error" role="alert" class="mb-4 rounded-lg bg-bad-bg px-4 py-3 text-sm text-bad">{{ error }}</p>

        <section class="card mb-4 overflow-hidden">
            <header class="card-head">
                <h2 class="card-title">From assigned to ready</h2>
                <span class="text-[12.5px] text-ink-2 tabular-nums">of {{ num(r.units) }} polling units</span>
            </header>
            <ol class="grid sm:grid-cols-2 xl:grid-cols-5">
                <li v-for="(f, i) in r.funnel" :key="f.key" class="border-rule max-xl:border-t max-xl:first:border-t-0 sm:max-xl:[&:nth-child(2)]:border-t-0 sm:max-xl:[&:nth-child(even)]:border-l xl:[&:not(:first-child)]:border-l">
                    <RouterLink :to="{ path: '/setup/roster', query: { filter: i === 0 ? 'all' : 'incomplete' } }" class="flex h-full flex-col px-5 py-4 transition-colors hover:bg-[#f8f9fb]" :title="i === 0 ? 'Open the agent roster' : 'Open agents not fully onboarded'">
                        <span class="text-[13px] text-ink-2">{{ f.label }}</span>
                        <span class="fig mt-0.5 text-[28px] leading-none font-[700]" :class="i === 4 ? 'text-ink' : ''">{{ num(f.value) }}</span>
                        <span class="mt-2.5 h-1.5 rounded-[1px] bg-[#e8ebf0]"><span class="bar-fill block h-full rounded-[1px]" :class="i === 4 ? 'bg-good' : 'bg-navy'" :style="{ width: (r.units ? (100 * f.value) / r.units : 0) + '%' }"></span></span>
                        <span class="mt-2 text-[12.5px] text-ink-2">{{ notes[f.key](r) }}</span>
                    </RouterLink>
                </li>
            </ol>
        </section>

        <div class="grid items-start gap-4 [&>*]:min-w-0 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
            <section class="card overflow-hidden">
                <header class="card-head">
                    <h2 class="card-title">Readiness by LGA</h2>
                    <span class="text-[12.5px] text-ink-2">Share of units, lowest confirmed first</span>
                </header>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[560px] text-sm">
                        <thead><tr><SortTh :sort="sort" k="name">LGA</SortTh><SortTh :sort="sort" k="units" cls="text-right">Units</SortTh><SortTh :sort="sort" k="tested" cls="text-right">Practice</SortTh><SortTh :sort="sort" k="trained" cls="text-right">Trained</SortTh><SortTh :sort="sort" k="confirmed" cls="text-right">Confirmed</SortTh><SortTh :sort="sort" k="status">Status</SortTh></tr></thead>
                        <tbody>
                            <tr v-for="l in lgas" :key="l.id">
                                <td class="td font-[600]">{{ l.name }}</td>
                                <td class="td fig text-right">{{ num(l.units) }}</td>
                                <td class="td fig text-right">{{ l.tested }}%</td>
                                <td class="td fig text-right">{{ l.trained }}%</td>
                                <td class="td fig text-right text-[15px] font-[700]" :class="l.status === 'ready' ? '' : l.status === 'at_risk' ? 'text-[#7a4f00]' : 'text-action'">{{ l.confirmed }}%</td>
                                <td class="td"><Badge :tone="statusTone[l.status]">{{ statusLabel[l.status] }}</Badge></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="r.lgas.length > 8" class="border-t border-rule px-5 py-3 text-sm">
                    <button class="link" @click="showAll = !showAll">{{ showAll ? 'Show the lowest 8' : `Show all ${r.lgas.length}` }}</button>
                </div>
            </section>

            <section class="card overflow-hidden">
                <header class="card-head"><h2 class="card-title">Close the gaps</h2></header>
                <ul>
                    <li v-for="g in gaps" :key="g.title" class="flex items-center gap-4 border-t border-rule px-5 py-3.5 first:border-t-0">
                        <div class="fig w-12 shrink-0 text-right text-[24px] leading-none font-[700]" :class="g.count ? g.tone : 'text-ink-3'">{{ num(g.count) }}</div>
                        <div class="min-w-0 flex-1">
                            <div class="text-[14px] font-[620]">{{ g.title }}</div>
                            <div class="text-[12.5px] text-ink-2">{{ g.note }}</div>
                        </div>
                        <button v-if="g.group" class="btn-ghost h-8 shrink-0 px-3 text-[13px]" :disabled="!g.count || busy === g.group" @click="remind(g.group)">{{ busy === g.group ? 'Sending…' : g.action }}</button>
                        <RouterLink v-else :to="g.to" class="btn-ghost h-8 shrink-0 px-3 text-[13px]">Open</RouterLink>
                    </li>
                </ul>
            </section>
        </div>
    </LoadState>
</template>
