(() => {
  "use strict";

  const bell = document.getElementById("notification-bell");
  const popover = document.getElementById("notification-popover");
  const feedback = document.getElementById("feedback");
  const allList = document.getElementById("all-list");
  const dataKey = "unistay-landlord-notifications-data";
  const returnPages = {
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
    try {
      localStorage.setItem(dataKey, JSON.stringify(notifications));
    } catch (_) {}
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
      markNotificationAsRead(item.id);
      if (inPopover) closePopover(true);
      else {
        const page = document.getElementById("notification-page");
        if (page) page.focus();
      }
    });
    li.append(entry);

    if (!inPopover) {
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
    unread.slice(0, 5).forEach((item) => list.append(makeEntry(item, true)));
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
    }).catch(() => {});
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
      body: JSON.stringify({ action: "mark_read", id }),
    }).catch(() => {});
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
      body: JSON.stringify({ action: "mark_read", id }),
    }).catch(() => {});
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
    }).catch(() => {});
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
    }).catch(() => {});
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
      const res = await fetch("../api/notifications.php");
      if (res.ok) {
        const data = await res.json();
        if (data && data.ok && Array.isArray(data.notifications)) {
          notifications = clean(data.notifications);
          persist();
          updateBadge();
          renderPopover();
          renderAll();
        }
      }
    } catch (_) {}
  }

  function init() {
    try {
      const stored = localStorage.getItem(dataKey);
      if (stored) {
        const parsed = clean(JSON.parse(stored));
        // Purge any legacy mock notifications (notif-ll-*)
        notifications = parsed.filter(item => !item.id.startsWith("notif-ll-"));
        if (notifications.length !== parsed.length) {
          persist();
        }
      } else {
        notifications = clean(INITIAL_LANDLORD_NOTIFS);
        persist();
      }
    } catch (_) {
      notifications = clean(INITIAL_LANDLORD_NOTIFS);
    }

    const notifDataEl = document.getElementById("notification-data");
    if (notifDataEl) {
      try {
        const embedded = clean(JSON.parse(notifDataEl.textContent));
        if (embedded.length > 0) {
          notifications = embedded;
          persist();
        }
      } catch (_) {}
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
})();
