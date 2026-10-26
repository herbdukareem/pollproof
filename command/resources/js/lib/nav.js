// Which screens each role sees. Mirrors the role checks on the API routes.
const READ = ['dg', 'situation_room', 'verifier', 'data_officer', 'legal', 'lga_supervisor', 'ward_supervisor'];

const SETUP = ['dg', 'situation_room'];

export const NAV = [
    { name: 'overview', label: 'Overview', path: '/overview', icon: 'grid', roles: READ, section: 'Election day' },
    { name: 'units', label: 'Units', path: '/units', icon: 'pin', roles: READ, section: 'Election day' },
    { name: 'verify', label: 'Verify results', path: '/verify', icon: 'check', roles: ['verifier', 'situation_room', 'dg'], section: 'Election day' },
    { name: 'collation', label: 'Collation checks', path: '/collation', icon: 'layers', roles: READ, section: 'Election day' },
    { name: 'irev', label: 'IReV entry', path: '/irev', icon: 'edit', roles: ['data_officer', 'situation_room', 'dg'], section: 'Election day' },
    { name: 'incidents', label: 'Incidents', path: '/incidents', icon: 'alert', roles: READ, section: 'Election day' },
    { name: 'agents', label: 'Agents', path: '/agents', icon: 'users', roles: ['ward_supervisor', 'lga_supervisor', 'situation_room', 'dg'], section: 'Election day' },
    { name: 'readiness', label: 'Readiness', path: '/setup/readiness', icon: 'flag', roles: SETUP, section: 'Setup' },
    { name: 'structure', label: 'Structure', path: '/setup/structure', icon: 'map', roles: SETUP, section: 'Setup' },
    { name: 'roster', label: 'Agent roster', path: '/setup/roster', icon: 'list', roles: SETUP, section: 'Setup' },
    { name: 'supervisors', label: 'Supervisors', path: '/setup/supervisors', icon: 'tree', roles: SETUP, section: 'Setup' },
    { name: 'users', label: 'Users and roles', path: '/admin/users', icon: 'key', roles: ['dg'], section: 'Admin' },
    { name: 'campaign', label: 'Campaign', path: '/admin/campaign', icon: 'settings', roles: ['dg'], section: 'Admin' },
    { name: 'notifications', label: 'Notifications', path: '/admin/notifications', icon: 'bell', roles: ['dg'], section: 'Admin' },
    { name: 'stipends', label: 'Stipends', path: '/stipends', icon: 'wallet', roles: ['finance', 'dg'], section: 'Admin' },
    { name: 'audit', label: 'Audit log', path: '/audit', icon: 'shield', roles: ['legal', 'dg'], section: 'Admin' },
];

export const allowed = (role, roles) => !roles || roles.includes(role);
export const navFor = (role) => NAV.filter((item) => allowed(role, item.roles));
export const homeFor = (role) => navFor(role)[0]?.path ?? '/login';

/** Nav items grouped by section, in order. */
export const sectionsFor = (role) => {
    const groups = [];
    for (const item of navFor(role)) {
        const last = groups[groups.length - 1];
        if (last && last.title === item.section) last.items.push(item);
        else groups.push({ title: item.section, items: [item] });
    }
    return groups;
};
