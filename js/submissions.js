/**
 * The submissions list page. Loaded via TemplateManager::addJavaScript, not
 * a raw <script> tag inside the page's own Smarty block — a raw inline tag
 * there was present in the server-rendered HTML but never executed in the
 * live DOM (found live on a 3.5.0-5 instance: document.scripts did not
 * contain it after load). addJavaScript is the same mechanism the earlier
 * per-submission button injection used successfully all session, on pages
 * that are also full top-level loads, not modals.
 *
 * Runs in <head>, before the parser reaches <body> — the same reason the
 * old per-submission injection needed a DOMContentLoaded guard applies
 * here too (found live a second time: this file has no retry/observer, so
 * running once against an empty document silently wires nothing, ever).
 */
(function () {
  var data = window.colophonSubmissionsData;
  if (!data) return;

  function post(url) {
    var body = new URLSearchParams();
    body.set('csrfToken', data.csrfToken);
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    }).then(function (r) { return r.json(); });
  }

  function wireRow(host) {
    var hasCode = host.getAttribute('data-has-code') === '1';
    var primaryUrl = hasCode ? host.getAttribute('data-start-url') : host.getAttribute('data-send-url');
    var primaryLabel = hasCode ? data.labels.generate : data.labels.send;

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pkp_button';
    btn.textContent = primaryLabel;
    btn.addEventListener('click', function () {
      btn.disabled = true;
      post(primaryUrl).then(function (resp) {
        var c = (resp && resp.content) || {};
        btn.disabled = false;
        if (c.articleCode) {
          primaryUrl = host.getAttribute('data-start-url');
          primaryLabel = data.labels.generate;
          btn.textContent = primaryLabel;
          var statusCell = document.querySelector(
            '.colophonStatus[data-submission-id="' + host.getAttribute('data-submission-id') + '"]'
          );
          if (statusCell) statusCell.textContent = c.articleCode;
        } else {
          btn.textContent = c.message || primaryLabel;
          setTimeout(function () { btn.textContent = primaryLabel; }, 4000);
        }
      }).catch(function () {
        btn.disabled = false;
        btn.textContent = 'request failed';
      });
    });

    var status = document.createElement('a');
    status.href = '#';
    status.className = 'pkp_button';
    status.style.marginInlineStart = '6px';
    status.textContent = data.labels.checkStatus;
    status.addEventListener('click', function (e) {
      e.preventDefault();
      status.textContent = '…';
      fetch(host.getAttribute('data-status-url'), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
          var c = (resp && resp.content) || resp || {};
          status.textContent = (c.status || 'unknown') + (c.phase ? ' — ' + c.phase : '');
          setTimeout(function () { status.textContent = data.labels.checkStatus; }, 4000);
        }).catch(function () { status.textContent = 'failed'; });
    });

    host.appendChild(btn);
    host.appendChild(status);
  }

  function run() {
    document.querySelectorAll('.colophonRowActions').forEach(wireRow);
  }

  if (document.body) {
    run();
  } else {
    document.addEventListener('DOMContentLoaded', run);
  }
})();
