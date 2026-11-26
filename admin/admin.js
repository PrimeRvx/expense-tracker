// หน้าผู้ดูแลระบบ: JavaScript ล้วน ไม่ต้อง build เรียก API เดียวกับแอปหลัก
'use strict';

const API = '../api';
const state = { csrf: '', me: null, q: '', page: 1, userId: null, tab: 'help', detail: null, txFilter: {} };
const $ = (id) => document.getElementById(id);

const money = new Intl.NumberFormat('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtMoney = (v) => money.format(Number(v || 0));
const fmtDate = (s, time = true) => {
  if (!s) return '–';
  const d = new Date(String(s).replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? s : d.toLocaleString('th-TH', time ? { dateStyle: 'medium', timeStyle: 'short' } : { dateStyle: 'medium' });
};

const ACTIONS = {
  update_user: 'แก้ข้อมูลบัญชี', unlock_login: 'ปลดล็อกการเข้าสู่ระบบ', reset_password: 'ตั้งรหัสผ่านชั่วคราว',
  restore_categories: 'กู้หมวดเริ่มต้น', repair_slips: 'ล้างข้อมูลสลิปที่ไฟล์หาย', delete_user: 'ลบบัญชี',
  update_transaction: 'แก้ไขรายการ', delete_transaction: 'ลบรายการ', view_slip: 'เปิดดูสลิป', update_category: 'แก้ไขหมวด',
};
const FIELDS = {
  display_name: 'ชื่อ', email: 'อีเมล', role: 'สิทธิ์', type: 'ชนิด', category: 'หมวด', amount: 'จำนวนเงิน',
  occurred_at: 'วันที่', note: 'หมายเหตุ', name: 'ชื่อ', color: 'สี', icon: 'ไอคอน', transaction_id: 'รายการ #',
  category_id: 'หมวด #', cleared: 'ล้างจำนวนครั้งที่ผิด', added: 'เพิ่ม', transactions: 'รายการ', slips: 'สลิป', had_slip: 'มีสลิป',
};

// ------------------------------------------------------------ สร้าง DOM อย่างปลอดภัย (ไม่ใช้ innerHTML กับข้อมูลผู้ใช้)
function h(tag, attrs = {}, ...children) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === null || v === undefined || v === false) continue;
    if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
    else if (k === 'class') el.className = v;
    else if (k === 'style') el.style.cssText = v;
    else el.setAttribute(k, v === true ? '' : String(v));
  }
  for (const c of children.flat()) {
    if (c === null || c === undefined || c === false) continue;
    el.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  return el;
}

// ใส่ลูกใหม่แทนของเดิม โดยข้ามค่าว่าง (null/false) ที่มาจากเงื่อนไข
function put(el, ...kids) {
  el.replaceChildren(...kids.flat().filter((k) => k !== null && k !== undefined && k !== false));
}

// ------------------------------------------------------------ API
class ApiError extends Error {
  constructor(status, body) {
    super((body && body.message) || 'เกิดข้อผิดพลาด');
    this.status = status; this.code = body && body.code; this.field = body && body.field;
  }
}

async function api(method, path, body, raw = false) {
  const opts = { method, credentials: 'same-origin', headers: {} };
  if (method !== 'GET') opts.headers['X-CSRF-Token'] = state.csrf;
  if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
  const res = await fetch(API + path, opts);
  if (raw && res.ok) return res;
  let data = null;
  try { data = await res.json(); } catch { /* ไม่ใช่ JSON */ }
  if (data && data.data && data.data.csrfToken) state.csrf = data.data.csrfToken;
  if (!res.ok || !data || !data.ok) {
    const err = new ApiError(res.status, data);
    if (res.status === 401 && !path.startsWith('/auth/')) showLogin();
    throw err;
  }
  return data.data;
}

const qs = (o) => {
  const p = new URLSearchParams();
  for (const [k, v] of Object.entries(o)) if (v !== '' && v !== null && v !== undefined && v !== false) p.set(k, v === true ? '1' : v);
  const s = p.toString();
  return s ? `?${s}` : '';
};

// ------------------------------------------------------------ แจ้งเตือนและป๊อปอัป
let toastTimer;
function toast(msg) {
  const t = $('toast');
  t.textContent = msg; t.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { t.hidden = true; }, 3200);
}

/**
 * fields: [{name, label, type, value, options:[{value,label}], hint}] หรือ content: Node
 * onSubmit(values) คืน promise ถ้า throw จะแสดงข้อความในป๊อปอัปและไม่ปิด
 */
