<script setup>
import { ref } from 'vue';
import Icon from './Icon.vue';
import { api } from '../lib/api';

// Upload a CSV to an import endpoint and report what happened, including rows that were skipped.
const props = defineProps({
    endpoint: { type: String, required: true },
    label: { type: String, default: 'Upload CSV' },
    columns: { type: String, required: true },
    disabled: Boolean,
    tone: { type: String, default: 'primary' },
});
const emit = defineEmits(['done']);
const input = ref(null);
const busy = ref(false);
const error = ref('');

async function pick(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    busy.value = true;
    error.value = '';
    const fd = new FormData();
    fd.append('file', file);
    try {
        const summary = await api.post(props.endpoint, fd);
        emit('done', { ...summary, file: file.name, at: new Date() });
    } catch (err) {
        error.value = err.firstError();
    } finally {
        busy.value = false;
        e.target.value = '';
    }
}
</script>

<template>
    <span class="inline-flex flex-col items-end gap-1">
        <input ref="input" type="file" accept=".csv,text/csv" class="sr-only" :aria-label="label" @change="pick" />
        <button :class="tone === 'primary' ? 'btn-primary' : 'btn-outline'" :disabled="busy || disabled" :title="`Columns: ${columns}`" @click="input.click()">
            <Icon name="upload" :size="16" /> {{ busy ? 'Uploading…' : label }}
        </button>
        <span v-if="error" role="alert" class="max-w-xs text-right text-xs text-bad">{{ error }}</span>
    </span>
</template>
