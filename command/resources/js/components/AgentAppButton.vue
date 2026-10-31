<script setup>
import { computed, ref } from 'vue';
import QRCode from 'qrcode';
import Icon from './Icon.vue';
import Modal from './Modal.vue';
import { api } from '../lib/api';
import { hasRole } from '../lib/session';
import { dateTime } from '../lib/format';

/** "Agent app" in the top bar: download the Android app, or scan a QR code with a phone. */
const allowed = hasRole('dg', 'situation_room', 'verifier', 'data_officer', 'legal', 'lga_supervisor', 'ward_supervisor');
const open = ref(false);
const info = ref(null);
const qr = ref('');
const error = ref('');
const busy = ref(false);
const note = ref('');
const build = computed(() => info.value?.build);
const mb = (n) => (n ? `${(n / 1048576).toFixed(1)} MB` : '');

async function load() {
    error.value = '';
    try {
        info.value = await api.get('agent-app');
        qr.value = await QRCode.toDataURL(info.value.phone_url, { margin: 1, width: 320, color: { dark: '#13213c', light: '#ffffff' } });
    } catch (e) {
        error.value = e.firstError?.() ?? 'Could not load the app details.';
    }
}
function show() {
    open.value = true;
    note.value = '';
    load();
}
async function update() {
    busy.value = true;
    error.value = '';
    note.value = '';
    try {
        const before = build.value?.sha256;
        const res = await api.post('agent-app/update');
        note.value = res.build?.sha256 === before ? 'This server already has the latest build.' : `Updated to version ${res.build.version} (build ${res.build.build}).`;
        await load();
    } catch (e) {
        error.value = e.firstError();
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <template v-if="allowed">
        <button class="btn-outline h-10 gap-2 px-3" title="Download the agent app" aria-label="Agent app" @click="show">
            <Icon name="phone" :size="17" /><span class="hidden text-sm font-semibold xl:inline">Agent app</span>
        </button>
        <Modal :open="open" title="PollProof agent app" subtitle="For Android phones. Agents sign in with their registered number." width="max-w-xl" :busy="busy" @close="open = false">
            <p v-if="error" class="mb-3 rounded-xl bg-bad-bg px-3 py-2 text-sm text-bad">{{ error }}</p>
            <p v-if="note" class="mb-3 rounded-xl bg-good-bg px-3 py-2 text-sm text-good">{{ note }}</p>
            <div v-if="!info && !error" class="skeleton h-48 rounded-xl"></div>
            <div v-else-if="info" class="grid gap-5 sm:grid-cols-[1fr_180px]">
                <div>
                    <div v-if="build" class="rounded-xl bg-mist p-3.5 text-sm">
                        <div class="font-bold">Version {{ build.version }} <span class="font-semibold text-ink-2">· build {{ build.build }}</span></div>
                        <div class="mt-0.5 text-ink-2">{{ mb(build.size) }}<template v-if="build.built_at"> · built {{ dateTime(build.built_at) }}</template></div>
                    </div>
                    <div v-else class="rounded-xl bg-warn-bg p-3.5 text-[13px] text-warn">This server has no copy yet, so the download comes straight from the latest published build.</div>
                    <a :href="info.download_url" class="btn-primary mt-4 w-full justify-center" download><Icon name="download" :size="16" /> Download APK</a>
                    <ol class="mt-4 list-decimal space-y-1.5 pl-5 text-[13px] text-ink-2">
                        <li>Open the downloaded file on the phone and tap <b class="text-ink">Install</b>.</li>
                        <li>If Android asks, allow installs from your browser or file manager.</li>
                        <li>Agents sign in with the phone number on the roster. No data? They can still send results by USSD or SMS.</li>
                    </ol>
                </div>
                <div class="text-center">
                    <img v-if="qr" :src="qr" alt="QR code to download the agent app" class="mx-auto w-[180px] rounded-xl border border-line p-1.5" />
                    <p class="mt-2 text-[12px] text-ink-2">Scan with an Android phone to download. The link works for one hour.</p>
                </div>
            </div>
            <template v-if="info?.can_update" #footer>
                <span class="mr-auto self-center text-[12px] text-ink-3">New builds arrive by themselves within the hour.</span>
                <button class="btn-outline" :disabled="busy" @click="update"><Icon name="refresh" :size="15" :class="busy ? 'animate-spin' : ''" /> {{ busy ? 'Getting…' : 'Get latest build now' }}</button>
            </template>
        </Modal>
    </template>
</template>
