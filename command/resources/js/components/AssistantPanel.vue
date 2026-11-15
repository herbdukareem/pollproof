<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Icon from './Icon.vue';
import { api } from '../lib/api';
import { session } from '../lib/session';
import { renderMarkdown } from '../lib/markdown';
import { assistant } from '../lib/assistant';
import { timeOrDate } from '../lib/format';

/**
 * Ask PollProof: a panel at the side of the command room for questions about the campaign
 * ("how are we doing in Bida?", "why can't AG-00127 check in?"). It answers from live data
 * through read-only tools, and keeps each person's conversations.
 */
const enabled = ref(true);
const threads = ref([]);
const threadId = ref(null);
const messages = ref([]);
const draft = ref('');
const thinking = ref(false);
const problem = ref('');
const showHistory = ref(false);
const scroller = ref(null);
const input = ref(null);

const SUGGEST = {
    dg: ['How are we doing in every race?', 'Which LGAs are we behind in for the governorship?', 'What needs my attention right now?', 'Is the system healthy? Is the cron job running?'],
    situation_room: ['What needs attention right now?', 'Which units have no result yet, by LGA?', 'Summarise the open high-priority alerts', 'Is the system healthy? Is the cron job running?'],
    verifier: ['How many results are waiting for me?', 'Which units have votes above accreditation?', 'Show the announcement videos that differ'],
    data_officer: ['Which units are not on IReV?', 'Which collation sheets differ from our units?', 'How many results are in so far?'],
    legal: ['Which results are flagged?', 'Which collation sheets differ from our units?', 'List incidents of violence today'],
    lga_supervisor: ['Which agents in my LGA have not checked in?', 'Which units in my LGA have no result yet?', 'Why can\'t an agent check in?'],
    ward_supervisor: ['Which agents in my ward have not checked in?', 'Which units in my ward have no result yet?', 'Why can\'t an agent check in?'],
};
const suggestions = computed(() => SUGGEST[session.user?.role] ?? SUGGEST.situation_room);
const firstName = computed(() => (session.user?.name ?? '').split(' ')[0]);

async function loadThreads() {
    try {
        const r = await api.get('assistant/threads');
        enabled.value = r.enabled;
        threads.value = r.threads;
    } catch { /* the panel still opens */ }
}
async function openThread(id) {
    showHistory.value = false;
    problem.value = '';
    threadId.value = id;
    messages.value = [];
    const r = await api.get(`assistant/threads/${id}`);
    messages.value = r.messages;
    scrollDown();
}
function newChat() {
    showHistory.value = false;
    threadId.value = null;
    messages.value = [];
    problem.value = '';
    nextTick(() => input.value?.focus());
}
async function removeThread(t) {
    await api.delete(`assistant/threads/${t.id}`);
    threads.value = threads.value.filter((x) => x.id !== t.id);
    if (threadId.value === t.id) newChat();
}

async function send(text = draft.value) {
    const q = text.trim();
    if (!q || thinking.value) return;
    draft.value = '';
    problem.value = '';
    messages.value.push({ role: 'user', text: q });
    thinking.value = true;
    scrollDown();
    try {
        const r = await api.post('assistant/messages', { message: q, thread_id: threadId.value });
        threadId.value = r.thread.id;
        messages.value.push(r.reply);
        loadThreads();
    } catch (e) {
        if (e.body?.thread?.id) threadId.value = e.body.thread.id;
        problem.value = e.status === 503 ? e.message : e.body?.message ?? e.message;
    } finally {
        thinking.value = false;
        scrollDown();
        nextTick(() => input.value?.focus());
    }
}
function onKey(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        send();
    }
}
function grow() {
    const el = input.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = Math.min(160, el.scrollHeight) + 'px';
}
function scrollDown() {
    nextTick(() => scroller.value?.scrollTo({ top: scroller.value.scrollHeight, behavior: 'smooth' }));
}
const onEsc = (e) => e.key === 'Escape' && assistant.open && (assistant.open = false);

watch(() => assistant.open, (o) => {
    if (!o) return;
    loadThreads();
    nextTick(() => input.value?.focus());
    if (assistant.question) {
        const q = assistant.question;
        assistant.question = '';
        newChat();
        send(q);
    }
});
watch(draft, () => nextTick(grow));
onMounted(() => window.addEventListener('keydown', onEsc));
onBeforeUnmount(() => window.removeEventListener('keydown', onEsc));
</script>

