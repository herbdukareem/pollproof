import { createRouter, createWebHistory } from 'vue-router';
import { session, loadSession } from './lib/session';
import { allowed, homeFor, NAV } from './lib/nav';
import { navEnd, navStart } from './lib/progress';

const roles = (name) => NAV.find((n) => n.name === name)?.roles;

const routes = [
    { path: '/login', name: 'login', component: () => import('./pages/LoginPage.vue'), meta: { public: true } },
    // The page an ID tag's QR code opens: anyone, no sign-in.
    { path: '/tag/:token', name: 'tag-check', component: () => import('./pages/TagCheckPage.vue'), meta: { public: true, title: 'ID tag check' } },
    // Printable ID tags: full page, no menu.
    { path: '/id-tags', name: 'id-tags', component: () => import('./pages/IdTagsPage.vue'), meta: { bare: true, roles: ['dg', 'situation_room'] } },
    { path: '/', name: 'home', redirect: () => homeFor(session.user?.role) },
    { path: '/overview', name: 'overview', component: () => import('./pages/OverviewPage.vue'), meta: { roles: roles('overview') } },
    { path: '/alerts', name: 'alerts', component: () => import('./pages/AlertsPage.vue'), meta: { roles: roles('alerts') } },
    { path: '/units', name: 'units', component: () => import('./pages/UnitsPage.vue'), meta: { roles: roles('units') } },
    { path: '/units/:id/evidence', name: 'evidence', component: () => import('./pages/EvidencePage.vue'), meta: { roles: ['legal', 'dg'] } },
    { path: '/verify', name: 'verify', component: () => import('./pages/VerifyPage.vue'), meta: { roles: roles('verify') } },
    { path: '/collation', name: 'collation', component: () => import('./pages/CollationPage.vue'), meta: { roles: roles('collation') } },
    { path: '/irev', name: 'irev', component: () => import('./pages/IrevPage.vue'), meta: { roles: roles('irev') } },
    { path: '/incidents', name: 'incidents', component: () => import('./pages/IncidentsPage.vue'), meta: { roles: roles('incidents') } },
    { path: '/agents', name: 'agents', component: () => import('./pages/AgentsPage.vue'), meta: { roles: roles('agents') } },
    { path: '/stipends', name: 'stipends', component: () => import('./pages/StipendsPage.vue'), meta: { roles: roles('stipends') } },
    { path: '/audit', name: 'audit', component: () => import('./pages/AuditPage.vue'), meta: { roles: roles('audit') } },
    { path: '/setup/readiness', name: 'readiness', component: () => import('./pages/setup/ReadinessPage.vue'), meta: { roles: roles('readiness') } },
    { path: '/setup/structure', name: 'structure', component: () => import('./pages/setup/StructurePage.vue'), meta: { roles: roles('structure') } },
    { path: '/setup/roster', name: 'roster', component: () => import('./pages/setup/RosterPage.vue'), meta: { roles: roles('roster') } },
    { path: '/setup/supervisors', name: 'supervisors', component: () => import('./pages/setup/SupervisorsPage.vue'), meta: { roles: roles('supervisors') } },
    { path: '/admin/users', name: 'users', component: () => import('./pages/admin/UsersPage.vue'), meta: { roles: roles('users') } },
    { path: '/after/closeout', name: 'closeout', component: () => import('./pages/after/CloseoutPage.vue'), meta: { roles: roles('closeout') } },
    { path: '/after/tribunal', name: 'tribunal', component: () => import('./pages/after/TribunalPage.vue'), meta: { roles: roles('tribunal') } },
    { path: '/after/tribunal/:id', name: 'tribunal-case', component: () => import('./pages/after/TribunalPage.vue'), meta: { roles: roles('tribunal') } },
    { path: '/admin/notifications', name: 'notifications', component: () => import('./pages/admin/NotificationsPage.vue'), meta: { roles: roles('notifications') } },
    { path: '/admin/campaign', name: 'campaign', component: () => import('./pages/admin/CampaignPage.vue'), meta: { roles: roles('campaign') } },
    { path: '/account/password', name: 'password', component: () => import('./pages/PasswordPage.vue') },
    { path: '/account/security', name: 'security', component: () => import('./pages/SecurityPage.vue') },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('./pages/NotFoundPage.vue') },
];

const router = createRouter({ history: createWebHistory(), routes });

router.beforeEach(async (to, from) => {
    if (to.path !== from.path) navStart();
    if (!session.loaded) await loadSession();
    if (to.meta.public) return session.user && to.name === 'login' ? homeFor(session.user.role) : true;
    if (!session.user) return { name: 'login', query: { next: to.fullPath } };
    // A temporary password from the DG has to be replaced before anything else.
    if (session.user.must_change_password && to.name !== 'password') return { name: 'password' };
    // The campaign requires two-step sign-in and this person hasn't set it up.
    if (!session.user.must_change_password && session.user.two_factor_required && to.name !== 'security') return { name: 'security' };
    if (!allowed(session.user.role, to.meta.roles)) return homeFor(session.user.role);
    return true;
});

router.afterEach(() => navEnd());
router.onError(() => navEnd());

export default router;
