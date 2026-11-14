<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import Icon from './Icon.vue';
import { api } from '../lib/api';
import { session } from '../lib/session';

/**
 * Reads new incidents, alerts and race news out loud on this screen (POLLPROOF_VOICE_ENABLED).
 * The server decides what is said and groups what arrives together; this plays its clips in
 * order. Off until someone turns it on here, since a browser only plays sound after a click.
 * With several tabs open, only one of them speaks.
 */
const KINDS = [
    ['incident', 'New incidents'],
    ['alert', 'New alerts'],
    ['escalation', 'Alerts nobody has handled'],
    ['race', 'How the race stands'],
];
const POLL_MS = 3000;
const store = `pp:voice:${session.user?.id ?? 0}`;

const router = useRouter();
const available = computed(() => !!session.user?.rules?.voice);
const open = ref(false);
const settings = reactive(read(store, { on: false, kinds: KINDS.map(([k]) => k) }));
const saying = ref(null); // the clip playing now
const last = ref(null); // the last clip played, for the panel
const problem = ref('');
const otherTab = ref(false);

let cursor = null;
let timer = null;
const queue = [];
let busy = false;
let audio = null;

function read(key, fallback) {
    try {
        return { ...fallback, ...JSON.parse(localStorage.getItem(key) ?? '{}') };
    } catch {
        return fallback;
    }
}
watch(settings, () => {
    try {
        localStorage.setItem(store, JSON.stringify(settings));
    } catch {
        /* settings just won't be remembered */
    }
});

// --- One speaking tab per browser ------------------------------------------------------------
const tabId = Math.random().toString(36).slice(2);
const LEAD = `${store}:tab`;
function leading() {
    try {
        const now = Date.now();
        const cur = JSON.parse(localStorage.getItem(LEAD) ?? 'null');
        if (!cur || cur.id === tabId || now - cur.at > 10000) {
            localStorage.setItem(LEAD, JSON.stringify({ id: tabId, at: now }));
            otherTab.value = false;
            return true;
        }
        otherTab.value = true;
        return false;
    } catch {
        return true;
    }
}
function letGo() {
    try {
        if (JSON.parse(localStorage.getItem(LEAD) ?? 'null')?.id === tabId) localStorage.removeItem(LEAD);
    } catch {
        /* nothing to release */
    }
}

// --- Fetching and playing ---------------------------------------------------------------------
async function poll() {
    if (!settings.on || !leading()) return;
    try {
        const res = await api.get('voice/feed', { after: cursor, kinds: settings.kinds.join(',') });
        cursor = res.cursor ?? cursor;
        if (res.items?.length) enqueue(res.items);
    } catch {
        /* the next poll tries again */
    }
}

function enqueue(clips) {
    // A screen that falls far behind keeps the newest few groups rather than talking for minutes.
    queue.push(...clips);
    const groups = [...new Set(queue.map((c) => c.group))];
    if (groups.length > 3) queue.splice(0, queue.findIndex((c) => c.group === groups[groups.length - 3]));
    playNext();
}

let previousGroup = null;
async function playNext() {
    if (busy || !queue.length) return;
    busy = true;
    const clip = queue.shift();
    try {
        const newGroup = clip.group !== previousGroup;
        await pause(newGroup ? 700 : 300);
        if (newGroup && clip.severity === 'high') await chime();
        saying.value = clip;
        await play(clip.audio);
        problem.value = '';
        last.value = { ...clip, at: new Date() };
        previousGroup = clip.group;
    } catch (e) {
        problem.value = e?.message === 'blocked' ? 'The browser blocked sound. Click the speaker to allow it.' : "The voice server isn't answering. Announcements will resume when it does.";
    } finally {
        saying.value = null;
        busy = false;
        playNext();
    }
}

function play(src) {
    return new Promise((resolve, reject) => {
        audio = new Audio(src);
        audio.onended = resolve;
        audio.onerror = () => reject(new Error('audio'));
        audio.play().catch((e) => reject(new Error(e?.name === 'NotAllowedError' ? 'blocked' : 'audio')));
    });
}
const pause = (ms) => new Promise((r) => setTimeout(r, ms));

// Two soft notes before anything urgent, so heads turn before the words start.
let ctx = null;
async function chime() {
    try {
        ctx ??= new AudioContext();
        if (ctx.state === 'suspended') await ctx.resume();
        [880, 660].forEach((f, i) => {
            const o = ctx.createOscillator();
            const g = ctx.createGain();
            const t = ctx.currentTime + i * 0.16;
            o.frequency.value = f;
            g.gain.setValueAtTime(0.0001, t);
            g.gain.exponentialRampToValueAtTime(0.18, t + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, t + 0.15);
            o.connect(g).connect(ctx.destination);
            o.start(t);
            o.stop(t + 0.16);
        });
        await pause(380);
    } catch {
        /* no chime, the words still play */
    }
}

