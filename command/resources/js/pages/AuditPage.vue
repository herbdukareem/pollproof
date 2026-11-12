<script setup>
import { computed, ref, watch } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import Icon from '../components/Icon.vue';
import Tabs from '../components/Tabs.vue';
import Pagination from '../components/Pagination.vue';
import TableBusy from '../components/TableBusy.vue';
import SortTh from '../components/SortTh.vue';
import { useSortState } from '../lib/useSort';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { dateTime, titleCase } from '../lib/format';

const action = ref('');
const page = ref(1);
const perPage = ref(50);
const search = ref('');
const sort = useSortState({
    time: { label: 'Time', dir: 'desc' },
    user: { label: 'User', dir: 'asc' },
    action: { label: 'Action', dir: 'asc' },
    ip: { label: 'IP', dir: 'asc' },
}, { key: 'time', dir: 'desc' }, { mode: 'server' });
const logs = useResource(() => api.get('audit-logs', { action: action.value, search: search.value, page: page.value, per_page: perPage.value, ...sort.params }));
watch(() => [sort.key, sort.dir], () => { page.value = 1; logs.load(); });
let t;
watch(search, () => { clearTimeout(t); t = setTimeout(() => { page.value = 1; logs.load(); }, 300); });
const check = useResource(() => api.get('audit-logs/verify'));
watch(action, () => { page.value = 1; logs.load(); });

const rows = computed(() => logs.data.value?.data ?? []);
const meta = computed(() => logs.data.value);
const kinds = [['', 'All'], ['result', 'Results'], ['collation', 'Collation'], ['irev', 'IReV'], ['auth', 'Sign-ins'], ['stipends', 'Exports'], ['agent', 'Assignments']];
const kindTabs = kinds.map(([value, label]) => ({ value, label }));
// 'result.approved' reads as 'Result approved'.
const actionText = (a) => a.replace('.', ' ').replace(/_/g, ' ');
const tone = (a) => (a.includes('failed') ? 'bad' : a.includes('export') ? 'info' : a.includes('approve') ? 'good' : 'neutral');

function go(p) {
    page.value = p;
    logs.load();
}
</script>

<template>
    <PageHeader title="Audit log" subtitle="Every sign-in, edit, approval and export. Entries are chained, so a change to any one breaks the chain." :updated-at="logs.updatedAt.value" :loading="logs.loading.value" @refresh="() => { logs.load(); check.load(); }" />

    <div v-if="check.data.value" class="mb-4 flex flex-wrap items-center gap-3 rounded-[10px] px-5 py-3" :class="check.data.value.valid ? 'bg-good-bg text-good' : 'bg-bad-bg text-bad'">
        <Icon name="shield" :size="19" />
        <p class="text-sm">
            <b class="font-[650]">{{ check.data.value.valid ? 'Log intact.' : 'Chain broken.' }}</b>
            {{ check.data.value.valid ? `${check.data.value.checked} entries checked; none has been altered or removed.` : `Entry #${check.data.value.broken_at} does not match the chain. Treat everything from that point as suspect.` }}
        </p>
    </div>

    <Tabs v-model="action" label="Kind of entry" :tabs="kindTabs" />

    <section class="card overflow-hidden">
        <div class="border-b border-rule px-4 py-3">
            <div class="relative w-full sm:w-[300px]">
                <Icon name="search" :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" />
                <label class="sr-only" for="audit-search">Search the log</label>
                <input id="audit-search" v-model="search" class="field h-9 w-full pl-9" placeholder="Person, agent ID, action or IP" />
            </div>
        </div>
        <LoadState :loading="logs.loading.value" :error="logs.error.value" :empty="!rows.length" empty-text="No entries.">
            <div class="relative overflow-x-auto">
                <TableBusy :busy="logs.busy.value" />
                <table class="w-full min-w-[760px] text-sm">
                    <thead><tr><SortTh :sort="sort" k="time">Time</SortTh><SortTh :sort="sort" k="user">User</SortTh><SortTh :sort="sort" k="action">Action</SortTh><th class="th">Detail</th><SortTh :sort="sort" k="ip">IP</SortTh></tr></thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.id">
                            <td class="td whitespace-nowrap text-ink-2 tabular-nums">{{ dateTime(r.created_at) }}</td>
                            <td class="td"><template v-if="r.user"><div class="font-[600]">{{ r.user.name }}</div><div class="text-[12.5px] text-ink-2">{{ r.user.code ?? titleCase(r.user.role) }}</div></template><span v-else class="text-ink-2">System</span></td>
                            <td class="td"><Badge :tone="tone(r.action)">{{ actionText(r.action) }}</Badge></td>
                            <td class="td max-w-md truncate text-xs text-ink-2">{{ r.subject_type ? `${r.subject_type.split('\\').pop()} #${r.subject_id} ` : '' }}{{ r.meta && Object.keys(r.meta).length ? JSON.stringify(r.meta) : '' }}</td>
                            <td class="td text-ink-2 tabular-nums">{{ r.ip ?? '–' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :meta="meta" :sort="sort" noun="entries" :per-page-options="[50, 100, 200]" :loading="logs.loading.value" @page="go" @per-page="(n) => { perPage = n; go(1); }" />
        </LoadState>
    </section>
</template>
