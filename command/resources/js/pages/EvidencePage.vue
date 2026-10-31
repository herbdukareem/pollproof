<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import Icon from '../components/Icon.vue';
import ResultFlags from '../components/ResultFlags.vue';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { dateTime, num, signed, titleCase } from '../lib/format';

const route = useRoute();
const pack = useResource(() => api.get(`units/${route.params.id}/evidence`));
const d = computed(() => pack.data.value);
const current = computed(() => d.value?.results.find((r) => r.is_current) ?? null);
const parties = computed(() => Object.keys(current.value?.votes ?? {}));
const irevTone = { match: 'good', differs: 'bad', no_result: 'warn', not_on_irev: 'bad' };
const irevLabel = { match: 'Matches our result', differs: 'Differs from our result', no_result: 'We have no result to compare', not_on_irev: 'Not on IReV' };
const printPack = () => window.print();

/** Every recorded event for the unit, in time order. */
const timeline = computed(() => {
    if (!d.value) return [];
    const ev = [];
    for (const c of d.value.check_ins) ev.push({ at: c.captured_at, text: `Agent checked in${c.channel === 'ussd' ? ' by USSD (no GPS)' : c.distance_m != null ? `, ${c.distance_m} m from the unit` : ''}`, kind: ['verified', 'approved'].includes(c.status) ? 'ok' : 'warn' });
    for (const i of d.value.incidents) ev.push({ at: i.occurred_at, text: `Incident: ${i.type === 'bvas_problem' ? 'BVAS problem' : titleCase(i.type)}`, kind: i.severity === 'high' ? 'bad' : 'warn' });
    d.value.results.forEach((r, k) => {
        ev.push({ at: r.captured_at, text: `${k ? 'Correction' : 'Result'} signed on the agent's phone`, kind: r.signature_valid ? 'ok' : 'info' });
        ev.push({ at: r.received_at, text: `Received by the command room${r.channel !== 'app' ? ' by ' + r.channel.toUpperCase() : ''}`, kind: 'info' });
        if (r.review.at) ev.push({ at: r.review.at, text: `Second check: ${titleCase(r.review.status)}`, kind: r.review.status === 'approved' ? 'ok' : 'warn' });
    });
    if (d.value.irev) ev.push({ at: d.value.irev.seen_at, text: `IReV: ${irevLabel[d.value.irev.comparison.status] ?? d.value.irev.comparison.status}`, kind: d.value.irev.comparison.status === 'match' ? 'ok' : 'bad' });
    for (const f of d.value.flags ?? []) {
        ev.push({ at: f.at, text: `Flagged by ${f.by?.name}: ${f.label.toLowerCase()}`, kind: 'bad' });
        if (f.cleared_at) ev.push({ at: f.cleared_at, text: `Flag cleared by ${f.cleared_by}`, kind: 'ok' });
    }
    for (const c of d.value.collation) ev.push({ at: c.announced_at, text: `${titleCase(c.comparison.level)} sheet ${Object.keys(c.comparison.differences).length ? 'differs from our units' : 'matches our units'}`, kind: Object.keys(c.comparison.differences).length ? 'bad' : 'ok' });
    return ev.filter((e) => e.at).sort((a, b) => new Date(a.at) - new Date(b.at));
});
const dot = { ok: 'bg-good', bad: 'bg-action', warn: 'bg-[#d99a00]', info: 'bg-navy' };
const compareRows = computed(() => {
    const irev = d.value?.irev?.votes;
    if (!current.value || !irev) return [];
    const codes = [...new Set([...Object.keys(current.value.votes), ...Object.keys(irev)])].sort();
    return codes.map((code) => ({ label: `Party ${code}`, ours: current.value.votes[code] ?? 0, irev: irev[code] ?? 0 }));
});
const headline = computed(() => {
    if (!d.value) return null;
    if (current.value?.flags.includes('overvote')) return ['bad', 'Overvote'];
    if ((d.value.flags ?? []).some((f) => f.status === 'open')) return ['bad', 'Flagged by a reviewer'];
    if (d.value.irev?.comparison.status === 'differs') return ['bad', 'IReV differs'];
    if (d.value.irev?.comparison.status === 'not_on_irev') return ['warn', 'Not on IReV'];
    if (d.value.collation.some((c) => Object.keys(c.comparison.differences).length)) return ['bad', 'Collation differs'];
    return current.value ? ['good', 'No discrepancy found'] : ['neutral', 'No result'];
});
</script>

