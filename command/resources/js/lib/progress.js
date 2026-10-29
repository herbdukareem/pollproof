import { reactive } from 'vue';

/**
 * The thin bar across the top of the screen while a page and its first data load,
 * and while a save is on its way. Background refreshes don't show it.
 */
export const progress = reactive({ value: 0, visible: false });

let pending = 0;
let navigating = false;
let trickle = null;
let windowTimer = null;
let hideTimer = null;

function start() {
    clearTimeout(hideTimer);
    progress.visible = true;
    progress.value = Math.max(progress.value, 0.08);
    clearInterval(trickle);
    trickle = setInterval(() => {
        progress.value += (0.92 - progress.value) * 0.08;
    }, 180);
}

function finish() {
    if (pending > 0 || navigating) return;
    clearInterval(trickle);
    progress.value = 1;
    hideTimer = setTimeout(() => {
        progress.visible = false;
        setTimeout(() => { if (!progress.visible) progress.value = 0; }, 250);
    }, 220);
}

export function navStart() {
    navigating = true;
    clearTimeout(windowTimer);
    start();
}

/** The route has resolved; give the new page a moment to start its requests, then finish when they land. */
export function navEnd() {
    clearTimeout(windowTimer);
    windowTimer = setTimeout(() => {
        navigating = false;
        finish();
    }, 450);
}

/** Show the bar for a request if a page is loading or it changes something. */
export function track(promise, mutation = false) {
    if (!navigating && !mutation) return promise;
    pending++;
    start();
    return promise.finally(() => {
        pending--;
        finish();
    });
}