function openDialog({ title, fields = [], content = null, okText = 'บันทึก', danger = false, cancel = true, onSubmit }) {
  const dlg = $('dialog');
  $('dialog-title').textContent = title;
  $('dialog-error').textContent = '';
  const body = $('dialog-body');
  put(body, );
  if (content) body.append(content);
  for (const f of fields) {
    let input;
    if (f.type === 'select') {
      input = h('select', { name: f.name }, f.options.map((o) => h('option', { value: o.value, selected: String(o.value) === String(f.value) }, o.label)));
    } else if (f.type === 'textarea') {
      input = h('textarea', { name: f.name, rows: 3, maxlength: f.maxlength }, f.value ?? '');
    } else {
      input = h('input', { name: f.name, type: f.type || 'text', value: f.value ?? '', step: f.step, required: f.required, autocomplete: 'off', maxlength: f.maxlength });
    }
    if (f.onchange) input.addEventListener('change', () => f.onchange(input, body));
    body.append(h('label', {}, f.label, input, f.hint ? h('span', { class: 'muted small' }, f.hint) : null));
  }
  const ok = $('dialog-ok');
  ok.textContent = okText;
  ok.className = danger ? 'btn btn-danger' : 'btn btn-primary';
  dlg.querySelector('[value="cancel"]').hidden = !cancel;

  const form = $('dialog-form');
  form.onsubmit = async (e) => {
    if (e.submitter && e.submitter.value === 'cancel') return; // ปิดตามปกติ
    e.preventDefault();
    if (!onSubmit) { dlg.close(); return; }
    const values = Object.fromEntries(new FormData(form).entries());
    ok.disabled = true;
    try {
      await onSubmit(values, body);
      dlg.close();
    } catch (err) {
      $('dialog-error').textContent = err.message;
      const bad = err.field && form.elements[err.field];
      if (bad) bad.focus();
    } finally {
      ok.disabled = false;
    }
  };
  dlg.showModal();
  const first = body.querySelector('input, select, textarea');
  if (first) first.focus();
}

const confirmDialog = (title, message, okText, run) => openDialog({ title, content: h('p', {}, message), okText, danger: true, onSubmit: run });

// ------------------------------------------------------------ เริ่มต้น
function showOnly(id) {
  for (const v of ['view-login', 'view-denied', 'view-app']) $(v).hidden = v !== id;
  $('whoami').hidden = id === 'view-login';
}

function showLogin() {
  showOnly('view-login');
  $('login-form').email.focus();
}

async function boot() {
  try {
    const me = await api('GET', '/auth/me');
    state.me = me.user;
    if (!me.user) return showLogin();
    $('whoami-email').textContent = me.user.email;
    await api('GET', '/admin/overview');
  } catch (err) {
    if (err.status === 401) return showLogin();
    showOnly('view-denied');
    if (err.code === 'admin_not_installed') {
      $('denied-title').textContent = 'ยังไม่ได้ติดตั้งระบบผู้ดูแล';
      $('denied-text').textContent = 'import ไฟล์ sql/migrate_admin.sql เข้าฐานข้อมูลผ่าน phpMyAdmin แล้วเปิดหน้านี้ใหม่';
    } else if (err.status !== 403) {
      $('denied-title').textContent = 'เปิดหน้าผู้ดูแลไม่ได้';
      $('denied-text').textContent = err.message;
    }
    return;
  }
  showOnly('view-app');
  await loadUsers();
  route();
}

function route() {
  const m = location.hash.match(/^#user\/(\d+)(?:\/(\w+))?/);
  if (m) {
    if (Number(m[1]) !== state.userId) state.txFilter = {}; // ตัวกรองรายการเป็นของผู้ใช้แต่ละคน
    state.userId = Number(m[1]);
    state.tab = m[2] || 'help';
    renderUser();
  } else {
    state.userId = null;
    renderOverview();
  }
  highlightUser();
}

$('login-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.target;
  $('login-error').textContent = '';
  try {
    await api('POST', '/auth/login', { email: f.email.value, password: f.password.value });
    f.password.value = '';
    boot();
  } catch (err) {
    $('login-error').textContent = err.message;
  }
});

$('btn-logout').addEventListener('click', async () => {
  try { await api('POST', '/auth/logout'); } catch { /* ออกอยู่แล้ว */ }
  location.hash = '';
  showLogin();
});

