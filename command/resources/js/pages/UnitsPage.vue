<script setup>
import { computed, reactive, watch } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { hasRole } from '../lib/session';
import { num, time } from '../lib/format';

const filters = reactive({ status: '', lga_id: '', search: '', page: 1 });
const structure = useResource(() => api.get('structure'));
const units = useResource(() => api.get('units', filters), { refreshMs: 60000 });

let debounce;
watch(() => [filters.status, filters.lga_id], () => { filters.page = 1; units.load(); });
watch(() => filters.search, () => { clearTimeout(debounce); debounce = setTimeout(() => { filters.page = 1; units.load(); }, 300); });

const rows = computed(() => units.data.value?.data ?? []);
const meta = computed(() => units.data.value);
const tabs = [
    { value: '', label: 'All units' },
    { value: 'reported', label: 'Reported' },
    { value: 'awaiting', label: 'Awaiting result' },
    { value: 'no_agent', label: 'No agent' },
    { value: 'flagged', label: 'Flagged' },
];
const canSeeEvidence = hasRole('legal', 'dg');

function flag(u) {
    if (u.result?.flags?.includes('overvote')) return ['bad', 'Overvote'];
    if (!u.agent) return ['bad', 'No agent'];
    if (!u.agent_checked_in) return ['warn', 'Not checked in'];
    if (!u.result) return ['neutral', 'Awaiting'];
    if (u.result.status === 'sent_back') return ['warn', 'Sent back'];
    return ['good', u.result.status === 'approved' ? 'Verified' : 'Reported'];
}

function go(page) {
    filters.page = page;
    units.load();
}
</script>

<template>
    <PageHeader title="Units" :subtitle="meta ? `${num(meta.total)} polling units` : ''" :updated-at="units.updatedAt.value" :loading="units.loading.value" @refresh="units.load" />

    <div class="mb-4 flex flex-wrap gap-2" role="group" aria-label="Status">
        <button
            v-for="tab in tabs"
            :key="tab.value"
            class="btn h-9 border text-[13.5px]"
            :class="filters.status === tab.value ? 'border-navy bg-navy text-white' : 'border-[#d6dae1] bg-white text-ink'"
            :aria-pressed="filters.status === tab.value"
            @click="filters.status = tab.value"
        >{{ tab.label }}</button>
    </div>

    <section class="card overflow-hidden">
        <div class="flex flex-wrap gap-3 border-b border-[#eef0f3] p-4">
            <label class="sr-only" for="unit-search">Search units</label>
            <input id="unit-search" v-model="filters.search" class="field max-w-xs" placeholder="Search unit code or name" />
            <label class="sr-only" for="lga">LGA</label>
            <select id="lga" v-model="filters.lga_id" class="field max-w-[220px]">
                <option value="">All LGAs</option>
                <option v-for="l in structure.data.value?.lgas ?? []" :key="l.id" :value="l.id">{{ l.name }}</option>
            </select>
        </div>
        <LoadState :loading="units.loading.value" :error="units.error.value" :empty="!rows.length" empty-text="No units match.">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="bg-mist"><tr><th class="th">Unit</th><th class="th">Agent</th><th class="th">Our result</th><th class="th">Status</th><th class="th"></th></tr></thead>
                    <tbody>
                        <tr v-for="u in rows" :key="u.id">
                            <td class="td"><div class="font-bold">{{ u.code }}</div><div class="text-xs text-ink-2">{{ u.name }} · {{ u.ward }} · {{ u.lga }}</div></td>
                            <td class="td">
                                <template v-if="u.agent">{{ u.agent.code }} <span class="text-ink-2">· {{ u.agent_checked_in ? 'checked in' : 'not checked in' }}</span></template>
                                <span v-else class="font-semibold text-action">No agent</span>
                            </td>
                            <td class="td">
                                <template v-if="u.result">{{ num(u.result.total_cast) }} cast · <span class="text-ink-2">{{ time(u.result.captured_at) }}</span></template>
                                <span v-else class="text-ink-2">–</span>
                            </td>
                            <td class="td"><Badge :tone="flag(u)[0]">{{ flag(u)[1] }}</Badge></td>
                            <td class="td text-right"><RouterLink v-if="canSeeEvidence" :to="`/units/${u.id}/evidence`" class="text-[13px] font-bold">Evidence</RouterLink></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="meta && meta.last_page > 1" class="flex items-center justify-between border-t border-[#eef0f3] px-4 py-3 text-sm text-ink-2">
                <span>Page {{ meta.current_page }} of {{ meta.last_page }}</span>
                <div class="flex gap-2">
                    <button class="btn-outline h-9" :disabled="meta.current_page <= 1" @click="go(meta.current_page - 1)">Previous</button>
                    <button class="btn-outline h-9" :disabled="meta.current_page >= meta.last_page" @click="go(meta.current_page + 1)">Next</button>
                </div>
            </div>
        </LoadState>
    </section>
</template>
