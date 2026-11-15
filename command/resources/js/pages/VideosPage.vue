<script setup>
import { computed, ref, watch } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import LoadState from '../components/LoadState.vue';
import Icon from '../components/Icon.vue';
import Modal from '../components/Modal.vue';
import Pagination from '../components/Pagination.vue';
import TableBusy from '../components/TableBusy.vue';
import Tabs from '../components/Tabs.vue';
import SearchSelect from '../components/SearchSelect.vue';
import RaceTag from '../components/RaceTag.vue';
import VideoPlayer from '../components/VideoPlayer.vue';
import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { hasRole } from '../lib/session';
import { dateTime, timeOrDate } from '../lib/format';
import { byId, hasSeveral, viewKey, viewRace, withRace } from '../lib/races';

/**
 * Videos from the field: results being announced, incidents, collation centres. Newest first;
 * each card shows a frame of the video, and opens it with everything known about where and by whom.
 */
const kind = ref('');
const lgaId = ref('');
const search = ref('');
const page = ref(1);
const videos = useResource(async () => api.get('videos', {
    kind: kind.value, lga_id: lgaId.value, search: search.value, page: page.value, per_page: 24,
    ...(await withRace(() => viewRace.value)),
}), { refreshMs: 30000, watch: viewKey });
const structure = useResource(() => api.get('structure'));
watch([kind, lgaId], () => { page.value = 1; videos.load(); });
let t;
watch(search, () => { clearTimeout(t); t = setTimeout(() => { page.value = 1; videos.load(); }, 300); });
const go = (p) => { page.value = p; videos.load(); };

const rows = computed(() => videos.data.value?.data ?? []);
const counts = computed(() => videos.data.value?.counts?.kinds ?? {});
const KIND = { result: 'Result announcements', incident: 'Incidents', collation: 'Collation', other: 'Something else' };
const tabs = computed(() => [
    { value: '', label: 'All videos', count: Object.values(counts.value).reduce((a, b) => a + b, 0) },
    ...Object.entries(KIND).map(([value, label]) => ({ value, label, count: counts.value[value] ?? 0 })),
]);

const open = ref(null);
const length = (s) => (s == null ? '' : `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`);
const size = (b) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.ceil(b / 1024)} KB`);
const where = (v) => (v.polling_unit ? `${v.polling_unit.code}, ${v.polling_unit.name}` : v.ward ? `${v.ward} ward, ${v.lga}` : v.lga ?? 'No place');
const raceOf = (v) => (v.election ? byId(v.election.id) ?? v.election : null);
const thumb = (v) => `${api.url(`videos/${v.id}/file`)}?thumb=1#t=0.8`;
const canEvidence = hasRole('legal', 'dg');
</script>

