<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import Icon from '../components/Icon.vue';
import Tabs from '../components/Tabs.vue';
import Pagination from '../components/Pagination.vue';
import TableBusy from '../components/TableBusy.vue';
import SearchSelect from '../components/SearchSelect.vue';
import RaceTag from '../components/RaceTag.vue';
import VideoPlayer from '../components/VideoPlayer.vue';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { hasRole } from '../lib/session';
import { dateTime, num, signed, timeOrDate } from '../lib/format';
import { byId, hasSeveral, viewKey, viewRace, withRace } from '../lib/races';
import { toast } from '../lib/toast';

/**
 * Result announcement videos, listened to by the AI and compared with the agent's signed result.
 * The AI only suggests: a person confirms a difference (the result is flagged for the reviewers
 * and legal) or dismisses it (the AI misheard).
 */
const route = useRoute();
const deepLink = route.query.check ? Number(route.query.check) : null;
const view = ref(deepLink ? 'all' : 'review');
const lgaId = ref('');
const search = ref('');
const page = ref(1);
const checks = useResource(async () => api.get('ai-checks', {
    view: view.value, lga_id: lgaId.value, search: search.value, page: page.value, per_page: 20,
    ...(deepLink && page.value === 1 ? { check: deepLink } : {}),
    ...(await withRace(() => viewRace.value)),
}), { refreshMs: 30000, watch: viewKey });
const structure = useResource(() => api.get('structure'));
watch([view, lgaId], () => { page.value = 1; selectedId.value = null; checks.load(); });
let t;
watch(search, () => { clearTimeout(t); t = setTimeout(() => { page.value = 1; checks.load(); }, 300); });
const go = (p) => { page.value = p; selectedId.value = null; checks.load(); };

const items = computed(() => checks.data.value?.data ?? []);
const counts = computed(() => checks.data.value?.counts ?? {});
const enabled = computed(() => checks.data.value?.enabled !== false);
const tabs = computed(() => [
    { value: 'review', label: 'Needs a decision', count: counts.value.review, act: true },
    { value: 'unclear', label: "Couldn't make out", count: counts.value.unclear },
    { value: 'no_result', label: 'No result yet', count: counts.value.no_result },
    { value: 'match', label: 'Matches', count: counts.value.match },
    { value: 'reviewed', label: 'Decided', count: counts.value.reviewed },
    { value: 'pending', label: 'Listening', count: counts.value.pending },
    { value: 'failed', label: 'Not checked', count: counts.value.failed },
]);

const selectedId = ref(deepLink);
const selected = computed(() => items.value.find((c) => c.id === selectedId.value) ?? items.value[0] ?? null);
const note = ref('');
const busy = ref('');
watch(selected, () => (note.value = ''));

/** Each figure side by side: what was heard, what the agent signed, and the gap. */
const rows = computed(() => {
    const c = selected.value;
    if (!c) return [];
    const heard = Object.fromEntries((c.heard?.votes ?? []).map((v) => [v.party, v.votes]));
    const signedVotes = c.result?.votes ?? {};
    const list = (c.ballot?.length ? c.ballot : Object.keys({ ...heard, ...signedVotes }).map((code) => ({ code, name: code })))
        .map((p) => ({ key: p.code, label: p.candidate ? `${p.code}, ${p.candidate}` : `${p.code}, ${p.name}`, ours: p.is_ours, heard: heard[p.code], signed: signedVotes[p.code] }));
    list.push({ key: 'accredited', label: 'Accredited voters', heard: c.heard?.accredited, signed: c.result?.accredited, rule: true });
    list.push({ key: 'rejected', label: 'Rejected ballots', heard: c.heard?.rejected, signed: c.result?.rejected });
    return list.map((r) => ({ ...r, differs: r.heard != null && r.signed != null && r.heard !== r.signed }));
});
const heardCount = computed(() => rows.value.filter((r) => r.heard != null).length);

