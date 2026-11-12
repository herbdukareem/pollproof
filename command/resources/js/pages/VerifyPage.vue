<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import Icon from '../components/Icon.vue';
import Pagination from '../components/Pagination.vue';
import TableBusy from '../components/TableBusy.vue';
import ResultFlags from '../components/ResultFlags.vue';
import Tabs from '../components/Tabs.vue';
import { api } from '../lib/api';
import SearchSelect from '../components/SearchSelect.vue';
import { useResource } from '../lib/useResource';
import { session } from '../lib/session';
import { ago, dateTime, minutesSince, num, time } from '../lib/format';

const kind = ref('');
const search = ref('');
const lgaId = ref('');
const page = ref(1);
const queue = useResource(() => api.get('results-queue', { kind: kind.value, search: search.value, lga_id: lgaId.value, page: page.value, per_page: 20 }), { refreshMs: 20000 });
const structure = useResource(() => api.get('structure'));
const items = computed(() => queue.data.value?.data ?? []);
const counts = computed(() => queue.data.value?.counts ?? {});
watch([kind, lgaId], () => { page.value = 1; queue.load(); });
let t;
watch(search, () => { clearTimeout(t); t = setTimeout(() => { page.value = 1; queue.load(); }, 300); });
const flagsPanel = ref(null);
// A new or cleared flag changes the counts in the queue.
const flagsChanged = (open) => {
    if (selected.value) selected.value.open_flags = open;
    queue.load();
};
const go = (p) => { page.value = p; selectedId.value = null; queue.load(); };
const selectedId = ref(null);
const selected = computed(() => items.value.find((r) => r.id === selectedId.value) ?? items.value[0] ?? null);
const note = ref('');
const busy = ref(false);
const message = ref('');
const error = ref('');

watch(selected, () => { note.value = ''; error.value = ''; });

const tabs = computed(() => [
    { value: '', label: 'All waiting', count: counts.value.all },
    { value: 'flagged', label: 'Overvote', count: counts.value.flagged, act: true },
    { value: 'corrections', label: 'Corrections', count: counts.value.corrections },
    { value: 'review_flagged', label: 'Reviewer flags', count: counts.value.review_flagged, act: true },
]);
const channelName = (c) => ({ ussd: 'USSD', sms: 'SMS', app: 'the app' })[c] ?? c;

const ownResult = computed(() => selected.value?.agent?.id === session.user?.id);

async function decide(action) {
    if (!selected.value) return;
    busy.value = true;
    error.value = '';
    try {
        const id = selected.value.id;
        await api.post(`results/${id}/${action}`, { note: note.value || null });
        message.value = action === 'approve' ? `Approved ${selected.value.polling_unit.code}.` : `Sent ${selected.value.polling_unit.code} back to the agent.`;
        selectedId.value = null;
        await queue.load();
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = false;
    }
}

// Ticks every 30 seconds so waiting times stay current between refreshes.
const now = ref(Date.now());
const tick = setInterval(() => (now.value = Date.now()), 30000);
onBeforeUnmount(() => clearInterval(tick));
const age = (r) => ago(r.received_at, now.value);
// Over 15 minutes waiting turns amber, over 30 red, so the oldest get picked up.
const ageClass = (r) => {
    const m = minutesSince(r.received_at, now.value);
    return m == null ? 'text-ink-2' : m >= 30 ? 'font-[650] text-action' : m >= 15 ? 'font-[650] text-[#b4470a]' : 'text-ink-2';
};
</script>

