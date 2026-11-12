<script setup>
import { computed, ref } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import Icon from '../components/Icon.vue';
import Pagination from '../components/Pagination.vue';
import TableBusy from '../components/TableBusy.vue';
import SortTh from '../components/SortTh.vue';
import { sortRows, ts, useSortState } from '../lib/useSort';
import { usePaged } from '../lib/usePaged';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { num, timeOrDate } from '../lib/format';

// Latest first: the most recent check-in or result at the top.
const sort = useSortState({
    latest: { label: 'latest activity', dir: 'desc', value: (a) => a.activity },
    code: { label: 'Agent', dir: 'asc', value: (a) => a.agent_code },
    unit: { label: 'Unit', dir: 'asc', value: (a) => a.polling_unit },
    checked: { label: 'Checked in', dir: 'desc', value: (a) => ts(a.checked_in_at) },
    result: { label: 'Result', dir: 'desc', value: (a) => ts(a.result_at) },
    status: { label: 'Status', dir: 'asc', value: (a) => (a.eligible ? 'Eligible' : a.reason) },
}, undefined, { onChange: () => stipends.load() });

const stipends = useResource(() => api.get('stipends', sort.params));
const d = computed(() => stipends.data.value);
const show = ref('hold');
const search = ref('');
const reason = ref('');
const reasons = computed(() => Object.keys(d.value?.hold_reasons ?? {}));
const matches = computed(() => (d.value?.agents ?? []).filter((a) =>
    (show.value === 'all' || (show.value === 'hold' ? !a.eligible : a.eligible))
    && (!reason.value || a.reason === reason.value)
    && (!search.value || `${a.agent_code} ${a.name} ${a.polling_unit}`.toLowerCase().includes(search.value.toLowerCase()))));
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
        <div class="grid items-start gap-4 xl:grid-cols-[340px_minmax(0,1fr)]">
            <section class="card p-5">
                <button type="button" class="-m-2 block w-[calc(100%+1rem)] rounded-lg p-2 text-left transition-colors hover:bg-[#f6f7f9]" :aria-pressed="show === 'eligible'" title="Show eligible agents" @click="show = 'eligible'">
                    <div class="text-[13px] text-ink-2">Eligible now</div>
                    <div class="fig text-[44px] leading-none font-[700]">{{ num(d.eligible) }} <span class="text-[15px] font-[500] text-ink-2">of {{ num(total) }} agents</span></div>
                </button>
                <div class="mt-4 flex h-2 gap-px overflow-hidden rounded-[2px] bg-[#e8ebf0]">
                    <div class="bg-good" :style="{ width: total ? (d.eligible / total) * 100 + '%' : 0 }"></div>
                    <div class="bg-orange" :style="{ width: total ? (d.on_hold / total) * 100 + '%' : 0 }"></div>
                </div>
                <h2 class="mt-5 mb-1 flex items-baseline justify-between text-[14px] font-[650]"><button type="button" class="underline-offset-2 hover:underline" :aria-pressed="show === 'hold'" @click="show = 'hold'">On hold</button><span class="fig text-[17px] text-action">{{ num(d.on_hold) }}</span></h2>
                <ul>
                    <li v-for="(count, reason) in d.hold_reasons" :key="reason" class="flex justify-between gap-3 border-t border-rule py-2 text-sm"><span class="text-ink-2">{{ reason }}</span><span class="fig font-[650]">{{ num(count) }}</span></li>
                </ul>
                <p class="mt-4 text-[12.5px] text-ink-2">The export lists eligible agents only, and every export is recorded in the audit log. Payment goes through the campaign's own bank.</p>
            </section>

            <section class="card overflow-hidden">
                <div class="flex flex-wrap items-center gap-2 border-b border-rule px-4 py-3">
                    <div class="seg" role="group" aria-label="Show">
                        <button v-for="[v, text, n] in [['hold', 'On hold', d.on_hold], ['eligible', 'Eligible', d.eligible], ['all', 'All', total]]" :key="v" type="button" :aria-pressed="show === v" class="seg-btn" :class="show === v && 'seg-on'" @click="show = v">{{ text }} <span class="ml-0.5 tabular-nums opacity-70">{{ num(n) }}</span></button>
                    </div>
                    <select v-if="show !== 'eligible' && reasons.length" v-model="reason" class="field h-9 w-auto text-sm" aria-label="Reason on hold">
                        <option value="">Any reason</option>
                        <option v-for="x in reasons" :key="x" :value="x">{{ x }}</option>
                    </select>
                    <div class="relative w-full sm:ml-auto sm:w-[240px]">
                        <Icon name="search" :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" />
                        <input v-model="search" class="field h-9 w-full pl-9" placeholder="Agent ID, name or unit" aria-label="Search agents" />
                    </div>
                </div>
                <LoadState :empty="!rows.length" :empty-text="search || reason ? 'No agents match.' : 'Nobody in this list.'">
                    <div class="relative overflow-x-auto">
                        <TableBusy :busy="stipends.busy.value" />
                        <table class="w-full min-w-[560px] text-sm">
                            <thead><tr><SortTh :sort="sort" k="code">Agent</SortTh><SortTh :sort="sort" k="unit">Unit</SortTh><SortTh :sort="sort" k="checked">Checked in</SortTh><SortTh :sort="sort" k="result">Result</SortTh><SortTh :sort="sort" k="status">Status</SortTh></tr></thead>
                            <tbody>
                                <tr v-for="a in rows" :key="a.agent_id">
                                    <td class="td"><div class="fig font-[650] whitespace-nowrap">{{ a.agent_code }}</div><div class="text-[13px] text-ink-2">{{ a.name }}</div></td>
                                    <td class="td fig whitespace-nowrap">{{ a.polling_unit }}</td>
                                    <td class="td whitespace-nowrap tabular-nums">{{ a.checked_in ? (a.checked_in_at ? timeOrDate(a.checked_in_at) : 'Yes') : 'No' }}</td>
                                    <td class="td whitespace-nowrap tabular-nums">{{ a.signed_result ? (a.result_at ? timeOrDate(a.result_at) : 'Yes') : 'No' }}</td>
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