$('btn-overview').addEventListener('click', () => { location.hash = ''; });
window.addEventListener('hashchange', route);

let searchTimer;
$('search').addEventListener('input', (e) => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => { state.q = e.target.value.trim(); state.page = 1; loadUsers(); }, 250);
});
$('search-form').addEventListener('submit', (e) => e.preventDefault());

// ------------------------------------------------------------ รายชื่อผู้ใช้
async function loadUsers() {
  const list = $('user-list');
  let data;
  try {
    data = await api('GET', '/admin/users' + qs({ q: state.q, page: state.page, limit: 30 }));
  } catch (err) {
    put(list, h('li', { class: 'empty' }, err.message));
    return;
  }
  put(list, ...(data.items.length ? data.items.map((u) => h('li', {},
    h('button', { type: 'button', 'data-id': u.id, onclick: () => { location.hash = `#user/${u.id}`; } },
      h('span', { class: 'u-name' }, u.displayName || '(ไม่มีชื่อ)',
        u.role === 'admin' ? h('span', { class: 'badge badge-admin' }, 'ผู้ดูแล') : null,
        u.loginFailures >= 5 ? h('span', { class: 'badge badge-warn' }, 'ถูกล็อก') : null),
      h('span', { class: 'u-mail' }, `#${u.id} · ${u.email}`),
      h('span', { class: 'u-mail' }, `${u.txCount} รายการ · ล่าสุด ${fmtDate(u.lastActivity, false)}`)),
  )) : [h('li', { class: 'empty' }, 'ไม่พบผู้ใช้')]));

  const pages = Math.max(1, Math.ceil(data.total / data.limit));
  put($('user-pager'), 
    h('button', { class: 'btn btn-ghost btn-sm', disabled: state.page <= 1, onclick: () => { state.page--; loadUsers(); } }, '‹'),
    h('span', {}, `${data.total} คน · หน้า ${state.page}/${pages}`),
    h('button', { class: 'btn btn-ghost btn-sm', disabled: state.page >= pages, onclick: () => { state.page++; loadUsers(); } }, '›'),
  );
  highlightUser();
}

function highlightUser() {
  for (const b of $('user-list').querySelectorAll('button')) b.classList.toggle('active', Number(b.dataset.id) === state.userId);
  $('btn-overview').classList.toggle('active', state.userId === null);
}

// ------------------------------------------------------------ ภาพรวม
async function renderOverview() {
  const c = $('content');
  put(c, h('div', { class: 'card panel muted' }, 'กำลังโหลด…'));
  const d = await api('GET', '/admin/overview');
  put(c, 
    h('div', { class: 'card panel' },
      h('h1', {}, 'ภาพรวมระบบ'),
      h('p', { class: 'muted' }, 'เลือกผู้ใช้จากรายชื่อด้านซ้ายเพื่อดู ช่วยเหลือ หรือแก้ไขข้อมูล'),
      h('div', { class: 'stats' },
        stat('ผู้ใช้ทั้งหมด', d.users), stat('ผู้ดูแล', d.admins),
        stat('รายการรับจ่าย', d.transactions.toLocaleString('th-TH')), stat('รูปสลิป', d.slips.toLocaleString('th-TH')))),
    d.failingLogins > 0 ? h('div', { class: 'alert alert-warn' },
      `มี ${d.failingLogins} บัญชีที่ใส่รหัสผ่านผิดใน 15 นาทีที่ผ่านมา ดูได้จากป้าย "ถูกล็อก" ในรายชื่อ`) : null,
    h('div', { class: 'card panel' }, h('h2', {}, 'การแก้ไขล่าสุด'), auditList(d.recentAudit, true)),
  );
}

const stat = (label, value, cls = '') => h('div', { class: 'stat' }, h('div', { class: 'label' }, label), h('div', { class: `value ${cls}` }, value));

