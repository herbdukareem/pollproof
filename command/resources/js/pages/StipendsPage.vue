<script setup>
import { computed, ref } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Badge from '../components/Badge.vue';
import Icon from '../components/Icon.vue';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { num } from '../lib/format';

const stipends = useResource(() => api.get('stipends'));
const d = computed(() => stipends.data.value);
const show = ref('hold');
const rows = computed(() => (d.value?.agents ?? []).filter((a) => (show.value === 'hold' ? !a.eligible : a.eligible)));
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
                <div class="flex gap-2 border-b border-[#eef0f3] p-3">
                    <button class="btn h-9" :class="show === 'hold' ? 'bg-navy text-white' : 'text-ink'" @click="show = 'hold'">On hold</button>
                    <button class="btn h-9" :class="show === 'eligible' ? 'bg-navy text-white' : 'text-ink'" @click="show = 'eligible'">Eligible</button>
                </div>
                <LoadState :empty="!rows.length" empty-text="Nobody in this list.">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[560px] text-sm">
                            <thead class="bg-mist"><tr><th class="th">Agent</th><th class="th">Unit</th><th class="th">Checked in</th><th class="th">Result</th><th class="th">Status</th></tr></thead>
                            <tbody>
                                <tr v-for="a in rows" :key="a.agent_id">
                                    <td class="td"><b>{{ a.agent_code }}</b> <span class="text-ink-2">· {{ a.name }}</span></td>
                                    <td class="td">{{ a.polling_unit }}</td>
                                    <td class="td">{{ a.checked_in ? 'Yes' : 'No' }}</td>
                                    <td class="td">{{ a.signed_result ? 'Yes' : 'No' }}</td>
                                    <td class="td"><Badge :tone="a.eligible ? 'good' : 'bad'">{{ a.eligible ? 'Eligible' : a.reason }}</Badge></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </LoadState>
            </section>
        </div>
    </LoadState>
</template>
