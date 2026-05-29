/**
 * Campus Portal — App shell: auth guard, routing, toast, nav rendering.
 * Uses Layui 2.x for layer (toasts/modals), element (nav), laypage (pagination).
 */

/* ── Toast ──────────────────────────────────────────────── */
function toast(msg, type = 'info') {
  const iconMap = { success: 1, error: 2, info: 0, warn: 3 };
  if (typeof layui !== 'undefined') {
    layui.layer.msg(msg, { icon: iconMap[type] ?? 0, time: 3000 });
  }
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
  if (isNaN(d.getTime())) return '—';
  const mm     = String(d.getMonth() + 1).padStart(2, '0');
  const dd     = String(d.getDate()).padStart(2, '0');
  const yyyy   = d.getFullYear();
  const h      = d.getHours();
  const min    = String(d.getMinutes()).padStart(2, '0');
  const hour12 = h % 12 || 12;
  const ampm   = h < 12 ? 'AM' : 'PM';
  return `${mm}/${dd}/${yyyy} ${hour12}:${min} ${ampm}`;
}

/* ── Sidebar navigation ──────────────────────────────────── */
const NAV_ITEMS = [
  { label: 'Dashboard',     href: '/index.html',              roles: ['admin','ops_staff','team_lead','reviewer','regular'] },
  { label: 'Activities',    href: '/pages/activities.html',   roles: ['admin','ops_staff','team_lead','reviewer','regular'] },
  { label: 'Orders',        href: '/pages/orders.html',       roles: ['admin','ops_staff'] },
  { label: 'Shipments',     href: '/pages/shipments.html',    roles: ['admin','ops_staff'] },
  { label: 'Tasks',         href: '/pages/tasks.html',        roles: ['admin','team_lead'] },
  { label: 'Violations',    href: '/pages/violations.html',   roles: ['admin','ops_staff','team_lead','reviewer','regular'] },
  { label: 'Search',        href: '/pages/search.html',       roles: ['admin','ops_staff','team_lead','reviewer','regular'] },
  { label: 'My Dashboards', href: '/pages/dashboards.html',   roles: ['admin','ops_staff','team_lead','reviewer'] },
  { label: 'Users',         href: '/pages/users.html',        roles: ['admin'] },
];

function renderNav() {
  const s = getSession();
  if (!s) return;
  const current = window.location.pathname;

  const nav = document.getElementById('sidebar-nav');
  if (nav) {
    nav.innerHTML = NAV_ITEMS.filter(i => i.roles.includes(s.role)).map(item =>
      `<li class="layui-nav-item${current === item.href ? ' layui-this' : ''}">
         <a href="${item.href}">${item.label}</a>
       </li>`
    ).join('');
  }

  const headerRight = document.getElementById('header-right-nav');
  if (headerRight) {
    // Username/role label + an always-visible Sign Out action. Rendered as plain
    // nav links (not a hover dropdown) so logout is discoverable without relying
    // on layui.element nav initialization.
    headerRight.innerHTML =
      `<li class="layui-nav-item" lay-unselect>
         <a href="javascript:;" class="cp-user-label">${s.username}<span class="cp-user-role">${ROLE_LABELS[s.role] || s.role}</span></a>
       </li>
       <li class="layui-nav-item" lay-unselect>
         <a href="javascript:void(0)" class="cp-signout" onclick="logout()">Sign Out</a>
       </li>`;
  }

  const titleEl = document.getElementById('page-title');
  if (titleEl) {
    const found = NAV_ITEMS.find(i => i.href === current);
    if (found) titleEl.textContent = found.label;
  }
}

async function logout() {
  try { await API.post('/auth/logout'); } catch { /* ignore */ }
  clearSession();
  window.location.href = '/pages/login.html';
}

/* ── Pagination helper using layui.laypage ───────────────── */
function renderPagination(containerId, currentPage, totalPages, onPageFn) {
  const el = document.getElementById(containerId);
  if (!el || totalPages <= 1) { if (el) el.innerHTML = ''; return; }
  layui.laypage.render({
    elem: containerId,
    count: totalPages * 20,
    limit: 20,
    curr: currentPage,
    theme: '#3D405B',
    jump: function(obj, first) {
      if (!first) window[onPageFn](obj.curr);
    }
  });
}

/* ── Init on every page ──────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  const isLoginPage = window.location.pathname.includes('login');
  if (!isLoginPage && !requireAuth()) return;
  layui.use(['element', 'layer', 'form'], function() {
    if (!isLoginPage) {
      renderNav();
      layui.element.render('nav', 'side-nav');
    }
  });
});
