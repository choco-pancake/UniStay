(() => {
  "use strict";

  const bell = document.getElementById("notification-bell");
  const popover = document.getElementById("notification-popover");
  if (!bell || !popover || !window.UniStayNotifications || bell.dataset.notificationsInitialized) return;
  bell.dataset.notificationsInitialized = 'true';
  const feedback = document.getElementById("feedback");
  const allList = document.getElementById("all-list");
  const dataKey = window.UniStayNotifications.cacheKey;
  const readKey = `${dataKey}:read`;
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
  let expandedNotification = null;
  let approvalContactDialog = null;

  function openApprovalContactPrompt(item) {
    if (!approvalContactDialog) {
      approvalContactDialog = document.createElement('dialog');
      approvalContactDialog.className = 'approval-contact-dialog';
      approvalContactDialog.setAttribute('aria-labelledby', 'approval-contact-title');
      approvalContactDialog.innerHTML = `
        <section class="approval-contact-card">
          <span class="approval-contact-icon" aria-hidden="true">✓</span>
          <h2 id="approval-contact-title">Your booking is approved</h2>
          <p id="approval-contact-message">Contact your landlord to arrange your move-in date, check-in time, and any remaining requirements.</p>
          <div class="approval-contact-actions">
            <button type="button" class="approval-contact-later">Later</button>
            <a class="approval-contact-view" href="dashboard.php">View active stay</a>
          </div>
        </section>`;
      document.body.append(approvalContactDialog);
      const close = () => { if (approvalContactDialog.open) approvalContactDialog.close(); };
      approvalContactDialog.querySelector('.approval-contact-later').addEventListener('click', close);
      approvalContactDialog.addEventListener('click', event => { if (event.target === approvalContactDialog) close(); });
      approvalContactDialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
    }

    const propertyId = Number(item.propertyId), roomId = Number(item.roomId);
    const hasRoom = Number.isInteger(propertyId) && propertyId > 0 && Number.isInteger(roomId) && roomId > 0;
    const destination = hasRoom
      ? `dorms.php?property=${encodeURIComponent(propertyId)}&room=${encodeURIComponent(roomId)}&contact=1`
      : 'dashboard.php';
    approvalContactDialog.querySelector('.approval-contact-view').href = destination;
    if (approvalContactDialog.open) approvalContactDialog.close();
    approvalContactDialog.showModal();
    approvalContactDialog.querySelector('.approval-contact-later').focus();
  }

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
        propertyId: item.property_id || item.propertyId || null,
        roomId: item.room_id || item.roomId || null,
        type:
          typeof item.type === "string" && icons[item.type]
            ? item.type
            : "system",
        title: item.title.slice(0, 120),
        message: item.message,
        timestamp: Number.isFinite(item.timestamp)
          ? item.timestamp
          : Date.now(),
        read: item.read === true,
      }));
  }

  function persist() {
    // The database is authoritative. Keeping notification payloads in
    // localStorage allowed a newly-created account to inherit a deleted
    // account's items when the numeric user id was reused.
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
    window.UniStayNotifications.alert(item);
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
    entry.addEventListener('click', async () => {
      if (item.type === 'approval') {
        markNotificationAsRead(item.id);
        if (inPopover) closePopover();
        openApprovalContactPrompt(item);
        return;
      }
      const propertyId = Number(item.propertyId), roomId = Number(item.roomId);
      const isBooking = item.type === 'booking' || item.bookingId || /booking/i.test(item.title);
      if (isBooking && Number.isInteger(propertyId) && propertyId > 0 && Number.isInteger(roomId) && roomId > 0) {
        await markNotificationAsRead(item.id);
        window.location.href = `dorms.php?property=${propertyId}&room=${roomId}`;
        return;
      }
      if (!isBooking && /^(?:account verified\b|verification\b|identity verification\b|credential review\b)/i.test(item.title.trim())) {
        await markNotificationAsRead(item.id);
        window.location.href = 'settings.php?notice=verification#verification-result';
        return;
      }
      if (inPopover) {
        expandedNotification = item;
        markNotificationAsRead(item.id);
        renderExpandedNotification();
        document.getElementById('notification-detail-back')?.focus();
      } else {
        markNotificationAsRead(item.id);
        document.getElementById('notification-page')?.focus();
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
      li.classList.add("notification-row-compact");
    } else {
      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "notification-delete";
      remove.textContent = "Delete";
      remove.setAttribute("aria-label", `Delete notification: ${item.title}`);
      remove.addEventListener("click", (e) => {
        e.stopPropagation();
        deleteNotification(item.id);
        const page = document.getElementById("notification-page");
        if (page) page.focus();
      });
      li.append(remove);
    }
    return li;
  }

  function renderExpandedNotification() {
    let panel = document.getElementById('notification-detail');
    if (!panel) {
      panel = document.createElement('section'); panel.id = 'notification-detail'; panel.className = 'notification-detail';
      document.getElementById('popover-list')?.after(panel);
    }
    panel.hidden = !expandedNotification;
    if (!expandedNotification) return;
    panel.replaceChildren();
    const back = document.createElement('button'); back.id = 'notification-detail-back'; back.type = 'button'; back.textContent = 'Back to notifications';
    back.addEventListener('click', () => { expandedNotification = null; render(); bell.focus({preventScroll:true}); });
    const title = document.createElement('h3'); title.textContent = expandedNotification.title;
    const message = document.createElement('p'); message.textContent = expandedNotification.message;
    panel.append(back, title, message);
    if (expandedNotification.bookingId && (!expandedNotification.propertyId || !expandedNotification.roomId)) {
      const unavailable = document.createElement('p'); unavailable.textContent = 'Room details are unavailable for this notification.'; panel.append(unavailable);
    }
    for (const id of ['popover-list','popover-empty','popover-actions']) {
      const element = document.getElementById(id); if (element) element.hidden = true;
    }
  }

  function render() {
    const unread = notifications.filter((item) => !isRead(item));
    updateBadge();
    const list = document.getElementById("popover-list");
    if (!list) return;
    list.replaceChildren(...unread.map((item) => makeEntry(item, true)));
    list.hidden = unread.length === 0;
    if (document.getElementById("popover-empty")) document.getElementById("popover-empty").hidden = unread.length !== 0;

    const popoverActions = document.getElementById("popover-actions");
    if (popoverActions) {
      popoverActions.hidden = unread.length === 0;
    }

    renderExpandedNotification();
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
      message: message.trim(),
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
    return fetch("../api/notifications.php", {
      method: "POST",
      keepalive: true,
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

  function markAllNotificationsAsRead() {
    notifications.forEach((item) => {
      item.read = true;
    });
    persistAndRender("All notifications marked as read.");
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

  function deleteNotification(id) {
    notifications = notifications.filter((item) => item.id !== id);
    persistAndRender("Notification deleted.");
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

  function clearAllNotifications() {
    notifications = [];
    persistAndRender("All notifications cleared.");
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

  function closePopover(focusBell = false) {
    if (expandedNotification) { expandedNotification = null; render(); }
    bell.setAttribute("aria-expanded", "false");
    if (window.UniStayPopover) window.UniStayPopover.hide(popover, focusBell ? bell : null);
    else { popover.hidden = true; if (focusBell) bell.focus(); }
  }

  function initializeControls() {
    const popoverList = document.getElementById("popover-list");
    if (popoverList && !document.getElementById("popover-actions")) {
      const actions = document.createElement("div");
      actions.className = "notification-actions";
      actions.id = "popover-actions";
      const markAll = document.createElement("button");
      markAll.type = "button";
      markAll.textContent = "Mark all as read";
      markAll.addEventListener("click", markAllNotificationsAsRead);
      const clearAll = document.createElement("button");
      clearAll.type = "button";
      clearAll.textContent = "Clear all";
      clearAll.addEventListener("click", clearAllNotifications);
      actions.append(markAll, clearAll);
      popoverList.after(actions);
    }

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
    if (back) back.href = returnPages[origin].href;
    if (back?.querySelector("span")) back.querySelector("span").textContent =
      `Back to ${returnPages[origin].label}`;
    document.getElementById("view-all").href =
      `notifications.php?from=${origin}`;
  }

  try {
    localStorage.removeItem(dataKey);
    localStorage.removeItem(readKey);
    notifications = clean(JSON.parse(document.getElementById("notification-data")?.textContent || "[]"));
  } catch {
    if (feedback)
      feedback.textContent =
        "Notifications could not be loaded or saved. Check your browser storage settings.";
  }

  async function fetchLatestNotifications() {
    try {
      await window.UniStayNotifications.ready;
      await window.UniStayNotifications.refresh().catch(() => {});
      const res = await fetch("../api/notifications.php", {cache: "no-store"});
      if (res.status === 401) { window.location.replace('../auth/login.php'); return; }
      if (res.ok) {
        const data = await res.json();
        if (data && data.ok && Array.isArray(data.notifications)) {
          notifications = clean(data.notifications);
          window.UniStayNotifications.observe(notifications);
          persist();
          render();
        }
      }
    } catch (_) {}
  }

  initializeControls();
  render();
  fetchLatestNotifications();

  bell.addEventListener("click", () => {
    const isOpen = !popover.hidden && !popover.classList.contains("is-closing");
    if (isOpen) {
      closePopover();
    } else {
      fetchLatestNotifications();
      if (window.UniStayPopover) window.UniStayPopover.show(popover);
      else popover.hidden = false;
      bell.setAttribute("aria-expanded", "true");
    }
  });
  document.addEventListener("click", (event) => {
    if (!event.composedPath().includes(bell) && !event.composedPath().includes(popover))
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
    ?.addEventListener("click", () => closePopover());
  window.addEventListener("focus", fetchLatestNotifications);

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
  setInterval(() => { if (!document.hidden) fetchLatestNotifications(); }, 3500);
})();
