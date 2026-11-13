document.querySelectorAll('nav a[href^="#"]').forEach(function (link) {
  link.addEventListener('click', function (e) {
    var target = document.querySelector(link.getAttribute('href'));
    if (!target) return;
    e.preventDefault();
    var top = target.getBoundingClientRect().top + window.pageYOffset - 88;
    window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
    history.replaceState(null, '', link.getAttribute('href'));
  });
});

function placeIndicator() {
  var tabs = document.querySelector('.tabs');
  var indicator = tabs && tabs.querySelector('.tab-indicator');
  if (!indicator) return;
  var active = tabs.querySelector('.tab.active');
  if (!active) return;
  if (!placeIndicator.ready) { indicator.style.transition = 'none'; }
  indicator.style.width = active.offsetWidth + 'px';
  indicator.style.transform = 'translateX(' + active.offsetLeft + 'px)';
  if (!placeIndicator.ready) { void indicator.offsetWidth; indicator.style.transition = ''; placeIndicator.ready = true; }
}

var panelTimer, panelCleanup;
var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function resetPanels() {
  clearTimeout(panelTimer);
  clearTimeout(panelCleanup);
  document.querySelectorAll('.panel').forEach(function (p) {
    p.classList.remove('is-out', 'is-in');
    p.style.height = '';
    p.style.transition = '';
  });
}

function switchPanel(targetId) {
  var target = document.getElementById(targetId);
  var current = document.querySelector('.panel:not([hidden])');
  if (!target || target === current) return;

  resetPanels();

  if (reduceMotion || !current) {
    document.querySelectorAll('.panel').forEach(function (p) { p.hidden = p !== target; });
    return;
  }

  var oldHeight = current.offsetHeight;
  current.classList.add('is-out');

  panelTimer = setTimeout(function () {
    current.hidden = true;
    current.classList.remove('is-out');

    // measure the new panel's natural height
    target.hidden = false;
    target.style.transition = 'none';
    target.style.height = '';
    var newHeight = target.offsetHeight;

    // start from the old height and hidden, then animate to the new height and visible
    target.style.height = oldHeight + 'px';
    target.classList.add('is-in');
    void target.offsetHeight;
    target.style.transition = '';

    requestAnimationFrame(function () {
      target.classList.remove('is-in');
      target.style.height = newHeight + 'px';
    });

    panelCleanup = setTimeout(function () { target.style.height = ''; }, 450);
  }, 250);
}

document.querySelectorAll('.tab').forEach(function (tab) {
  tab.addEventListener('click', function () {
    document.querySelectorAll('.tab').forEach(function (t) { t.classList.toggle('active', t === tab) });
    placeIndicator();
    switchPanel(tab.dataset.t);
  });
});

placeIndicator();
window.addEventListener('load', placeIndicator);
window.addEventListener('resize', placeIndicator);
if (document.fonts && document.fonts.ready) { document.fonts.ready.then(placeIndicator) }

