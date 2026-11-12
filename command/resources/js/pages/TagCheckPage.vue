<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import PinLogo from '../components/PinLogo.vue';

/**
 * What anyone sees after scanning the QR code on an agent's or supervisor's ID tag:
 * valid or not, their photo, name and post. No sign-in; built for a phone screen.
 */
const route = useRoute();
const r = ref(null);
const failed = ref(false);
const loading = ref(true);

onMounted(async () => {
    try {
        const res = await fetch(`/api/v1/id-tags/${encodeURIComponent(route.params.token)}`, { headers: { Accept: 'application/json' } });
        r.value = await res.json();
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
});

const valid = computed(() => r.value?.valid === true);
const initials = computed(() => (r.value?.name || '?').split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase());
const when = computed(() => (r.value?.checked_at ? new Date(r.value.checked_at).toLocaleString('en-NG', { hour: 'numeric', minute: '2-digit', day: 'numeric', month: 'short', year: 'numeric' }) : ''));
const fmtDate = (d) => (d ? new Date(`${d}T12:00:00`).toLocaleDateString('en-NG', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }) : '');
</script>

<template>
    <div class="min-h-dvh bg-mist">
        <header class="flex items-center gap-2 bg-navy px-5 py-4 text-white">
            <PinLogo :size="28" />
            <span class="text-[18px] tracking-[-0.02em]"><b class="font-[800]">Poll</b><span class="font-[460]">Proof</span></span>
            <span class="ml-auto text-[13px] text-[#c9d1de]">ID tag check</span>
        </header>

        <main class="mx-auto max-w-md px-4 py-5">
            <div v-if="loading" class="card p-6">
                <div class="skeleton mx-auto h-36 w-36 rounded-full"></div>
                <div class="skeleton mx-auto mt-4 h-6 w-48 rounded"></div>
            </div>

            <div v-else-if="failed" class="card p-6 text-center">
                <b class="text-[18px] font-[650]">Couldn't check this tag</b>
                <p class="mt-1 text-sm text-ink-2">Check the phone's internet connection and scan again.</p>
            </div>

            <template v-else>
                <!-- The answer first, big enough to read at arm's length. -->
                <div class="flex items-center gap-4 rounded-[10px] p-5 text-white" :class="valid ? 'bg-good' : 'bg-action'" role="status">
                    <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-white/20">
                        <svg v-if="valid" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5" /></svg>
                        <svg v-else width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
                    </span>
                    <div>
                        <div class="text-[26px] leading-none font-[700] tracking-wide">{{ valid ? 'VALID' : 'NOT VALID' }}</div>
                        <div class="mt-1 text-[13.5px] text-white/90">{{ r.reason }}</div>
                    </div>
                </div>

                <section v-if="r.found" class="card mt-4 p-6 text-center">
                    <img v-if="r.photo_url" :src="r.photo_url" :alt="`Photo of ${r.name}`" class="mx-auto h-40 w-40 rounded-full border-4 object-cover" :class="valid ? 'border-good' : 'border-action'" />
                    <div v-else class="mx-auto flex h-40 w-40 items-center justify-center rounded-full border-4 bg-mist text-4xl font-[700] text-ink-2" :class="valid ? 'border-good' : 'border-action'">{{ initials }}</div>
                    <p v-if="!r.photo_url" class="mt-2 text-xs text-ink-3">No photo on file. Check their ID card.</p>

                    <h1 class="mt-4 text-[24px] leading-tight font-[700]">{{ r.name }}</h1>
                    <div class="mt-1 text-[15px] font-[600] text-navy">{{ r.role_label }}</div>
                    <div class="mt-0.5 fig text-sm text-ink-2">{{ r.code }}</div>

                    <dl class="mt-5 divide-y divide-rule border-y border-rule text-left text-sm">
                        <div v-if="r.unit" class="flex justify-between gap-3 px-1 py-2.5"><dt class="text-ink-2">Polling unit</dt><dd class="text-right font-[600]">{{ r.unit.code }}<span class="block text-xs font-normal text-ink-2">{{ r.unit.name }}</span></dd></div>
                        <div v-if="r.ward" class="flex justify-between gap-3 px-1 py-2.5"><dt class="text-ink-2">Ward</dt><dd class="text-right font-[600]">{{ r.ward }}</dd></div>
                        <div v-if="r.lga" class="flex justify-between gap-3 px-1 py-2.5"><dt class="text-ink-2">LGA</dt><dd class="text-right font-[600]">{{ r.lga }}</dd></div>
                        <div class="flex justify-between gap-3 px-1 py-2.5"><dt class="text-ink-2">Campaign</dt><dd class="text-right font-[600]">{{ r.campaign.name }}<span class="block text-xs font-normal text-ink-2">{{ r.campaign.state }} governorship, {{ fmtDate(r.campaign.election_date) }}</span></dd></div>
                    </dl>
                </section>

                <p v-else class="card mt-4 p-5 text-sm text-ink-2">Ask the person for another form of ID, and report the tag to the campaign's situation room.</p>

                <p class="mt-4 text-center text-xs text-ink-3">Checked {{ when || 'just now' }}.<template v-if="r.found"> Compare the photo with the person in front of you.</template></p>
            </template>
        </main>
    </div>
</template>
