<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import Icon from './Icon.vue';
import { api } from '../lib/api';
import { dateTime } from '../lib/format';

// Notifications for the signed-in person. Checks every 30 seconds.
const router = useRouter();
const open = ref(false);
const data = ref({ unread: 0, data: [] });
let timer;

async function load() {
    try {
        data.value = await api.get('me/notices');
    } catch {
        /* the next poll will try again */
    }
}
async function markRead(ids) {
    data.value = await api.post('me/notices/read', ids ? { ids } : {});
}
async function go(n) {
    if (!n.read_at) await markRead([n.id]);
    open.value = false;
    if (n.url) router.push(n.url);
}

onMounted(() => {
    load();
    timer = setInterval(load, 30000);
});
onBeforeUnmount(() => clearInterval(timer));
const tone = { high: 'bg-action', medium: 'bg-[#d99a00]', low: 'bg-ink-3' };
</script>

<template>
    <div class="relative">
        <button class="btn-outline relative h-10 w-10 !px-0" :aria-label="`Notifications, ${data.unread} unread`" :aria-expanded="open" @click="open = !open">
            <Icon name="bell" :size="18" />
            <span v-if="data.unread" class="absolute -top-1.5 -right-1.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-action px-1 text-[11px] font-bold text-white">{{ data.unread > 99 ? '99+' : data.unread }}</span>
        </button>
        <div v-if="open" class="fixed inset-0 z-30" @click="open = false"></div>
        <div v-if="open" class="absolute right-0 z-40 mt-2 w-[min(380px,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-line bg-white shadow-xl" role="dialog" aria-label="Notifications">
            <div class="flex items-center justify-between border-b border-line px-4 py-3">
                <b class="text-sm">Notifications</b>
                <button v-if="data.unread" class="text-xs font-semibold text-navy hover:underline" @click="markRead()">Mark all read</button>
            </div>
            <p v-if="!data.data.length" class="px-4 py-8 text-center text-sm text-ink-2">Nothing yet.</p>
            <ul class="max-h-[60vh] overflow-y-auto">
                <li v-for="n in data.data" :key="n.id">
                    <button class="flex w-full gap-3 border-b border-[#eef0f3] px-4 py-3 text-left hover:bg-mist" :class="n.read_at ? 'opacity-70' : ''" @click="go(n)">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="n.read_at ? 'bg-transparent' : tone[n.severity]"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-[13.5px] font-bold" :class="n.escalation ? 'text-action' : ''">{{ n.title }}</span>
                            <span class="line-clamp-2 block text-[12.5px] text-ink-2">{{ n.body }}</span>
                            <span class="mt-0.5 block text-[11.5px] text-ink-3">{{ dateTime(n.created_at) }}{{ n.test ? ' · test' : '' }}</span>
                        </span>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
