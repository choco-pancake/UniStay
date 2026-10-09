(() => {
  "use strict";
  const key = "unistay-settings-preview";
  const status = document.getElementById("settings-status");
  const profileDialog = document.getElementById("profile-dialog");
  const accountDialog = document.getElementById("account-dialog");
  const nameInput = document.getElementById("edit-name"),
    emailInput = document.getElementById("edit-email");
  const push = document.getElementById("push-switch");
  let settings = { name: "", email: "", push: true },
    opener = null;
  try {
    const saved = JSON.parse(sessionStorage.getItem(key));
    if (saved && typeof saved === "object")
      settings = {
        name: typeof saved.name === "string" ? saved.name.slice(0, 100) : "",
        email: typeof saved.email === "string" ? saved.email.slice(0, 254) : "",
        push: saved.push !== false,
      };
  } catch {
    status.textContent =
      "Saved preferences are unavailable. You can still use this preview.";
  }
  function render() {
    document.getElementById("profile-name").textContent =
      settings.name || "Name";
    document.getElementById("profile-email").textContent =
      settings.email || "Email Address";
    push.setAttribute("aria-checked", String(settings.push));
  }
  function save(message) {
    try {
      sessionStorage.setItem(key, JSON.stringify(settings));
      status.textContent = message;
    } catch {
      status.textContent =
        message + " Changes could not be saved for page reloads.";
    }
    render();
  }
  document.getElementById("edit-profile").addEventListener("click", (event) => {
    opener = event.currentTarget;
    nameInput.value = settings.name;
    emailInput.value = settings.email;
    nameInput.setCustomValidity("");
    profileDialog.showModal();
  });
  document
    .getElementById("profile-form")
    .addEventListener("submit", (event) => {
      event.preventDefault();
      if (!nameInput.value.trim()) {
        nameInput.setCustomValidity("Enter your name.");
        nameInput.reportValidity();
        return;
      }
      settings.name = nameInput.value.trim();
      settings.email = emailInput.value.trim();
      save("Profile saved for this session.");
      profileDialog.close();
    });
  nameInput.addEventListener("input", () => nameInput.setCustomValidity(""));
  push.addEventListener("click", () => {
    settings.push = !settings.push;
    save(
      `Push preference ${settings.push ? "enabled" : "disabled"} for this preview. Device notifications are not connected.`,
    );
  });
  document
    .querySelectorAll("[data-close]")
    .forEach((button) =>
      button.addEventListener("click", () =>
        document.getElementById(button.dataset.close).close(),
      ),
    );
  for (const dialog of [profileDialog, accountDialog])
    dialog.addEventListener("close", () => opener?.focus());
  document.querySelectorAll("[data-password]").forEach((button) =>
    button.addEventListener("click", () => {
      const input = document.getElementById(button.dataset.password);
      const showing = input.type === "password";
      input.type = showing ? "text" : "password";
      button.setAttribute("aria-pressed", String(showing));
      const label = document
        .querySelector(`label[for="${input.id}"]`)
        .textContent.replace("*", "")
        .trim()
        .toLowerCase();
      button.setAttribute(
        "aria-label",
        `${showing ? "Hide" : "Show"} ${label}`,
      );
    }),
  );
  const form = document.getElementById("password-form"),
    current = document.getElementById("current-password"),
    next = document.getElementById("new-password"),
    confirm = document.getElementById("confirm-password");
  form.addEventListener("submit", (event) => {
    event.preventDefault();
    next.setCustomValidity("");
    confirm.setCustomValidity("");
    if (next.value.length < 8)
      next.setCustomValidity("Use at least 8 characters.");
    else if (next.value === current.value)
      next.setCustomValidity("Choose a different new password.");
    if (next.value !== confirm.value)
      confirm.setCustomValidity("Passwords do not match.");
    if (!form.reportValidity()) return;
    form.reset();
    document.querySelectorAll("[data-password]").forEach((button) => {
      const input = document.getElementById(button.dataset.password);
      input.type = "password";
      button.setAttribute("aria-pressed", "false");
      button.setAttribute(
        "aria-label",
        `Show ${document.querySelector(`label[for="${input.id}"]`).textContent.replace("*", "").trim().toLowerCase()}`,
      );
    });
    status.textContent =
      "Password fields validated and cleared. No password was changed; account security is not connected in this preview.";
  });
  [current, next, confirm].forEach((input) =>
    input.addEventListener("input", () => {
      next.setCustomValidity("");
      confirm.setCustomValidity("");
    }),
  );
  const actions = {
    "forgot-password": [
      "Forgot Password?",
      "Password reset is not connected in this preview. No reset email has been sent.",
    ],
    "delete-account": [
      "Delete Account",
      "Account deletion is not connected in this preview. No account or data has been deleted.",
    ],
    logout: [
      "Log Out",
      "This preview has no authenticated account. No account has been signed out.",
    ],
  };
  Object.entries(actions).forEach(([id, [title, description]]) =>
    document.getElementById(id).addEventListener("click", (event) => {
      opener = event.currentTarget;
      document.getElementById("account-title").textContent = title;
      document.getElementById("account-description").textContent = description;
      accountDialog.showModal();
    }),
  );
  render();
})();
