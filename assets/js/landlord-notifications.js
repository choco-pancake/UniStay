(() => {
  "use strict";

  const bell = document.getElementById("notification-bell");
  const popover = document.getElementById("notification-popover");
  if (!bell || !popover || !window.UniStayNotifications || bell.dataset.notificationsInitialized) return;
  bell.dataset.notificationsInitialized = 'true';
  const feedback = document.getElementById("feedback");
  const allList = document.getElementById("all-list");
  const dataKey = window.UniStayNotifications.cacheKey;
  const returnPages = {
    tenants: { href: "tenants.php", label: "Tenants" },
    settings: { href: "settings.php", label: "Settings" },
    properties: { href: "properties.php", label: "Properties" },
    dashboard: { href: "dashboard.php", label: "Dashboard" },
    rooms: { href: "rooms.php", label: "Rooms" },
  };
  const icons = {
    approval: "✓",
    booking: "⌂",
    cancellation: "×",
    message: "✉",
    saved: "♥",
    system: "•",
  };
  let filter = "all";
  let notifications = [];

  const INITIAL_LANDLORD_NOTIFS = [];

  function clean(data) {
    const ids = new Set();
    if (!Array.isArray(data)) return [];
    return data
      .filter((item) => {
        if (
          !item ||
          typeof item.id !== "string" ||
          typeof item.title !== "string" ||
          typeof item.message !== "string" ||
          ids.has(item.id)
        )
          return false;
        ids.add(item.id);
        return true;
      })
      .map((item) => ({
        id: item.id,
        bookingId: item.booking_id || item.bookingId || null,
        type:
          typeof item.type === "string" && icons[item.type]
            ? item.type
            : "system",
        title: item.title.slice(0, 120),
        message: item.message.slice(0, 500),
        timestamp: Number.isFinite(item.timestamp)
          ? item.timestamp
          : Date.now(),
        read: item.read === true,
      }));
  }

  function persist() {
    // Notification records are server-owned; never persist them across accounts.
  }

  function isRead(item) {
    return item.read;
  }

  function formatTimestamp(timestamp) {
    return new Date(timestamp).toLocaleString(undefined, {
      dateStyle: "medium",
      timeStyle: "short",
    });
  }

  function updateBadge() {
    if (!bell) return;
    const unreadCount = notifications.filter((item) => !isRead(item)).length;
    let badge = document.getElementById("notification-badge");
    if (!badge) {
      badge = document.createElement("span");
      badge.id = "notification-badge";
      badge.className = "notification-badge";
      badge.setAttribute("aria-hidden", "true");
      bell.append(badge);
    }
    badge.textContent = unreadCount > 99 ? "99+" : String(unreadCount);
    badge.hidden = unreadCount === 0;
    bell.setAttribute("aria-label", `Notifications, ${unreadCount} unread`);
  }

  function makeEntry(item, inPopover) {
    const li = document.createElement("li");
    li.className = "notification-row";
    li.classList.toggle("is-unread", !isRead(item));
    const entry = document.createElement("button");
    entry.type = "button";
    entry.className = "notification-entry";
    entry.classList.toggle("is-unread", !isRead(item));
    const icon = document.createElement("i");
    icon.className = "entry-icon";
    icon.setAttribute("aria-hidden", "true");
    icon.textContent = icons[item.type] || icons.system;
    const title = document.createElement("strong");
    title.textContent = item.title;
    const message = document.createElement("span");
    message.textContent = item.message;
    const timestamp = document.createElement("small");
    timestamp.textContent = `${formatTimestamp(item.timestamp)} · ${isRead(item) ? "Read" : "Unread"}`;
    entry.append(icon, title, message, timestamp);
    entry.setAttribute(
      "aria-label",
      `${item.title}. ${item.message}. ${isRead(item) ? "Read" : "Unread"}`,
    );
    entry.addEventListener("click", () => {
      const titleLower = (item.title || "").toLowerCase();
      const msgLower = (item.message || "").toLowerCase();
      const isBookingRequest = item.type === "booking" || item.bookingId || titleLower.includes("booking") || titleLower.includes("request");

      if (isBookingRequest) {
        markNotificationAsRead(item.id);
        if (inPopover) closePopover(true);
        const targetUrl = item.bookingId
          ? `tenants.php?highlight=${encodeURIComponent(item.bookingId)}`
          : `tenants.php?status=pending`;
        window.location.href = targetUrl;
        return;
      }

      // Informational notifications (such as Admin Verification):
      // In the floating popover, smoothly slide & fade out, mark as read, and update badge without unwanted redirects
      if (inPopover) {
        li.style.transition = "opacity 0.2s ease, transform 0.2s ease";
        li.style.opacity = "0";
        li.style.transform = "translateX(8px)";
        setTimeout(() => {
          markNotificationAsRead(item.id);
        }, 200);
      } else {
        markNotificationAsRead(item.id);
        const page = document.getElementById("notification-page");
        if (page) page.focus();
      }
    });
    li.append(entry);

    if (inPopover) {
      const markBtn = document.createElement("button");
      markBtn.type = "button";
      markBtn.className = "notification-mark-read";
      markBtn.textContent = "Mark as read";
      markBtn.setAttribute("aria-label", `Mark ${item.title} as read`);
      markBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        li.style.transition = "opacity 0.2s ease, transform 0.2s ease";
        li.style.opacity = "0";
        li.style.transform = "translateX(8px)";
        setTimeout(() => {
          markNotificationAsRead(item.id);
        }, 200);
      });
      li.append(markBtn);
    } else {
      const actions = document.createElement("div");
      actions.className = "notification-actions";
      const toggle = document.createElement("button");
      toggle.type = "button";
      toggle.className = "toggle-read";
      toggle.textContent = isRead(item) ? "Mark unread" : "Mark read";
      toggle.addEventListener("click", (e) => {
        e.stopPropagation();
        toggleReadState(item.id);
      });
      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "remove";
      remove.textContent = "Dismiss";
      remove.addEventListener("click", (e) => {
        e.stopPropagation();
        dismissNotification(item.id);
      });
      actions.append(toggle, remove);
      li.append(actions);
    }
    return li;
  }

  function renderPopover() {
    if (!popover) return;
    const list = document.getElementById("popover-list");
    const empty = document.getElementById("popover-empty");
    if (!list || !empty) return;
    list.replaceChildren();
    const unread = notifications.filter((item) => !isRead(item));
    if (unread.length === 0) {
      empty.hidden = false;
      list.hidden = true;
      return;
    }
    empty.hidden = true;
    list.hidden = false;
    unread.forEach((item) => list.append(makeEntry(item, true)));
  }

  function renderAll() {
    if (!allList) return;
    allList.replaceChildren();
    const empty = document.getElementById("all-empty");
    const items =
      filter === "all"
        ? notifications
        : filter === "unread"
          ? notifications.filter((item) => !isRead(item))
          : notifications.filter((item) => isRead(item));
    if (items.length === 0) {
      if (empty) empty.hidden = false;
      allList.hidden = true;
      return;
    }
    if (empty) empty.hidden = true;
    allList.hidden = false;
    items.forEach((item) => allList.append(makeEntry(item, false)));
  }

  function markNotificationAsRead(id) {
    const item = notifications.find((n) => n.id === id);
    if (!item || item.read) return;
    item.read = true;
    persist();
    updateBadge();
    renderPopover();
    renderAll();
    fetch("../api/notifications.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "mark_read", id }),
    }).then(async response => {
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || 'Could not update notifications.');
    }).catch(() => {
      if (feedback) feedback.textContent = 'Could not update notifications. Please try again.';
      fetchLatestNotifications();
    });
  }

  function toggleReadState(id) {
    const item = notifications.find((n) => n.id === id);
    if (!item) return;
    item.read = !item.read;
    persist();
    updateBadge();
    renderPopover();
    renderAll();
    fetch("../api/notifications.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: item.read ? "mark_read" : "mark_unread", id }),
    }).then(async response => {
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || 'Could not update notifications.');
    }).catch(() => {
      if (feedback) feedback.textContent = 'Could not update notifications. Please try again.';
      fetchLatestNotifications();
    });
  }

  function dismissNotification(id) {
    notifications = notifications.filter((n) => n.id !== id);
    persist();
    updateBadge();
    renderPopover();
    renderAll();
    fetch("../api/notifications.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "delete", id }),
    }).then(async response => {
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || 'Could not update notifications.');
    }).catch(() => {
      if (feedback) feedback.textContent = 'Could not update notifications. Please try again.';
      fetchLatestNotifications();
    });
  }

  function markAllRead() {
    notifications.forEach((item) => {
      item.read = true;
    });
    persist();
    updateBadge();
    renderPopover();
    renderAll();
    fetch("../api/notifications.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "mark_all_read" }),
    }).then(async response => {
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || 'Could not update notifications.');
    }).catch(() => {
      if (feedback) feedback.textContent = 'Could not update notifications. Please try again.';
      fetchLatestNotifications();
    });
  }

  function clearAll() {
    notifications = [];
    persist();
    updateBadge();
    renderPopover();
    renderAll();
    fetch("../api/notifications.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "clear_all" }),
    }).then(async response => {
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || 'Could not update notifications.');
    }).catch(() => {
      if (feedback) feedback.textContent = 'Could not update notifications. Please try again.';
      fetchLatestNotifications();
    });
  }

  function openPopover() {
    if (!popover || !bell) return;
    if (window.UniStayPopover) {
      window.UniStayPopover.show(popover);
    } else {
      popover.hidden = false;
    }
    bell.setAttribute("aria-expanded", "true");
  }

  function closePopover(focusBell = false) {
    if (!popover || !bell) return;
    if (window.UniStayPopover) {
      window.UniStayPopover.hide(popover, focusBell ? bell : null);
    } else {
      popover.hidden = true;
      if (focusBell) bell.focus();
    }
    bell.setAttribute("aria-expanded", "false");
  }

  async function fetchLatestNotifications() {
    try {
      await window.UniStayNotifications.ready;
      await window.UniStayNotifications.refresh().catch(() => { });
      const res = await fetch("../api/notifications.php", { cache: "no-store" });
      if (res.status === 401) { window.location.replace('../auth/login.php'); return; }
      if (res.ok) {
        const data = await res.json();
        if (data && data.ok && Array.isArray(data.notifications)) {
          notifications = clean(data.notifications);
          window.UniStayNotifications.observe(notifications);
          persist();
          updateBadge();
          renderPopover();
          renderAll();
        }
      }
    } catch (_) { }
  }

  function init() {
    try { localStorage.removeItem(dataKey); } catch (_) { }
    notifications = clean(INITIAL_LANDLORD_NOTIFS);

    const notifDataEl = document.getElementById("notification-data");
    if (notifDataEl) {
      try {
        const embedded = clean(JSON.parse(notifDataEl.textContent));
        notifications = embedded;
      } catch (_) { }
    }

    updateBadge();
    renderPopover();
    renderAll();
    fetchLatestNotifications();

    if (bell && popover) {
      bell.addEventListener("click", (e) => {
        e.stopPropagation();
        const open = bell.getAttribute("aria-expanded") === "true";
        if (open) {
          closePopover();
        } else {
          fetchLatestNotifications();
          openPopover();
        }
      });

      document.addEventListener("click", (e) => {
        if (!popover.contains(e.target) && !bell.contains(e.target)) {
          closePopover();
        }
      });
    }

    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && !popover.hidden) {
        event.preventDefault(); closePopover(true);
      }
    });
    document.addEventListener('focusin', event => {
      if (!bell.contains(event.target) && !popover.contains(event.target)) closePopover();
    });
    window.addEventListener('focus', fetchLatestNotifications);
    if (popover && !document.getElementById('popover-actions')) {
      const actions = document.createElement('div');
      actions.className = 'notification-actions'; actions.id = 'popover-actions';
      for (const [label, handler] of [['Mark all as read', markAllRead], ['Clear all', clearAll]]) {
        const button = document.createElement('button'); button.type = 'button';
        button.textContent = label; button.addEventListener('click', handler); actions.append(button);
      }
      document.getElementById('popover-list')?.after(actions);
    }
    const readAllBtn = document.getElementById("read-all");
    if (readAllBtn) readAllBtn.addEventListener("click", markAllRead);

    const clearAllBtn = document.getElementById("clear-all");
    if (clearAllBtn) clearAllBtn.addEventListener("click", clearAll);

    // Return link configuration for landlord notifications page
    const backLink = document.getElementById("back-to-page");
    if (backLink) {
      const urlParams = new URLSearchParams(window.location.search);
      const from = urlParams.get("from");
      if (from && returnPages[from]) {
        backLink.href = returnPages[from].href;
        const span = backLink.querySelector("span");
        if (span) span.textContent = `Back to ${returnPages[from].label}`;
      } else {
        backLink.href = "properties.php";
        const span = backLink.querySelector("span");
        if (span) span.textContent = "Back to Properties";
      }
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
  setInterval(() => { if (!document.hidden) fetchLatestNotifications(); }, 30000);
})();
