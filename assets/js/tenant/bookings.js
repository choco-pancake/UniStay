(() => {
  "use strict";
  const cards = [...document.querySelectorAll(".booking-card")];
  const filters = [...document.querySelectorAll(".booking-filter")];
  const search = document.getElementById("booking-search");
  const feedback = document.getElementById("booking-feedback");
  const messageDialog = document.getElementById("message-dialog");
  const cancelDialog = document.getElementById("cancel-dialog");
  const messageText = document.getElementById("message-text");
  const key = "unistay-booking-preview";
  const validStatuses = ["active", "pending", "upcoming", "cancelled"];
  const state = Object.fromEntries(
    cards.map((card) => [
      card.dataset.id,
      {
        status: card.dataset.status,
        saved: false,
        cancelled: false,
        draft: "",
      },
    ]),
  );
  let filter = "all",
    selected = null,
    opener = null;
  try {
    const stored = JSON.parse(sessionStorage.getItem(key));
    if (stored && typeof stored === "object")
      for (const id of Object.keys(state)) {
        const value = stored[id];
        if (value && typeof value === "object")
          state[id] = {
            status: validStatuses.includes(value.status)
              ? value.status
              : state[id].status,
            saved: value.saved === true,
            cancelled: value.cancelled === true || value.status === "cancelled",
            draft:
              typeof value.draft === "string" ? value.draft.slice(0, 2000) : "",
          };
      }
  } catch {
    feedback.textContent =
      "Saved booking changes are unavailable. You can still use this preview.";
  }
  function persist(message) {
    try {
      sessionStorage.setItem(key, JSON.stringify(state));
      feedback.textContent = message;
    } catch {
      feedback.textContent =
        message + " Changes could not be saved for page reloads.";
    }
  }
  function render() {
    let visible = 0;
    const counts = {
      all: cards.length,
      active: 0,
      pending: 0,
      upcoming: 0,
      saved: 0,
    };
    cards.forEach((card) => {
      const value = state[card.dataset.id];
      const statusValue = value.status;
      if (statusValue !== "cancelled") counts[statusValue]++;
      if (value.saved) counts.saved++;
      const matches =
        filter === "all" ||
        (filter === "saved"
          ? value.saved
          : !value.cancelled && statusValue === filter);
      const text = [
        card.querySelector("h2").textContent,
        card.querySelector(".booking-meta").textContent,
        card.querySelector(".booking-description").textContent,
      ]
        .join(" ")
        .toLowerCase();
      card.hidden = !(
        matches && text.includes(search.value.trim().toLowerCase())
      );
      if (!card.hidden) visible++;
      const bookmark = card.querySelector(".bookmark");
      bookmark.setAttribute("aria-pressed", String(value.saved));
      bookmark.setAttribute(
        "aria-label",
        `${value.saved ? "Unsave" : "Save"} ${statusValue} booking`,
      );
      const statusBadge = card.querySelector(".booking-status");
      statusBadge.textContent = {
        active: "Active Stay",
        pending: "Pending Approval",
        upcoming: "Upcoming Stay",
        cancelled: "Cancelled",
      }[value.status];
      statusBadge.classList.toggle("cancelled", value.status === "cancelled");
      const cancel = card.querySelector(".cancel-stay");
      cancel.disabled = value.cancelled;
      cancel.querySelector("span").textContent = value.cancelled
        ? "Cancelled"
        : "Cancel Stay";
    });
    filters.forEach((button) => {
      button.querySelector(".filter-count").textContent =
        counts[button.dataset.filter];
      button.setAttribute(
        "aria-pressed",
        String(filter === button.dataset.filter),
      );
    });
    document.getElementById("booking-empty").hidden = visible !== 0;
  }
  function restoreFocus() {
    if (opener && !opener.disabled && !opener.closest("[hidden]"))
      opener.focus();
    else filters.find((button) => button.dataset.filter === filter).focus();
  }
  function addNotification(details) {
    if (!window.NotificationSystem) {
      feedback.textContent = "Notification system is unavailable.";
      return;
    }
    window.NotificationSystem.add(details);
  }
  function dormName(card) {
    return card.querySelector(".booking-body h2").textContent.trim();
  }
  function landlordName(card) {
    return card
      .querySelector(".landlord-name")
      .childNodes[0].textContent.trim();
  }
  function updateBookingStatus(id, status) {
    const card = cards.find((item) => item.dataset.id === id);
    if (!card || !validStatuses.includes(status) || state[id].status === status)
      return;
    state[id].status = status;
    state[id].cancelled = status === "cancelled";
    persist("Booking status updated.");
    render();
    const dorm = dormName(card);
    if (status === "active")
      addNotification({
        type: "approval",
        title: "Booking Approved",
        message: `Your stay at ${dorm} is now active.`,
      });
    else if (status === "pending")
      addNotification({
        type: "booking",
        title: "Pending Approval",
        message: `Your booking at ${dorm} is waiting for landlord approval.`,
      });
    else if (status === "cancelled")
      addNotification({
        type: "cancellation",
        title: "Booking Cancelled",
        message: `Your stay at ${dorm} has been cancelled.`,
      });
    else
      addNotification({
        type: "booking",
        title: "Booking Updated",
        message: `Your booking at ${dorm} has been updated.`,
      });
  }
  for (const dialog of [messageDialog, cancelDialog])
    dialog.addEventListener("close", restoreFocus);
  document
    .querySelectorAll("[data-close]")
    .forEach((button) =>
      button.addEventListener("click", () =>
        document.getElementById(button.dataset.close).close(),
      ),
    );
  document.querySelector(".booking-grid").addEventListener("click", (event) => {
    const card = event.target.closest(".booking-card");
    if (!card) return;
    const id = card.dataset.id;
    if (event.target.closest(".bookmark")) {
      state[id].saved = !state[id].saved;
      const saved = state[id].saved;
      persist(saved ? "Booking saved." : "Booking removed from saved.");
      render();
      addNotification(
        saved
          ? {
              type: "saved",
              title: "Dorm Saved",
              message: `${dormName(card)} has been added to your saved listings.`,
            }
          : {
              type: "saved",
              title: "Removed from Saved",
              message: `${dormName(card)} has been removed from your saved listings.`,
            },
      );
      if (card.hidden)
        filters.find((button) => button.dataset.filter === filter).focus();
    } else if (event.target.closest(".message-landlord")) {
      selected = id;
      opener = event.target.closest(".message-landlord");
      messageText.value = state[id].draft;
      messageText.setCustomValidity("");
      messageDialog.showModal();
    } else if (event.target.closest(".cancel-stay")) {
      selected = id;
      opener = event.target.closest(".cancel-stay");
      cancelDialog.showModal();
    }
  });
  filters.forEach((button) =>
    button.addEventListener("click", () => {
      filter = button.dataset.filter;
      render();
    }),
  );
  search.addEventListener("input", render);
  document
    .getElementById("message-form")
    .addEventListener("submit", (event) => {
      event.preventDefault();
      if (!selected) return;
      if (!messageText.value.trim()) {
        messageText.setCustomValidity("Enter a message.");
        messageText.reportValidity();
        return;
      }
      state[selected].draft = messageText.value.trim();
      persist("Message draft saved. No message was sent.");
      const card = cards.find((item) => item.dataset.id === selected);
      addNotification({
        type: "message",
        title: "Message Draft Saved",
        message: `Your message draft for ${landlordName(card)} at ${dormName(card)} was saved. It was not sent.`,
      });
      messageDialog.close();
    });
  messageText.addEventListener("input", () =>
    messageText.setCustomValidity(""),
  );
  document.getElementById("confirm-cancel").addEventListener("click", () => {
    if (!selected || state[selected].status === "cancelled") return;
    const card = cards.find((item) => item.dataset.id === selected);
    state[selected].cancelled = true;
    state[selected].status = "cancelled";
    persist("Stay cancelled in this preview only.");
    render();
    addNotification({
      type: "cancellation",
      title: "Booking Cancelled",
      message: `Your stay at ${dormName(card)} has been cancelled in this preview.`,
    });
    cancelDialog.close();
  });
  window.addEventListener("unistay:booking-status-change", (event) => {
    const detail = event.detail;
    if (
      detail &&
      typeof detail.bookingId === "string" &&
      typeof detail.status === "string"
    ) {
      updateBookingStatus(detail.bookingId, detail.status);
    }
  });
  window.UnistayBookings = Object.freeze({
    updateStatus: (bookingId, status) => updateBookingStatus(bookingId, status),
  });
  render();
})();