const outcome = {
    differs: ['bad', 'Differs'], match: ['good', 'Matches'], no_result: ['neutral', 'No signed result yet'], unclear: ['warn', "Couldn't make out the figures"],
};
const statusLabel = { queued: 'Waiting to be listened to', working: 'Listening now', failed: 'Failed', skipped: 'Not checked' };
const confidence = { high: ['good', 'Heard clearly'], medium: ['warn', 'Partly unclear'], low: ['bad', 'Mostly unclear'] };
const reviewLabel = { confirmed: 'Confirmed: result flagged', dismissed: 'Dismissed: the AI misheard' };
const raceOf = (c) => (c.election ? byId(c.election.id) ?? c.election : null);
const diffCount = (c) => Object.keys(c.differences ?? {}).length;
const canEvidence = hasRole('legal', 'dg');

async function act(action) {
    const c = selected.value;
    busy.value = action;
    try {
        if (action === 'rerun') {
            await api.post(`ai-checks/${c.id}/rerun`);
            toast({ icon: 'refresh', tone: 'info', title: `Listening to ${c.polling_unit?.code} again`, body: 'The result appears here in a minute or two.' });
        } else {
            await api.post(`ai-checks/${c.id}/review`, { action, note: note.value || null });
            toast({
                icon: 'check', tone: 'good',
                title: action === 'confirm' ? `${c.polling_unit?.code}: result flagged` : action === 'dismiss' ? 'Dismissed' : 'Reopened',
                body: action === 'confirm' ? 'Reviewers and legal see the flag on the result.' : undefined,
            });
        }
        selectedId.value = null;
        await checks.load();
    } catch (e) {
        toast({ icon: 'alert', tone: 'bad', title: "Couldn't do that", body: e.firstError?.() ?? e.message });
    } finally {
        busy.value = '';
    }
}
</script>