// ------------------------------------------------------------ ผู้ใช้
async function renderUser() {
  const c = $('content');
  const id = state.userId;
  put(c, h('div', { class: 'card panel muted' }, 'กำลังโหลด…'));
  let d;
  try {
    d = await api('GET', `/admin/users/${id}`);
  } catch (err) {
    put(c, h('div', { class: 'card panel' }, err.message));
    return;
  }
  if (state.userId !== id) return; // ผู้ใช้กดไปคนอื่นระหว่างโหลด
  state.detail = d;
  const u = d.user;
  const isSelf = state.me && state.me.id === u.id;

  const tabs = [['help', 'ช่วยเหลือ'], ['tx', `รายการรับจ่าย (${d.stats.txCount})`], ['cat', 'หมวดหมู่และงบ'], ['audit', 'ประวัติการแก้ไข']];
  const tabBody = h('div', {});

  put(c, 
    h('div', { class: 'card panel' },
      h('div', { class: 'head' },
        h('div', {},
          h('h1', {}, u.displayName || '(ไม่มีชื่อ)', ' ',
            u.role === 'admin' ? h('span', { class: 'badge badge-admin' }, 'ผู้ดูแล') : h('span', { class: 'badge' }, 'ผู้ใช้'),
            d.login.locked ? h('span', { class: 'badge badge-warn' }, ' ถูกล็อก') : null),
          h('div', { class: 'muted' }, `#${u.id} · ${u.email} · สมัครเมื่อ ${fmtDate(u.createdAt, false)}`)),
        h('div', { class: 'actions' },
          h('button', { class: 'btn btn-ghost', onclick: () => editUser(u, isSelf) }, 'แก้ไขบัญชี'),
          isSelf ? null : h('button', { class: 'btn btn-danger', onclick: () => deleteUser(u) }, 'ลบบัญชี'))),
      h('div', { class: 'stats', style: 'margin-top:1rem' },
        stat('รายรับรวม', fmtMoney(d.stats.income), 'income'),
        stat('รายจ่ายรวม', fmtMoney(d.stats.expense), 'expense'),
        stat('จำนวนรายการ', d.stats.txCount.toLocaleString('th-TH')),
        stat('ใช้งานล่าสุด', fmtDate(d.stats.lastActivity, false)))),
    issueAlerts(d),
    h('div', { class: 'card panel' },
      h('div', { class: 'tabs', role: 'tablist' }, tabs.map(([key, label]) => h('button', {
        role: 'tab', 'aria-selected': state.tab === key ? 'true' : 'false',
        onclick: () => { location.hash = `#user/${u.id}/${key}`; },
      }, label))),
      tabBody),
  );

  if (state.tab === 'tx') renderTx(tabBody);
  else if (state.tab === 'cat') renderCategories(tabBody);
  else if (state.tab === 'audit') renderAudit(tabBody);
  else renderHelp(tabBody, isSelf);
}

function issueAlerts(d) {
  const i = d.issues;
  const out = [];
  if (i.categoryMismatch.length) {
    out.push(h('div', { class: 'alert alert-warn' },
      h('span', {}, `มี ${i.categoryMismatch.length} รายการที่ชนิด (รายรับ/รายจ่าย) ไม่ตรงกับหมวด ทำให้ยอดสรุปผิด`),
      h('button', { class: 'btn btn-sm btn-ghost', onclick: () => { state.txFilter = { issues: true }; location.hash = `#user/${d.user.id}/tx`; if (state.tab === 'tx') renderUser(); } }, 'ดูและแก้รายการ')));
  }
  if (i.missingSlipFiles.length) {
    out.push(h('div', { class: 'alert alert-warn' },
      h('span', {}, `มี ${i.missingSlipFiles.length} รายการที่ระบบบอกว่ามีสลิป แต่ไฟล์รูปหายไปแล้ว`),
      h('button', { class: 'btn btn-sm btn-ghost', onclick: () => repairSlips(d.user) }, 'ล้างข้อมูลสลิปที่เสีย')));
  }
  if (i.missingCategoryTypes.length) {
    out.push(h('div', { class: 'alert alert-warn' },
      h('span', {}, `ผู้ใช้ไม่มีหมวด${i.missingCategoryTypes.join('และ')}เหลืออยู่ จะบันทึกรายการชนิดนี้ไม่ได้`),
      h('button', { class: 'btn btn-sm btn-ghost', onclick: () => restoreCategories(d.user) }, 'กู้หมวดเริ่มต้น')));
  }
  return out.length ? h('div', { style: 'display:grid;gap:.5rem' }, out) : null;
}

