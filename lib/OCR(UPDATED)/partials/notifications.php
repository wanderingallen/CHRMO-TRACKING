<?php
// Unified Notifications UI + JS + Styles
?>
<style>
  .notification-icon { position: relative; margin-right: 20px; cursor: pointer; width: 40px; height: 40px; display: inline-flex; align-items: center; justify-content: center; border-radius: 999px; }
  .notification-icon i.fa-bell { font-size: 20px; color: #0097A7; }
  .notification-badge { position: absolute; top: -4px; right: -2px; min-width: 16px; height: 16px; padding: 0 4px; border-radius: 10px; background: #FF5252; color: #fff; display: none; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; line-height: 1; box-shadow: 0 0 0 2px #fff; }
  .notification-dropdown { position: absolute; top: 110%; right: 0; background: #fff; border-radius: 12px; box-shadow: 0 12px 36px rgba(0,0,0,.14), 0 2px 8px rgba(0,0,0,.06); width: 380px; max-height: 480px; overflow-y: auto; display: none; border: 1px solid #eef3f7; z-index: 100; }
  .notification-dropdown.show { display: block; animation: notifDropIn .18s ease-out; }
  @keyframes notifDropIn { from { opacity:0; transform: translateY(-6px); } to { opacity:1; transform: translateY(0); } }
  .notification-dropdown::-webkit-scrollbar { width: 4px; }
  .notification-dropdown::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
  .notification-header { padding: 14px 16px 10px; border-bottom: 1px solid #f1f5f9; display:flex; align-items:center; justify-content: space-between; }
  .notification-header h3 { font-size: 14px; color: #0f172a; margin: 0; font-weight: 700; }
  .notification-clear { color: #64748b; font-size: 12px; cursor: pointer; font-weight: 500; padding: 4px 10px; border-radius: 6px; }
  .notification-item { padding: 12px 16px; border-bottom: 1px solid #f8fafc; background:#fff; cursor:pointer; position: relative; }
  .notification-item.unread { background: #f8fdfe; }
  .notification-item.unread::before { content: ''; position:absolute; left:0; top:12px; bottom:12px; width:3px; background: #0891b2; border-radius: 0 2px 2px 0; }
  .notification-item:last-child { border-bottom: none; }
  .notif-top-row { display:flex; align-items:flex-start; gap:10px; }
  .notif-type-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; margin-top: 5px; }
  .notif-type-dot.type-memo { background: #7c3aed; }
  .notif-type-dot.type-payroll { background: #16a34a; }
  .notif-type-dot.type-travel { background: #d97706; }
  .notif-type-dot.type-announcement { background: #db2777; }
  .notif-type-dot.type-default { background: #0891b2; }
  .notif-body { flex:1; min-width:0; }
  .notif-title-row { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:3px; }
  .notif-title-text { font-weight: 600; font-size: 13px; color:#1e293b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .notif-time { color: #94a3b8; font-size: 11px; white-space:nowrap; flex-shrink:0; }
  .notif-content-text { font-size: 12.5px; color:#64748b; line-height:1.45; margin-bottom:4px; }
  .notif-details { display:flex; flex-wrap:wrap; gap:5px; margin-top:5px; }
  .notif-detail-chip { display:inline-flex; align-items:center; gap:3px; padding:2px 7px; border-radius:5px; font-size:11px; font-weight:500; background:#f1f5f9; color:#475569; }
  .notif-status-dot { width:6px; height:6px; border-radius:50%; display:inline-block; }
  .notif-status-dot.pending { background:#f59e0b; }
  .notif-status-dot.in-review { background:#3b82f6; }
  .notif-status-dot.completed { background:#22c55e; }
  .notif-empty { text-align:center; padding:32px 20px; color:#94a3b8; font-size:13px; }
</style>
<div class="notification-icon" id="notificationIcon" aria-haspopup="true" aria-expanded="false" title="Notifications">
  <i class="fas fa-bell" aria-hidden="true"></i>
  <span class="notification-badge" id="notificationBadge" aria-live="polite" aria-atomic="true" role="status">0</span>
  <div class="notification-dropdown" id="notificationDropdown" role="menu" aria-label="Notifications">
    <div class="notification-header">
      <h3>Notifications</h3>
      <div class="notification-clear" id="clearNotifications">Clear All</div>
    </div>
    <!-- Items will be injected dynamically -->
  </div>
</div>
<script>
(function(){
  const notificationIcon = document.getElementById('notificationIcon');
  const notificationBadge = document.getElementById('notificationBadge');
  const notificationDropdown = document.getElementById('notificationDropdown');
  const clearNotificationsBtn = document.getElementById('clearNotifications');

  function getTypeClass(title) {
    const t = (title || '').toLowerCase();
    if (t.includes('memo')) return 'type-memo';
    if (t.includes('payroll')) return 'type-payroll';
    if (t.includes('travel')) return 'type-travel';
    if (t.includes('announcement') || t.includes('advisory')) return 'type-announcement';
    return 'type-default';
  }
  function getStatusDot(status) {
    const s = (status || '').toLowerCase();
    if (s.includes('pending')) return 'pending';
    if (s.includes('review')) return 'in-review';
    if (s.includes('completed') || s.includes('approved')) return 'completed';
    return 'pending';
  }

  async function loadNotifications() {
    try {
      const res = await fetch('tracking.php?action=notifications', { cache: 'no-store' });
      let data = { notifications: [], count: 0 };
      try {
        const ct = (res.headers.get('content-type') || '').toLowerCase();
        if (ct.includes('application/json')) {
          data = await res.json();
        } else {
          const txt = await res.text();
          try { data = JSON.parse(txt); } catch(_) { data = { notifications: [], count: 0 }; }
        }
      } catch(_) {
        data = { notifications: [], count: 0 };
      }
      const list = data.notifications || [];
      const isCleared = data.cleared === true;

      notificationDropdown.querySelectorAll('.notification-item').forEach(el => el.remove());

      if (isCleared && list.length === 0) {
        notificationBadge.textContent = '0';
        notificationBadge.style.display = 'none';
        notificationIcon.classList.remove('has-unread');
        const noNotif = document.createElement('div');
        noNotif.className = 'notification-item';
        noNotif.innerHTML = `<div class="notif-empty">No notifications</div>`;
        notificationDropdown.appendChild(noNotif);
        return;
      }

      const count = (data.count || 0) >>> 0;
      notificationBadge.textContent = String(count);
      notificationBadge.style.display = count > 0 ? 'inline-flex' : 'none';
      notificationIcon.classList.toggle('has-unread', count > 0);

      if (list.length === 0) {
        const noNotif = document.createElement('div');
        noNotif.className = 'notification-item';
        noNotif.innerHTML = `<div class="notif-empty">No notifications</div>`;
        notificationDropdown.appendChild(noNotif);
      } else {
        list.forEach(n => {
          const item = document.createElement('div');
          item.className = 'notification-item' + (n.unread ? ' unread' : '');
          item.dataset.id = n.id;
          const typeClass = getTypeClass(n.title);
          const statusClass = getStatusDot(n.doc_status || n.title);

          // Parse content for sender info
          const parts = (n.content || '').split('•').map(s => s.trim());
          const sender = parts[1] || '';

          // Build minimal detail chips
          let chipHtml = '';
          if (n.department) chipHtml += `<span class="notif-detail-chip">${n.department}</span>`;
          if (n.doc_status) chipHtml += `<span class="notif-detail-chip"><span class="notif-status-dot ${statusClass}"></span> ${n.doc_status}</span>`;
          if (sender) chipHtml += `<span class="notif-detail-chip">${sender}</span>`;

          item.innerHTML = `
            <div class="notif-top-row">
              <span class="notif-type-dot ${typeClass}"></span>
              <div class="notif-body">
                <div class="notif-title-row">
                  <span class="notif-title-text">${n.title || 'Notification'}</span>
                  <span class="notif-time">${n.time || ''}</span>
                </div>
                <div class="notif-content-text">${n.content || ''}</div>
                ${chipHtml ? '<div class="notif-details">' + chipHtml + '</div>' : ''}
              </div>
            </div>
          `;
          item.addEventListener('click', async () => {
            try {
              if (n.id) {
                await fetch(`tracking.php?action=mark_read&id=${encodeURIComponent(n.id)}`, { cache: 'no-store', keepalive: true });
              }
            } catch(_) {}
            const target = n.id ? `tracking.php?id=${encodeURIComponent(n.id)}` : 'tracking.php';
            window.location.href = target;
          });
          notificationDropdown.appendChild(item);
        });
      }
    } catch(err) {
      // Swallow errors
    }
  }

  async function openCloseDropdown(e){
    e && e.stopPropagation();
    const willShow = !notificationDropdown.classList.contains('show');
    notificationDropdown.classList.toggle('show');
    notificationIcon.setAttribute('aria-expanded', willShow ? 'true' : 'false');
    if (notificationDropdown.classList.contains('show')) {
      try { await fetch('tracking.php?action=mark_all_read', { cache: 'no-store', keepalive: true }); } catch(_) {}
      notificationDropdown.querySelectorAll('.notification-item').forEach(item => item.classList.remove('unread'));
      notificationBadge.textContent = '0';
      notificationBadge.style.display = 'none';
      notificationIcon.classList.remove('has-unread');
      loadNotifications();
    }
  }

  async function clearNotifications(e){
    e.stopPropagation();
    try { await fetch('tracking.php?action=clear_all_notifications', { cache: 'no-store', keepalive: true }); } catch(_) {}
    notificationDropdown.querySelectorAll('.notification-item').forEach(item => item.remove());
    notificationBadge.textContent = '0';
    notificationBadge.style.display = 'none';
    notificationIcon.classList.remove('has-unread');
    const noNotif = document.createElement('div');
    noNotif.className = 'notification-item';
    noNotif.innerHTML = `<div class="notif-empty">No notifications</div>`;
    notificationDropdown.appendChild(noNotif);
  }

  if (notificationIcon) notificationIcon.addEventListener('click', openCloseDropdown);
  if (clearNotificationsBtn) clearNotificationsBtn.addEventListener('click', async (e) => {
    e.stopPropagation();
    await clearNotifications(e);
  });
  document.addEventListener('click', () => {
    notificationDropdown.classList.remove('show');
  });
  notificationDropdown.addEventListener('click', e => e.stopPropagation());

  window.loadNotifications = loadNotifications;
  document.addEventListener('DOMContentLoaded', loadNotifications);
  let pollTimer = setInterval(loadNotifications, 30000);

  // Realtime via SSE
  let lastId = 0;
  function startSSE(){
    try {
      const es = new EventSource('api/notifications_stream.php?last_id=' + encodeURIComponent(lastId));
      es.addEventListener('notification', (e)=>{
        try {
          const payload = JSON.parse(e.data || '{}');
          if (payload.last_id) lastId = payload.last_id;
          const items = Array.isArray(payload.items) ? payload.items : [];
          if (items.length > 0) loadNotifications();
        } catch(_){}
      });
      es.addEventListener('end', ()=>{
        es.close();
        setTimeout(startSSE, 1000);
      });
      es.onerror = function(){ try{ es.close(); }catch(_){ } setTimeout(startSSE, 2000); };
      if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    } catch(_) {}
  }
  document.addEventListener('DOMContentLoaded', startSSE);
})();
</script>

<script type="module">
  import { initializeApp } from "https://www.gstatic.com/firebasejs/12.6.0/firebase-app.js";
  import { getFirestore, collection, onSnapshot } from "https://www.gstatic.com/firebasejs/12.6.0/firebase-firestore.js";

  const firebaseConfig = {
    apiKey: <?= json_encode(getenv('FIREBASE_API_KEY') ?: 'ROTATED_AND_STORE_OUTSIDE_SOURCE_CONTROL') ?>,
    authDomain: "chrmo-dta-capstone.firebaseapp.com",
    databaseURL: "https://chrmo-dta-capstone-default-rtdb.asia-southeast1.firebasedatabase.app",
    projectId: "chrmo-dta-capstone",
    storageBucket: "chrmo-dta-capstone.firebasestorage.app",
    messagingSenderId: "654853931664",
    appId: "1:654853931664:web:2ff43fa7891ab848218e15",
    measurementId: "G-8T4VTXZQNE"
  };

  const app = initializeApp(firebaseConfig);
  const db = getFirestore(app);

  const notifRef = collection(db, 'notifications');

  onSnapshot(notifRef, (snapshot) => {
    console.log('[Firestore] notifications changed, refreshing bell UI');
    if (typeof window.loadNotifications === 'function') {
      window.loadNotifications();
    }
  });
</script>
