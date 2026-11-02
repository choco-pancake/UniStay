(() => {
  'use strict';
  if (document.getElementById('notification-bell') || !window.UniStayNotifications) return;
  let firstLoad = true;
  async function poll() {
    try {
      await window.UniStayNotifications.ready;
      const response = await fetch('../api/notifications.php', { cache: 'no-store' });
      if (response.status === 401) {
        window.location.replace('../auth/login.php');
        return;
      }
      if (!response.ok) return;
      const data = await response.json();
      if (!data.ok || !Array.isArray(data.notifications)) return;
      if (firstLoad) {
        // Establish a baseline without replaying historical items as toasts.
        window.UniStayNotifications.observe(data.notifications);
        firstLoad = false;
        return;
      }
      window.UniStayNotifications.observe(data.notifications);
    } catch (_) { }
  }
  poll();
  window.addEventListener('focus', poll);
  setInterval(() => { if (!document.hidden) poll(); }, 3500);
})();
