(function () {
  var root = document.documentElement;

  function replay(className) {
    root.classList.remove(className);
    void root.offsetWidth;
    root.classList.add(className);
  }

  // Fade the page in when it is painted.
  replay('page-entering');

  // The browser back button restores the previous page from the back/forward
  // cache, so the entrance animation has to be replayed by hand.
  window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
      replay('page-entering');
    }
  });

  // Browsers that support the View Transition API cross-fade the documents
  // on their own, so the manual fade-out below is not needed there.
  if (typeof document.startViewTransition === 'function') {
    return;
  }

  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    return;
  }

  document.addEventListener('click', function (event) {
    if (
      event.defaultPrevented ||
      event.button !== 0 ||
      event.metaKey ||
      event.ctrlKey ||
      event.shiftKey ||
      event.altKey
    ) {
      return;
    }

    var link = event.target.closest ? event.target.closest('a[href]') : null;

    if (!link || link.target === '_blank' || link.hasAttribute('download')) {
      return;
    }

    var destination = new URL(link.href, window.location.href);

    if (
      destination.origin !== window.location.origin ||
      destination.pathname + destination.search === window.location.pathname + window.location.search
    ) {
      return;
    }

    event.preventDefault();
    root.classList.add('page-leaving');

    window.setTimeout(function () {
      window.location.href = destination.href;
    }, 200);
  });
})();