<template>
    <PageHeader title="AI checks" subtitle="Result announcement videos, listened to by AI and compared with the agent's signed result. A person decides every difference." :updated-at="checks.updatedAt.value" :loading="checks.loading.value" @refresh="checks.load" />

    <p v-if="!enabled" class="note mb-4"><b class="font-[650] text-ink">AI is off.</b> Set <code class="font-mono text-[12.5px]">OPENAI_API_KEY</code> on the server to have announcement videos checked. Videos already received can then be checked with <code class="font-mono text-[12.5px]">php artisan pollproof:ai-check-videos</code>.</p>

    <Tabs v-model="view" label="Checks" :tabs="tabs" />

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <div class="relative w-full sm:w-[260px]">
            <Icon name="search" :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" />
            <label class="sr-only" for="ai-search">Search by unit</label>
            <input id="ai-search" v-model="search" class="field h-9 w-full pl-9 text-sm" placeholder="Unit code or name" />
        </div>
        <label class="sr-only" for="ai-lga">LGA</label>
        <SearchSelect id="ai-lga" v-model="lgaId" class="w-full sm:w-[190px]" size="sm" all-label="All LGAs" noun="LGAs" :options="(structure.data.value?.lgas ?? []).map((l) => ({ value: l.id, label: l.name }))" />
    </div>

    <LoadState :loading="checks.loading.value" :error="checks.error.value" :empty="!items.length" :empty-text="view === 'review' ? 'Nothing waiting. Every announcement video the AI has heard agrees with its signed result, or has been decided.' : 'Nothing here.'">
        <div class="grid items-start gap-4 lg:grid-cols-[320px_1fr]">
            <section class="card relative flex flex-col overflow-hidden" aria-label="Checks">
                <TableBusy :busy="checks.busy.value" />
                <ul>
                    <li v-for="c in items" :key="c.id" class="border-b border-rule last:border-0">
                        <button class="relative block w-full px-5 py-3 text-left transition-colors" :class="selected?.id === c.id ? 'bg-[#f3f5f8]' : 'hover:bg-[#f8f9fb]'" :aria-current="selected?.id === c.id ? 'true' : null" @click="selectedId = c.id">
                            <span v-if="selected?.id === c.id" class="absolute inset-y-0 left-0 w-[3px] bg-navy"></span>
                            <span class="flex items-baseline justify-between gap-2">
                                <span class="flex min-w-0 items-center gap-2">
                                    <RaceTag v-if="hasSeveral && raceOf(c)" :race="raceOf(c)" size="sm" />
                                    <b class="fig text-[15px] font-[650]">{{ c.polling_unit?.code ?? 'No unit' }}</b>
                                </span>
                                <span class="text-[12.5px] whitespace-nowrap text-ink-2 tabular-nums">{{ timeOrDate(c.video?.captured_at) }}</span>
                            </span>
                            <span class="block truncate text-[13px] text-ink-2">{{ c.polling_unit ? `${c.polling_unit.ward}, ${c.polling_unit.lga}` : '' }}</span>
                            <span class="mt-1 block text-[12.5px] font-[620]" :class="c.outcome === 'differs' ? 'text-action' : c.outcome === 'match' ? 'text-good' : 'text-ink-2'">
                                <template v-if="c.status !== 'done'">{{ statusLabel[c.status] }}</template>
                                <template v-else-if="c.outcome === 'differs'">{{ diffCount(c) }} figure{{ diffCount(c) === 1 ? '' : 's' }} differ{{ diffCount(c) === 1 ? 's' : '' }}</template>
                                <template v-else>{{ outcome[c.outcome]?.[1] }}</template>
                                <span v-if="c.review !== 'open'" class="font-[500] text-ink-3">, {{ c.review }}</span>
                            </span>
                        </button>
                    </li>
                </ul>
                <Pagination :meta="checks.data.value" noun="checks" :per-page-options="[20]" :loading="checks.loading.value" @page="go" />
            </section>

            <section v-if="selected" class="card">
                <header class="flex flex-wrap items-start justify-between gap-3 border-b border-rule px-5 pt-4 pb-3.5">
                    <div class="min-w-0">
                        <p v-if="selected.election" class="mb-1 flex items-center gap-2 text-[13px] font-[620] text-ink"><RaceTag v-if="raceOf(selected)" :race="raceOf(selected)" size="sm" />{{ selected.election.name }}</p>
                        <h2 class="text-[20px] leading-tight font-[700]"><span class="fig">{{ selected.polling_unit?.code }}</span> {{ selected.polling_unit?.name }}</h2>
                        <p class="mt-0.5 text-[13px] text-ink-2">{{ selected.polling_unit?.ward }}, {{ selected.polling_unit?.lga }}. Recorded {{ dateTime(selected.video?.captured_at) }} by {{ selected.video?.by?.name }} ({{ selected.video?.by?.code }}).</p>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <Badge v-if="selected.status === 'done' && outcome[selected.outcome]" :tone="outcome[selected.outcome][0]">{{ outcome[selected.outcome][1] }}</Badge>
                        <Badge v-else :tone="selected.status === 'failed' || selected.status === 'skipped' ? 'bad' : 'info'">{{ statusLabel[selected.status] }}</Badge>
                        <Badge v-if="selected.heard?.confidence && selected.status === 'done'" :tone="confidence[selected.heard.confidence]?.[0]">{{ confidence[selected.heard.confidence]?.[1] }}</Badge>
                    </div>
                </header>

                <div class="grid gap-5 p-5 xl:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)]">
                    <div class="min-w-0">
                        <VideoPlayer v-if="selected.video" :video="selected.video" />
                        <p v-if="selected.heard?.notes" class="mt-3 rounded-lg bg-mist px-3.5 py-2.5 text-[13.5px] text-ink-2"><b class="font-[650] text-ink">The AI noted:</b> {{ selected.heard.notes }}</p>
                        <p v-if="selected.heard?.unit_heard" class="mt-2 text-[13px] text-ink-2">Unit named in the video: <b class="font-[620] text-ink">{{ selected.heard.unit_heard }}</b></p>
                        <details v-if="selected.transcript" class="group mt-3 rounded-lg border border-line">
                            <summary class="flex cursor-pointer items-center justify-between px-3.5 py-2.5 text-[13.5px] font-[620] text-ink select-none">What was said <Icon name="chevron" :size="15" class="rotate-90 text-ink-3 transition-transform group-open:-rotate-90" /></summary>
                            <p class="max-h-60 overflow-y-auto border-t border-rule px-3.5 py-3 text-[14px] leading-relaxed whitespace-pre-line text-ink-2">{{ selected.transcript }}</p>
                        </details>
                        <p v-if="selected.error" class="mt-3 rounded-lg bg-bad-bg px-3.5 py-2.5 text-[13px] text-bad">{{ selected.error }}</p>
                    </div>

                    <div class="min-w-0">
                        <template v-if="selected.status === 'done'">
                            <div class="overflow-hidden rounded-lg border border-line">
                                <table class="w-full text-[14px]">
                                    <thead><tr><th class="th">Figure</th><th class="th text-right">Heard</th><th class="th text-right">Signed</th><th class="th text-right"><span class="sr-only">Difference</span></th></tr></thead>
                                    <tbody>
                                        <tr v-for="r in rows" :key="r.key" :class="r.differs ? 'bg-[#fff6f1]' : ''">
                                            <td class="td py-2.5" :class="r.rule && 'border-t-line'"><span :class="r.ours ? 'font-[650]' : 'text-ink-2'">{{ r.label }}</span><span v-if="r.ours" class="ml-1.5 rounded bg-[#eceff3] px-1.5 py-px text-[11px] font-[600] text-ink-2">ours</span></td>
                                            <td class="td fig py-2.5 text-right text-[15px] font-[650]" :class="r.differs ? 'text-action' : ''">{{ r.heard != null ? num(r.heard) : '–' }}</td>
                                            <td class="td fig py-2.5 text-right text-[15px]">{{ r.signed != null ? num(r.signed) : '–' }}</td>
                                            <td class="td fig w-16 py-2.5 text-right text-[13px] font-[650] text-action">{{ r.differs ? signed(r.heard - r.signed) : '' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p class="mt-2 text-[12.5px] text-ink-2">
                                "Heard" is what the AI made out of the video; "–" means it wasn't heard clearly enough and is left out of the comparison.
                                <template v-if="selected.result"> Signed result ref {{ selected.result.fingerprint }}, {{ selected.result.status === 'approved' ? 'verified' : selected.result.status.replace('_', ' ') }}.</template>
                                <template v-else> No signed result for this race yet; it is compared as soon as one arrives.</template>
                                <template v-if="!heardCount"> No figures were made out.</template>
                            </p>
                        </template>

                        <!-- A person decides. -->
                        <div class="mt-4 border-t border-rule pt-4">
                            <template v-if="selected.review !== 'open'">
                                <p class="text-[14px]"><b class="font-[650]">{{ reviewLabel[selected.review] }}</b><span class="text-ink-2"> by {{ selected.reviewed_by }}, {{ dateTime(selected.reviewed_at) }}</span></p>
                                <p v-if="selected.review_note" class="mt-1 text-[13.5px] text-ink-2">"{{ selected.review_note }}"</p>
                                <button class="btn-ghost mt-3" :disabled="!!busy" @click="act('reopen')">Reopen</button>
                            </template>
                            <template v-else-if="selected.status === 'done' && selected.outcome === 'differs'">
                                <label class="label" for="ai-note">Your note <span class="font-normal text-ink-2">(optional)</span></label>
                                <input id="ai-note" v-model="note" class="field" placeholder="e.g. Clear on the video at 0:42" maxlength="1000" />
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <button class="btn-primary" :disabled="!!busy" @click="act('confirm')"><Icon name="flag" :size="15" /> {{ busy === 'confirm' ? 'Flagging…' : 'The video is right: flag the result' }}</button>
                                    <button class="btn-ghost" :disabled="!!busy" @click="act('dismiss')">{{ busy === 'dismiss' ? 'Dismissing…' : 'The AI misheard: dismiss' }}</button>
                                </div>
                                <p class="mt-2 text-[12.5px] text-ink-2">Flagging adds a reviewer flag to the signed result with these figures, for the verifiers and legal. The agent's record never changes.</p>
                            </template>
                            <div class="flex flex-wrap gap-2" :class="selected.review === 'open' && selected.outcome === 'differs' && selected.status === 'done' ? 'mt-3' : ''">
                                <button v-if="selected.review === 'open' && selected.status !== 'queued' && selected.status !== 'working'" class="btn-ghost" :disabled="!!busy || !enabled" @click="act('rerun')"><Icon name="refresh" :size="15" /> Listen again</button>
                                <RouterLink v-if="selected.polling_unit && canEvidence" :to="`/units/${selected.polling_unit.id}/evidence`" class="btn-ghost">Evidence pack</RouterLink>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </LoadState>
</template>
