<script setup>
import { computed, ref, watch } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import Icon from '../components/Icon.vue';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { dateTime, titleCase } from '../lib/format';

const action = ref('');
const page = ref(1);
const logs = useResource(() => api.get('audit-logs', { action: action.value, page: page.value }));
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

    <div class="mb-4 flex flex-wrap gap-2">
        <button v-for="[value, text] in kinds" :key="value" class="btn h-9 border text-[13.5px]" :class="action === value ? 'border-navy bg-navy text-white' : 'border-[#d6dae1] bg-white text-ink'" @click="action = value">{{ text }}</button>
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
            <div v-if="meta && meta.last_page > 1" class="flex items-center justify-between border-t border-[#eef0f3] px-4 py-3 text-sm text-ink-2">
                <span>Page {{ meta.current_page }} of {{ meta.last_page }}</span>
                <div class="flex gap-2">
                    <button class="btn-outline h-9" :disabled="meta.current_page <= 1" @click="go(meta.current_page - 1)">Newer</button>
                    <button class="btn-outline h-9" :disabled="meta.current_page >= meta.last_page" @click="go(meta.current_page + 1)">Older</button>
                </div>
            </div>
        </LoadState>
    </section>
</template>
