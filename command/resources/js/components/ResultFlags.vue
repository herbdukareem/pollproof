<script setup>
import { computed, reactive, ref, watch } from 'vue';
import Badge from './Badge.vue';
import Icon from './Icon.vue';
import Modal from './Modal.vue';
import { api } from '../lib/api';
import { dateTime } from '../lib/format';

/**
 * Reviewer flags on a polling unit's results: raise one with a reason and comment, discuss it, clear it.
 * The signed result is never changed by any of this. `resultId` is the result being looked at;
 * flags on earlier versions of the same unit's result are shown too, marked as such.
 * Exposes open() so a parent's "Flag" button can start a new flag.
 */
const props = defineProps({ resultId: { type: Number, required: true }, readOnly: Boolean, compact: Boolean });
const emit = defineEmits(['changed']);

const flags = ref([]);
const reasons = ref([]);
const loading = ref(false);
const busy = ref('');
const error = ref('');
const dialog = reactive({ open: false, reason: '', note: '' });
const clearing = reactive({ flag: null, note: '' });
const replies = reactive({});
const shown = reactive({});

const openFlags = computed(() => flags.value.filter((f) => f.status === 'open'));
const sevTone = { high: 'bad', medium: 'warn' };

async function load() {
    loading.value = true;
    try {
        const res = await api.get(`results/${props.resultId}/flags`);
        flags.value = res.data;
        reasons.value = res.reasons;
    } catch {
        flags.value = [];
    } finally {
        loading.value = false;
    }
}
watch(() => props.resultId, load, { immediate: true });

async function run(key, fn) {
    busy.value = key;
    error.value = '';
    try {
        await fn();
        await load();
        emit('changed', openFlags.value.length);
        return true;
    } catch (e) {
        error.value = e.firstError?.() ?? e.message;
        return false;
    } finally {
        busy.value = '';
    }
}

function open() {
    Object.assign(dialog, { open: true, reason: '', note: '' });
    error.value = '';
}
defineExpose({ open, count: () => openFlags.value.length });

const submit = async () => {
    if (await run('new', () => api.post(`results/${props.resultId}/flags`, { reason: dialog.reason, note: dialog.note }))) dialog.open = false;
};
const reply = (f) => run(`c${f.id}`, async () => {
    await api.post(`result-flags/${f.id}/comments`, { body: replies[f.id] });
    replies[f.id] = '';
});
const clear = async () => {
    if (await run(`x${clearing.flag.id}`, () => api.patch(`result-flags/${clearing.flag.id}`, { status: 'cleared', note: clearing.note }))) clearing.flag = null;
};
const reopen = (f) => run(`o${f.id}`, () => api.patch(`result-flags/${f.id}`, { status: 'open' }));
const initials = (name) => (name || '?').split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase();
</script>

