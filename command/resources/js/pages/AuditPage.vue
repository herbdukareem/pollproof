<script setup>
import { computed, ref, watch } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import Icon from '../components/Icon.vue';
import Pagination from '../components/Pagination.vue';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { dateTime, titleCase } from '../lib/format';

const action = ref('');
const page = ref(1);
const perPage = ref(50);
const search = ref('');
const logs = useResource(() => api.get('audit-logs', { action: action.value, search: search.value, page: page.value, per_page: perPage.value }));
let t;
watch(search, () => { clearTimeout(t); t = setTimeout(() => { page.value = 1; logs.load(); }, 300); });
const check = useResource(() => api.get('audit-logs/verify'));
watch(action, () => { page.value = 1; logs.load(); });

const rows = computed(() => logs.data.value?.data ?? []);
const meta = computed(() => logs.data.value);
const kinds = [['', 'All'], ['result', 'Results'], ['collation', 'Collation'], ['irev', 'IReV'], ['auth', 'Sign-ins'], ['stipends', 'Exports'], ['agent', 'Assignments']];
const tone = (a) => (a.includes('failed') ? 'bad' : a.includes('export') ? 'info' : a.includes('approve') ? 'good' : 'neutral');

function go(p) {
    page.value = p;
    logs.load();
}
</script>

<template>
    <PageHeader title="Audit log" subtitle="Every sign-in, edit, approval and export. Entries are chained, so a change to any one breaks the chain." :updated-at="logs.updatedAt.value" :loading="logs.loading.value" @refresh="() => { logs.load(); check.load(); }" />

    <div v-if="check.data.value" class="mb-5 flex flex-wrap items-center gap-3 rounded-2xl px-5 py-4" :class="check.data.value.valid ? 'bg-good-bg text-good' : 'bg-bad-bg text-bad'">
        <Icon name="shield" :size="22" />
        <p class="text-sm">
            <b>{{ check.data.value.valid ? 'Log intact.' : 'Chain broken.' }}</b>
            {{ check.data.value.valid ? `${check.data.value.checked} entries checked; none has been altered or removed.` : `Entry #${check.data.value.broken_at} does not match the chain. Treat everything from that point as suspect.` }}
        </p>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <button v-for="[value, text] in kinds" :key="value" class="chip h-9 text-[13.5px]" :class="action === value ? 'chip-on' : ''" :aria-pressed="action === value" @click="action = value">{{ text }}</button>
        <div class="relative ml-auto w-full max-w-xs">
            <Icon name="search" :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" />
            <label class="sr-only" for="audit-search">Search the log</label>
            <input id="audit-search" v-model="search" class="field w-full pl-9" placeholder="Person, agent ID, action or IP" />
        </div>
    </div>

    <section class="card overflow-hidden">
        <LoadState :loading="logs.loading.value" :error="logs.error.value" :empty="!rows.length" empty-text="No entries.">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="bg-mist"><tr><th class="th">Time</th><th class="th">User</th><th class="th">Action</th><th class="th">Detail</th><th class="th">IP</th></tr></thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.id">
                            <td class="td whitespace-nowrap text-ink-2">{{ dateTime(r.created_at) }}</td>
                            <td class="td"><template v-if="r.user"><div class="font-semibold">{{ r.user.name }}</div><div class="text-xs text-ink-2">{{ r.user.code ?? titleCase(r.user.role) }}</div></template><span v-else class="text-ink-2">System</span></td>
                            <td class="td"><Badge :tone="tone(r.action)">{{ r.action.replace('.', ' · ').replace(/_/g, ' ') }}</Badge></td>
                            <td class="td max-w-md truncate font-mono text-xs text-ink-2">{{ r.subject_type ? `${r.subject_type.split('\\').pop()} #${r.subject_id} ` : '' }}{{ r.meta && Object.keys(r.meta).length ? JSON.stringify(r.meta) : '' }}</td>
                            <td class="td text-ink-2">{{ r.ip ?? '–' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :meta="meta" noun="entries" :per-page-options="[50, 100, 200]" :loading="logs.loading.value" @page="go" @per-page="(n) => { perPage = n; go(1); }" />
        </LoadState>
    </section>
</template>
