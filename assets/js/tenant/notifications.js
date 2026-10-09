(() => {
  "use strict";

  const bell = document.getElementById("notification-bell");
  const popover = document.getElementById("notification-popover");
  const feedback = document.getElementById("feedback");
  const allList = document.getElementById("all-list");
  const dataKey = "unistay-notifications-data";
  const readKey = "unistay-notifications-read";
  const returnPages = {
    dashboard: { href: "dashboard.php", label: "Dashboard" },
    map: { href: "map.php", label: "Map" },
    bookings: { href: "rooms.php", label: "Bookings" },
    settings: { href: "settings.php", label: "Settings" },
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
    localStorage.setItem(dataKey, JSON.stringify(notifications));
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

  function showToast(item) {
    let toast = document.getElementById("notification-toast");
    if (!toast) {
      toast = document.createElement("div");
      toast.id = "notification-toast";
      toast.className = "notification-toast";
      toast.setAttribute("role", "status");
      toast.setAttribute("aria-live", "polite");
      document.body.append(toast);
    }
    toast.replaceChildren();
    const title = document.createElement("strong");
    title.textContent = item.title;
    const message = document.createElement("span");
    message.textContent = item.message;
    toast.append(title, message);
    requestAnimationFrame(() => toast.classList.add("is-visible"));
    clearTimeout(showToast.timeout);
    showToast.timeout = setTimeout(() => {
      toast.classList.remove("is-visible");
    }, 3500);
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
      else document.getElementById("notification-page").focus();
    });
    li.append(entry);

    const remove = document.createElement("button");
    remove.type = "button";
    remove.className = "notification-delete";
    remove.textContent = "Delete";
    remove.setAttribute("aria-label", `Delete notification: ${item.title}`);
    remove.addEventListener("click", () => {
      deleteNotification(item.id);
      if (inPopover) bell.focus();
      else document.getElementById("notification-page").focus();
    });
    li.append(remove);
    if (inPopover) li.classList.add("notification-row-compact");
    return li;
  }

  function render() {
    const unread = notifications.filter((item) => !isRead(item));
    updateBadge();
    const list = document.getElementById("popover-list");
    list.replaceChildren(...unread.map((item) => makeEntry(item, true)));
    list.hidden = unread.length === 0;
    document.getElementById("popover-empty").hidden = unread.length !== 0;

    if (allList) {
      const visible = notifications.filter(
        (item) =>
          filter === "all" ||
          (filter === "read" ? isRead(item) : !isRead(item)),
      );
      allList.replaceChildren(...visible.map((item) => makeEntry(item, false)));
      allList.hidden = visible.length === 0;
      document.getElementById("all-empty").hidden = visible.length !== 0;
      document.getElementById("empty-title").textContent = notifications.length
        ? "Nothing in this filter just yet"
        : "You’re all caught up.";
      const notificationCount = document.getElementById("notification-count");
      if (notificationCount)
        notificationCount.textContent = `${notifications.length} total · ${unread.length} unread`;
      const readAll = document.getElementById("read-all");
      if (readAll) readAll.disabled = unread.length === 0;
      document
        .querySelectorAll("[data-notification-filter]")
        .forEach((button) => {
          button.setAttribute(
            "aria-pressed",
            String(button.dataset.notificationFilter === filter),
          );
          const count = button.querySelector(".filter-count");
          if (count)
            count.textContent =
              button.dataset.notificationFilter === "all"
                ? notifications.length
                : button.dataset.notificationFilter === "unread"
                  ? unread.length
                  : notifications.length - unread.length;
        });
      const counts = {
        total: notifications.length,
        unread: unread.length,
        read: notifications.length - unread.length,
      };
      Object.entries(counts).forEach(([id, value]) => {
        const node = document.getElementById(`summary-${id}`);
        if (node) node.textContent = value;
      });
    }
  }

  function persistAndRender(message) {
    try {
      persist();
      if (feedback) feedback.textContent = message;
    } catch {
      if (feedback)
        feedback.textContent = `${message} Notifications could not be saved in this browser.`;
    }
    render();
  }

  function addNotification({ type = "system", title, message }) {
    if (
      typeof title !== "string" ||
      !title.trim() ||
      typeof message !== "string" ||
      !message.trim()
    ) {
      throw new TypeError(
        "A notification requires a non-empty title and message.",
      );
    }
    const item = {
      id:
        window.crypto && typeof window.crypto.randomUUID === "function"
          ? window.crypto.randomUUID()
          : `${Date.now()}-${Math.random().toString(36).slice(2)}`,
      type: icons[type] ? type : "system",
      title: title.trim().slice(0, 120),
      message: message.trim().slice(0, 500),
      timestamp: Date.now(),
      read: false,
    };
    notifications.unshift(item);
    persistAndRender("New notification added.");
    showToast(item);
    return item;
  }

  function markNotificationAsRead(id) {
    const item = notifications.find((notification) => notification.id === id);
    if (!item || isRead(item)) return;
    item.read = true;
    persistAndRender("Notification marked as read.");
  }

  function markAllNotificationsAsRead() {
    notifications.forEach((item) => {
      item.read = true;
    });
    persistAndRender("All notifications marked as read.");
  }

  function deleteNotification(id) {
    notifications = notifications.filter((item) => item.id !== id);
    persistAndRender("Notification deleted.");
  }

  function clearAllNotifications() {
    notifications = [];
    persistAndRender("All notifications cleared.");
  }

  function closePopover(focusBell = false) {
    popover.hidden = true;
    bell.setAttribute("aria-expanded", "false");
    if (focusBell) bell.focus();
  }

  function initializeControls() {
    const actions = document.createElement("div");
    actions.className = "notification-actions";
    const markAll = document.createElement("button");
    markAll.type = "button";
    markAll.textContent = "Mark all as read";
    markAll.addEventListener("click", markAllNotificationsAsRead);
    const clearAll = document.createElement("button");
    clearAll.type = "button";
    clearAll.textContent = "Clear all";
    clearAll.addEventListener("click", clearAllNotifications);
    actions.append(markAll, clearAll);
    document.getElementById("popover-list").after(actions);

    document
      .querySelectorAll("[data-notification-filter]")
      .forEach((button) => {
        button.addEventListener("click", () => {
          filter = button.dataset.notificationFilter;
          render();
        });
      });
    document.getElementById("read-all")?.addEventListener("click", () => {
      markAllNotificationsAsRead();
      document.getElementById("notification-page").focus();
    });
    document
      .getElementById("clear-all")
      ?.addEventListener("click", clearAllNotifications);
  }

  const originQuery = new URLSearchParams(window.location.search).get("from");
  if (allList) {
    const origin = Object.hasOwn(returnPages, originQuery)
      ? originQuery
      : "dashboard";
    const back = document.getElementById("back-to-page");
    back.href = returnPages[origin].href;
    back.querySelector("span").textContent =
      `Back to ${returnPages[origin].label}`;
    document.getElementById("view-all").href =
      `notifications.php?from=${origin}`;
  }

  try {
    notifications = clean(JSON.parse(localStorage.getItem(dataKey)));
    const legacyReadIds = JSON.parse(localStorage.getItem(readKey));
    if (Array.isArray(legacyReadIds)) {
      const oldReadIds = new Set(
        legacyReadIds.filter((id) => typeof id === "string"),
      );
      notifications.forEach((item) => {
        if (oldReadIds.has(item.id)) item.read = true;
      });
    }
    const embedded = clean(
      JSON.parse(document.getElementById("notification-data").textContent),
    );
    embedded.forEach((item) => {
      if (!notifications.some((existing) => existing.id === item.id))
        notifications.push(item);
    });
    persist();
  } catch {
    if (feedback)
      feedback.textContent =
        "Notifications could not be loaded or saved. Check your browser storage settings.";
  }

  initializeControls();
  bell.addEventListener("click", () => {
    popover.hidden = !popover.hidden;
    bell.setAttribute("aria-expanded", String(!popover.hidden));
  });
  document.addEventListener("click", (event) => {
    if (!bell.contains(event.target) && !popover.contains(event.target))
      closePopover();
  });
  document.addEventListener("focusin", (event) => {
    if (!bell.contains(event.target) && !popover.contains(event.target))
      closePopover();
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !popover.hidden) {
      event.preventDefault();
      closePopover(true);
    }
  });
  document
    .getElementById("view-all")
    .addEventListener("click", () => closePopover());
  window.addEventListener("storage", (event) => {
    if (event.key !== dataKey && event.key !== null) return;
    try {
      notifications = clean(JSON.parse(localStorage.getItem(dataKey)));
      render();
    } catch {
      if (feedback)
        feedback.textContent =
          "Notifications could not be synchronized across tabs.";
    }
  });

  window.NotificationSystem = Object.freeze({
    add: addNotification,
    addNotification,
    render: render,
    updateBadge,
    markAsRead: markNotificationAsRead,
    markNotificationAsRead,
    markAllAsRead: markAllNotificationsAsRead,
    markAllNotificationsAsRead,
    delete: deleteNotification,
    deleteNotification,
    clearAll: clearAllNotifications,
    clearAllNotifications,
  });
  render();
})();
