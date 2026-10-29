// Which screens each role sees. Mirrors the role checks on the API routes.
const READ = ['dg', 'situation_room', 'verifier', 'data_officer', 'legal', 'lga_supervisor', 'ward_supervisor'];

const SETUP = ['dg', 'situation_room'];

// `count` names a number from /nav-counts shown beside the item.
export const NAV = [
    { name: 'readiness', label: 'Readiness', path: '/setup/readiness', icon: 'flag', roles: SETUP, section: 'setup' },
    { name: 'structure', label: 'Structure', path: '/setup/structure', icon: 'map', roles: SETUP, section: 'setup', count: 'structure' },
    { name: 'roster', label: 'Agent roster', path: '/setup/roster', icon: 'list', roles: SETUP, section: 'setup' },
    { name: 'supervisors', label: 'Supervisors', path: '/setup/supervisors', icon: 'tree', roles: SETUP, section: 'setup', count: 'supervisors' },
    { name: 'overview', label: 'Overview', path: '/overview', icon: 'grid', roles: READ, section: 'election' },
    { name: 'units', label: 'Units', path: '/units', icon: 'pin', roles: READ, section: 'election' },
    { name: 'verify', label: 'Verify results', path: '/verify', icon: 'check', roles: ['verifier', 'situation_room', 'dg'], section: 'election', count: 'verify' },
    { name: 'collation', label: 'Collation checks', path: '/collation', icon: 'layers', roles: READ, section: 'election', count: 'collation' },
    { name: 'irev', label: 'IReV entry', path: '/irev', icon: 'edit', roles: ['data_officer', 'situation_room', 'dg'], section: 'election' },
    { name: 'incidents', label: 'Incidents', path: '/incidents', icon: 'alert', roles: READ, section: 'election', count: 'incidents' },
    { name: 'agents', label: 'Agents', path: '/agents', icon: 'users', roles: ['ward_supervisor', 'lga_supervisor', 'situation_room', 'dg'], section: 'election' },
    { name: 'closeout', label: 'Close-out', path: '/after/closeout', icon: 'archive', roles: ['dg', 'legal', 'situation_room'], section: 'after' },
    { name: 'tribunal', label: 'Tribunal workspace', path: '/after/tribunal', icon: 'gavel', roles: ['legal', 'dg'], section: 'after' },
    { name: 'users', label: 'Users and roles', path: '/admin/users', icon: 'key', roles: ['dg'], section: 'admin' },
    { name: 'campaign', label: 'Campaign', path: '/admin/campaign', icon: 'settings', roles: ['dg'], section: 'admin' },
    { name: 'notifications', label: 'Notifications', path: '/admin/notifications', icon: 'bell', roles: ['dg'], section: 'admin' },
    { name: 'stipends', label: 'Stipends', path: '/stipends', icon: 'wallet', roles: ['finance', 'dg'], section: 'admin' },
    { name: 'audit', label: 'Audit log', path: '/audit', icon: 'shield', roles: ['legal', 'dg'], section: 'admin' },
];

export const SECTIONS = [
    { key: 'setup', label: 'Setup', title: 'Before election day' },
    { key: 'election', label: 'Election', title: 'Election day' },
    { key: 'after', label: 'After', title: 'After the election' },
    { key: 'admin', label: 'Admin', title: 'Admin' },
];

export const allowed = (role, roles) => !roles || roles.includes(role);
export const navFor = (role) => NAV.filter((item) => allowed(role, item.roles));

/** Election day comes first for everyone who can see it; otherwise the first screen they have. */
export const homeFor = (role) => {
    const items = navFor(role);
    return (items.find((i) => i.section === 'election') ?? items[0])?.path ?? '/login';
};

/** The sections this role has, in order, each with its items. */
export const sectionsFor = (role) => {
    const items = navFor(role);
    return SECTIONS.map((s) => ({ ...s, items: items.filter((i) => i.section === s.key) })).filter((s) => s.items.length);
};

/** The nav item for a path, matching nested pages to their parent screen. */
export const itemForPath = (path) => NAV.filter((i) => path === i.path || path.startsWith(i.path + '/')).sort((a, b) => b.path.length - a.path.length)[0]
    ?? (path.startsWith('/units/') ? NAV.find((i) => i.name === 'units') : null);
