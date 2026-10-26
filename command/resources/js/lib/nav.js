// Which screens each role sees. Mirrors the role checks on the API routes.
const READ = ['dg', 'situation_room', 'verifier', 'data_officer', 'legal', 'lga_supervisor', 'ward_supervisor'];

export const NAV = [
    { name: 'overview', label: 'Overview', path: '/overview', icon: 'grid', roles: READ },
    { name: 'units', label: 'Units', path: '/units', icon: 'pin', roles: READ },
    { name: 'verify', label: 'Verify results', path: '/verify', icon: 'check', roles: ['verifier', 'situation_room', 'dg'] },
    { name: 'collation', label: 'Collation checks', path: '/collation', icon: 'layers', roles: READ },
    { name: 'irev', label: 'IReV entry', path: '/irev', icon: 'edit', roles: ['data_officer', 'situation_room', 'dg'] },
    { name: 'incidents', label: 'Incidents', path: '/incidents', icon: 'alert', roles: READ },
    { name: 'agents', label: 'Agents', path: '/agents', icon: 'users', roles: ['ward_supervisor', 'lga_supervisor', 'situation_room', 'dg'] },
    { name: 'stipends', label: 'Stipends', path: '/stipends', icon: 'wallet', roles: ['finance', 'dg'] },
    { name: 'audit', label: 'Audit log', path: '/audit', icon: 'shield', roles: ['legal', 'dg'] },
];

export const allowed = (role, roles) => !roles || roles.includes(role);
export const navFor = (role) => NAV.filter((item) => allowed(role, item.roles));
export const homeFor = (role) => navFor(role)[0]?.path ?? '/login';
