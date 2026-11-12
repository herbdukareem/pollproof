<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import TableBusy from '../components/TableBusy.vue';
import AlertList from '../components/AlertList.vue';
import Tabs from '../components/Tabs.vue';
import Pagination from '../components/Pagination.vue';
import Icon from '../components/Icon.vue';
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
const statusTabs = computed(() => statuses.map((s) => ({ ...s, count: counts.value.status[s.value] ?? 0, act: s.value === 'open' })));
const severities = [
    { value: '', label: 'Any' },
    { value: 'high', label: 'High' },
    { value: 'medium', label: 'Medium' },
    { value: 'low', label: 'Low' },
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
        subtitle="Raised automatically from results, collation, IReV and incidents."
        :updated-at="alerts.updatedAt.value"
        :loading="alerts.loading.value"
        @refresh="alerts.load()"
    />

    <Tabs v-model="filters.status" label="Status" :tabs="statusTabs" />

    <section class="card min-w-0 overflow-hidden">
        <div class="flex flex-wrap items-center gap-2 border-b border-rule px-4 py-3">
            <div class="relative w-full sm:w-[260px]">
                <Icon name="search" :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" />
                <label class="sr-only" for="alert-search">Search alerts</label>
                <input id="alert-search" v-model="filters.search" class="field h-9 w-full pl-9" placeholder="Title, ward or unit code" />
            </div>
            <label class="sr-only" for="alert-type">Type</label>
            <select id="alert-type" v-model="filters.type" class="field h-9 w-auto max-w-[260px]">
                <option value="">All types</option>
                <option v-for="t in counts.types" :key="t.type" :value="t.type">{{ t.label }} ({{ t.count }})</option>
                <option v-if="filters.type && !counts.types.some((t) => t.type === filters.type)" :value="filters.type">{{ filters.type }} (0)</option>
            </select>
            <div class="seg" role="group" aria-label="Priority">
                <button v-for="s in severities" :key="s.value" type="button" class="seg-btn" :class="filters.severity === s.value && 'seg-on'" :aria-pressed="filters.severity === s.value" @click="filters.severity = s.value">
                    {{ s.label }}<span class="ml-1 tabular-nums opacity-70">{{ num(sevCount(s.value)) }}</span>
                </button>
            </div>
            <label class="sr-only" for="alert-sort">Order</label>
            <select id="alert-sort" v-model="filters.sort" class="field h-9 w-auto sm:ml-auto">
                <option value="latest">Newest first</option>
                <option value="severity">High priority first</option>
            </select>
        </div>
        <LoadState :loading="alerts.loading.value" :error="alerts.error.value" :empty="!rows.length" :empty-text="filters.status === 'open' ? 'No open alerts match. All clear here.' : 'No alerts match.'">
            <div class="relative"><TableBusy :busy="alerts.busy.value" /><AlertList class="px-5 py-1" :alerts="rows" :can-resolve="hasRole('situation_room', 'dg', 'legal')" :busy="busy" @resolve="resolve" /></div>
            <Pagination :meta="meta" noun="alerts" :per-page-options="[25]" :loading="alerts.loading.value" @page="go" />
        </LoadState>
    </section>
</template>
