<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import Icon from '../components/Icon.vue';
import Pagination from '../components/Pagination.vue';
import { api } from '../lib/api';
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
const go = (p) => { page.value = p; selectedId.value = null; queue.load(); };
const selectedId = ref(null);
const selected = computed(() => items.value.find((r) => r.id === selectedId.value) ?? items.value[0] ?? null);
const note = ref('');
const busy = ref(false);
const message = ref('');
const error = ref('');

watch(selected, () => { note.value = ''; error.value = ''; });

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
    return m == null ? 'text-ink-2' : m >= 30 ? 'font-bold text-action' : m >= 15 ? 'font-bold text-[#b4470a]' : 'text-ink-2';
};
</script>

<template>
    <PageHeader title="Verify results" subtitle="A second person checks each result against its EC8A photo before it counts." :updated-at="queue.updatedAt.value" :loading="queue.loading.value" @refresh="queue.load">
        <template #actions><Badge tone="warn">{{ num(counts.all ?? 0) }} waiting</Badge></template>
    </PageHeader>
    <p v-if="message" role="status" class="mb-4 rounded-xl bg-good-bg px-4 py-3 text-sm font-semibold text-good">{{ message }}</p>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <button v-for="[v, text, n] in [['', 'All waiting', counts.all], ['flagged', 'Votes exceed accreditation', counts.flagged], ['corrections', 'Corrections', counts.corrections]]" :key="v" class="chip h-9" :class="kind === v ? 'chip-on' : ''" :aria-pressed="kind === v" @click="kind = v">{{ text }} <b>{{ num(n ?? 0) }}</b></button>
        <div class="ml-auto flex w-full flex-wrap gap-2 sm:w-auto">
            <select v-model="lgaId" class="field h-9 w-auto text-sm" aria-label="LGA">
                <option value="">All LGAs</option>
                <option v-for="l in structure.data.value?.lgas ?? []" :key="l.id" :value="l.id">{{ l.name }}</option>
            </select>
            <div class="relative min-w-[200px] flex-1">
                <Icon name="search" :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" />
                <input v-model="search" class="field h-9 w-full pl-9 text-sm" placeholder="Unit code, name or agent ID" aria-label="Search the queue" />
            </div>
        </div>
    </div>

    <LoadState :loading="queue.loading.value" :error="queue.error.value" :empty="!items.length" :empty-text="kind || search || lgaId ? 'Nothing waiting matches these filters.' : 'Queue is clear. Every result has been checked.'">
        <div class="grid gap-5 lg:grid-cols-[320px_1fr]">
            <section class="card flex flex-col self-start overflow-hidden" aria-label="Queue">
              <div class="flex flex-col gap-1 p-2">
                <div class="px-2.5 pt-2 pb-1.5 text-xs font-bold tracking-wide text-ink-2 uppercase">Flagged first, then oldest</div>
                <button
                    v-for="r in items"
                    :key="r.id"
                    class="rounded-xl border-2 px-3.5 py-3 text-left"
                    :class="selected?.id === r.id ? 'border-navy bg-mist' : 'border-transparent hover:bg-mist'"
                    @click="selectedId = r.id"
                >
                    <span class="flex justify-between gap-2"><b class="text-sm">{{ r.polling_unit.code }}</b><span class="text-xs whitespace-nowrap" :class="ageClass(r)" :title="`Received ${dateTime(r.received_at)}`">{{ age(r) }}</span></span>
                    <span class="block text-xs text-ink-2">{{ r.polling_unit.ward }} · {{ r.polling_unit.lga }}</span>
                    <span v-if="r.flags.includes('overvote')" class="mt-1 block text-xs font-bold text-action">Votes exceed accreditation</span>
                    <span v-else-if="r.supersedes_id" class="mt-1 block text-xs font-bold text-warn">Correction</span>
                </button>
              </div>
              <Pagination :meta="queue.data.value" noun="results" :per-page-options="[20]" :loading="queue.loading.value" @page="go" />
            </section>

            <section v-if="selected" class="card p-5">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="text-[13px] text-ink-2">{{ selected.polling_unit.ward }} · {{ selected.polling_unit.lga }} · agent {{ selected.agent?.code }}</div>
                        <h2 class="text-xl font-extrabold">{{ selected.polling_unit.code }} · {{ selected.polling_unit.name }}</h2>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Badge v-if="selected.flags.includes('overvote')" tone="bad">Overvote</Badge>
                        <Badge :tone="selected.signature_valid ? 'good' : 'warn'">{{ selected.signature_valid ? 'Signature valid' : `Via ${selected.channel}` }}</Badge>
                    </div>
                </div>

                <div class="grid gap-5 md:grid-cols-2">
                    <div class="flex min-h-[320px] items-center justify-center overflow-hidden rounded-xl bg-[#e4e8ee]">
                        <img v-if="selected.has_photo" :src="api.url(`results/${selected.id}/photo`)" :alt="`EC8A photo for ${selected.polling_unit.code}`" class="max-h-[460px] w-full object-contain" />
                        <div v-else class="flex flex-col items-center gap-2 p-6 text-center text-sm text-ink-2"><Icon name="image" :size="28" />No photo yet. It uploads when the agent's phone has data.</div>
                    </div>
                    <div>
                        <table class="w-full overflow-hidden rounded-xl border border-line text-sm">
                            <tbody>
                                <tr><td class="td font-semibold">Accredited voters</td><td class="td text-right font-bold">{{ num(selected.accredited) }}</td></tr>
                                <tr v-for="(v, code) in selected.votes" :key="code"><td class="td">Party {{ code }}</td><td class="td text-right font-bold">{{ num(v) }}</td></tr>
                                <tr><td class="td">Rejected ballots</td><td class="td text-right font-bold">{{ num(selected.rejected) }}</td></tr>
                                <tr :class="selected.total_cast > selected.accredited ? 'bg-bad-bg' : ''"><td class="td font-semibold">Total votes cast</td><td class="td text-right font-extrabold">{{ num(selected.total_cast) }}</td></tr>
                            </tbody>
                        </table>
                        <dl class="mt-4 grid grid-cols-[110px_1fr] gap-y-1.5 text-[13px]">
                            <dt class="text-ink-2">Captured</dt><dd>{{ time(selected.captured_at) }}</dd>
                            <dt class="text-ink-2">Fingerprint</dt><dd class="font-mono">{{ selected.fingerprint }}</dd>
                            <template v-if="selected.correction_reason"><dt class="text-ink-2">Correction</dt><dd>{{ selected.correction_reason }}</dd></template>
                        </dl>
                    </div>
                </div>

                <label class="label mt-5" for="note">Note to the agent (required to send back)</label>
                <input id="note" v-model="note" class="field" placeholder="e.g. Signatures at the bottom are unreadable. Retake the photo." />
                <p v-if="ownResult" class="mt-3 text-sm text-warn">You submitted this result, so someone else must check it.</p>
                <p v-if="error" role="alert" class="mt-3 rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>
                <div class="mt-4 flex flex-wrap gap-3">
                    <button class="btn-good h-11" :disabled="busy || ownResult" @click="decide('approve')">Approve: matches the photo</button>
                    <button class="btn-outline h-11" :disabled="busy || ownResult || !note" @click="decide('send-back')">Send back to agent</button>
                </div>
                <p class="mt-3 text-xs text-ink-2">The agent's signed record never changes. A send-back asks them to submit a correction.</p>
            </section>
        </div>
    </LoadState>
</template>
