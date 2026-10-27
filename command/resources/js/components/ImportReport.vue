<script setup>
import { computed } from 'vue';
import { dateTime, num } from '../lib/format';

// What an import did: the counts that matter plus the rows that were skipped, downloadable to fix and re-upload.
const props = defineProps({ report: Object, counts: Array });
const rejected = computed(() => props.report?.rejected ?? []);

function download() {
    const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const csv = ['line,name,phone,reason', ...rejected.value.map((r) => [r.line, r.name, r.phone, r.reason].map(esc).join(','))].join('\n');
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    const a = Object.assign(document.createElement('a'), { href: url, download: 'rejected-rows.csv' });
    a.click();
    URL.revokeObjectURL(url);
}
</script>

<template>
    <section v-if="report" class="card p-5" aria-live="polite">
        <h2 class="text-[15px] font-bold">Last upload</h2>
        <p class="mt-0.5 text-[13px] text-ink-2">{{ report.file }} · {{ dateTime(report.at) }}</p>
        <dl class="mt-3">
            <div v-for="[label, key, tone] in counts" :key="key" class="flex justify-between border-t border-[#eef0f3] py-2 text-sm">
                <dt class="text-ink-2">{{ label }}</dt>
                <dd class="font-bold" :class="tone">{{ num(report[key] ?? 0) }}</dd>
            </div>
            <div class="flex justify-between border-t border-[#eef0f3] py-2 text-sm">
                <dt class="text-ink-2">Skipped</dt>
                <dd class="font-bold" :class="rejected.length ? 'text-action' : ''">{{ num(rejected.length) }}</dd>
            </div>
        </dl>
        <template v-if="rejected.length">
            <ul class="mt-2 max-h-48 overflow-y-auto rounded-xl bg-bad-bg/60 p-3 text-[13px]">
                <li v-for="r in rejected.slice(0, 50)" :key="r.line" class="py-0.5"><b>Line {{ r.line }}</b> {{ r.name }}: {{ r.reason }}</li>
            </ul>
            <button class="btn-outline mt-3 w-full" @click="download">Download skipped rows</button>
        </template>
    </section>
</template>
