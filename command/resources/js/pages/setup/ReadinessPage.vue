<script setup>
import { computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import PageHeader from '../../components/PageHeader.vue';
import LoadState from '../../components/LoadState.vue';
import Badge from '../../components/Badge.vue';
import FreezeNote from '../../components/FreezeNote.vue';
import { api } from '../../lib/api';
import { useResource } from '../../lib/useResource';
import { num } from '../../lib/format';

const report = useResource(() => api.get('readiness'));
const r = computed(() => report.data.value);
const showAll = ref(false);
const lgas = computed(() => (showAll.value ? r.value?.lgas : r.value?.lgas.slice(0, 8)) ?? []);
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
        <p v-if="message" role="status" class="mb-4 rounded-xl bg-good-bg px-4 py-3 text-sm font-semibold text-good">{{ message }}</p>
        <p v-if="error" role="alert" class="mb-4 rounded-xl bg-bad-bg px-4 py-3 text-sm text-bad">{{ error }}</p>

        <section class="card mb-5 p-5">
            <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-[17px] font-bold">From assigned to ready</h2>
                <span class="text-sm text-ink-2">{{ num(r.units) }} polling units</span>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <div v-for="(f, i) in r.funnel" :key="f.key" class="flex flex-col gap-2">
                    <div class="text-[28px] leading-none font-extrabold">{{ num(f.value) }}</div>
                    <div class="text-sm font-bold">{{ f.label }}</div>
                    <div class="h-2 overflow-hidden rounded-full bg-[#e9ecf1]"><div class="h-full rounded-full" :class="i === 4 ? 'bg-action' : 'bg-navy'" :style="{ width: (r.units ? (100 * f.value) / r.units : 0) + '%' }"></div></div>
                    <div class="text-[13px] text-ink-2">{{ notes[f.key](r) }}</div>
                </div>
            </div>
        </section>

        <div class="grid items-start gap-5 xl:grid-cols-[1.4fr_1fr]">
            <section class="card overflow-hidden">
                <div class="flex items-baseline justify-between gap-2 px-5 pt-5 pb-3">
                    <h2 class="text-[17px] font-bold">Readiness by LGA</h2>
                    <span class="text-xs text-ink-2">Lowest confirmation first · % of units</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[560px] text-sm">
                        <thead class="bg-mist"><tr><th class="th">LGA</th><th class="th text-right">Units</th><th class="th text-right">Practice</th><th class="th text-right">Trained</th><th class="th text-right">Confirmed</th><th class="th">Status</th></tr></thead>
                        <tbody>
                            <tr v-for="l in lgas" :key="l.id">
                                <td class="td font-bold">{{ l.name }}</td>
                                <td class="td text-right">{{ num(l.units) }}</td>
                                <td class="td text-right">{{ l.tested }}%</td>
                                <td class="td text-right">{{ l.trained }}%</td>
                                <td class="td text-right font-bold" :class="l.status === 'ready' ? '' : l.status === 'at_risk' ? 'text-[#7a4f00]' : 'text-action'">{{ l.confirmed }}%</td>
                                <td class="td"><Badge :tone="statusTone[l.status]">{{ statusLabel[l.status] }}</Badge></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="r.lgas.length > 8" class="border-t border-[#eef0f3] px-5 py-3 text-sm">
                    <button class="font-bold text-navy underline-offset-2 hover:underline" @click="showAll = !showAll">{{ showAll ? 'Show the lowest 8' : `Show all ${r.lgas.length}` }}</button>
                </div>
            </section>

            <section class="card p-5">
                <h2 class="mb-3 text-[17px] font-bold">Close the gaps</h2>
                <ul class="flex flex-col gap-2">
                    <li v-for="g in gaps" :key="g.title" class="flex items-center gap-4 rounded-xl bg-mist p-3.5">
                        <div class="w-14 shrink-0 text-center text-[22px] font-extrabold" :class="g.count ? g.tone : 'text-ink-3'">{{ num(g.count) }}</div>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-bold">{{ g.title }}</div>
                            <div class="text-[12.5px] text-ink-2">{{ g.note }}</div>
                        </div>
                        <button v-if="g.group" class="btn-outline h-9 shrink-0 px-3 text-xs" :disabled="!g.count || busy === g.group" @click="remind(g.group)">{{ busy === g.group ? 'Sending…' : g.action }}</button>
                        <RouterLink v-else :to="g.to" class="btn-outline h-9 shrink-0 px-3 text-xs">Open</RouterLink>
                    </li>
                </ul>
            </section>
        </div>
    </LoadState>
</template>