// ---------- แท็บช่วยเหลือ
function renderHelp(el, isSelf) {
  const d = state.detail;
  const u = d.user;
  const tool = (title, desc, btn) => h('div', { class: 'stat', style: 'display:grid;gap:.5rem;align-content:space-between' },
    h('div', {}, h('strong', {}, title), h('div', { class: 'muted small' }, desc)), btn);
  put(el, 
    h('h2', {}, 'เครื่องมือช่วยเหลือผู้ใช้'),
    h('div', { class: 'stats', style: 'grid-template-columns:repeat(auto-fit,minmax(230px,1fr))' },
      tool('เข้าสู่ระบบไม่ได้เพราะใส่รหัสผิดหลายครั้ง',
        d.login.failures15m ? `ใส่ผิด ${d.login.failures15m} ครั้งใน 15 นาที${d.login.locked ? ' (ถูกล็อกอยู่)' : ''}` : 'ตอนนี้ไม่มีการใส่รหัสผิด',
        h('button', { class: 'btn btn-ghost btn-sm', disabled: !d.login.failures15m, onclick: () => unlock(u) }, 'ปลดล็อก')),
      tool('ลืมรหัสผ่าน',
        'สร้างรหัสผ่านชั่วคราวให้ผู้ใช้ เครื่องที่ล็อกอินค้างไว้จะออกจากระบบทั้งหมด',
        h('button', { class: 'btn btn-ghost btn-sm', disabled: isSelf, onclick: () => resetPassword(u) }, isSelf ? 'ใช้หน้าตั้งค่าในแอป' : 'ตั้งรหัสผ่านชั่วคราว')),
      tool('หมวดเริ่มต้นหาย',
        'เพิ่มหมวดเริ่มต้นที่ผู้ใช้ลบหรือเปลี่ยนชื่อไปกลับมา ไม่แตะหมวดที่มีอยู่',
        h('button', { class: 'btn btn-ghost btn-sm', onclick: () => restoreCategories(u) }, 'กู้หมวดเริ่มต้น')),
      tool('ข้อมูลอื่นๆ',
        `สลิป ${d.stats.slips} รูป · คำที่ระบบเรียนรู้ ${d.stats.parserRules} คำ · รายการแรก ${fmtDate(d.stats.firstTransaction, false)}`,
        h('button', { class: 'btn btn-ghost btn-sm', onclick: () => { location.hash = `#user/${u.id}/tx`; } }, 'ดูรายการรับจ่าย'))),
  );
}

async function refresh(msg) {
  if (msg) toast(msg);
  await Promise.all([renderUser(), loadUsers()]);
}

function editUser(u, isSelf) {
  openDialog({
    title: 'แก้ไขบัญชี',
    fields: [
      { name: 'display_name', label: 'ชื่อที่แสดง', value: u.displayName, maxlength: 100 },
      { name: 'email', label: 'อีเมล', type: 'email', value: u.email, required: true, hint: 'ผู้ใช้ต้องใช้อีเมลใหม่นี้ในการเข้าสู่ระบบครั้งต่อไป' },
      isSelf ? null : { name: 'role', label: 'สิทธิ์', type: 'select', value: u.role, options: [{ value: 'user', label: 'ผู้ใช้ทั่วไป' }, { value: 'admin', label: 'ผู้ดูแลระบบ' }] },
    ].filter(Boolean),
    onSubmit: async (v) => { await api('PUT', `/admin/users/${u.id}`, v); await refresh('บันทึกข้อมูลบัญชีแล้ว'); },
  });
}

function unlock(u) {
  confirmDialog('ปลดล็อกการเข้าสู่ระบบ', `ล้างจำนวนครั้งที่ใส่รหัสผิดของ ${u.email} ผู้ใช้จะลองเข้าสู่ระบบได้ทันที`, 'ปลดล็อก', async () => {
    await api('POST', `/admin/users/${u.id}/unlock`);
    await refresh('ปลดล็อกแล้ว');
  });
}

function resetPassword(u) {
  confirmDialog('ตั้งรหัสผ่านชั่วคราว', `รหัสผ่านเดิมของ ${u.email} จะใช้ไม่ได้ และทุกเครื่องที่ล็อกอินอยู่จะออกจากระบบ`, 'ตั้งรหัสใหม่', async () => {
    const r = await api('POST', `/admin/users/${u.id}/reset-password`);
    setTimeout(() => openDialog({
      title: 'รหัสผ่านชั่วคราว',
      content: h('div', { style: 'display:grid;gap:.75rem' },
        h('div', { class: 'secret' }, r.tempPassword),
        h('p', { class: 'muted small' }, 'แจ้งรหัสนี้ให้ผู้ใช้ทางช่องทางส่วนตัว และให้เปลี่ยนรหัสผ่านที่หน้าตั้งค่าหลังเข้าสู่ระบบ ระบบไม่เก็บรหัสนี้ไว้ ปิดหน้าต่างแล้วจะดูอีกไม่ได้')),
      okText: 'คัดลอกและปิด', cancel: false,
      onSubmit: async () => { try { await navigator.clipboard.writeText(r.tempPassword); toast('คัดลอกรหัสแล้ว'); } catch { /* เบราว์เซอร์ไม่อนุญาต */ } },
    }), 0);
    refresh();
  });
}