var subForm = document.getElementById('sub');
if (subForm) {
  var toastEl = document.getElementById('landing-toast');
  var toastTitle = document.getElementById('landing-toast-title');
  var toastMsg = document.getElementById('landing-toast-msg');
  var toastClose = document.getElementById('landing-toast-close');
  var toastTimer = null;

  function dismissLandingToast() {
    if (!toastEl || toastEl.classList.contains('is-hiding') || !toastEl.classList.contains('is-visible')) return;
    toastEl.classList.remove('is-visible');
    toastEl.classList.add('is-hiding');
    setTimeout(function () {
      toastEl.classList.remove('is-hiding');
    }, 300);
  }

  if (toastClose) {
    toastClose.addEventListener('click', dismissLandingToast);
  }

  function showLandingToast(title, message) {
    if (!toastEl) return;
    if (toastTitle) toastTitle.textContent = title;
    if (toastMsg) toastMsg.textContent = message;
    clearTimeout(toastTimer);
    toastEl.classList.remove('is-hiding');
    void toastEl.offsetWidth;
    toastEl.classList.add('is-visible');
    toastTimer = setTimeout(dismissLandingToast, 5000);
  }

  subForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var input = document.getElementById('sub-email');
    var btn = document.getElementById('sub-btn');
    var email = input.value.trim();

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      showLandingToast('Subscription Notice', 'Please enter a valid email address.');
      input.focus();
      return;
    }
    btn.disabled = true;
    btn.textContent = 'Subscribing...';
    fetch('api/subscribe.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: email, website: (document.getElementById('sub-website') || {}).value || '' }) })
      .then(function (r) { return r.text().then(function (t) { var d = null; try { d = JSON.parse(t); } catch (err) { } return { ok: r.ok, data: d }; }); })
      .then(function (res) {
        if (!res.ok || !res.data || res.data.ok === false) {
          showLandingToast('Subscription Notice', (res.data && res.data.error) || 'Something went wrong. Please try again.');
          return;
        }
        if (res.data.mail_sent === true) {
          showLandingToast('Subscribed!', 'Check ' + res.data.email + ' for your UniStay promotional email. It may appear under Promotions or Spam.');
          input.value = '';
        } else if (res.data.mail_status === 'already_sent') {
          showLandingToast('Already subscribed', 'This address is already subscribed and its promotional email was previously sent.');
          input.value = '';
        } else if (res.data.mail_status === 'queued') {
          showLandingToast('Subscribed', 'The immediate send was delayed, but your promotional email is safely queued for retry.');
          input.value = '';
        } else if (res.data.mail_status === 'stored') {
          showLandingToast('Email delivery unavailable', 'Your subscription was saved, but SMTP email delivery is not configured yet.');
        } else if (res.data.mail_status === 'failed') {
          showLandingToast('Email not sent', 'Your address was saved, but the promotional email could not be delivered. Please try subscribing again.');
        } else {
          showLandingToast('Subscription saved', 'Your address was saved. Email delivery status is not available yet.');
        }
      })
      .catch(function () { showLandingToast('Subscription Notice', 'Could not reach the server. Please try again.'); })
      .then(function () { btn.disabled = false; btn.textContent = 'Subscribe'; });
  });
}

(function () {
  var featureHero = document.querySelector('.feature-hero');
  if (!featureHero) return;

  function revealIcons() { featureHero.classList.add('icons-revealed'); }

  if (!('IntersectionObserver' in window)) {
    revealIcons();
    return;
  }

  var iconObserver = new IntersectionObserver(function (entries) {
    if (!entries[0].isIntersecting) return;
    iconObserver.disconnect();
    requestAnimationFrame(revealIcons);
  }, { threshold: 0.25 });

  iconObserver.observe(featureHero);
})();