<template>
    <Teleport to="body">
        <Transition name="fade">
            <div v-if="assistant.open" class="fixed inset-0 z-[58] bg-navy/25 lg:hidden" @click="assistant.open = false"></div>
        </Transition>
        <Transition name="drawer">
            <aside
                v-if="assistant.open"
                class="fixed inset-y-0 right-0 z-[59] flex w-full flex-col border-l border-line bg-white shadow-[-16px_0_40px_-20px_rgba(19,33,60,0.35)] sm:w-[440px]"
                role="dialog"
                aria-label="Ask PollProof"
            >
                <header class="flex items-center gap-2 border-b border-line px-4 py-3">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-navy text-white"><Icon name="sparkle" :size="17" /></span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-[15px] leading-tight font-[700] text-ink">Ask PollProof</h2>
                        <p class="truncate text-[12px] text-ink-2">Answers from live campaign data</p>
                    </div>
                    <button class="btn-ghost h-8 w-8 !px-0" :class="showHistory && '!border-navy !text-navy'" title="Earlier conversations" aria-label="Earlier conversations" @click="showHistory = !showHistory"><Icon name="list" :size="16" /></button>
                    <button class="btn-ghost h-8 w-8 !px-0" title="New conversation" aria-label="New conversation" @click="newChat"><Icon name="plus" :size="16" /></button>
                    <button class="btn-ghost h-8 w-8 !px-0" title="Close" aria-label="Close" @click="assistant.open = false"><Icon name="x" :size="16" /></button>
                </header>

                <!-- Earlier conversations -->
                <div v-if="showHistory" class="flex-1 overflow-y-auto">
                    <p class="px-4 pt-3 pb-1 text-[12.5px] font-[600] text-ink-2">Earlier conversations</p>
                    <p v-if="!threads.length" class="px-4 py-6 text-[13.5px] text-ink-2">None yet.</p>
                    <ul class="px-2 pb-3">
                        <li v-for="t in threads" :key="t.id" class="group flex items-center gap-1 rounded-lg hover:bg-mist">
                            <button class="min-w-0 flex-1 px-2.5 py-2 text-left" @click="openThread(t.id)">
                                <span class="block truncate text-[14px] font-[580]" :class="t.id === threadId ? 'text-navy' : 'text-ink'">{{ t.title }}</span>
                                <span class="block text-[12px] text-ink-3">{{ timeOrDate(t.updated_at) }}</span>
                            </button>
                            <button class="mr-1 hidden h-7 w-7 items-center justify-center rounded-md text-ink-3 group-hover:flex hover:bg-white hover:text-action" :aria-label="`Delete ${t.title}`" @click="removeThread(t)"><Icon name="x" :size="14" /></button>
                        </li>
                    </ul>
                </div>

                <template v-else>
                    <div ref="scroller" class="flex-1 overflow-y-auto px-4 py-4">
                        <div v-if="!enabled" class="note text-[13.5px]"><b class="font-[650] text-ink">Ask PollProof is off.</b> It needs an OpenAI key on the server (<code class="font-mono text-[12px]">OPENAI_API_KEY</code>).</div>

                        <div v-else-if="!messages.length" class="pt-4">
                            <p class="text-[20px] leading-snug font-[700] text-ink">What do you want to know{{ firstName ? `, ${firstName}` : '' }}?</p>
                            <p class="mt-1.5 text-[14px] leading-relaxed text-ink-2">Ask about results, units, agents, alerts and incidents, or why something isn't working. Answers come from the campaign's live data; nothing is changed.</p>
                            <div class="mt-5 flex flex-col gap-2">
                                <button v-for="s in suggestions" :key="s" class="rounded-lg border border-line px-3.5 py-2.5 text-left text-[14px] text-ink transition-colors hover:border-navy hover:bg-[#f6f8fb]" @click="send(s)">{{ s }}</button>
                            </div>
                        </div>

                        <div v-for="(m, k) in messages" :key="k" class="mb-4 flex" :class="m.role === 'user' ? 'justify-end' : 'justify-start'">
                            <p v-if="m.role === 'user'" class="max-w-[85%] rounded-2xl rounded-br-md bg-navy px-3.5 py-2 text-[14.5px] leading-relaxed whitespace-pre-wrap text-white">{{ m.text }}</p>
                            <div v-else class="flex max-w-full gap-2.5">
                                <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-mist text-navy"><Icon name="sparkle" :size="13" /></span>
                                <div class="md min-w-0 text-[14.5px] leading-relaxed text-ink" v-html="renderMarkdown(m.text)"></div>
                            </div>
                        </div>

                        <div v-if="thinking" class="mb-4 flex items-center gap-2.5 text-[13.5px] text-ink-2" role="status">
                            <span class="flex h-6 w-6 items-center justify-center rounded-md bg-mist text-navy"><Icon name="sparkle" :size="13" /></span>
                            Looking into it<span class="thinking-dots" aria-hidden="true"><i></i><i></i><i></i></span>
                        </div>
                        <p v-if="problem" role="alert" class="rounded-lg bg-bad-bg px-3.5 py-2.5 text-[13.5px] text-bad">{{ problem }}</p>
                    </div>

                    <form class="border-t border-line p-3" @submit.prevent="send()">
                        <div class="flex items-end gap-2 rounded-xl border border-line bg-white p-1.5 pl-3 focus-within:border-navy focus-within:ring-2 focus-within:ring-navy/10">
                            <label class="sr-only" for="ask-input">Your question</label>
                            <textarea id="ask-input" ref="input" v-model="draft" rows="1" maxlength="2000" class="max-h-40 min-h-[36px] flex-1 resize-none bg-transparent py-2 text-[14.5px] text-ink outline-none placeholder:text-ink-3" placeholder="Ask anything about the campaign…" :disabled="!enabled" @keydown="onKey"></textarea>
                            <button class="btn-navy h-9 w-9 shrink-0 !px-0" :disabled="!draft.trim() || thinking || !enabled" aria-label="Send"><Icon name="send" :size="16" /></button>
                        </div>
                        <p class="mt-1.5 px-1 text-[11.5px] text-ink-3">AI can be wrong. Check anything important on its page before acting on it.</p>
                    </form>
                </template>
            </aside>
        </Transition>
    </Teleport>
</template>