function restoreCategories(u) {
  confirmDialog('กู้หมวดเริ่มต้น', 'เพิ่มหมวดเริ่มต้นที่ขาดหายไป หมวดที่ผู้ใช้สร้างเองจะไม่ถูกแก้', 'กู้หมวด', async () => {
    const r = await api('POST', `/admin/users/${u.id}/restore-categories`);
    await refresh(r.added.length ? `เพิ่ม ${r.added.length} หมวด: ${r.added.join(', ')}` : 'หมวดเริ่มต้นครบอยู่แล้ว');
  });
}

function repairSlips(u) {
  confirmDialog('ล้างข้อมูลสลิปที่เสีย', 'ลบข้อมูลสลิปที่ไฟล์รูปหายไปแล้ว รายการรับจ่ายยังอยู่ครบ แค่ไม่มีรูปแนบ', 'ล้างข้อมูล', async () => {
    const r = await api('POST', `/admin/users/${u.id}/repair-slips`);
    await refresh(`ล้างข้อมูลสลิป ${r.removed} รายการ`);
  });
}

function deleteUser(u) {
  openDialog({
    title: 'ลบบัญชีถาวร',
    content: h('p', {}, `บัญชี ${u.email} และข้อมูลทั้งหมด (รายการ หมวด งบ และรูปสลิป) จะถูกลบและกู้คืนไม่ได้`),
    fields: [{ name: 'confirm_email', label: 'พิมพ์อีเมลของบัญชีเพื่อยืนยัน', type: 'email', required: true }],
    okText: 'ลบบัญชี', danger: true,
    onSubmit: async (v) => {
      await api('DELETE', `/admin/users/${u.id}`, v);
      toast('ลบบัญชีแล้ว');
      location.hash = '';
      loadUsers();
    },
  });
}

// ---------- แท็บรายการรับจ่าย
async function renderTx(el, page = 1) {
  const u = state.detail.user;
  const f = state.txFilter;
  const form = h('form', { class: 'filters', onsubmit: (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    state.txFilter = { q: fd.get('q'), type: fd.get('type'), from: fd.get('from'), to: fd.get('to'), issues: fd.get('issues') === '1' };
    renderTx(el);
  } },
  h('label', {}, 'ค้นหา', h('input', { name: 'q', type: 'search', value: f.q || '', placeholder: 'หมายเหตุหรือชื่อหมวด' })),
  h('label', {}, 'ชนิด', h('select', { name: 'type' }, ['', 'รายรับ', 'รายจ่าย'].map((t) => h('option', { value: t, selected: (f.type || '') === t }, t || 'ทั้งหมด')))),
  h('label', {}, 'ตั้งแต่', h('input', { name: 'from', type: 'date', value: f.from || '' })),
  h('label', {}, 'ถึง', h('input', { name: 'to', type: 'date', value: f.to || '' })),
  h('button', { class: 'btn btn-primary', type: 'submit' }, 'กรอง'),
  h('label', { style: 'grid-column:1/-1;display:flex;gap:.4rem;align-items:center;font-weight:400' },
    h('input', { type: 'checkbox', name: 'issues', value: '1', checked: !!f.issues, style: 'width:auto' }), 'เฉพาะรายการที่ชนิดไม่ตรงกับหมวด'));

  const tableBox = h('div', { class: 'table-wrap' }, h('p', { class: 'muted' }, 'กำลังโหลด…'));
  put(el, form, tableBox);

  let data;
  try {
    data = await api('GET', `/admin/users/${u.id}/transactions` + qs({ ...f, page, limit: 25 }));
  } catch (err) {
    put(tableBox, h('p', { class: 'error' }, err.message));
    return;
  }
  if (!data.items.length) { put(tableBox, h('p', { class: 'empty' }, 'ไม่พบรายการ')); return; }

  const pages = Math.max(1, Math.ceil(data.total / data.limit));
  put(tableBox, 
    h('table', {},
      h('thead', {}, h('tr', {}, ['วันที่', 'ชนิด', 'หมวด', 'หมายเหตุ', 'จำนวนเงิน', 'สลิป', ''].map((t, i) => h('th', { class: i === 4 ? 'num' : null }, t)))),
      h('tbody', {}, data.items.map((t) => h('tr', { class: t.categoryMismatch ? 'flag' : null },
        h('td', { style: 'white-space:nowrap' }, fmtDate(t.occurredAt)),
        h('td', { class: t.type === 'รายรับ' ? 'income' : 'expense', style: 'white-space:nowrap' }, t.type),
        h('td', {}, h('span', { class: 'swatch', style: `background:${t.category.color}` }), t.category.name,
          t.categoryMismatch ? h('div', { class: 'small expense' }, 'ชนิดไม่ตรงกับหมวด') : null),
        h('td', {}, t.note || h('span', { class: 'muted' }, '–')),
        h('td', { class: `num ${t.type === 'รายรับ' ? 'income' : 'expense'}` }, fmtMoney(t.amount)),
        h('td', {}, t.hasSlip ? h('button', { class: 'btn btn-ghost btn-sm', onclick: () => viewSlip(t) }, 'ดู') : h('span', { class: 'muted' }, '–')),
        h('td', { style: 'white-space:nowrap' },
          h('button', { class: 'btn btn-ghost btn-sm', onclick: () => editTx(t, el) }, 'แก้ไข'), ' ',
          h('button', { class: 'btn btn-danger btn-sm', onclick: () => deleteTx(t, el) }, 'ลบ')))))),
    h('div', { class: 'pager' },
      h('button', { class: 'btn btn-ghost btn-sm', disabled: page <= 1, onclick: () => renderTx(el, page - 1) }, '‹ ก่อนหน้า'),
      h('span', {}, `${data.total} รายการ · หน้า ${page}/${pages}`),
      h('button', { class: 'btn btn-ghost btn-sm', disabled: page >= pages, onclick: () => renderTx(el, page + 1) }, 'ถัดไป ›')),
  );
}

