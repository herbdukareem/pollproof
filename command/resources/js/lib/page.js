import { reactive } from 'vue';

/** The current page's title and subtitle, shown in the top bar. Set by PageHeader. */
export const page = reactive({ title: '', subtitle: '', owner: null });
