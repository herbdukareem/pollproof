import { createApp } from 'vue';
import App from './App.vue';
import router from './router';
import { setUnauthorizedHandler } from './lib/api';
import { session } from './lib/session';

setUnauthorizedHandler(() => {
    session.user = null;
    // Public pages (sign-in, an ID tag check) work without a session: never send them to sign in.
    if (router.resolve(window.location.pathname).meta.public) return;
    if (router.currentRoute.value.name !== 'login') {
        router.push({ name: 'login', query: { next: router.currentRoute.value.fullPath } });
    }
});

createApp(App).use(router).mount('#app');
