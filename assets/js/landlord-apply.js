(() => {
    "use strict";

    const dialog = document.getElementById("landlord-dialog");
    const form = document.getElementById("landlord-form");
    if (!dialog || !form) return;

    /* ---------- +63 Phone Normalization ---------- */
    const phone = document.getElementById("la-phone");
    const phoneFull = document.getElementById("la-phone-full");

    function normalizePhone() {
        if (!phone || !phoneFull) return;
        let d = phone.value.replace(/\D/g, "");
        if (d.startsWith("63") && d.length > 10) d = d.slice(2);
        else if (d.startsWith("0")) d = d.slice(1);
        d = d.slice(0, 10);
        phone.value = d;
        phoneFull.value = d ? "+63" + d : "";
    }
    if (phone) {
        phone.addEventListener("input", normalizePhone);
        form.addEventListener("submit", normalizePhone, true);
    }

    /* ---------- ID Dropzone Handling & Validation ---------- */
    const zone = document.getElementById("la-dropzone");
    const input = document.getElementById("la-gov-id");
    const preview = document.getElementById("la-dz-preview");
    const hint = zone?.querySelector(".la-dz-hint");
    const uploadButtonRow = zone?.querySelector(".la-dz-btn-row");
    const nameEl = document.getElementById("la-dz-name");
    const msg = document.getElementById("apply-msg");
    const VALID = ["image/jpeg", "image/png", "image/webp"];
    let fileCheck = 0;

    function showError(text) {
        if (msg) {
            msg.textContent = text;
            msg.className = "apply-msg error";
            msg.style.display = "block";
            msg.scrollIntoView({ behavior: "smooth", block: "nearest" });
        } else {
            alert(text);
        }
    }

    function clearPreview() {
        zone?.classList.remove("has-file");
        if (hint) hint.hidden = false;
        if (uploadButtonRow) uploadButtonRow.hidden = false;
        if (!preview) return;
        if (preview.dataset.url) URL.revokeObjectURL(preview.dataset.url);
        preview.removeAttribute("src");
        delete preview.dataset.url;
        preview.hidden = true;
    }

    function resetZone() {
        fileCheck++;
        if (!input) return;
        input.value = "";
        clearPreview();
        if (nameEl) nameEl.textContent = "No file chosen";
    }

    function handleFile() {
        const currentCheck = ++fileCheck;
        const file = input && input.files && input.files[0];
        if (!file) return;
        clearPreview();

        if (file.size < 10 * 1024) {
            showError(`The selected file is too small (${Math.round(file.size / 1024)} KB) or blank. Please upload a clear photo of your ID (minimum 10 KB).`);
            return resetZone();
        }
        if (file.size > 10 * 1024 * 1024) {
            showError("The selected file exceeds the 10 MB limit. Please upload a smaller image file.");
            return resetZone();
        }
        if (!VALID.includes(file.type)) {
            showError("Invalid file format. Please upload a JPG, PNG, or WEBP photo of your ID.");
            return resetZone();
        }

        const url = URL.createObjectURL(file);
        const probe = new Image();
        probe.onload = () => {
            if (currentCheck !== fileCheck) {
                URL.revokeObjectURL(url);
                return;
            }
            if (probe.naturalWidth < 200 || probe.naturalHeight < 120) {
                URL.revokeObjectURL(url);
                showError(`The image resolution (${probe.naturalWidth}×${probe.naturalHeight}px) is too small to be a legible ID. Minimum required is 200×120 pixels.`);
                return resetZone();
            }
            if (preview) {
                preview.src = url;
                preview.dataset.url = url;
                preview.hidden = false;
                zone?.classList.add("has-file");
                if (hint) hint.hidden = true;
                if (uploadButtonRow) uploadButtonRow.hidden = true;
            } else {
                URL.revokeObjectURL(url);
            }
            if (nameEl) nameEl.textContent = file.name;
            if (msg) {
                msg.textContent = "";
                msg.className = "apply-msg";
            }
        };
        probe.onerror = () => {
            URL.revokeObjectURL(url);
            if (currentCheck !== fileCheck) return;
            showError("Could not load the file as an image. Please ensure it is a valid photo.");
            resetZone();
        };
        probe.src = url;
    }

    if (zone && input) {
        zone.addEventListener("click", (e) => {
            if (e.target === input) return;
            input.click();
        });
        zone.addEventListener("keydown", (e) => {
            if (e.target !== zone) return;
            if (e.key === "Enter" || e.key === " ") { e.preventDefault(); input.click(); }
        });
        input.addEventListener("change", handleFile);
        zone.addEventListener("dragover", (e) => { e.preventDefault(); zone.classList.add("dragover"); });
        zone.addEventListener("dragleave", () => zone.classList.remove("dragover"));
        zone.addEventListener("drop", (e) => {
            e.preventDefault();
            zone.classList.remove("dragover");
            if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                input.files = e.dataTransfer.files;
                handleFile();
            }
        });
    }

    /* Reset dropzone and phone hidden values when form resets */
    form.addEventListener("reset", () => setTimeout(() => {
        resetZone();
        if (phoneFull) phoneFull.value = "";
    }, 0));

    /* ---------- Dialog Control & AJAX Submission ---------- */
    const btn = document.getElementById("apply-btn");
    const cancelBtn = form.querySelector(".apply-actions [data-close-landlord-form]");
    let submittedSuccessfully = false;
    let isSubmitting = false;
    let isClosing = false;
    let closeTimer = null;

    function show(text, state) {
        if (!msg) return;
        msg.textContent = text;
        msg.className = "apply-msg" + (state ? " " + state : "");
        msg.style.display = text ? "block" : "none";
    }

    /* ---------- Toast Notification Control ---------- */
    const applyToast = document.getElementById("landlord-apply-toast");
    const applyToastClose = document.getElementById("landlord-apply-toast-close");
    let toastTimer = null;

    function showApplyToast() {
        if (!applyToast) return;
        if (toastTimer) {
            clearTimeout(toastTimer);
            toastTimer = null;
        }
        applyToast.classList.remove("is-hiding");
        applyToast.classList.add("is-visible");
        toastTimer = setTimeout(() => {
            hideApplyToast();
        }, 7000);
    }

    function hideApplyToast() {
        if (!applyToast) return;
        if (toastTimer) {
            clearTimeout(toastTimer);
            toastTimer = null;
        }
        applyToast.classList.add("is-hiding");
        applyToast.classList.remove("is-visible");
        setTimeout(() => {
            applyToast.classList.remove("is-hiding");
        }, 300);
    }

    if (applyToastClose) {
        applyToastClose.addEventListener("click", hideApplyToast);
    }

    function openDialog() {
        if (dialog.open || isClosing) return;
        show("");
        submittedSuccessfully = false;
        document.body.classList.add("landlord-dialog-open");
        dialog.showModal();
        void dialog.offsetWidth;
        dialog.classList.add("is-open");
        const first = form.querySelector("input:not([type=\"hidden\"])");
        if (first) setTimeout(() => { first.focus(); }, 0);
    }

    function closeDialog() {
        if (!dialog.open || isClosing || isSubmitting) return;
        if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
            dialog.close();
            return;
        }
        isClosing = true;
        dialog.classList.remove("is-open");
        closeTimer = window.setTimeout(() => {
            closeTimer = null;
            dialog.close();
        }, 220);
    }

    document.querySelectorAll("[data-open-landlord-form]").forEach((el) => {
        el.addEventListener("click", openDialog);
    });
    document.addEventListener("click", (e) => {
        const link = e.target.closest('a[href="#landlord-application"]');
        if (!link) return;
        e.preventDefault();
        openDialog();
    });
    if (window.location.hash === "#landlord-application") {
        openDialog();
    }
    document.querySelectorAll("[data-close-landlord-form]").forEach((el) => {
        el.addEventListener("click", closeDialog);
    });
    dialog.addEventListener("cancel", (e) => {
        e.preventDefault();
        closeDialog();
    });
    dialog.addEventListener("click", (e) => {
        if (e.target !== dialog || isSubmitting) return;
        const rect = dialog.getBoundingClientRect();
        const inside = e.clientX >= rect.left && e.clientX <= rect.right && e.clientY >= rect.top && e.clientY <= rect.bottom;
        if (!inside) closeDialog();
    });
    dialog.addEventListener("close", () => {
        if (closeTimer !== null) window.clearTimeout(closeTimer);
        closeTimer = null;
        isClosing = false;
        dialog.classList.remove("is-open");
        document.body.classList.remove("landlord-dialog-open");
        if (submittedSuccessfully) {
            form.reset();
            Array.prototype.forEach.call(form.elements, (field) => { field.disabled = false; });
            if (btn) btn.textContent = "Submit Application";
            if (cancelBtn) cancelBtn.textContent = "Cancel";
            submittedSuccessfully = false;
        }
    });

    form.addEventListener("keydown", (e) => {
        if (e.key === "Enter" && e.target.tagName !== "TEXTAREA") e.preventDefault();
    });

    form.addEventListener("submit", (e) => {
        e.preventDefault();
        if (isSubmitting) return;

        // Normalize phone number
        normalizePhone();
        if (!phoneFull || !phoneFull.value || phoneFull.value.length < 13) {
            showError("Please enter a valid 10-digit mobile phone number.");
            phone?.focus();
            return;
        }

        // Validate Facebook profile
        const fb = document.getElementById("la-facebook");
        if (!fb || !fb.value.trim()) {
            showError("Please provide your Facebook profile username or link.");
            fb?.focus();
            return;
        }

        // Validate experience text
        const exp = document.getElementById("la-experience");
        if (!exp || exp.value.trim().length < 30) {
            showError("Please provide at least 30 characters describing your rental experience.");
            exp?.focus();
            return;
        }

        // Explicitly check ID file before native validation so invisible file inputs do not trigger silent failure
        if (!input || !input.files || !input.files[0]) {
            showError("Please upload a clear government-issued ID photo.");
            zone?.focus();
            return;
        }

        // Validate agreement checkbox
        const agree = form.querySelector('input[name="responsibility_acknowledged"]');
        if (!agree || !agree.checked) {
            showError("Please agree to UniStay policies and responsibilities.");
            agree?.focus();
            return;
        }

        if (!form.checkValidity()) {
            showError("Please fill in all required fields with valid details.");
            form.reportValidity();
            return;
        }

        const data = new FormData(form);

        isSubmitting = true;
        if (btn) {
            btn.disabled = true;
            btn.textContent = "Submitting...";
        }
        fetch("api/landlord-apply.php", {
            method: "POST",
            body: data
        })
            .then((r) => r.json().catch(() => null))
            .then((res) => {
                if (!res || res.ok === false) {
                    showError((res && res.error) || "Something went wrong. Please try again.");
                    return;
                }
                submittedSuccessfully = true;
                closeDialog();
                showApplyToast();
                form.reset();
                resetZone();
                if (phoneFull) phoneFull.value = "";
                Array.prototype.forEach.call(form.elements, (field) => { field.disabled = false; });
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = "Submit Application";
                }
                if (cancelBtn) cancelBtn.textContent = "Cancel";
            })
            .catch(() => { showError("Could not reach the server. Please try again."); })
            .then(() => {
                isSubmitting = false;
                if (!submittedSuccessfully && btn) {
                    btn.disabled = false;
                    btn.textContent = "Submit Application";
                }
            });
    });
})();
