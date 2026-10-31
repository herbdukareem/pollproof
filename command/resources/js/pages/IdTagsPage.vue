<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import QRCode from 'qrcode';
import PinLogo from '../components/PinLogo.vue';
import { api } from '../lib/api';

/**
 * Printable ID tags, ten to an A4 sheet at ID-card size (85.6 × 54 mm), with cut marks.
 * Opened from the roster (an LGA, or chosen people) or a person's menu. ?ids=1,2 · ?lga_id= · ?role=supervisors
 */
const route = useRoute();
const tags = ref([]);
const qr = ref({});
const loading = ref(true);
const error = ref('');

onMounted(async () => {
    try {
        const q = route.query;
        const ids = String(q.ids ?? '').split(',').filter(Boolean);
        tags.value = (await api.get('id-tags', { ...(ids.length ? { ids: ids.join(',') } : {}), ...(q.lga_id ? { lga_id: q.lga_id } : {}), ...(q.ward_id ? { ward_id: q.ward_id } : {}), ...(q.role ? { role: q.role } : {}) })).data;
        const codes = await Promise.all(tags.value.map((t) => QRCode.toDataURL(t.verify_url, { margin: 0, width: 260, errorCorrectionLevel: 'M', color: { dark: '#13213c', light: '#ffffff' } })));
        qr.value = Object.fromEntries(tags.value.map((t, i) => [t.id, codes[i]]));
    } catch (e) {
        error.value = e.firstError?.() ?? 'Could not load the tags.';
    } finally {
        loading.value = false;
    }
});

const pages = computed(() => {
    const out = [];
    for (let i = 0; i < tags.value.length; i += 10) out.push(tags.value.slice(i, i + 10));
    return out;
});
const print = () => window.print();
const close = () => (window.history.length > 1 ? window.history.back() : window.close());
const initials = (n) => (n || '?').split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase();
const year = (d) => (d ? new Date(`${d}T12:00:00`).getFullYear() : '');
</script>

<template>
    <div class="min-h-dvh bg-[#e9ecf2] print:bg-white">
        <div class="sticky top-0 z-10 flex flex-wrap items-center gap-3 border-b border-line bg-white px-5 py-3 print:hidden">
            <PinLogo :size="24" pin="#13213c" />
            <b>ID tags</b>
            <span class="text-sm text-ink-2">{{ loading ? 'Loading…' : `${tags.length} ${tags.length === 1 ? 'tag' : 'tags'} · ${pages.length} ${pages.length === 1 ? 'sheet' : 'sheets'} of A4` }}</span>
            <span class="text-xs text-ink-3">Print at 100% (actual size), then cut along the marks.</span>
            <button class="btn-primary ml-auto" :disabled="loading || !tags.length" @click="print">Print</button>
            <button class="btn-outline" @click="close">Close</button>
        </div>
        <p v-if="error" class="m-6 rounded-xl bg-bad-bg p-4 text-bad">{{ error }}</p>
        <p v-else-if="!loading && !tags.length" class="m-6 rounded-xl bg-white p-6 text-center text-ink-2">Nobody to print for this choice.</p>

        <div v-for="(sheet, s) in pages" :key="s" class="tag-sheet mx-auto my-6 bg-white shadow-lg print:my-0 print:shadow-none">
            <article v-for="t in sheet" :key="t.id" class="tag">
                <header class="tag-head">
                    <PinLogo :size="15" />
                    <b>PollProof</b>
                    <span class="truncate">{{ t.campaign.name }}</span>
                </header>
                <div class="tag-body">
                    <img v-if="t.photo_url" :src="t.photo_url" alt="" class="tag-photo" />
                    <div v-else class="tag-photo tag-initials">{{ initials(t.name) }}</div>
                    <div class="tag-info">
                        <div class="tag-role">{{ t.role_label }}</div>
                        <div class="tag-name">{{ t.name }}</div>
                        <div class="tag-code">{{ t.code }}</div>
                        <div v-if="t.unit" class="tag-line"><b>{{ t.unit.code }}</b> {{ t.unit.name }}</div>
                        <div class="tag-line">{{ [t.ward, t.lga].filter(Boolean).join(' · ') }}</div>
                    </div>
                    <div class="tag-qr">
                        <img :src="qr[t.id]" alt="QR code to check this tag" />
                        <span>Scan to verify</span>
                    </div>
                </div>
                <footer class="tag-foot">{{ t.campaign.state }} governorship {{ year(t.campaign.election_date) }} · Valid only while the QR check says VALID</footer>
            </article>
        </div>
    </div>
</template>

<style scoped>
/* A4 sheet: two columns by five rows of CR80 cards, with a little gutter for cutting. */
.tag-sheet {
    width: 210mm;
    min-height: 297mm;
    padding: 12mm 12mm;
    display: grid;
    grid-template-columns: repeat(2, 85.6mm);
    grid-auto-rows: 54mm;
    gap: 2mm 6mm;
    justify-content: center;
    align-content: start;
    break-after: page;
}
.tag {
    width: 85.6mm;
    height: 54mm;
    border: 0.2mm dashed #9aa3b2;
    border-radius: 3mm;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    font-family: inherit;
    color: #13213c;
    background: #fff;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
.tag-head {
    display: flex;
    align-items: center;
    gap: 1.5mm;
    background: #13213c;
    color: #fff;
    padding: 1.6mm 3mm;
    font-size: 2.7mm;
}
.tag-head span {
    margin-left: auto;
    color: #c9d1de;
    font-size: 2.3mm;
    max-width: 45mm;
}
.tag-body {
    flex: 1;
    display: flex;
    gap: 2.5mm;
    padding: 2.5mm 3mm 1mm;
    min-height: 0;
}
.tag-photo {
    width: 24mm;
    height: 30mm;
    border-radius: 1.5mm;
    object-fit: cover;
    flex-shrink: 0;
    border: 0.3mm solid #d6dae1;
}
.tag-initials {
    display: flex;
    align-items: center;
    justify-content: center;
    background: #eef1f6;
    font-weight: 800;
    font-size: 7mm;
    color: #5b6578;
}
.tag-info {
    flex: 1;
    min-width: 0;
    line-height: 1.25;
}
.tag-role {
    font-size: 2.3mm;
    font-weight: 800;
    letter-spacing: 0.15mm;
    text-transform: uppercase;
    color: #e8590c;
}
.tag-name {
    font-size: 4.2mm;
    font-weight: 800;
    margin-top: 0.6mm;
}
.tag-code {
    font-family: ui-monospace, monospace;
    font-size: 2.8mm;
    margin: 0.4mm 0 1mm;
}
.tag-line {
    font-size: 2.6mm;
    color: #3d4759;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.tag-qr {
    width: 25mm;
    display: flex;
    flex-direction: column;
    align-items: center;
    flex-shrink: 0;
}
.tag-qr img {
    width: 25mm;
    height: 25mm;
}
.tag-qr span {
    font-size: 2mm;
    font-weight: 700;
    margin-top: 0.6mm;
}
.tag-foot {
    font-size: 1.9mm;
    color: #5b6578;
    padding: 0 3mm 1.6mm;
}
@media print {
    @page {
        size: A4;
        margin: 0;
    }
    .tag-sheet {
        margin: 0;
    }
}
</style>