(function () {
  var stack = document.getElementById('stack');
  var copy = document.getElementById('hero-copy');
  if (!stack || !copy) return;

  var cards = [].slice.call(stack.querySelectorAll('.sq'));
  var total = cards.length;
  var kickerEl = document.getElementById('hc-kicker');
  var titleEl = document.getElementById('hc-title');
  var descEl = document.getElementById('hc-desc');
  var ctaEl = document.getElementById('hc-cta');
  var countEl = document.getElementById('hc-count');
  var barsEl = document.getElementById('hc-bars');
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  var active = 0;
  var ready = false;   // clicks are ignored until the deal-in has finished
  var transitioning = false;
  var dealStarted = false;
  var swapTimer;
  var transitionTimer;

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  for (var b = 0; b < total; b++) {
    var barBtn = document.createElement('button');
    barBtn.type = 'button';
    barBtn.setAttribute('aria-label', 'Show photo ' + (b + 1));
    barsEl.appendChild(barBtn);
  }
  var bars = [].slice.call(barsEl.children);

  function fillCopy() {
    var i = Math.min(active, total - 1);
    var d = cards[i].dataset;
    kickerEl.textContent = pad(i + 1) + ' \u00b7 ' + d.kicker;
    titleEl.textContent = d.title;
    descEl.textContent = d.desc;
    if (ctaEl) {
      ctaEl.textContent = d.cta;
      ctaEl.setAttribute('href', d.href);
      ctaEl.classList.toggle('landlord-application-cta', d.href === '#landlord-application');
    }
    countEl.textContent = pad(i + 1) + ' / ' + pad(total);
    bars.forEach(function (bar, j) {
      bar.classList.toggle('on', j <= i);
      if (j === i) bar.setAttribute('aria-current', 'true'); else bar.removeAttribute('aria-current');
    });
  }

  function warmCard(index) {
    var card = cards[index];
    var img = card && card.querySelector('img');
    if (!img) return;
    if (img.loading === 'lazy') img.loading = 'eager';
    if (img.decode) img.decode().catch(function () { });
  }

  function showCopy() {
    clearTimeout(swapTimer);
    if (reduce) { fillCopy(); return; }
    copy.classList.add('is-swapping');
    swapTimer = setTimeout(function () {
      fillCopy();
      copy.classList.remove('is-swapping');
    }, 180);
  }

  function layout() {
    cards.forEach(function (card, i) {
      var depth = i - active;
      var gone = depth < 0;
      card.classList.toggle('is-gone', gone);
      card.classList.toggle('is-top', depth === 0);
      if (gone) {
        card.style.zIndex = 30;
      } else {
        card.style.setProperty('--d', depth);
        card.style.zIndex = 20 - depth;
      }
      if (depth === 0) {
        card.setAttribute('role', 'button');
        card.setAttribute('tabindex', '0');
        card.setAttribute('aria-hidden', 'false');
        card.setAttribute('aria-label', card.dataset.title + '. Click to show the next photo.');
      } else {
        card.removeAttribute('role');
        card.setAttribute('tabindex', '-1');
        card.setAttribute('aria-hidden', 'true');
        card.removeAttribute('aria-label');
      }
    });
  }

  function deal() {
    if (dealStarted && !stack.classList.contains('no-anim')) return;
    dealStarted = true;
    if (reduce) {
      stack.classList.add('is-dealt');
      ready = true;
      return;
    }
    stack.classList.add('is-dealing');
    void stack.offsetWidth;
    stack.classList.add('is-dealt');
    setTimeout(function () {
      stack.classList.remove('is-dealing');
      ready = true;
    }, 1500);
  }

  function restart() {
    clearTimeout(transitionTimer);
    transitioning = false;
    ready = false;
    active = 0;
    stack.classList.add('no-anim');
    stack.classList.remove('is-dealt');
    layout();
    void stack.offsetWidth;
    stack.classList.remove('no-anim');
    clearTimeout(swapTimer);
    copy.classList.remove('is-swapping');
    fillCopy();
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        copy.classList.remove('is-resetting');
      });
    });
    dealStarted = false;
    deal();
  }

  function next() {
    if (!ready || transitioning || active >= total) return;
    var hadFocus = document.activeElement === cards[active];
    transitioning = true;
    active++;
    layout();
    if (active >= total) {
      ready = false;
      clearTimeout(swapTimer);
      copy.classList.remove('is-swapping');
      copy.classList.add('is-resetting');
      setTimeout(restart, reduce ? 0 : 420);
    } else {
      showCopy();
      warmCard(active + 1);
      if (hadFocus) {
        cards[active].focus({ preventScroll: true });
      }
      clearTimeout(transitionTimer);
      transitionTimer = setTimeout(function () { transitioning = false; }, reduce ? 0 : 740);
    }
  }

  stack.addEventListener('click', function (e) {
    var card = e.target.closest('.sq');
    if (card && card.classList.contains('is-top')) next();
  });

  stack.addEventListener('keydown', function (e) {
    var card = e.target.closest('.sq');
    if (!card || !card.classList.contains('is-top')) return;
    if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowRight') {
      e.preventDefault();
      next();
    }
  });

  function goTo(index) {
    if (!ready || index === active || index < 0 || index >= total) return;
    var hadFocus = document.activeElement === cards[active];
    clearTimeout(transitionTimer);
    transitioning = true;
    active = index;
    layout();      // cards before the chosen one fly off, the chosen one and the rest return to the stack
    showCopy();
    warmCard(active);
    warmCard(active + 1);
    if (hadFocus) cards[active].focus({ preventScroll: true });
    transitionTimer = setTimeout(function () { transitioning = false; }, reduce ? 0 : 740);
  }

  barsEl.addEventListener('click', function (e) {
    var btn = e.target.closest('button');
    if (btn) goTo(bars.indexOf(btn));
  });

  layout();
  fillCopy();
  warmCard(0);
  warmCard(1);

  // Deal the stack in each time the page loads and the section is scrolled into view
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      if (entries[0].isIntersecting) {
        io.disconnect();
        cards.forEach(function (_, index) { warmCard(index); });
        setTimeout(deal, 150);
      }
    }, { threshold: 0.35 });
    io.observe(stack);
  } else {
    deal();
  }
})();
