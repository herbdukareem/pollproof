import { createRouter, createWebHistory } from 'vue-router';
import { session, loadSession } from './lib/session';
import { allowed, homeFor, NAV } from './lib/nav';

const roles = (name) => NAV.find((n) => n.name === name)?.roles;

const routes = [
    { path: '/login', name: 'login', component: () => import('./pages/LoginPage.vue'), meta: { public: true } },
    { path: '/', name: 'home', redirect: () => homeFor(session.user?.role) },
    { path: '/overview', name: 'overview', component: () => import('./pages/OverviewPage.vue'), meta: { roles: roles('overview') } },
    { path: '/units', name: 'units', component: () => import('./pages/UnitsPage.vue'), meta: { roles: roles('units') } },
    { path: '/units/:id/evidence', name: 'evidence', component: () => import('./pages/EvidencePage.vue'), meta: { roles: ['legal', 'dg'] } },
    { path: '/verify', name: 'verify', component: () => import('./pages/VerifyPage.vue'), meta: { roles: roles('verify') } },
    { path: '/collation', name: 'collation', component: () => import('./pages/CollationPage.vue'), meta: { roles: roles('collation') } },
    { path: '/irev', name: 'irev', component: () => import('./pages/IrevPage.vue'), meta: { roles: roles('irev') } },
    { path: '/incidents', name: 'incidents', component: () => import('./pages/IncidentsPage.vue'), meta: { roles: roles('incidents') } },
    { path: '/agents', name: 'agents', component: () => import('./pages/AgentsPage.vue'), meta: { roles: roles('agents') } },
    { path: '/stipends', name: 'stipends', component: () => import('./pages/StipendsPage.vue'), meta: { roles: roles('stipends') } },
    { path: '/audit', name: 'audit', component: () => import('./pages/AuditPage.vue'), meta: { roles: roles('audit') } },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('./pages/NotFoundPage.vue') },
];

const router = createRouter({ history: createWebHistory(), routes });

router.beforeEach(async (to) => {
    if (!session.loaded) await loadSession();
    if (to.meta.public) return session.user && to.name === 'login' ? homeFor(session.user.role) : true;
    if (!session.user) return { name: 'login', query: { next: to.fullPath } };
    if (!allowed(session.user.role, to.meta.roles)) return homeFor(session.user.role);
    return true;
});

export default router;
