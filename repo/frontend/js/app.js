/**
 * Campus Portal — App shell: auth guard, routing, toast, nav rendering.
 */

/* ── Toast ──────────────────────────────────────────────── */
function toast(msg, type = 'info') {
  const el = document.createElement('div');
  el.className = `toast toast-${type}`;
  el.textContent = msg;
  document.getElementById('toast-container').appendChild(el);
  setTimeout(() => el.remove(), 3800);
}

/* ── Session helpers ─────────────────────────────────────── */
function getSession() {
  try { return JSON.parse(localStorage.getItem('campus_session') || 'null'); } catch { return null; }
}
function setSession(data) { localStorage.setItem('campus_session', JSON.stringify(data)); }
function clearSession() { localStorage.removeItem('campus_session'); localStorage.removeItem('campus_token'); }

function requireAuth() {
  if (!getSession()) { window.location.href = '/pages/login.html'; return false; }
  return true;
}

/* ── Role checks ─────────────────────────────────────────── */
const ROLE_LABELS = {
  admin: 'Administrator', ops_staff: 'Operations Staff',
  team_lead: 'Team Lead', reviewer: 'Reviewer', regular: 'Regular User',
};

function hasRole(...roles) {
  const s = getSession();
  return s && roles.includes(s.role);
}

/* ── Badge helper ────────────────────────────────────────── */
function badge(status) {
  return `<span class="badge badge-${status}">${status.replace(/_/g, ' ')}</span>`;
}

/* ── Date formatting ─────────────────────────────────────── */
function fmt(dtStr) {
  if (!dtStr) return '—';
  const d = new Date(dtStr);
  return d.toLocaleDateString('en-US') + ' ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
}

/* ── Sidebar navigation ──────────────────────────────────── */
const NAV_ITEMS = [
  { label: 'Dashboard',    icon: '&#9783;', href: '/index.html',              roles: ['admin','ops_staff','team_lead','reviewer','regular'] },
  { label: 'Activities',   icon: '&#9873;', href: '/pages/activities.html',   roles: ['admin','ops_staff','team_lead','reviewer','regular'] },
  { label: 'Orders',       icon: '&#9889;', href: '/pages/orders.html',       roles: ['admin','ops_staff'] },
  { label: 'Shipments',    icon: '&#9992;', href: '/pages/shipments.html',    roles: ['admin','ops_staff'] },
  { label: 'Tasks',        icon: '&#9745;', href: '/pages/tasks.html',        roles: ['admin','team_lead'] },
  { label: 'Violations',   icon: '&#9888;', href: '/pages/violations.html',   roles: ['admin','ops_staff','team_lead','reviewer','regular'] },
  { label: 'Search',       icon: '&#9906;', href: '/pages/search.html',       roles: ['admin','ops_staff','team_lead','reviewer','regular'] },
  { label: 'My Dashboards',icon: '&#9781;', href: '/pages/dashboards.html',   roles: ['admin'] },
  { label: 'Users',        icon: '&#9786;', href: '/pages/users.html',        roles: ['admin'] },
];

function renderNav() {
  const s = getSession();
  if (!s) return;

  const nav = document.getElementById('sidebar-nav');
  if (!nav) return;

  nav.innerHTML = NAV_ITEMS
    .filter(item => item.roles.includes(s.role))
    .map(item => {
      const active = window.location.pathname === item.href ? ' active' : '';
      return `<a class="nav-item${active}" href="${item.href}">
        <span class="nav-icon">${item.icon}</span>${item.label}
      </a>`;
    }).join('');

  const footer = document.getElementById('sidebar-footer');
  if (footer) {
    footer.innerHTML = `
      <div class="sidebar-user-info">
        <strong>${s.username}</strong>${ROLE_LABELS[s.role] || s.role}
      </div>
      <button class="btn btn-secondary btn-sm" onclick="logout()">Logout</button>`;
  }

  const titleEl = document.getElementById('page-title');
  if (titleEl) {
    const current = NAV_ITEMS.find(i => i.href === window.location.pathname);
    if (current) titleEl.textContent = current.label;
  }
}

async function logout() {
  try { await API.post('/auth/logout'); } catch { /* ignore */ }
  clearSession();
  window.location.href = '/pages/login.html';
}

/* ── Pagination helper ───────────────────────────────────── */
function renderPagination(containerId, currentPage, totalPages, onPageChange) {
  const el = document.getElementById(containerId);
  if (!el || totalPages <= 1) { if (el) el.innerHTML = ''; return; }

  let html = '<div class="pagination">';
  if (currentPage > 1) html += `<button class="page-btn" onclick="(${onPageChange})(${currentPage - 1})">&#8249;</button>`;
  for (let p = Math.max(1, currentPage - 2); p <= Math.min(totalPages, currentPage + 2); p++) {
    html += `<button class="page-btn${p === currentPage ? ' active' : ''}" onclick="(${onPageChange})(${p})">${p}</button>`;
  }
  if (currentPage < totalPages) html += `<button class="page-btn" onclick="(${onPageChange})(${currentPage + 1})">&#8250;</button>`;
  html += '</div>';
  el.innerHTML = html;
}

/* ── Init on every page ──────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  const isLoginPage = window.location.pathname.includes('login');
  if (!isLoginPage && !requireAuth()) return;
  if (!isLoginPage) renderNav();

  // Ensure toast container exists
  if (!document.getElementById('toast-container')) {
    const tc = document.createElement('div');
    tc.id = 'toast-container';
    document.body.appendChild(tc);
  }
});