<template>
    <PageHeader title="Videos" subtitle="Recorded by agents, collation agents and supervisors, newest first." :updated-at="videos.updatedAt.value" :loading="videos.loading.value" @refresh="videos.load" />

    <Tabs v-model="kind" label="What the video shows" :tabs="tabs" />

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <div class="relative w-full sm:w-[280px]">
            <Icon name="search" :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" />
            <label class="sr-only" for="vid-search">Search videos</label>
            <input id="vid-search" v-model="search" class="field h-9 w-full pl-9 text-sm" placeholder="Unit, agent or words in the note" />
        </div>
        <label class="sr-only" for="vid-lga">LGA</label>
        <SearchSelect id="vid-lga" v-model="lgaId" class="w-full sm:w-[200px]" size="sm" all-label="All LGAs" noun="LGAs" :options="(structure.data.value?.lgas ?? []).map((l) => ({ value: l.id, label: l.name }))" />
    </div>

    <LoadState :loading="videos.loading.value" :error="videos.error.value" :empty="!rows.length" :empty-text="kind || lgaId || search ? 'No videos match these filters.' : 'No videos yet. They appear here once a phone has sent the whole video.'">
        <div class="relative">
            <TableBusy :busy="videos.busy.value" />
            <ul class="grid grid-cols-[repeat(auto-fill,minmax(250px,1fr))] gap-4">
                <li v-for="v in rows" :key="v.id">
                    <button class="group block w-full overflow-hidden rounded-[10px] border border-line bg-white text-left transition-colors hover:border-ink-3 focus-visible:border-navy" :aria-label="`Play ${v.kind_label} video from ${where(v)}`" @click="open = v">
                        <span class="relative block aspect-video bg-[#0d1626]">
                            <video :src="thumb(v)" preload="metadata" muted playsinline class="pointer-events-none h-full w-full object-cover opacity-90" aria-hidden="true" tabindex="-1"></video>
                            <span class="absolute inset-0 flex items-center justify-center">
                                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-white/90 text-navy shadow transition-transform group-hover:scale-105"><svg viewBox="0 0 24 24" class="ml-0.5 h-5 w-5" fill="currentColor" aria-hidden="true"><path d="M7 4.5v15l13-7.5z" /></svg></span>
                            </span>
                            <span v-if="v.duration_s != null" class="fig absolute right-2 bottom-2 rounded bg-black/70 px-1.5 py-px text-[12px] font-[650] text-white">{{ length(v.duration_s) }}</span>
                            <span class="absolute top-2 left-2 rounded bg-white/90 px-1.5 py-px text-[12px] font-[650] text-ink">{{ v.kind_label }}</span>
                        </span>
                        <span class="block px-3.5 pt-2.5 pb-3">
                            <span class="flex items-center gap-2">
                                <RaceTag v-if="hasSeveral && raceOf(v)" :race="raceOf(v)" size="sm" />
                                <span class="truncate text-[14px] font-[640] text-ink">{{ where(v) }}</span>
                            </span>
                            <span class="mt-0.5 block truncate text-[12.5px] text-ink-2">{{ v.by?.code }}<template v-if="v.by?.name">, {{ v.by.name }}</template>, {{ timeOrDate(v.captured_at) }}</span>
                            <span v-if="v.note" class="mt-1 line-clamp-2 block text-[13px] text-ink-2">{{ v.note }}</span>
                        </span>
                    </button>
                </li>
            </ul>
        </div>
        <Pagination class="mt-4" :meta="videos.data.value" noun="videos" :per-page-options="[24]" :loading="videos.loading.value" @page="go" />
    </LoadState>

    <Modal :open="!!open" :title="open ? `${open.kind_label}, ${where(open)}` : ''" :subtitle="open ? `Recorded ${dateTime(open.captured_at)} by ${open.by?.name ?? 'a field user'}` : ''" width="max-w-3xl" @close="open = null">
        <template v-if="open">
            <VideoPlayer :video="open" autoplay />
            <p v-if="open.note" class="mt-3 border-l-[3px] border-navy py-0.5 pl-3 text-[14.5px]">{{ open.note }}</p>
            <dl class="mt-4 grid gap-x-6 gap-y-2 text-[13.5px] sm:grid-cols-2">
                <div v-if="open.election" class="flex justify-between gap-3 border-b border-rule pb-2"><dt class="text-ink-2">Race</dt><dd class="font-[600]">{{ open.election.name }}</dd></div>
                <div v-if="open.polling_unit" class="flex justify-between gap-3 border-b border-rule pb-2"><dt class="text-ink-2">Unit</dt><dd class="text-right font-[600]">{{ open.polling_unit.code }}</dd></div>
                <div class="flex justify-between gap-3 border-b border-rule pb-2"><dt class="text-ink-2">Ward and LGA</dt><dd class="text-right font-[600]">{{ [open.ward, open.lga].filter(Boolean).join(', ') || '–' }}</dd></div>
                <div class="flex justify-between gap-3 border-b border-rule pb-2"><dt class="text-ink-2">Recorded by</dt><dd class="text-right font-[600]">{{ open.by?.name }} <span class="font-[450] text-ink-2">({{ open.by?.code ?? open.by?.role }})</span></dd></div>
                <div class="flex justify-between gap-3 border-b border-rule pb-2"><dt class="text-ink-2">Received</dt><dd class="font-[600] tabular-nums">{{ dateTime(open.received_at) }}</dd></div>
                <div class="flex justify-between gap-3 border-b border-rule pb-2"><dt class="text-ink-2">Length and size</dt><dd class="font-[600] tabular-nums">{{ length(open.duration_s) }}, {{ size(open.size) }}</dd></div>
                <div v-if="open.latitude != null" class="flex justify-between gap-3 border-b border-rule pb-2"><dt class="text-ink-2">Where the phone was</dt><dd><a class="link tabular-nums" :href="`https://www.google.com/maps?q=${open.latitude},${open.longitude}`" target="_blank" rel="noopener">{{ open.latitude.toFixed(5) }}, {{ open.longitude.toFixed(5) }}</a></dd></div>
            </dl>
        </template>
        <template #footer>
            <RouterLink v-if="open?.incident" to="/incidents" class="btn-ghost" @click="open = null">Incidents</RouterLink>
            <RouterLink v-if="open?.polling_unit && canEvidence" :to="`/units/${open.polling_unit.id}/evidence`" class="btn-ghost" @click="open = null">Evidence pack for {{ open.polling_unit.code }}</RouterLink>
            <button class="btn-navy" @click="open = null">Close</button>
        </template>
    </Modal>
</template>
