<script setup>
import { ref, watch } from 'vue';
import Icon from './Icon.vue';
import Modal from './Modal.vue';
import { api } from '../lib/api';
import { num } from '../lib/format';

/**
 * Upload a list in three steps: download the template (already listing every LGA, ward or unit,
 * with whoever holds each post now), fill it in, drop it here. The result shows in the same window,
 * with the rows that were skipped and why.
 */
const props = defineProps({
    open: Boolean,
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    endpoint: { type: String, required: true },
    template: { type: String, required: true }, // API path of the template download
    templateName: { type: String, default: 'template.csv' },
    columns: { type: Array, default: () => [] }, // [[column, what to put in it, required?]]
    tips: { type: Array, default: () => [] },
    counts: { type: Array, default: () => [] }, // [[label, key]] from the import summary
});
const emit = defineEmits(['close', 'done']);
const file = ref(null);
const busy = ref(false);
const error = ref('');
const report = ref(null);
const dragging = ref(false);
const picker = ref(null);

watch(() => props.open, (o) => {
    if (!o) return;
    file.value = null;
    error.value = '';
    report.value = null;
});

function take(f) {
    if (!f) return;
    if (!/\.csv$/i.test(f.name) && f.type !== 'text/csv') {
        error.value = 'Choose the .csv file. In Excel use File → Save As → CSV UTF-8.';
        return;
    }
    error.value = '';
    report.value = null;
    file.value = f;
}
function drop(e) {
    dragging.value = false;
    take(e.dataTransfer?.files?.[0]);
}

async function upload() {
    if (!file.value) return;
    busy.value = true;
    error.value = '';
    const fd = new FormData();
    fd.append('file', file.value);
    try {
        report.value = { ...(await api.post(props.endpoint, fd)), file: file.value.name, at: new Date() };
        emit('done', report.value);
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = false;
    }
}

function downloadSkipped() {
    const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const csv = ['line,name,phone,reason', ...report.value.rejected.map((r) => [r.line, r.name, r.phone, r.reason].map(esc).join(','))].join('\n');
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    Object.assign(document.createElement('a'), { href: url, download: 'skipped-rows.csv' }).click();
    URL.revokeObjectURL(url);
}
</script>

<template>
    <Modal :open="open" :title="title" :subtitle="subtitle" width="max-w-2xl" :busy="busy" @close="emit('close')">
        <ol class="flex flex-col gap-4">
            <li class="flex gap-3">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-navy text-xs font-bold text-white">1</span>
                <div class="min-w-0 flex-1">
                    <b class="text-sm">Download the template</b>
                    <p class="mt-0.5 text-[13px] text-ink-2">It already lists every post, with whoever holds it now. You only fill in names and phone numbers.</p>
                    <a :href="api.url(template)" :download="templateName" class="btn-outline mt-2 h-9"><Icon name="download" :size="15" /> Download template (.csv)</a>
                </div>
            </li>
            <li class="flex gap-3">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-navy text-xs font-bold text-white">2</span>
                <div class="min-w-0 flex-1">
                    <b class="text-sm">Fill it in, in Excel or Google Sheets</b>
                    <div class="mt-2 overflow-hidden rounded-xl border border-line">
                        <table class="w-full text-[12.5px]">
                            <tbody>
                                <tr v-for="[col, what, req] in columns" :key="col" class="border-b border-[#eef0f3] last:border-0">
                                    <td class="w-32 bg-mist px-3 py-1.5 font-mono font-semibold whitespace-nowrap">{{ col }}<span v-if="req" class="text-action">*</span></td>
                                    <td class="px-3 py-1.5 text-ink-2">{{ what }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <ul v-if="tips.length" class="mt-2 list-disc space-y-0.5 pl-5 text-[12.5px] text-ink-2">
                        <li v-for="t in tips" :key="t">{{ t }}</li>
                    </ul>
                </div>
            </li>
            <li class="flex gap-3">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-navy text-xs font-bold text-white">3</span>
                <div class="min-w-0 flex-1">
                    <b class="text-sm">Upload it</b>
                    <input ref="picker" type="file" accept=".csv,text/csv" class="sr-only" aria-label="Choose CSV file" @change="take($event.target.files?.[0]); $event.target.value = ''" />
                    <button
                        type="button"
                        class="mt-2 flex w-full flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed px-4 py-6 text-center transition"
                        :class="dragging ? 'border-navy bg-[#eef2fb]' : file ? 'border-good/50 bg-good-bg/50' : 'border-line hover:border-navy/50 hover:bg-mist'"
                        @click="picker.click()"
                        @dragover.prevent="dragging = true"
                        @dragleave="dragging = false"
                        @drop.prevent="drop"
                    >
                        <Icon :name="file ? 'check' : 'upload'" :size="22" :class="file ? 'text-good' : 'text-ink-2'" />
                        <span class="text-sm font-semibold">{{ file ? file.name : 'Drop the file here, or click to choose' }}</span>
                        <span class="text-xs text-ink-3">{{ file ? `${(file.size / 1024).toFixed(0)} KB · click to choose another` : 'CSV file' }}</span>
                    </button>
                </div>
            </li>
        </ol>

        <p v-if="error" role="alert" class="mt-4 rounded-xl bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>

        <section v-if="report" class="mt-4 rounded-xl border border-line p-4" aria-live="polite">
            <b class="text-sm" :class="report.rejected?.length ? '' : 'text-good'">{{ report.rejected?.length ? 'Uploaded, with some rows skipped' : 'Uploaded' }}</b>
            <dl class="mt-2 grid grid-cols-2 gap-x-6 sm:grid-cols-3">
                <div v-for="[label, key] in counts" :key="key" class="flex justify-between border-t border-[#eef0f3] py-1.5 text-[13px]">
                    <dt class="text-ink-2">{{ label }}</dt><dd class="font-bold">{{ num(report[key] ?? 0) }}</dd>
                </div>
                <div class="flex justify-between border-t border-[#eef0f3] py-1.5 text-[13px]"><dt class="text-ink-2">Left empty</dt><dd class="font-bold">{{ num(report.blank ?? 0) }}</dd></div>
                <div class="flex justify-between border-t border-[#eef0f3] py-1.5 text-[13px]"><dt class="text-ink-2">Skipped</dt><dd class="font-bold" :class="report.rejected?.length ? 'text-action' : ''">{{ num(report.rejected?.length ?? 0) }}</dd></div>
            </dl>
            <template v-if="report.rejected?.length">
                <ul class="mt-2 max-h-40 overflow-y-auto rounded-lg bg-bad-bg/60 p-2.5 text-[12.5px]">
                    <li v-for="r in report.rejected.slice(0, 100)" :key="r.line" class="py-0.5"><b>Line {{ r.line }}</b> {{ r.name }}: {{ r.reason }}</li>
                </ul>
                <button class="btn-outline mt-2 h-9" @click="downloadSkipped"><Icon name="download" :size="15" /> Download skipped rows</button>
            </template>
        </section>

        <template #footer>
            <button class="btn-outline" :disabled="busy" @click="emit('close')">{{ report ? 'Done' : 'Cancel' }}</button>
            <button class="btn-primary" :disabled="!file || busy" @click="upload"><Icon name="upload" :size="16" /> {{ busy ? 'Uploading…' : report ? 'Upload again' : 'Upload' }}</button>
        </template>
    </Modal>
</template>
