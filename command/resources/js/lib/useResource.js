import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Loads data on mount, exposes loading/error state, and optionally refreshes on an interval
 * (election-night screens refresh themselves so the room never looks at stale numbers).
 *
 * `busy` is true only for loads someone asked for (a new page, a sort, a filter), not the quiet
 * background refresh, so tables can show a loader without flashing every minute.
 * If loads overlap (clicking through pages quickly), only the newest one's data is kept.
 */
export function useResource(loader, { refreshMs = 0, immediate = true } = {}) {
    const data = ref(null);
    const error = ref(null);
    const loading = ref(false);
    const busy = ref(false);
    const updatedAt = ref(null);
    let timer = null;
    let seq = 0;

    async function run(quiet) {
        const mine = ++seq;
        loading.value = true;
        if (!quiet) busy.value = true;
        try {
            const result = await loader();
            if (mine !== seq) return;
            data.value = result;
            error.value = null;
            updatedAt.value = new Date();
        } catch (e) {
            if (mine === seq) error.value = e;
        } finally {
            if (mine === seq) {
                loading.value = false;
                busy.value = false;
            }
        }
    }
    const load = () => run(false);

    onMounted(() => {
        if (immediate) load();
        if (refreshMs) timer = setInterval(() => run(true), refreshMs);
    });
    onBeforeUnmount(() => timer && clearInterval(timer));

    return { data, error, loading, busy, updatedAt, load };
}
