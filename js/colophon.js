/**
 * The workflow-page affordance. Two OJS shapes, chosen by whether
 * window.colophonData.submissionId is already known:
 *
 * - 3.4: workflow/workflow.tpl renders once per submission, as a real page
 *   load, with the submission (and its colophonArticleCode) already known
 *   server-side. One-shot insertion into the Vue workflow header.
 *
 * - 3.5: dashboard/editors.tpl renders once for the whole editor dashboard;
 *   every submission a person opens afterwards is a client-side route change
 *   (workflowSubmissionId in the URL, fetched over the REST API) with no
 *   further template render — confirmed live against a 3.5.0-5 instance.
 *   colophonData carries URL *templates* (a __SUBMISSION_ID__ placeholder)
 *   instead of a baked-in id; this half watches the route and the DOM, and
 *   re-fetches colophonArticleCode per submission.
 *
 * Both halves are defensive: the header/panel renders asynchronously and its
 * markup can change in a future OJS point release. If insertion never finds
 * a host, the plugin still works through its callback/status endpoints —
 * only the button is missing.
 */
(function () {
  var data = window.colophonData;
  if (!data || !data.sendUrl) return;

  function fillTemplate(tpl, id) {
    return tpl.replace('__SUBMISSION_ID__', id);
  }

  // state: { url, label, statusUrl } — mutable, so a successful Send can
  // flip the same button to the Generate action without a re-render.
  function makeButton(state) {
    var wrap = document.createElement('span');
    // The 3.5 dashboard hosts this in a narrow slide-in panel, not a
    // full-width page header: a result message ("Colophon is producing the
    // package...") is a full sentence, not a label, and an unbounded
    // display:inline-flex row pushed the title down to a one-word column
    // before this was capped — confirmed live by actually clicking the
    // button, not by inspection.
    wrap.style.cssText = 'display:inline-flex;flex-wrap:wrap;gap:6px;align-items:center;'
      + 'margin-inline-start:8px;max-width:220px;';

    var btn = document.createElement('button');
    btn.id = 'colophonGenerate';
    btn.type = 'button';
    btn.className = 'pkpButton';
    btn.style.cssText = 'white-space:normal;text-align:start;';
    btn.textContent = state.label;

    var status = document.createElement('a');
    status.href = '#';
    status.className = 'pkpButton pkpButton--isLink';
    status.textContent = data.labels.checkStatus;
    status.setAttribute('data-colophon-status-url', state.statusUrl);

    btn.addEventListener('click', function () {
      btn.disabled = true;
      var body = new URLSearchParams();
      body.set('csrfToken', data.csrfToken);
      fetch(state.url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
      })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
          var c = (resp && resp.content) || {};
          btn.textContent = c.message || state.label;
          btn.disabled = false;
          if (c.articleCode && state.onSent) state.onSent();
        })
        .catch(function () {
          btn.textContent = 'request failed';
          btn.disabled = false;
        });
    });

    wrap.appendChild(btn);
    wrap.appendChild(status);
    return wrap;
  }

  // The status poller: one delegated listener, works for either insertion
  // strategy and survives the button being removed/re-inserted.
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

  // ----- 3.4: one-shot, submission already known server-side --------------
  if (data.submissionId) {
    var state = {
      url: data.hasArticleCode ? data.startUrl : data.sendUrl,
      label: data.hasArticleCode ? data.labels.generate : data.labels.send,
      statusUrl: data.statusUrl,
    };
    state.onSent = function () {
      state.url = data.startUrl;
      state.label = data.labels.generate;
    };

    function tryInsertWorkflow() {
      if (document.getElementById('colophonGenerate')) return true;
      var host = document.querySelector('.pkpWorkflow__actions')
        || document.querySelector('.pkpWorkflow__header')
        || document.querySelector('.pkp_page_workflow .pkpHeader__actions');
      if (!host) return false;
      host.appendChild(makeButton(state));
      return true;
    }

    if (!tryInsertWorkflow()) {
      var tries = 0;
      var timer = setInterval(function () {
        if (tryInsertWorkflow() || ++tries > 40) clearInterval(timer);
      }, 250);
    }
    return;
  }

  // ----- 3.5: dashboard SPA — route-aware, re-injects per submission ------
  var lastId = null;

  function currentSubmissionId() {
    var m = /[?&]workflowSubmissionId=(\d+)/.exec(location.search);
    return m ? m[1] : null;
  }

  function removeButton() {
    var existing = document.getElementById('colophonGenerate');
    if (existing) existing.closest('span').remove();
  }

  function ensureButton(id) {
    var host = document.querySelector('[data-cy="sidemodal-header"] .flex.gap-x-4')
      || document.querySelector('[data-cy="sidemodal-header"]');
    if (!host) return;

    fetch(data.apiBase + '/submissions/' + id, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (r) { return r.json(); })
      .then(function (sub) {
        // The editor may have switched submissions while this was in flight.
        if (currentSubmissionId() !== id || document.getElementById('colophonGenerate')) return;
        var hasCode = !!sub.colophonArticleCode;
        var state = {
          url: fillTemplate(hasCode ? data.startUrl : data.sendUrl, id),
          label: hasCode ? data.labels.generate : data.labels.send,
          statusUrl: fillTemplate(data.statusUrl, id),
        };
        state.onSent = function () {
          state.url = fillTemplate(data.startUrl, id);
          state.label = data.labels.generate;
        };
        host.appendChild(makeButton(state));
      })
      .catch(function () { /* the button just stays absent for this view */ });
  }

  function sync() {
    var id = currentSubmissionId();
    var hasButton = !!document.getElementById('colophonGenerate');
    if (id === lastId && (hasButton || !id)) return;
    lastId = id;
    removeButton();
    if (id) ensureButton(id);
  }

  var scheduled = false;
  function schedule() {
    if (scheduled) return;
    scheduled = true;
    setTimeout(function () { scheduled = false; sync(); }, 150);
  }

  // This script is injected in <head> and runs before the parser reaches
  // <body> — document.body is null at that point, and MutationObserver
  // throws (not returns null) when asked to observe a null target, which
  // would otherwise silently abort every listener below it. Deferring only
  // this DOM-dependent tail was confirmed live against a 3.5.0-5 instance.
  function start() {
    new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    window.addEventListener('popstate', schedule);
    // The dashboard's router uses pushState/replaceState for in-app
    // navigation, which fires no event of its own — wrap them so a
    // submission switch is caught even between DOM-mutation batches.
    ['pushState', 'replaceState'].forEach(function (fn) {
      var orig = history[fn];
      history[fn] = function () {
        var ret = orig.apply(this, arguments);
        schedule();
        return ret;
      };
    });
    sync();
  }
  if (document.body) {
    start();
  } else {
    document.addEventListener('DOMContentLoaded', start);
  }
})();
