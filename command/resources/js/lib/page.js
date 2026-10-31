import { reactive } from 'vue';

/**
 * The current page's title and subtitle, shown in the top bar. Set by PageHeader.
 * `back` is where the Back button goes when there is no earlier page in this tab (a link opened directly).
 */
export const page = reactive({ title: '', subtitle: '', back: '', owner: null });
