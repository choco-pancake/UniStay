(() => {
    "use strict";
    const form = document.getElementById("landlord-form");
    if (!form) return;

    /* ---------- +63 phone (same normalization as tenant/settings.php) ---------- */
    const phone = document.getElementById("la-phone");
    const phoneFull = document.getElementById("la-phone-full");

    function normalizePhone() {
        let d = phone.value.replace(/\D/g, "");
        if (d.startsWith("63") && d.length > 10) d = d.slice(2);
        else if (d.startsWith("0")) d = d.slice(1);
        d = d.slice(0, 10);
        phone.value = d;
        phoneFull.value = d ? "+63" + d : "";
    }
    phone.addEventListener("input", normalizePhone);
    form.addEventListener("submit", normalizePhone, true);

    /* ---------- ID dropzone (same rules as tenant/settings.php) ---------- */
    const zone = document.getElementById("la-dropzone");
    const input = document.getElementById("la-gov-id");
    const empty = document.getElementById("la-dz-empty");
    const filled = document.getElementById("la-dz-filled");
    const preview = document.getElementById("la-dz-preview");
    const nameEl = document.getElementById("la-dz-name");
    const msg = document.getElementById("apply-msg");
    const VALID = ["image/jpeg", "image/png", "image/webp"];

    function showError(text) {
        if (msg) msg.textContent = text;
        else alert(text);
    }

    function resetZone() {
        input.value = "";
        if (preview.dataset.url) URL.revokeObjectURL(preview.dataset.url);
        preview.removeAttribute("src");
        delete preview.dataset.url;
        nameEl.textContent = "";
        filled.hidden = true;
        empty.hidden = false;
    }

    function handleFile() {
        const file = input.files && input.files[0];
        if (!file) return;

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
            if (probe.naturalWidth < 200 || probe.naturalHeight < 120) {
                URL.revokeObjectURL(url);
                showError(`The image resolution (${probe.naturalWidth}×${probe.naturalHeight}px) is too small to be a legible ID. Minimum required is 200×120 pixels.`);
                return resetZone();
            }
            if (preview.dataset.url) URL.revokeObjectURL(preview.dataset.url);
            preview.src = url;
            preview.dataset.url = url;
            nameEl.textContent = file.name;
            empty.hidden = true;
            filled.hidden = false;
            if (msg) msg.textContent = "";
        };
        probe.onerror = () => {
            URL.revokeObjectURL(url);
            showError("Could not load the file as an image. Please ensure it is a valid photo.");
            resetZone();
        };
        probe.src = url;
    }

    zone.addEventListener("click", (e) => {
        if (e.target.closest(".la-dz-btn-danger") || e.target === input) return;
        input.click();
    });
    zone.addEventListener("keydown", (e) => {
        if (e.target !== zone) return;
        if (e.key === "Enter" || e.key === " ") { e.preventDefault(); input.click(); }
    });
    input.addEventListener("change", handleFile);
    document.getElementById("la-dz-remove").addEventListener("click", (e) => {
        e.stopPropagation();
        resetZone();
    });

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

    /* Keep the custom UI in sync when landlord-apply.js resets the form after submit */
    form.addEventListener("reset", () => setTimeout(() => { resetZone(); phoneFull.value = ""; }, 0));
})();
