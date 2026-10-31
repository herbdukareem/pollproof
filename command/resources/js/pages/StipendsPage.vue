<script setup>
import { computed, ref } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import Icon from '../components/Icon.vue';
import Pagination from '../components/Pagination.vue';
import SortTh from '../components/SortTh.vue';
import { sortRows, ts, useSortState } from '../lib/useSort';
import { usePaged } from '../lib/usePaged';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { num, timeOrDate } from '../lib/format';

const stipends = useResource(() => api.get('stipends'));
const d = computed(() => stipends.data.value);
const show = ref('hold');
const search = ref('');
const reason = ref('');
const reasons = computed(() => Object.keys(d.value?.hold_reasons ?? {}));
const matches = computed(() => (d.value?.agents ?? []).filter((a) =>
    (show.value === 'all' || (show.value === 'hold' ? !a.eligible : a.eligible))
    && (!reason.value || a.reason === reason.value)
    && (!search.value || `${a.agent_code} ${a.name} ${a.polling_unit}`.toLowerCase().includes(search.value.toLowerCase()))));
// Latest first: the most recent check-in or result at the top.
const sort = useSortState({
    latest: { label: 'latest activity', dir: 'desc', value: (a) => Math.max(ts(a.checked_in_at) ?? 0, ts(a.result_at) ?? 0) || null },
    code: { label: 'Agent', dir: 'asc', value: (a) => a.agent_code },
    unit: { label: 'Unit', dir: 'asc', value: (a) => a.polling_unit },
    checked: { label: 'Checked in', dir: 'desc', value: (a) => ts(a.checked_in_at) },
    result: { label: 'Result', dir: 'desc', value: (a) => ts(a.result_at) },
    status: { label: 'Status', dir: 'asc', value: (a) => (a.eligible ? 'Eligible' : a.reason) },
});
const sorted = computed(() => sortRows(matches.value, sort));
const paged = usePaged(sorted, { perPage: 25, resetOn: () => [show.value, search.value, reason.value, sort.key, sort.dir] });
const rows = paged.rows;
const total = computed(() => (d.value ? d.value.eligible + d.value.on_hold : 0));
</script>

<template>
    <PageHeader title="Stipends" subtitle="An agent is eligible only after a verified check-in and a result for their unit." :updated-at="stipends.updatedAt.value" :loading="stipends.loading.value" @refresh="stipends.load">
        <template #actions>
            <a :href="api.url('stipends/export')" class="btn-primary"><Icon name="download" :size="16" /> Export payment list</a>
        </template>
    </PageHeader>

    <LoadState :loading="stipends.loading.value" :error="stipends.error.value" :empty="!d">
        <div class="grid gap-5 xl:grid-cols-[360px_1fr]">
            <section class="card self-start p-5">
                <button type="button" class="-m-2 block w-[calc(100%+1rem)] rounded-xl p-2 text-left transition hover:bg-[#f6f7f9]" :aria-pressed="show === 'eligible'" title="Show eligible agents" @click="show = 'eligible'">
                    <div class="text-sm text-ink-2">Eligible now</div>
                    <div class="text-[34px] font-extrabold">{{ num(d.eligible) }} <span class="text-base font-semibold text-ink-2">of {{ num(total) }}</span></div>
                </button>
                <div class="mt-3 flex h-3 overflow-hidden rounded-full bg-[#e9ecf1]">
                    <div class="bg-good" :style="{ width: total ? (d.eligible / total) * 100 + '%' : 0 }"></div>
                    <div class="bg-action" :style="{ width: total ? (d.on_hold / total) * 100 + '%' : 0 }"></div>
                </div>
                <h2 class="mt-5 mb-2 text-sm font-bold"><button type="button" class="rounded-md underline-offset-2 hover:underline" :aria-pressed="show === 'hold'" @click="show = 'hold'">On hold · {{ num(d.on_hold) }}</button></h2>
                <ul>
                    <li v-for="(count, reason) in d.hold_reasons" :key="reason" class="flex justify-between border-t border-[#eef0f3] py-2 text-sm"><span class="text-ink-2">{{ reason }}</span><b>{{ num(count) }}</b></li>
                </ul>
                <p class="mt-4 text-xs text-ink-2">The export lists eligible agents only, and every export is recorded in the audit log. Payment goes through the campaign's own bank.</p>
            </section>

            <section class="card overflow-hidden">
                <div class="flex flex-wrap items-center gap-2 border-b border-[#eef0f3] p-3">
                    <div class="inline-flex rounded-xl border border-line p-1" role="tablist" aria-label="Show">
                        <button v-for="[v, text, n] in [['hold', 'On hold', d.on_hold], ['eligible', 'Eligible', d.eligible], ['all', 'All', total]]" :key="v" role="tab" :aria-selected="show === v" class="h-8 rounded-lg px-3 text-sm font-bold transition" :class="show === v ? 'bg-navy text-white' : 'text-ink-2 hover:text-ink'" @click="show = v">{{ text }} <span class="ml-1 text-xs opacity-70">{{ num(n) }}</span></button>
                    </div>
                    <select v-if="show !== 'eligible' && reasons.length" v-model="reason" class="field h-10 w-auto text-sm" aria-label="Reason on hold">
                        <option value="">Any reason</option>
                        <option v-for="x in reasons" :key="x" :value="x">{{ x }}</option>
                    </select>
                    <div class="relative ml-auto w-full max-w-[240px]">
                        <Icon name="search" :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" />
                        <input v-model="search" class="field h-10 w-full pl-9" placeholder="Agent ID, name or unit" aria-label="Search agents" />
                    </div>
                </div>
                <LoadState :empty="!rows.length" :empty-text="search || reason ? 'No agents match.' : 'Nobody in this list.'">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[560px] text-sm">
                            <thead class="bg-mist"><tr><SortTh :sort="sort" k="code">Agent</SortTh><SortTh :sort="sort" k="unit">Unit</SortTh><SortTh :sort="sort" k="checked">Checked in</SortTh><SortTh :sort="sort" k="result">Result</SortTh><SortTh :sort="sort" k="status">Status</SortTh></tr></thead>
                            <tbody>
                                <tr v-for="a in rows" :key="a.agent_id">
                                    <td class="td"><b>{{ a.agent_code }}</b> <span class="text-ink-2">· {{ a.name }}</span></td>
                                    <td class="td">{{ a.polling_unit }}</td>
                                    <td class="td whitespace-nowrap">{{ a.checked_in ? (a.checked_in_at ? timeOrDate(a.checked_in_at) : 'Yes') : 'No' }}</td>
                                    <td class="td whitespace-nowrap">{{ a.signed_result ? (a.result_at ? timeOrDate(a.result_at) : 'Yes') : 'No' }}</td>
                                    <td class="td"><Badge :tone="a.eligible ? 'good' : 'bad'">{{ a.eligible ? 'Eligible' : a.reason }}</Badge></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </LoadState>
                <Pagination :meta="paged.meta.value" :sort="sort" noun="agents" @page="paged.setPage" @per-page="paged.setPerPage" />
            </section>
        </div>
    </LoadState>
</template>
