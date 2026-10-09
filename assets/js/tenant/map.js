(() => {
  "use strict";
  const viewport = document.getElementById("map-viewport");
  const image = document.getElementById("map-image");
  const plus = document.getElementById("zoom-in");
  const minus = document.getElementById("zoom-out");
  let scale = 1,
    x = 0,
    y = 0,
    drag = null;
  function draw() {
    const limitX = (viewport.clientWidth * (scale - 1)) / 2;
    const limitY = (viewport.clientHeight * (scale - 1)) / 2;
    x = Math.max(-limitX, Math.min(limitX, x));
    y = Math.max(-limitY, Math.min(limitY, y));
    image.style.transform = `translate(${x}px, ${y}px) scale(${scale})`;
    viewport.classList.toggle("zoomed", scale > 1);
    plus.disabled = scale >= 4;
    minus.disabled = scale <= 1;
  }
  function zoom(change) {
    scale = Math.max(1, Math.min(4, scale + change));
    draw();
  }
  plus.addEventListener("click", () => zoom(0.5));
  minus.addEventListener("click", () => zoom(-0.5));
  viewport.addEventListener("keydown", (event) => {
    if (event.target !== viewport) return;
    if (event.key === "+" || event.key === "=") zoom(0.5);
    else if (event.key === "-") zoom(-0.5);
    else if (event.key === "0") {
      scale = 1;
      x = y = 0;
      draw();
    } else if (
      scale > 1 &&
      ["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown"].includes(event.key)
    ) {
      x +=
        event.key === "ArrowLeft" ? 40 : event.key === "ArrowRight" ? -40 : 0;
      y += event.key === "ArrowUp" ? 40 : event.key === "ArrowDown" ? -40 : 0;
      draw();
    } else return;
    event.preventDefault();
  });
  viewport.addEventListener("pointerdown", (event) => {
    if (event.button !== 0 || scale <= 1 || event.target.closest("button, a"))
      return;
    drag = { id: event.pointerId, x: event.clientX, y: event.clientY };
    viewport.setPointerCapture(event.pointerId);
    viewport.classList.add("dragging");
    event.preventDefault();
  });
  viewport.addEventListener("pointermove", (event) => {
    if (!drag || drag.id !== event.pointerId) return;
    x += event.clientX - drag.x;
    y += event.clientY - drag.y;
    drag.x = event.clientX;
    drag.y = event.clientY;
    draw();
  });
  function endDrag(event) {
    if (!drag || drag.id !== event.pointerId) return;
    drag = null;
    viewport.classList.remove("dragging");
    if (viewport.hasPointerCapture(event.pointerId))
      viewport.releasePointerCapture(event.pointerId);
  }
  viewport.addEventListener("pointerup", endDrag);
  viewport.addEventListener("pointercancel", endDrag);
  viewport.addEventListener("lostpointercapture", endDrag);
  window.addEventListener("resize", draw);
  document.querySelector(".zoom-controls").hidden = false;
  draw();
})();
