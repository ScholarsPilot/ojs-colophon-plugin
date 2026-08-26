/**
 * The workflow-page affordance. The 3.4 backend is a Vue app, so the button
 * is inserted from here rather than from a template: window.colophonData
 * (bootstrapped by ColophonPlugin::injectProductionAction) carries the URLs,
 * the CSRF token, and the labels. Insertion is defensive — the header renders
 * asynchronously, so we watch for it and give up quietly if the DOM changes
 * in a future OJS: the plugin then still works through its callback/status
 * endpoints, only the button is missing.
 */
(function () {
  var data = window.colophonData;
  if (!data || !data.startUrl) return;

  function renderButton(host) {
    if (document.getElementById('colophonGenerate')) return;
    var wrap = document.createElement('span');
    wrap.style.cssText = 'display:inline-flex;gap:6px;align-items:center;margin-inline-start:8px';

    // Before the submission is linked to a Colophon article, the primary
    // action is Send (one-call intake: manuscript + metadata, pipeline
    // starts). Afterwards it is the re-run action from v1.
    var primaryUrl = data.hasArticleCode ? data.startUrl : data.sendUrl;
    var primaryLabel = data.hasArticleCode ? data.labels.generate : data.labels.send;

    var btn = document.createElement('button');
    btn.id = 'colophonGenerate';
    btn.type = 'button';
    btn.className = 'pkpButton';
    btn.textContent = primaryLabel;

    var status = document.createElement('a');
    status.href = '#';
    status.className = 'pkpButton pkpButton--isLink';
    status.textContent = data.labels.checkStatus;
    status.setAttribute('data-colophon-status-url', data.statusUrl);

    btn.addEventListener('click', function () {
      btn.disabled = true;
      var body = new URLSearchParams();
      body.set('csrfToken', data.csrfToken);
      fetch(primaryUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
      })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
          var c = (resp && resp.content) || {};
          btn.textContent = (c.message || primaryLabel);
          btn.disabled = false;
          if (c.articleCode) {
            // Linked now: the next press re-runs rather than re-sending.
            primaryUrl = data.startUrl;
            primaryLabel = data.labels.generate;
          }
        })
        .catch(function () {
          btn.textContent = 'request failed';
          btn.disabled = false;
        });
    });

    wrap.appendChild(btn);
    wrap.appendChild(status);
    host.appendChild(wrap);
  }

  function tryInsert() {
    var host = document.querySelector('.pkpWorkflow__actions')
      || document.querySelector('.pkpWorkflow__header')
      || document.querySelector('.pkp_page_workflow .pkpHeader__actions');
    if (host) { renderButton(host); return true; }
    return false;
  }

  if (!tryInsert()) {
    var tries = 0;
    var timer = setInterval(function () {
      if (tryInsert() || ++tries > 40) clearInterval(timer);
    }, 250);
  }

  // The status poller (works wherever the status link renders).
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-colophon-status-url]');
    if (!el) return;
    e.preventDefault();
    el.textContent = '…';
    fetch(el.getAttribute('data-colophon-status-url'), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var c = (data && data.content) || data || {};
        el.textContent = (c.status || 'unknown') + (c.phase ? ' — ' + c.phase : '');
      })
      .catch(function () { el.textContent = 'status check failed'; });
  });
})();
