<script setup>
import { computed, ref, watch } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import Icon from '../components/Icon.vue';
import Pagination from '../components/Pagination.vue';
import TableBusy from '../components/TableBusy.vue';
import Tabs from '../components/Tabs.vue';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { hasRole } from '../lib/session';
import { dateTime, titleCase } from '../lib/format';

const status = ref('open');
const type = ref('');
const severity = ref('');
const search = ref('');
const page = ref(1);
const perPage = ref(25);
const order = ref('latest');
const incidents = useResource(() => api.get('incidents', { status: status.value, type: type.value, severity: severity.value, search: search.value, page: page.value, per_page: perPage.value, sort: order.value }), { refreshMs: 30000 });
watch([status, type, severity, order], () => { page.value = 1; incidents.load(); });
let t;
watch(search, () => { clearTimeout(t); t = setTimeout(() => { page.value = 1; incidents.load(); }, 300); });
const go = (p) => { page.value = p; incidents.load(); };

const rows = computed(() => incidents.data.value?.data ?? []);
const meta = computed(() => incidents.data.value);
const typeCounts = computed(() => incidents.data.value?.counts?.types ?? {});
const statusCounts = computed(() => incidents.data.value?.counts?.status ?? {});
const typeTotal = computed(() => Object.values(typeCounts.value).reduce((a, b) => a + b, 0));
const statusTabs = computed(() => [
    { value: 'open', label: 'Open', count: statusCounts.value.open ?? 0, act: true },
    { value: 'handling', label: 'Being handled', count: statusCounts.value.handling ?? 0 },
    { value: 'resolved', label: 'Resolved', count: statusCounts.value.resolved ?? 0 },
]);
const selectedId = ref(null);
const selected = computed(() => rows.value.find((i) => i.id === selectedId.value) ?? rows.value[0] ?? null);
const canEdit = hasRole('situation_room', 'dg', 'legal');
const note = ref('');
const assign = ref('');
const busy = ref(false);
const error = ref('');

watch(selected, (s) => { note.value = ''; assign.value = s?.assigned_to ?? ''; error.value = ''; });

async function update(fields) {
    busy.value = true;
    error.value = '';
    try {
        await api.patch(`incidents/${selected.value.id}`, fields);
        note.value = '';
        await incidents.load();
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = false;
    }
}

const typeLabel = { disruption: 'Disruption', vote_buying: 'Vote buying', bvas_problem: 'BVAS problem', agent_turned_away: 'Agent turned away', materials_late: 'Materials late', other: 'Other' };
const sevTone = { high: 'bad', medium: 'warn', low: 'neutral' };
const statusTone = { open: 'warn', handling: 'info', resolved: 'good' };
</script>