<template>
    <PageHeader title="Evidence pack" :subtitle="d ? `${d.polling_unit.code} · ${d.polling_unit.name} · ${d.polling_unit.ward} · ${d.polling_unit.lga}` : 'Everything we hold for one polling unit, for a petition.'" :updated-at="pack.updatedAt.value" :loading="pack.loading.value" @refresh="pack.load">
        <template #actions>
            <Badge v-if="headline" :tone="headline[0]">{{ headline[1] }}</Badge>
            <button class="btn-primary print:hidden" @click="printPack"><Icon name="download" :size="16" /> Print or save as PDF</button>
        </template>
    </PageHeader>

    <LoadState :loading="pack.loading.value" :error="pack.error.value" :empty="!d">
        <p class="mb-5 text-xs text-ink-2">Generated {{ dateTime(d.generated_at) }}. Opening this pack is recorded in the audit log.</p>

        <section class="card mb-5 p-5">
            <h2 class="card-title mb-3">Signed result{{ d.results.length > 1 ? 's' : '' }}</h2>
            <p v-if="!d.results.length" class="text-sm text-ink-2">No result was received for this unit.</p>
            <div v-else class="grid gap-5 lg:grid-cols-[1fr_320px]">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[520px] text-sm">
                        <thead class="bg-mist"><tr><th class="th">Version</th><th class="th">Received</th><th class="th text-right">Accredited</th><th class="th text-right" v-for="p in parties" :key="p">{{ p }}</th><th class="th">Signature</th></tr></thead>
                        <tbody>
                            <tr v-for="(r, i) in d.results" :key="r.id" :class="r.is_current ? '' : 'text-ink-2'">
                                <td class="td"><b>#{{ i + 1 }}</b> <Badge v-if="r.is_current" tone="navy">Current</Badge><Badge v-else tone="neutral">Superseded</Badge><div class="text-xs text-ink-2">{{ r.channel.toUpperCase() }} · {{ r.agent?.code }}</div><div v-if="r.correction_reason" class="text-xs">Correction: {{ r.correction_reason }}</div></td>
                                <td class="td whitespace-nowrap">{{ dateTime(r.received_at) }}<div class="text-xs text-ink-2">captured {{ dateTime(r.captured_at) }}</div></td>
                                <td class="td text-right">{{ num(r.accredited) }}</td>
                                <td class="td text-right font-semibold" v-for="p in parties" :key="p">{{ num(r.votes[p] ?? 0) }}</td>
                                <td class="td"><Badge :tone="r.signature_valid ? 'good' : r.channel !== 'app' ? 'neutral' : 'bad'">{{ r.signature_valid ? 'Valid' : r.channel !== 'app' ? `${r.channel.toUpperCase()}, unsigned` : 'Invalid' }}</Badge></td>
                            </tr>
                        </tbody>
                    </table>
                    <dl v-if="current" class="mt-4 grid gap-x-6 gap-y-1.5 text-xs sm:grid-cols-[140px_1fr]">
                        <dt class="text-ink-2">Payload hash</dt><dd class="font-mono break-all">{{ current.payload_hash ?? '–' }}</dd>
                        <dt class="text-ink-2">Photo SHA-256</dt><dd class="font-mono break-all">{{ current.photo_sha256 ?? '–' }}</dd>
                        <dt class="text-ink-2">GPS at capture</dt><dd>{{ current.latitude != null ? `${current.latitude}, ${current.longitude}` : '–' }} (unit at {{ d.polling_unit.latitude ?? '–' }}, {{ d.polling_unit.longitude ?? '–' }})</dd>
                        <dt class="text-ink-2">Review</dt><dd>{{ titleCase(current.review.status) }}{{ current.review.at ? ` · ${dateTime(current.review.at)}` : '' }}{{ current.review.note ? ` · ${current.review.note}` : '' }}</dd>
                        <dt v-if="current.flags.length" class="text-ink-2">Flags</dt><dd v-if="current.flags.length">{{ current.flags.map(titleCase).join(', ') }}</dd>
                    </dl>
                </div>
                <figure class="overflow-hidden rounded-xl border border-line bg-mist">
                    <img v-if="current?.has_photo" :src="api.url(`results/${current.id}/photo`)" :alt="`EC8A photo for ${d.polling_unit.code}`" class="w-full object-contain" />
                    <div v-else class="flex h-48 flex-col items-center justify-center gap-2 text-sm text-ink-2"><Icon name="image" :size="28" />No EC8A photo</div>
                    <figcaption class="px-3 py-2 text-xs text-ink-2">EC8A as photographed at the unit</figcaption>
                </figure>
            </div>
        </section>

        <section v-if="current" class="card mb-5 p-5">
            <ResultFlags :result-id="current.id" @changed="pack.load" />
        </section>

        <div class="mb-5 grid items-start gap-5 lg:grid-cols-[1.2fr_1fr]">
            <section class="card overflow-hidden">
                <div class="p-5 pb-3">
                    <h2 class="card-title">Our record against IReV</h2>
                    <p class="card-sub">{{ compareRows.length ? 'Our agent’s signed figures beside what IReV shows for this unit.' : 'Appears once both our signed result and an IReV entry are in.' }}</p>
                </div>
                <table v-if="compareRows.length" class="w-full text-sm">
                    <thead><tr><th class="th">Figure</th><th class="th text-right">Our agent</th><th class="th text-right">IReV</th><th class="th text-right">Difference</th></tr></thead>
                    <tbody>
                        <tr v-for="r in compareRows" :key="r.label" :class="r.irev !== r.ours ? 'bg-[#fff8f4]' : ''">
                            <td class="td font-semibold">{{ r.label }}</td>
                            <td class="td text-right">{{ num(r.ours) }}</td>
                            <td class="td text-right font-semibold" :class="r.irev !== r.ours ? 'text-action' : ''">{{ num(r.irev) }}</td>
                            <td class="td text-right font-extrabold" :class="r.irev !== r.ours ? 'text-action' : 'text-ink-3'">{{ r.irev !== r.ours ? signed(r.irev - r.ours) : '0' }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
            <section class="card p-5">
                <h2 class="card-title mb-3">Timeline</h2>
                <p v-if="!timeline.length" class="text-sm text-ink-2">Nothing recorded yet.</p>
                <ol class="relative ml-1.5 border-l-2 border-line">
                    <li v-for="(e, k) in timeline" :key="k" class="relative pb-3.5 pl-5 last:pb-0">
                        <span class="absolute top-1 -left-[7px] h-3 w-3 rounded-full border-2 border-white" :class="dot[e.kind]"></span>
                        <span class="block text-xs text-ink-2">{{ dateTime(e.at) }}</span>
                        <span class="text-sm" :class="e.kind === 'bad' ? 'font-bold text-action' : ''">{{ e.text }}</span>
                    </li>
                </ol>
            </section>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <section class="card p-5">
                <h2 class="card-title mb-3">IReV</h2>
                <p v-if="!d.irev" class="text-sm text-ink-2">Not yet checked against IReV.</p>
                <template v-else>
                    <Badge :tone="irevTone[d.irev.comparison.status]">{{ irevLabel[d.irev.comparison.status] }}</Badge>
                    <p class="mt-2 text-sm text-ink-2">Checked {{ dateTime(d.irev.seen_at) }}{{ d.irev.has_screenshot ? ' · screenshot kept' : '' }}</p>
                    <p v-if="d.irev.comparison.status === 'differs'" class="mt-2 text-sm">IReV minus our result: <b v-for="(v, k) in d.irev.comparison.differences" :key="k" class="mr-2 text-action">{{ k }} {{ signed(v) }}</b></p>
                </template>
            </section>

            <section class="card p-5">
                <h2 class="card-title mb-3">Collation sheets covering this unit</h2>
                <p v-if="!d.collation.length" class="text-sm text-ink-2">No collation figures entered yet.</p>
                <ul>
                    <li v-for="c in d.collation" :key="c.id" class="border-t border-[#eef0f3] py-2 text-sm first:border-0">
                        <div class="flex justify-between gap-2"><b>{{ titleCase(c.comparison.level) }} · {{ c.comparison.scope }}</b><span class="text-xs text-ink-2">{{ dateTime(c.announced_at) }}</span></div>
                        <div v-if="Object.keys(c.comparison.differences).length">Declared minus ours: <b v-for="(v, k) in c.comparison.differences" :key="k" class="mr-2 text-action">{{ k }} {{ signed(v) }}</b><span class="text-xs text-ink-2">({{ c.comparison.units_reported }} of {{ c.comparison.units_in_scope }} units in)</span></div>
                        <div v-else class="text-good">Matches our signed results.</div>
                    </li>
                </ul>
            </section>

            <section class="card p-5">
                <h2 class="card-title mb-3">Agent check-ins</h2>
                <p v-if="!d.check_ins.length" class="text-sm text-ink-2">No check-ins.</p>
                <ul>
                    <li v-for="c in d.check_ins" :key="c.id" class="flex justify-between gap-2 border-t border-[#eef0f3] py-2 text-sm first:border-0">
                        <span>{{ dateTime(c.captured_at) }} · {{ c.distance_m != null ? `${c.distance_m} m from unit` : 'no unit GPS' }} · ±{{ c.accuracy_m }} m</span>
                        <Badge :tone="c.status === 'verified' || c.status === 'approved' ? 'good' : c.status === 'rejected' ? 'bad' : c.status === 'replaced' ? 'neutral' : 'warn'">{{ c.status === 'replaced' ? 'Replaced by a later check-in' : titleCase(c.status) }}</Badge>
                    </li>
                </ul>
            </section>

            <section class="card p-5">
                <h2 class="card-title mb-3">Incidents</h2>
                <p v-if="!d.incidents.length" class="text-sm text-ink-2">None reported.</p>
                <ul>
                    <li v-for="i in d.incidents" :key="i.id" class="border-t border-[#eef0f3] py-2 text-sm first:border-0">
                        <div class="flex justify-between gap-2"><b>{{ i.type === 'bvas_problem' ? 'BVAS problem' : titleCase(i.type) }}</b><span class="text-xs text-ink-2">{{ dateTime(i.occurred_at) }}</span></div>
                        <p class="text-ink-2">{{ i.description }}</p>
                    </li>
                </ul>
            </section>
        </div>
    </LoadState>
</template>
