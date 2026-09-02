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
          host.dispatchEvent(new CustomEvent('colophon:status', { detail: c }));
        }).catch(function () { status.textContent = 'failed'; });
    });

    // Only once the article exists: OJS stays authoritative for the DOI,
    // section, licence and page range, and an editor fills those in after the
    // manuscript has already gone. Without this the run's own instruction —
    // "assign the DOI there and send the article again" — named an action the
    // row no longer offered.
    var resend = null;
    if (hasCode) {
      resend = document.createElement('button');
      resend.type = 'button';
      resend.className = 'pkp_button';
      resend.style.marginInlineStart = '6px';
      resend.textContent = data.labels.resend;
      resend.addEventListener('click', function () {
        resend.disabled = true;
        post(host.getAttribute('data-resend-url')).then(function (resp) {
          var c = (resp && resp.content) || {};
          resend.disabled = false;
          resend.textContent = c.articleCode ? '✓' : (c.message || data.labels.resend);
          setTimeout(function () { resend.textContent = data.labels.resend; }, 4000);
        }).catch(function () {
          resend.disabled = false;
          resend.textContent = 'request failed';
        });
      });
    }

    // The finished package. Fetched through the plugin's own download op
    // (the browser never holds the API key) and saved from the response, so
    // a refusal — no package yet, no PDF in this package — lands on the
    // button as words rather than as a JSON page in a new tab.
    function downloadButton(label, urlAttr) {
      var a = document.createElement('button');
      a.type = 'button';
      a.className = 'pkp_button';
      a.style.marginInlineStart = '6px';
      a.textContent = label;
      a.addEventListener('click', function () {
        a.disabled = true;
        a.textContent = '…';
        fetch(host.getAttribute(urlAttr), { credentials: 'same-origin' })
          .then(function (r) {
            var type = r.headers.get('Content-Type') || '';
            if (!r.ok || type.indexOf('application/json') === 0) {
              return r.json().then(function (j) { throw new Error((j && j.error) || r.statusText); });
            }
            var disposition = r.headers.get('Content-Disposition') || '';
            var m = /filename="?([^";]+)"?/.exec(disposition);
            return r.blob().then(function (blob) { return { blob: blob, name: m ? m[1] : 'colophon-package' }; });
          })
          .then(function (file) {
            var url = URL.createObjectURL(file.blob);
            var link = document.createElement('a');
            link.href = url;
            link.download = file.name;
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(function () { URL.revokeObjectURL(url); }, 10000);
            a.disabled = false;
            a.textContent = label;
          })
          .catch(function (err) {
            a.disabled = false;
            a.textContent = (err && err.message) || 'failed';
            setTimeout(function () { a.textContent = label; }, 6000);
          });
      });
      return a;
    }

    var download = downloadButton(data.labels.downloadPackage, 'data-download-url');
    var downloadPdf = downloadButton(data.labels.downloadPdf, 'data-download-pdf-url');

    // Attach as galley: the explicit door on the download delivery. On the
    // galley delivery the plugin attaches on its own, so the button appears
    // there only when a finished package has no galley (an attach failed).
    var attach = null;
    if (data.delivery !== 'galley' || host.getAttribute('data-has-galley') !== '1') {
      attach = document.createElement('button');
      attach.type = 'button';
      attach.className = 'pkp_button';
      attach.style.marginInlineStart = '6px';
      attach.textContent = host.getAttribute('data-has-galley') === '1'
        ? data.labels.attached : data.labels.attachGalley;
      attach.addEventListener('click', function () {
        attach.disabled = true;
        attach.textContent = '…';
        post(host.getAttribute('data-attach-url')).then(function (resp) {
          var c = (resp && resp.content) || {};
          attach.disabled = false;
          if (c.galleyId) {
            host.setAttribute('data-has-galley', '1');
            attach.textContent = data.labels.attached;
          } else {
            attach.textContent = (typeof resp.content === 'string' && resp.content) || c.message || 'failed';
            setTimeout(function () { attach.textContent = data.labels.attachGalley; }, 6000);
          }
        }).catch(function () {
          attach.disabled = false;
          attach.textContent = 'request failed';
        });
      });
    }

    // style.display, not the hidden attribute: OJS's .pkp_button sets
    // display itself, which beats [hidden] — every row showed Download
    // buttons before a package existed (seen live on 3.5.0-5).
    // Needs your review: the badge for everyone, and — for a manager — a
    // button that opens the Colophon page which clears the blockers, signed
    // in through the same short-lived panel link the settings block uses.
    // The link is minted on the click, never stored: ten minutes, single
    // origin, and the page it opens still checks its own permissions.
    var badge = document.querySelector(
      '.colophonNeedsReview[data-submission-id="' + host.getAttribute('data-submission-id') + '"]'
    );
    var review = null;
    if (data.canReview && data.panelUrl) {
      review = document.createElement('button');
      review.type = 'button';
      review.className = 'pkp_button pkp_button_primary';
      review.style.marginInlineStart = '6px';
      review.textContent = data.labels.review;
      review.addEventListener('click', function () {
        review.disabled = true;
        var body = new URLSearchParams();
        body.set('csrfToken', data.csrfToken);
        body.set('next', host.getAttribute('data-review-path') || '');
        fetch(data.panelUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString(),
        }).then(function (r) { return r.json(); }).then(function (resp) {
          review.disabled = false;
          var c = (resp && resp.content) || {};
          if (c.url) { window.open(c.url, '_blank', 'noopener'); }
          else { review.textContent = (typeof resp.content === 'string' && resp.content) || 'failed';
                 setTimeout(function () { review.textContent = data.labels.review; }, 6000); }
        }).catch(function () { review.disabled = false; review.textContent = 'request failed'; });
      });
    }
    function showAttention(needs, reviewPath) {
      if (badge) badge.style.display = needs ? '' : 'none';
      if (review) review.style.display = needs ? '' : 'none';
      if (reviewPath) host.setAttribute('data-review-path', reviewPath);
    }
    showAttention(host.getAttribute('data-needs-person') === '1');
    host.addEventListener('colophon:status', function (e) {
      var c = e.detail || {};
      if (typeof c.needsPerson === 'boolean') showAttention(c.needsPerson, c.reviewPath || '');
    });

    function showPackageControls(ready) {
      var display = ready ? '' : 'none';
      download.style.display = display;
      downloadPdf.style.display = display;
      if (attach) attach.style.display = display;
    }
    showPackageControls(host.getAttribute('data-has-package') === '1');
    host.addEventListener('colophon:status', function (e) {
      var c = e.detail || {};
      if (c.packageReady) showPackageControls(true);
      if (c.galleyAttached && attach) attach.textContent = data.labels.attached;
    });

    host.appendChild(btn);
    if (review) host.appendChild(review);
    if (resend) host.appendChild(resend);
    host.appendChild(status);
    host.appendChild(download);
    host.appendChild(downloadPdf);
    if (attach) host.appendChild(attach);
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