<template>
    <PageHeader title="Incidents" subtitle="Reports from agents, with who is handling each one." :updated-at="incidents.updatedAt.value" :loading="incidents.loading.value" @refresh="incidents.load" />

    <Tabs v-model="status" label="Status" :tabs="statusTabs" />

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <div class="relative w-full sm:w-[280px]">
            <Icon name="search" :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" />
            <label class="sr-only" for="inc-search">Search incidents</label>
            <input id="inc-search" v-model="search" class="field h-9 w-full pl-9 text-sm" placeholder="Unit, place or words in the report" />
        </div>
        <label class="sr-only" for="inc-type">Type</label>
        <select id="inc-type" v-model="type" class="field h-9 w-auto text-sm">
            <option value="">All types ({{ typeTotal }})</option>
            <option v-for="(n, t) in typeCounts" :key="t" :value="t">{{ typeLabel[t] ?? titleCase(t) }} ({{ n }})</option>
        </select>
        <label class="sr-only" for="inc-sev">Priority</label>
        <select id="inc-sev" v-model="severity" class="field h-9 w-auto text-sm">
            <option value="">Any priority</option>
            <option value="high">High</option>
            <option value="medium">Medium</option>
            <option value="low">Low</option>
        </select>
        <label class="sr-only" for="inc-order">Order</label>
        <select id="inc-order" v-model="order" class="field h-9 w-auto text-sm sm:ml-auto">
            <option value="latest">Newest first</option>
            <option value="severity">High priority first</option>
        </select>
    </div>

    <LoadState :loading="incidents.loading.value" :error="incidents.error.value" :empty="!rows.length" :empty-text="search || type || severity ? 'No incidents match these filters.' : `No ${status === 'handling' ? 'incidents being handled' : status + ' incidents'}.`">
        <div class="grid items-start gap-4 lg:grid-cols-[360px_1fr]">
            <section class="card relative flex max-h-[calc(100dvh-230px)] flex-col overflow-hidden" aria-label="Incident list">
                <TableBusy :busy="incidents.busy.value" />
                <ul class="flex-1 overflow-y-auto">
                    <li v-for="i in rows" :key="i.id" class="border-b border-rule last:border-0">
                        <button class="relative flex w-full gap-3 px-5 py-3 text-left transition-colors" :class="selected?.id === i.id ? 'bg-[#f3f5f8]' : 'hover:bg-[#f8f9fb]'" :aria-current="selected?.id === i.id ? 'true' : null" @click="selectedId = i.id">
                            <span v-if="selected?.id === i.id" class="absolute inset-y-0 left-0 w-[3px] bg-navy"></span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-baseline justify-between gap-2"><b class="text-[14px] font-[650]">{{ typeLabel[i.type] }}</b><span class="text-[12.5px] whitespace-nowrap text-ink-2 tabular-nums">{{ dateTime(i.occurred_at) }}</span></span>
                                <span class="block truncate text-[13px] text-ink-2">{{ i.polling_unit ? `${i.polling_unit.code}, ${i.polling_unit.name}` : 'No unit' }}<template v-if="i.user?.code">, by {{ i.user.code }}</template></span>
                                <span class="mt-1.5 flex gap-1.5"><Badge :tone="sevTone[i.severity]">{{ i.severity }}</Badge><Badge v-if="i.status !== status" :tone="statusTone[i.status]">{{ i.status }}</Badge></span>
                            </span>
                        </button>
                    </li>
                </ul>
                <Pagination :meta="meta" noun="incidents" :per-page-options="[25, 50, 100]" :loading="incidents.loading.value" @page="go" @per-page="(n) => { perPage = n; go(1); }" />
            </section>

            <section v-if="selected" class="card p-5">
                <div class="flex flex-wrap items-center gap-1.5"><Badge :tone="sevTone[selected.severity]">{{ selected.severity }} priority</Badge><Badge :tone="statusTone[selected.status]">{{ selected.status }}</Badge></div>
                <h2 class="mt-2.5 text-[22px] leading-tight font-[700]">{{ typeLabel[selected.type] }}</h2>
                <p class="mt-1 text-[13.5px] text-ink-2">{{ selected.polling_unit ? `${selected.polling_unit.code}, ${selected.polling_unit.name}` : 'No unit' }}, reported by {{ selected.user?.name }} ({{ selected.user?.code }}), {{ dateTime(selected.occurred_at) }}</p>
                <div v-if="selected.media_paths?.length" class="mt-4 grid grid-cols-3 gap-2.5">
                    <div v-for="(m, k) in selected.media_paths" :key="k" class="flex h-24 flex-col items-center justify-center gap-1 rounded-lg bg-[#e6e9ee] text-[12.5px] text-ink-2"><Icon name="image" :size="20" />Photo {{ k + 1 }}</div>
                </div>
                <blockquote class="mt-4 border-l-[3px] border-navy py-1 pl-4 text-[15.5px] leading-relaxed">{{ selected.description }}</blockquote>
                <p v-if="selected.assigned_to" class="mt-3 text-sm"><span class="text-ink-2">Assigned to</span> <b class="font-[620]">{{ selected.assigned_to }}</b></p>

                <div class="mt-5 border-t border-rule pt-4">
                    <h3 class="mb-2 text-[14px] font-[650]">Updates</h3>
                    <p v-if="!selected.updates?.length" class="text-sm text-ink-2">No updates yet.</p>
                    <ol class="relative ml-1 border-l border-line">
                        <li v-for="(u, k) in selected.updates" :key="k" class="relative pb-3 pl-5 text-sm last:pb-0">
                            <span class="absolute top-1.5 -left-[4.5px] h-2 w-2 rounded-full bg-navy"></span>
                            <span class="block text-[12.5px] text-ink-2 tabular-nums">{{ dateTime(u.at) }}, {{ u.by }}</span>{{ u.text }}
                        </li>
                    </ol>
                </div>

                <template v-if="canEdit">
                    <div class="mt-5 grid gap-3 border-t border-rule pt-4 sm:grid-cols-[220px_1fr]">
                        <div>
                            <label class="label" for="assign">Assigned to</label>
                            <input id="assign" v-model="assign" class="field" placeholder="e.g. LGA I supervisor" />
                        </div>
                        <div>
                            <label class="label" for="note">Add an update</label>
                            <input id="note" v-model="note" class="field" placeholder="What happened next" />
                        </div>
                    </div>
                    <p v-if="error" role="alert" class="mt-3 rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <button class="btn-navy" :disabled="busy" @click="update({ status: 'handling', assigned_to: assign || null, note: note || null })">Save as being handled</button>
                        <button class="btn-good" :disabled="busy" @click="update({ status: 'resolved', note: note || null })">Mark resolved</button>
                    </div>
                </template>
            </section>
        </div>
    </LoadState>
</template>