function categoryOptions(type) {
  return state.detail.categories.filter((c) => c.type === type).map((c) => ({ value: c.id, label: c.name }));
}

function editTx(t, el) {
  openDialog({
    title: `แก้ไขรายการ #${t.id}`,
    fields: [
      { name: 'type', label: 'ชนิด', type: 'select', value: t.type, options: [{ value: 'รายรับ', label: 'รายรับ' }, { value: 'รายจ่าย', label: 'รายจ่าย' }],
        onchange: (input, body) => {
          const sel = body.querySelector('select[name="category_id"]');
          put(sel, ...categoryOptions(input.value).map((o) => h('option', { value: o.value }, o.label)));
        } },
      { name: 'category_id', label: 'หมวด', type: 'select', value: t.category.id,
        options: categoryOptions(t.type).length ? categoryOptions(t.type) : [{ value: t.category.id, label: t.category.name }],
        hint: t.categoryMismatch ? `เดิมอยู่หมวด "${t.category.name}" ซึ่งชนิดไม่ตรง เลือกหมวดให้ตรงกับชนิดรายการ` : null },
      { name: 'amount', label: 'จำนวนเงิน (บาท)', type: 'number', step: '0.01', value: t.amount, required: true },
      { name: 'occurred_at', label: 'วันที่และเวลา', type: 'datetime-local', value: t.occurredAt.slice(0, 16).replace(' ', 'T'), required: true },
      { name: 'note', label: 'หมายเหตุ', type: 'textarea', value: t.note, maxlength: 500 },
    ],
    onSubmit: async (v) => {
      await api('PUT', `/admin/transactions/${t.id}`, { ...v, category_id: Number(v.category_id), occurred_at: v.occurred_at.replace('T', ' ') });
      toast('บันทึกรายการแล้ว');
      const d = await api('GET', `/admin/users/${state.detail.user.id}`);
      state.detail = d;
      renderTx(el);
      if (!d.issues.categoryMismatch.length && t.categoryMismatch) renderUser();
    },
  });
}

function deleteTx(t, el) {
  confirmDialog('ลบรายการ', `ลบ${t.type} ${fmtMoney(t.amount)} บาท (${t.category.name}) วันที่ ${fmtDate(t.occurredAt)}${t.hasSlip ? ' พร้อมรูปสลิป' : ''} ข้อมูลรายการจะถูกเก็บไว้ในประวัติการแก้ไข`, 'ลบรายการ', async () => {
    await api('DELETE', `/admin/transactions/${t.id}`);
    toast('ลบรายการแล้ว');
    renderTx(el);
  });
}

