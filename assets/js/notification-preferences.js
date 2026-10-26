(() => {
  'use strict';
  if (window.UniStayNotifications) return;
  const account = window.UNISTAY_NOTIFICATION_ACCOUNT;
  if (!account || !account.id) return;
  const cacheKey = `unistay-notifications:${account.role}:${account.id}`;
  let preferences = null;
  let csrfToken = '';
  let baseline = null;
  const controls = {
    togglePushNotifications: 'tenant_alerts',
    toggleEmailAnnouncements: 'announcement_emails',
    togglePushNotificationsLandlord: 'booking_alerts',
    toggleVerificationNotificationsLandlord: 'verification_alerts',
    toggleEmailSummariesLandlord: 'summary_emails',
  };
  const inputs = Object.entries(controls).map(([id, key]) => [document.getElementById(id), key]).filter(([el]) => el);
  let status = document.getElementById('notification-preference-status');
  if (inputs.length && !status) {
    status = document.createElement('p');
    status.id = 'notification-preference-status';
    status.setAttribute('role', 'status');
    (inputs[0][0].closest('.settings-card') || inputs[inputs.length - 1][0].closest('label').parentElement).append(status);
  }
  const report = text => { if (status) status.textContent = text; };
  const busy = value => inputs.forEach(([el]) => { el.disabled = value; });
  const sync = () => inputs.forEach(([el, key]) => { el.checked = preferences?.[key] !== false; });
  async function refresh() {
    const res = await fetch('../api/notifications.php?action=preferences', { cache: 'no-store' });
    const data = await res.json();
    if (!res.ok || !data.ok || data.user_id !== Number(account.id) || data.role !== account.role) throw new Error('Could not load notification settings. Refresh to try again.');
    preferences = data.preferences;
    csrfToken = data.csrf_token;
    sync();
    return preferences;
  }
  function enabled(item) {
    if (!preferences) return false;
    if (account.role === 'tenant') return preferences.tenant_alerts !== false;
    if (/verif|identity|government|credentials/i.test(item.title + ' ' + item.message)) return preferences.verification_alerts !== false;
    if (item.bookingId || item.booking_id || /booking|cancellation/.test(item.type)) return preferences.booking_alerts !== false;
    return true;
  }
  function alert(item) {
    if (!enabled(item)) return;
    let toast = document.getElementById('notification-toast');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'notification-toast';
      toast.className = 'notification-toast';
      toast.setAttribute('role', 'status');
      document.body.append(toast);
    }
    const title = document.createElement('strong'); title.textContent = item.title;
    const message = document.createElement('span'); message.textContent = item.message;
    toast.replaceChildren(title, message);
    requestAnimationFrame(() => toast.classList.add('is-visible'));
    clearTimeout(alert.timer);
    alert.timer = setTimeout(() => toast.classList.remove('is-visible'), 3500);
  }
  function observe(items) {
    const previous = baseline;
    baseline = new Set(items.map(item => item.id));
    if (!previous) return;
    const newest = items.find(item => !item.read && !previous.has(item.id) && enabled(item));
    if (newest) alert(newest);
  }
  busy(true);
  const ready = refresh().catch(error => { report(error.message); return null; }).finally(() => busy(!preferences));
  inputs.forEach(([el, key]) => el.addEventListener('change', async () => {
    const value = el.checked;
    busy(true);
    report('Saving notification settings...');
    try {
      const res = await fetch('../api/notifications.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save_preferences', csrf_token: csrfToken, preferences: { [key]: value } }),
      });
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.error || 'Preferences could not be saved.');
      preferences = data.preferences;
      try { localStorage.setItem(`${cacheKey}:preferences-changed`, String(Date.now())); } catch (_) { }
      report('Notification settings saved.');
    } catch (error) { report(error.message); }
    finally { sync(); busy(false); }
  }));
  window.addEventListener('storage', event => {
    if (event.key === `${cacheKey}:preferences-changed`) refresh().catch(() => { });
  });
  window.UniStayNotifications = { cacheKey, ready, refresh, enabled, alert, observe };
})();
