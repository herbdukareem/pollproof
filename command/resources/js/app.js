import { createApp } from 'vue';
import App from './App.vue';
import router from './router';
import { setUnauthorizedHandler } from './lib/api';
import { session } from './lib/session';

setUnauthorizedHandler(() => {
    session.user = null;
    if (router.currentRoute.value.name !== 'login') {
        router.push({ name: 'login', query: { next: router.currentRoute.value.fullPath } });
    }
});

createApp(App).use(router).mount('#app');