// --- Switching on and off ---------------------------------------------------------------------
async function start() {
    cursor = null;
    await poll(); // takes the current position: what already happened isn't read out
    clearInterval(timer);
    timer = setInterval(poll, POLL_MS);
}
function stop() {
    clearInterval(timer);
    timer = null;
    queue.length = 0;
    audio?.pause();
    saying.value = null;
    letGo();
}
async function toggle() {
    settings.on = !settings.on;
    if (settings.on) {
        await start();
        await sample();
    } else {
        stop();
    }
}
async function sample() {
    try {
        enqueue((await api.get('voice/sample')).items);
    } catch {
        problem.value = "The voice server isn't answering.";
    }
}
function follow(link) {
    if (!link) return;
    open.value = false;
    router.push(link);
}

// Resume after a reload if it was on. Some browsers hold the sound until the next click.
watch(available, (ok) => (ok && settings.on ? start() : stop()), { immediate: true });
window.addEventListener('beforeunload', letGo);
onBeforeUnmount(() => {
    stop();
    window.removeEventListener('beforeunload', letGo);
});
</script>

<template>
    <div v-if="available" class="relative">
        <button
            class="btn-ghost relative h-9 w-9 !px-0"
            :class="settings.on ? 'text-navy' : 'text-ink-3'"
            :aria-label="settings.on ? 'Voice announcements on' : 'Voice announcements off'"
            :aria-expanded="open"
            :title="saying ? saying.text : settings.on ? 'Voice announcements on' : 'Voice announcements off'"
            @click="open = !open"
        >
            <Icon :name="settings.on ? 'volume' : 'volume-off'" :size="18" />
            <span v-if="saying" class="absolute top-1.5 right-1.5 h-2 w-2 rounded-full bg-orange motion-safe:animate-pulse"></span>
        </button>
        <div v-if="open" class="fixed inset-0 z-30" @click="open = false"></div>
        <div v-if="open" class="float absolute right-0 z-40 mt-2 w-[min(340px,calc(100vw-2rem))] overflow-hidden" role="dialog" aria-label="Voice announcements">
            <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                <b class="text-sm font-[650]">Read out loud</b>
                <button
                    type="button"
                    role="switch"
                    :aria-checked="settings.on"
                    class="relative h-6 w-11 shrink-0 rounded-full transition-colors"
                    :class="settings.on ? 'bg-navy' : 'bg-[#cdd3dd]'"
                    @click="toggle"
                >
                    <span class="absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white transition-transform" :class="settings.on ? 'translate-x-5' : ''"></span>
                    <span class="sr-only">Read announcements out loud on this screen</span>
                </button>
            </div>
            <div class="px-4 py-3">
                <p class="text-[13px] text-ink-2">This screen says new incidents and alerts as they come in, and when the race changes. Each one is said once; several at the same moment are said together.</p>
                <fieldset class="mt-3" :disabled="!settings.on">
                    <legend class="sr-only">What to read out</legend>
                    <label v-for="[k, label] in KINDS" :key="k" class="flex items-center gap-2.5 py-1 text-sm" :class="!settings.on && 'opacity-60'">
                        <input v-model="settings.kinds" type="checkbox" class="checkbox" :value="k" />
                        {{ label }}
                    </label>
                </fieldset>
                <p v-if="otherTab && settings.on" class="note mt-3 text-[12.5px]">Another PollProof tab in this browser is speaking, so this one stays quiet.</p>
                <p v-if="problem" role="alert" class="mt-3 rounded-lg bg-warn-bg px-3 py-2 text-[12.5px] text-warn">{{ problem }}</p>
                <div v-if="saying || last" class="mt-3 border-t border-rule pt-3 text-[13px]">
                    <div class="text-[12.5px] text-ink-2">{{ saying ? 'Saying now' : `Last said at ${last.at.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}` }}</div>
                    <button v-if="(saying ?? last).link" type="button" class="mt-0.5 text-left hover:underline" @click="follow((saying ?? last).link)">{{ (saying ?? last).text }}</button>
                    <p v-else class="mt-0.5">{{ (saying ?? last).text }}</p>
                </div>
                <button v-if="settings.on" type="button" class="link mt-3 text-[13px]" @click="sample">Play a sample</button>
            </div>
        </div>
    </div>
</template>
