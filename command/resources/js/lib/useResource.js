import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Loads data on mount, exposes loading/error state, and optionally refreshes on an interval
 * (election-night screens refresh themselves so the room never looks at stale numbers).
 */
export function useResource(loader, { refreshMs = 0, immediate = true } = {}) {
    const data = ref(null);
    const error = ref(null);
    const loading = ref(false);
    const updatedAt = ref(null);
    let timer = null;

    async function load() {
        loading.value = true;
        try {
            data.value = await loader();
            error.value = null;
            updatedAt.value = new Date();
        } catch (e) {
            error.value = e;
        } finally {
            loading.value = false;
        }
    }

    onMounted(() => {
        if (immediate) load();
        if (refreshMs) timer = setInterval(load, refreshMs);
    });
    onBeforeUnmount(() => timer && clearInterval(timer));

    return { data, error, loading, updatedAt, load };
}
