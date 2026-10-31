<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import TableBusy from '../components/TableBusy.vue';
import AlertList from '../components/AlertList.vue';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { hasRole } from '../lib/session';
import { num } from '../lib/format';

const route = useRoute();
const router = useRouter();
const q = route.query;
const filters = reactive({
    status: ['open', 'resolved', 'all'].includes(q.status) ? q.status : 'open',
    severity: q.severity ?? '',
    type: q.type ?? '',
    search: q.search ?? '',
    sort: q.sort === 'severity' ? 'severity' : 'latest',
    per_page: 25,
    page: Number(q.page) || 1,
});
const alerts = useResource(() => api.get('alerts', filters), { refreshMs: 30000 });
const busy = ref(null);

// Keep the filters in the address so a filtered view can be shared or bookmarked.
watch(
    () => ({ ...filters }),
    (f) => {
        const query = {};
        if (f.status !== 'open') query.status = f.status;
        for (const k of ['severity', 'type', 'search']) if (f[k]) query[k] = f[k];
        if (f.sort !== 'latest') query.sort = f.sort;
        if (f.page > 1) query.page = f.page;
        router.replace({ query });
    },
);
let debounce;
watch(() => [filters.status, filters.severity, filters.type, filters.sort], () => { filters.page = 1; alerts.load(); });
watch(() => filters.search, () => { clearTimeout(debounce); debounce = setTimeout(() => { filters.page = 1; alerts.load(); }, 300); });

const rows = computed(() => alerts.data.value?.data ?? []);
const meta = computed(() => alerts.data.value ?? {});
const counts = computed(() => meta.value.counts ?? { status: {}, severity: {}, types: [] });
const statuses = [
    { value: 'open', label: 'Open' },
    { value: 'resolved', label: 'Resolved' },
    { value: 'all', label: 'All' },
];
const severities = [
    { value: '', label: 'Any severity', dot: 'bg-ink-3' },
    { value: 'high', label: 'High', dot: 'bg-action' },
    { value: 'medium', label: 'Medium', dot: 'bg-[#d99a00]' },
    { value: 'low', label: 'Low', dot: 'bg-ink-3' },
];
const sevCount = (v) => (v ? counts.value.severity[v] ?? 0 : Object.values(counts.value.severity).reduce((a, b) => a + b, 0));

function go(page) {
    filters.page = page;
    alerts.load();
    document.getElementById('pp-main')?.scrollTo({ top: 0, behavior: 'smooth' });
}

async function resolve(alert) {
    busy.value = alert.id;
    try {
        await api.patch(`alerts/${alert.id}`, { status: 'resolved' });
        await alerts.load();
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <PageHeader
        title="Live alerts"
        :subtitle="counts.status.open != null ? `${num(counts.status.open)} open · ${num(counts.status.resolved)} resolved · raised automatically from results, collation, IReV and incidents` : ''"
        :updated-at="alerts.updatedAt.value"
        :loading="alerts.loading.value"
        @refresh="alerts.load()"
    />

    <div class="mb-4 flex flex-wrap items-center gap-2.5">
        <div role="tablist" aria-label="Status" class="inline-flex rounded-xl border border-line bg-white p-1">
            <button
                v-for="s in statuses"
                :key="s.value"
                role="tab"
                :aria-selected="filters.status === s.value"
                class="h-8 rounded-lg px-3.5 text-sm font-bold transition"
                :class="filters.status === s.value ? 'bg-navy text-white shadow-sm' : 'text-ink-2 hover:text-ink'"
                @click="filters.status = s.value"
            >
                {{ s.label }}<span class="ml-1.5 text-xs font-semibold opacity-70">{{ num(counts.status[s.value] ?? 0) }}</span>
            </button>
        </div>
        <div class="flex flex-wrap gap-2" role="group" aria-label="Severity">
            <button
                v-for="s in severities"
                :key="s.value"
                class="chip h-10"
                :class="filters.severity === s.value ? 'chip-on' : ''"
                :aria-pressed="filters.severity === s.value"
                @click="filters.severity = s.value"
            >
                <span class="h-2.5 w-2.5 rounded-[3px]" :class="filters.severity === s.value ? 'bg-white' : s.dot"></span>
                {{ s.label }} <span class="font-bold">{{ num(sevCount(s.value)) }}</span>
            </button>
        </div>
    </div>

    <section class="card min-w-0 overflow-hidden">
        <div class="flex flex-wrap gap-3 border-b border-[#eef0f3] p-4">
            <label class="sr-only" for="alert-search">Search alerts</label>
            <input id="alert-search" v-model="filters.search" class="field max-w-xs min-w-[200px] flex-1" placeholder="Search title, ward, unit code…" />
            <label class="sr-only" for="alert-type">Type</label>
            <select id="alert-type" v-model="filters.type" class="field max-w-[280px]">
                <option value="">All types</option>
                <option v-for="t in counts.types" :key="t.type" :value="t.type">{{ t.label }} ({{ t.count }})</option>
                <option v-if="filters.type && !counts.types.some((t) => t.type === filters.type)" :value="filters.type">{{ filters.type }} (0)</option>
            </select>
            <label class="sr-only" for="alert-sort">Order</label>
            <select id="alert-sort" v-model="filters.sort" class="field ml-auto max-w-[200px]">
                <option value="latest">Newest first</option>
                <option value="severity">High priority first</option>
            </select>
        </div>
        <LoadState :loading="alerts.loading.value" :error="alerts.error.value" :empty="!rows.length" :empty-text="filters.status === 'open' ? 'No open alerts match. All clear here.' : 'No alerts match.'">
            <div class="relative"><TableBusy :busy="alerts.busy.value" /><AlertList class="p-4" :alerts="rows" :can-resolve="hasRole('situation_room', 'dg', 'legal')" :busy="busy" @resolve="resolve" /></div>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[#eef0f3] px-4 py-3 text-[13px] text-ink-2">
                <span>Showing {{ num(meta.from) }}–{{ num(meta.to) }} of {{ num(meta.total) }}</span>
                <div v-if="meta.last_page > 1" class="flex items-center gap-2">
                    <span>Page {{ meta.current_page }} of {{ meta.last_page }}</span>
                    <button class="btn-ghost h-9" :disabled="meta.current_page <= 1" @click="go(meta.current_page - 1)">Previous</button>
                    <button class="btn-ghost h-9" :disabled="meta.current_page >= meta.last_page" @click="go(meta.current_page + 1)">Next</button>
                </div>
            </div>
        </LoadState>
    </section>
</template>