<template>
    <PageHeader title="Verify results" subtitle="A second person checks each result against its EC8A photo before it counts." :updated-at="queue.updatedAt.value" :loading="queue.loading.value" @refresh="queue.load" />
    <p v-if="message" role="status" class="mb-4 rounded-lg bg-good-bg px-4 py-3 text-sm font-[600] text-good">{{ message }}</p>

    <Tabs v-model="kind" label="Queue" :tabs="tabs" />

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <SearchSelect v-model="lgaId" class="w-full sm:w-[190px]" size="sm" all-label="All LGAs" noun="LGAs" aria-label="LGA" :options="(structure.data.value?.lgas ?? []).map((l) => ({ value: l.id, label: l.name }))" />
        <div class="relative w-full sm:w-[280px]">
            <Icon name="search" :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" />
            <input v-model="search" class="field h-9 w-full pl-9 text-sm" placeholder="Unit code, name or agent ID" aria-label="Search the queue" />
        </div>
    </div>

    <LoadState :loading="queue.loading.value" :error="queue.error.value" :empty="!items.length" :empty-text="kind || search || lgaId ? 'Nothing waiting matches these filters.' : 'The queue is clear. Every result has been checked.'">
        <div class="grid items-start gap-4 lg:grid-cols-[300px_1fr]">
            <section class="card relative flex flex-col overflow-hidden" aria-label="Queue">
                <TableBusy :busy="queue.busy.value" />
                <header class="card-head">
                    <h2 class="card-title">Waiting</h2>
                    <span class="text-[12.5px] text-ink-2">Overvotes first, then oldest</span>
                </header>
                <ul>
                    <li v-for="r in items" :key="r.id" class="border-b border-rule last:border-0">
                        <button
                            class="relative block w-full px-5 py-3 text-left transition-colors"
                            :class="selected?.id === r.id ? 'bg-[#f3f5f8]' : 'hover:bg-[#f8f9fb]'"
                            :aria-current="selected?.id === r.id ? 'true' : null"
                            @click="selectedId = r.id"
                        >
                            <span v-if="selected?.id === r.id" class="absolute inset-y-0 left-0 w-[3px] bg-navy"></span>
                            <span class="flex items-baseline justify-between gap-2">
                                <b class="fig text-[15px] font-[650]">{{ r.polling_unit.code }}</b>
                                <span class="text-[12.5px] whitespace-nowrap tabular-nums" :class="ageClass(r)" :title="`Received ${dateTime(r.received_at)}`">{{ age(r) }}</span>
                            </span>
                            <span class="block truncate text-[13px] text-ink-2">{{ r.polling_unit.ward }}, {{ r.polling_unit.lga }}</span>
                            <span v-if="r.flags.includes('overvote')" class="mt-1 block text-[12.5px] font-[620] text-action">Votes exceed accreditation</span>
                            <span v-else-if="r.supersedes_id" class="mt-1 block text-[12.5px] font-[620] text-warn">Correction</span>
                            <span v-if="r.open_flags" class="mt-1 flex items-center gap-1 text-[12.5px] font-[620] text-action"><Icon name="flag" :size="13" /> {{ r.open_flags }} reviewer flag{{ r.open_flags === 1 ? '' : 's' }}</span>
                        </button>
                    </li>
                </ul>
                <Pagination :meta="queue.data.value" noun="results" :per-page-options="[20]" :loading="queue.loading.value" @page="go" />
            </section>

            <section v-if="selected" class="card">
                <header class="flex flex-wrap items-start justify-between gap-3 border-b border-rule px-5 pt-4 pb-3.5">
                    <div class="min-w-0">
                        <h2 class="text-[20px] leading-tight font-[700]"><span class="fig">{{ selected.polling_unit.code }}</span> {{ selected.polling_unit.name }}</h2>
                        <p class="mt-0.5 text-[13px] text-ink-2">{{ selected.polling_unit.ward }}, {{ selected.polling_unit.lga }}. Sent by agent <span class="tabular-nums">{{ selected.agent?.code }}</span> at {{ time(selected.captured_at) }}.</p>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <Badge v-if="selected.flags.includes('overvote')" tone="bad">Overvote</Badge>
                        <Badge v-if="selected.open_flags" tone="bad">{{ selected.open_flags }} open flag{{ selected.open_flags === 1 ? '' : 's' }}</Badge>
                        <Badge :tone="selected.signature_valid ? 'good' : 'warn'">{{ selected.signature_valid ? 'Signature valid' : `Sent by ${channelName(selected.channel)}` }}</Badge>
                    </div>
                </header>

                <div class="grid gap-5 p-5 md:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
                    <div class="flex min-h-[320px] items-center justify-center overflow-hidden rounded-lg bg-[#e6e9ee]">
                        <img v-if="selected.has_photo" :src="api.url(`results/${selected.id}/photo`)" :alt="`EC8A photo for ${selected.polling_unit.code}`" class="max-h-[480px] w-full object-contain" />
                        <div v-else class="flex flex-col items-center gap-2 p-6 text-center text-sm text-ink-2"><Icon name="image" :size="26" />No photo yet. It uploads when the agent's phone has data.</div>
                    </div>
                    <div>
                        <h3 class="mb-1 text-[13px] text-ink-2">As entered by the agent</h3>
                        <dl class="text-[14px]">
                            <div class="flex items-baseline justify-between border-b border-rule py-2"><dt>Accredited voters</dt><dd class="fig text-[16px] font-[650]">{{ num(selected.accredited) }}</dd></div>
                            <div v-for="(v, code) in selected.votes" :key="code" class="flex items-baseline justify-between border-b border-rule py-2"><dt class="text-ink-2">{{ code }}</dt><dd class="fig text-[16px] font-[600]">{{ num(v) }}</dd></div>
                            <div class="flex items-baseline justify-between border-b border-rule py-2"><dt class="text-ink-2">Rejected ballots</dt><dd class="fig text-[16px] font-[600]">{{ num(selected.rejected) }}</dd></div>
                            <div class="flex items-baseline justify-between py-2.5" :class="selected.total_cast > selected.accredited ? 'text-action' : ''">
                                <dt class="font-[650]">Total votes cast</dt>
                                <dd class="fig text-[22px] font-[700]">{{ num(selected.total_cast) }}</dd>
                            </div>
                        </dl>
                        <p v-if="selected.total_cast > selected.accredited" class="text-[13px] text-action">{{ num(selected.total_cast - selected.accredited) }} more votes than accredited voters.</p>
                        <dl class="mt-4 grid grid-cols-[96px_1fr] gap-y-1 text-[13px]">
                            <dt class="text-ink-2">Fingerprint</dt><dd class="font-mono text-[12px] break-all">{{ selected.fingerprint }}</dd>
                            <template v-if="selected.correction_reason"><dt class="text-ink-2">Correction</dt><dd>{{ selected.correction_reason }}</dd></template>
                        </dl>
                    </div>
                </div>

                <div class="border-t border-rule px-5 py-4">
                    <label class="label" for="note">Note to the agent <span class="font-[400] text-ink-2">(needed to send it back)</span></label>
                    <input id="note" v-model="note" class="field" placeholder="e.g. Signatures at the bottom are unreadable. Retake the photo." />
                    <p v-if="ownResult" class="mt-3 text-sm text-warn">You submitted this result, so someone else must check it.</p>
                    <p v-if="error" role="alert" class="mt-3 rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>
                    <p v-if="selected.open_flags" class="mt-3 rounded-lg bg-warn-bg px-3 py-2 text-[13px] text-warn">This result has {{ selected.open_flags }} open flag{{ selected.open_flags === 1 ? '' : 's' }}. Read the comments below before approving.</p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <button class="btn-good h-10" :disabled="busy || ownResult" @click="decide('approve')"><Icon name="check" :size="16" /> Approve, it matches the photo</button>
                        <button class="btn-outline h-10" :disabled="busy || ownResult || !note" @click="decide('send-back')">Send back to the agent</button>
                        <button class="btn-ghost h-10 text-action hover:text-action-dark sm:ml-auto" :disabled="busy" @click="flagsPanel?.open()"><Icon name="flag" :size="16" /> Flag with a comment</button>
                    </div>
                    <p class="mt-3 text-[12.5px] text-ink-2">The agent's signed record never changes. Sending back asks them for a correction; a flag alerts legal and the situation room without holding the result.</p>
                </div>

                <div class="border-t border-rule px-5 py-4">
                    <ResultFlags ref="flagsPanel" :key="selected.id" :result-id="selected.id" @changed="flagsChanged" />
                </div>
            </section>
        </div>
    </LoadState>
</template>