<template>
    <div>
        <div v-if="!compact || flags.length" class="mb-2.5 flex items-center justify-between gap-3">
            <h3 class="flex items-center gap-2 text-[15px] font-bold">
                <Icon name="flag" :size="16" class="text-action" /> Reviewer flags
                <span v-if="openFlags.length" class="rounded-full bg-action px-2 py-px text-xs text-white">{{ openFlags.length }} open</span>
            </h3>
            <button v-if="!readOnly && !compact" class="text-[13px] font-bold text-navy hover:underline print:hidden" @click="open">+ Add a flag</button>
        </div>
        <p v-if="!flags.length && !loading && !compact" class="rounded-xl border border-dashed border-line px-4 py-3 text-[13px] text-ink-2">No flags. Flag a result when something needs a second look from legal or the situation room; the result itself is never changed.</p>
        <p v-if="error && !dialog.open && !clearing.flag" role="alert" class="mb-2 rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>

        <TransitionGroup tag="ul" name="alert" class="relative flex flex-col gap-2.5">
            <li v-for="f in flags" :key="f.id" class="rounded-xl border p-3.5" :class="f.status === 'open' ? 'border-[#f2c9a8] bg-[#fffaf6]' : 'border-line bg-[#fafbfc]'">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :tone="f.status === 'open' ? sevTone[f.severity] : 'good'">{{ f.status === 'open' ? f.label : 'Cleared' }}</Badge>
                    <span v-if="f.result_id !== resultId" class="text-xs font-semibold text-ink-2">on an earlier version</span>
                    <span class="ml-auto text-xs text-ink-2">{{ dateTime(f.at) }}</span>
                </div>
                <div class="mt-2 flex gap-2.5">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-navy text-[11px] font-bold text-white" aria-hidden="true">{{ initials(f.by?.name) }}</span>
                    <div class="min-w-0 text-sm">
                        <div class="text-[12.5px] text-ink-2"><b class="text-ink">{{ f.by?.name }}</b><template v-if="f.by?.role && f.by.role !== f.by.name"> · {{ f.by.role }}</template><template v-if="f.status !== 'open'"> · {{ f.label }}</template></div>
                        <p class="mt-0.5 leading-snug whitespace-pre-line">{{ f.note }}</p>
                    </div>
                </div>

                <button v-if="f.comments.length > 2 && !shown[f.id]" class="mt-2 ml-9 text-xs font-bold text-navy hover:underline" @click="shown[f.id] = true">Show {{ f.comments.length - 2 }} earlier comment{{ f.comments.length - 2 === 1 ? '' : 's' }}</button>
                <ul v-if="f.comments.length" class="mt-2 ml-9 flex flex-col gap-2 border-l-2 border-line pl-3">
                    <li v-for="c in shown[f.id] ? f.comments : f.comments.slice(-2)" :key="c.id" class="text-sm">
                        <div class="text-[12.5px] text-ink-2"><b class="text-ink">{{ c.by?.name }}</b><template v-if="c.by?.role && c.by.role !== c.by.name"> · {{ c.by.role }}</template> · {{ dateTime(c.at) }}</div>
                        <p class="leading-snug whitespace-pre-line">{{ c.body }}</p>
                    </li>
                </ul>

                <p v-if="f.status !== 'open'" class="mt-2 ml-9 rounded-lg bg-good-bg px-3 py-2 text-[13px] text-good">
                    <b>Cleared by {{ f.cleared_by }}</b> · {{ dateTime(f.cleared_at) }}<span v-if="f.clear_note" class="block text-ink">{{ f.clear_note }}</span>
                </p>

                <div v-if="!readOnly" class="mt-2.5 ml-9 flex flex-wrap items-center gap-2 print:hidden">
                    <template v-if="f.status === 'open'">
                        <form class="flex min-w-[220px] flex-1 gap-2" @submit.prevent="reply(f)">
                            <label class="sr-only" :for="`reply-${f.id}`">Comment on this flag</label>
                            <input :id="`reply-${f.id}`" v-model="replies[f.id]" class="field h-9 flex-1 text-sm" placeholder="Add a comment" maxlength="1000" />
                            <button class="btn-ghost h-9 px-3 text-xs" :disabled="!replies[f.id]?.trim() || busy === `c${f.id}`">Comment</button>
                        </form>
                        <button class="btn h-9 border border-good bg-white px-3 text-xs text-good hover:bg-good-bg" @click="Object.assign(clearing, { flag: f, note: '' })"><Icon name="check" :size="14" /> Clear flag</button>
                    </template>
                    <button v-else class="text-xs font-bold text-navy hover:underline" :disabled="busy === `o${f.id}`" @click="reopen(f)">Reopen</button>
                </div>
            </li>
        </TransitionGroup>

        <Modal :open="dialog.open" title="Flag this result" subtitle="Legal and the situation room are alerted. The agent's signed result is not changed." :busy="busy === 'new'" width="max-w-xl" @close="dialog.open = false">
            <form id="flag-form" class="flex flex-col gap-4" @submit.prevent="submit">
                <fieldset>
                    <legend class="mb-2 text-sm font-semibold">What is the concern?</legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label v-for="r in reasons" :key="r.value" class="flex cursor-pointer items-start gap-2.5 rounded-xl border-2 px-3 py-2.5 text-sm transition" :class="dialog.reason === r.value ? 'border-navy bg-white shadow-sm' : 'border-transparent bg-mist hover:bg-[#eceff3]'">
                            <input v-model="dialog.reason" type="radio" name="reason" :value="r.value" class="mt-0.5 accent-[#13213c]" required />
                            <span><span class="block font-semibold">{{ r.label }}</span><span class="text-xs" :class="r.severity === 'high' ? 'text-action' : 'text-ink-2'">{{ r.severity === 'high' ? 'High priority alert' : 'Medium priority alert' }}</span></span>
                        </label>
                    </div>
                </fieldset>
                <label class="flex flex-col gap-1.5 text-sm font-semibold">
                    Comment
                    <textarea v-model="dialog.note" class="field min-h-[96px] py-2.5" required minlength="5" maxlength="1000" placeholder="What you saw, e.g. Party B figure on the photo reads 126 but looks overwritten from 26."></textarea>
                    <span class="text-xs font-normal text-ink-2">{{ dialog.note.length }}/1000 · recorded with your name in the audit log</span>
                </label>
                <p v-if="error" role="alert" class="rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>
            </form>
            <template #footer>
                <button class="btn-ghost" :disabled="busy === 'new'" @click="dialog.open = false">Cancel</button>
                <button class="btn-primary" form="flag-form" :disabled="busy === 'new' || !dialog.reason || dialog.note.trim().length < 5"><Icon name="flag" :size="16" /> {{ busy === 'new' ? 'Flagging…' : 'Flag result' }}</button>
            </template>
        </Modal>

        <Modal :open="!!clearing.flag" title="Clear this flag?" subtitle="Say what was found or done. The flag and its comments stay on record." :busy="busy.startsWith('x')" @close="clearing.flag = null">
            <form id="clear-form" @submit.prevent="clear">
                <label class="flex flex-col gap-1.5 text-sm font-semibold">How it was resolved <textarea v-model="clearing.note" class="field min-h-[80px] py-2.5" required maxlength="1000" placeholder="e.g. Called the agent; the photo was retaken and matches."></textarea></label>
                <p v-if="error" role="alert" class="mt-3 rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>
            </form>
            <template #footer>
                <button class="btn-ghost" @click="clearing.flag = null">Cancel</button>
                <button class="btn-good" form="clear-form" :disabled="busy.startsWith('x') || !clearing.note.trim()">Clear flag</button>
            </template>
        </Modal>
    </div>
</template>