async function viewSlip(t) {
  try {
    const res = await api('GET', `/admin/transactions/${t.id}/slip`, undefined, true);
    const url = URL.createObjectURL(await res.blob());
    openDialog({
      title: `สลิปของรายการ #${t.id}`,
      content: h('img', { src: url, alt: 'รูปสลิป', style: 'max-width:100%;max-height:70vh;border-radius:10px;justify-self:center' }),
      okText: 'ปิด', cancel: false,
      onSubmit: async () => { URL.revokeObjectURL(url); },
    });
  } catch (err) {
    toast(err.message);
  }
}

// ---------- แท็บหมวดหมู่
function renderCategories(el) {
  const d = state.detail;
  put(el, 
    h('h2', {}, 'หมวดหมู่'),
    h('div', { class: 'table-wrap' }, h('table', {},
      h('thead', {}, h('tr', {}, ['หมวด', 'ชนิด', 'รายการ', ''].map((t, i) => h('th', { class: i === 2 ? 'num' : null }, t)))),
      h('tbody', {}, d.categories.map((c) => h('tr', {},
        h('td', {}, h('span', { class: 'swatch', style: `background:${c.color}` }), c.name, c.isDefault ? h('span', { class: 'badge', style: 'margin-left:.4rem' }, 'เริ่มต้น') : null),
        h('td', { class: c.type === 'รายรับ' ? 'income' : 'expense' }, c.type),
        h('td', { class: 'num' }, c.txCount),
        h('td', { class: 'num' }, h('button', { class: 'btn btn-ghost btn-sm', onclick: () => editCategory(c) }, 'แก้ไข'))))))),
    h('h2', { style: 'margin-top:1.5rem' }, 'งบประมาณรายเดือน'),
    d.budgets.length
      ? h('table', {}, h('tbody', {}, d.budgets.map((b) => h('tr', {}, h('td', {}, b.name), h('td', { class: 'num' }, fmtMoney(b.amount))))))
      : h('p', { class: 'muted' }, 'ผู้ใช้ยังไม่ได้ตั้งงบ'),
  );
}

function editCategory(c) {
  openDialog({
    title: `แก้ไขหมวด "${c.name}"`,
    fields: [
      { name: 'name', label: 'ชื่อหมวด', value: c.name, required: true, maxlength: 60 },
      { name: 'color', label: 'สี', type: 'color', value: c.color },
    ],
    onSubmit: async (v) => { await api('PUT', `/admin/categories/${c.id}`, v); await refresh('บันทึกหมวดแล้ว'); },
  });
}

// ---------- แท็บประวัติ
async function renderAudit(el) {
  put(el, h('p', { class: 'muted' }, 'กำลังโหลด…'));
  const d = await api('GET', '/admin/audit' + qs({ user_id: state.detail.user.id, limit: 100 }));
  put(el, auditList(d.items, false));
}

function describe(detail) {
  if (!detail || typeof detail !== 'object') return null;
  const parts = Object.entries(detail).map(([k, v]) => {
    const label = FIELDS[k] || k;
    if (v && typeof v === 'object' && 'from' in v) return `${label}: ${v.from ?? '–'} → ${v.to ?? '–'}`;
    if (Array.isArray(v)) return `${label}: ${v.join(', ')}`;
    if (typeof v === 'boolean') return `${label}: ${v ? 'ใช่' : 'ไม่'}`;
    return `${label}: ${v}`;
  });
  return parts.length ? h('div', { class: 'changes' }, parts.join(' · ')) : null;
}

function auditList(items, showTarget) {
  if (!items.length) return h('p', { class: 'muted' }, 'ยังไม่มีประวัติการแก้ไข');
  return h('ul', { class: 'audit' }, items.map((a) => h('li', {},
    h('div', {}, h('strong', {}, ACTIONS[a.action] || a.action),
      showTarget ? [' · ', a.targetEmail
        ? h('a', { href: `#user/${a.targetUserId}` }, a.targetEmail)
        : h('span', { class: 'muted' }, `ผู้ใช้ #${a.targetUserId} (ลบแล้ว)`)] : null),
    h('div', { class: 'when' }, `${fmtDate(a.createdAt)} โดย ${a.adminEmail}`),
    describe(a.detail))));
}

boot();
