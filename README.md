# Colophon for OJS

Send an accepted submission to a [Colophon](https://github.com/ScholarsPilot)
server with one click, and get the finished, deposit-ready JATS/PMC package
back as a galley — references resolved and verified, figures as production
TIFFs, XML checked against PMC's own style rules. An agent drives the run on
the Colophon side; a person decides what ships.

The plugin is a thin, auditable client. It holds no processing logic of its
own: it sends the manuscript and the journal-system metadata OJS already has
(author emails, DOI, pages, received/accepted dates), and it receives files.
Everything it does is visible in this repository.

## Requirements

- OJS **3.4 or 3.5** (the Repo / Laravel-style APIs). 3.3 LTS is not supported.
- PHP 8.1+ with curl and zip extensions.
- Outbound HTTPS to your Colophon server.
- Inbound webhooks are optional: servers behind firewalls use **Check status**.

## Install and connect (no keys to copy)

1. Copy this directory to `plugins/generic/colophon/` and enable it in
   Website → Plugins.
2. In the plugin settings, enter your **Colophon server address** and press
   **Connect**. A Colophon page opens; sign in there (a one-time email link)
   and confirm. The plugin picks up its credentials automatically — nothing
   is copied by hand, and the journal account, welcome credits and journal
   profile are created on the Colophon side as part of the same step.
3. Alternatively, paste an API key and webhook signing secret minted on
   Colophon's API-keys page (both shown once). The form is write-only: a
   blank field keeps the stored value, and secrets are never echoed back.
4. A **Colophon** item appears in the editorial sidebar (Manager, Section
   Editor and Assistant see it). That page lists the submissions that have
   reached Copyediting or Production with a Send / Generate / Check status
   button on each — it is where the work is done, and the plugin owns it
   rather than borrowing a page from OJS.
5. Once connected, **Open Colophon panel** in the same settings takes you
   into your journal's Colophon workspace signed in — the key vouches for
   you, so no password ever needs to exist. The same block shows the
   journal's **remaining credits** and a **Top up** button that lands,
   signed in, on the credits shop.
5. **Manage submissions**, in the same settings block, opens the plugin's
   own page: every submission at Copyediting stage or later, one row each,
   with Send/Generate and Check status right there. Nothing renders inside
   any submission's own workflow page — see "How it works" for why.

## How it works

- The editor's entry point is the plugin's own **submissions page**
  (Settings → Manage submissions), not a button inside each submission's
  workflow page. An earlier version worked that way; it was replaced
  2026-08-27 after two problems surfaced from actually using it: OJS 3.5
  rebuilt the workflow page as a client-side dashboard SPA with no stable
  per-submission server render to hook, and — independent of that — the
  button had no stage check at all, so it appeared (and could be clicked)
  on a submission still awaiting review, before there was an accepted
  manuscript to send. The submissions page sidesteps both: it is a plain
  Smarty page extending `layouts/backend.tpl`, the same base every OJS
  version already uses for its own admin pages, and its one server-side
  query (`stageId >= WORKFLOW_STAGE_ID_EDITING`) only ever lists submissions
  that are actually eligible.
- **Send to Colophon** (Copyediting stage or later) uploads the accepted manuscript
  (FINAL file stage, falling back to the original submission file) to
  `POST /api/v1/articles` — one multipart call that creates the article and
  its issue and starts the run. A JATS `<front>` built from the OJS
  publication rides along, so author emails, the corresponding author, DOI,
  pages, and the received/accepted dates (from `dateSubmitted` and the
  editorial ACCEPT decision) arrive with the manuscript. The
  `Idempotency-Key` is derived from the submission and the manuscript file
  revision — a double-click cannot create two articles.
- **Produce package** posts a `produce_package` job with a `callback_url`
  pointing at this plugin's handler; the idempotency key includes the last
  job seen, so retrying after a finished run starts a fresh one instead of
  replaying it.
- When the job finishes, Colophon POSTs a **signed** notification
  (`X-Colophon-Signature`, HMAC-SHA256 of `"{t}.{body}"` under your webhook
  secret; 5-minute replay window). The handler verifies the signature before
  reading anything else, re-fetches the job **over the authenticated API**
  (it never trusts the notification's own claims), and records that a
  package exists. What happens next is the journal's **Delivery** setting:
  - **Download** (the default since 2026-09-02): nothing is written into
    OJS. The row on the submissions page offers **Download package (ZIP)**
    and **Download PDF** (the typeset PDF member, when the issue was
    typeset); each click fetches `GET /api/v1/articles/{code}/package`
    through the plugin — the browser never holds the API key — and streams
    it back. **Attach as galley** is a separate, explicit button that does
    the galley work below on demand. The editor downloads and decides.
  - **Galley**: every finished package is attached automatically — the XML
    as the galley file, every other ZIP member as a dependent file so
    in-XML hrefs resolve (`fileStage DEPENDENT`, `assocType
    SUBMISSION_FILE` — the Texture pattern), and the XML and PDF also as
    PRODUCTION_READY files. A redelivery replaces the plugin's own earlier
    galley and files; a galley an editor made by hand is never touched.
- Deliveries are at-least-once: the handler dedupes on `X-Colophon-Delivery`.
- **Check status** polls `GET /api/v1/jobs/{id}` and applies a finished job
  the same way — the fallback when inbound webhooks are blocked.
- **A finished job is not always a package.** A `produce_package` run
  reports `completed` whether it built the package, stopped where a person
  is needed, or was refused the build (credits). The job says which:
  `needs_person`, `blockers` (each one a sentence, with a code), and
  `review_path`. On `needs_person` the row shows **Needs your review** with
  the sentence, and — for a journal manager — **Review on Colophon**: the
  click asks `POST /api/v1/panel-link` for a signed link with `next` set to
  `review_path`, and opens it. The link is minted on the click, never
  stored, lives ten minutes, lands on a confirm button (so a link preview
  cannot spend it), and opens the article's Convert page signed in as the
  journal owner, where each blocker has its resolver. Such a run is never
  recorded as a delivery, so the Download buttons keep offering the last
  package that was actually built.

The API contract lives at `{your server}/api/docs/` and the error catalog +
verifier samples at `/api/v1/reference`.

## Security notes

- **No baked-in server address.** The plugin ships with an empty API base
  and refuses politely until the operator sets one — a hardcoded default
  domain would send pairing requests (and, after pairing, credentials) to
  whoever controlled that domain.
- The callback endpoint authenticates **only** by signature; a request that
  does not verify is 401 and does nothing. It also refuses job ids it did
  not start, even with a valid signature.
- Secrets are stored in plugin settings, write-only in the form, and never
  logged. The pairing poll never carries secrets; the claim is single
  delivery and the plaintext is wiped server-side once claimed.
- Pairing and job actions are role-gated (Manager/Site Admin for connect;
  Manager/Section Editor/Assistant for send/produce/status) and
  CSRF-checked.

## Verification record

**2026-09-02, second pass — "needs your review", with a timed link straight
to the fix.** The row had one word for three different endings of a run:
`completed`. Colophon's job payload now carries `needs_person`, `blockers`
and `review_path` (`publishing/services/agent_background_jobs.job_attention`,
pinned by `tests_api_job_attention`); the plugin persists them
(`colophonNeedsPerson`, `colophonReviewPath` — declared on the schema, or
they would be stripped), shows the badge, and offers **Review on Colophon**
to managers and site admins (`canOpenPanel`), which mints the panel link
with `next=review_path`. Verified live on `ijmm`, submission 33: pagination
blanked on the article → Generate → job 393 stopped at
`missing_first_and_last_page_or_elocation_id` → Check status: `needsPerson
true`, the blocker as a sentence, `review_path
/convert/?journal=7&issue=29&article=184`; the row showed the badge and the
button; `colophonAppliedJobId` stayed at the last *built* package (392), so
Download still served that one; the button's panel link opened the confirm
page, and Continue landed on the article's Convert page signed in as the
journal owner with the blocker and its resolver ("Save pages and continue")
on screen. Sandbox note: the panel link is built from the API request's
host (`host.docker.internal`), which the Mac's browser cannot resolve —
irrelevant in a real deployment, where the API base is a public name.

**2026-09-02 — the download delivery, verified live on 3.5.0-5.** Owner
decision: the package should reach the editor as a download by default and
enter OJS only when asked — the least of OJS touched. Built as a per-journal
**Delivery** setting (download | galley), a `download` op that proxies the
package through the plugin (`?member=pdf` streams just the PDF), an
`attach` op behind an explicit button, and `packageReady` / `galleyAttached`
on the status reply so the row reveals its buttons the moment a job
finishes. Verified on the `ijmm` journal (context 3 ↔ Colophon journal 7):

1. **Download package**: 200, `application/zip`, `Content-Disposition:
   attachment; filename="ijom-20-1-1105.zip"`, 5870 bytes — the recorded
   package, named after its XML member. A row with no package: 409 with a
   sentence, shown on the button rather than as a JSON page.
2. **Download PDF** on a package with no PDF: 404 with the reason (the
   issue was not typeset). The PDF member path itself was exercised with
   the real handler method against a real typeset package
   (`ijp-22-1-3.zip` → `ijp-22-1-3.pdf`, 1,885,979 bytes, `%PDF-` magic) —
   no OJS-paired journal here has a typeset issue yet.
3. **A full run on the download delivery**: Generate → job 392 → callback
   → the row recorded `colophonAppliedJobId=392` and *nothing else changed*
   — galley 74 and production file 250 exactly as before. (The first
   attempt reported "7 credits are needed and 5 are available" — a real
   refusal from Colophon's issue purchase, surfaced verbatim in the row.)
4. **Attach as galley** on submission 32: galley 75 → 77, production file
   252 → 260, one "JATS XML" galley on the publication — the replace rule
   holds through the button too.
5. **Settings**: the Delivery select renders with its description, a save
   of `galley` round-trips (re-fetched form shows it selected, DB row
   `colophonDelivery=galley`), and back to `download`.

Two defects found only by loading the page: OJS's `.pkp_button` sets
`display` itself, so the `hidden` attribute did nothing and every row showed
Download buttons before a package existed (now `style.display`); and
deliveries from before `colophonAppliedJobId` existed recorded only a galley
id, so their packages — still on Colophon — would have had no button (a
galley now also counts as proof of a package). Not re-verified on 3.4 this
round: nothing here is version-specific (`Repo::galley`, `Repo::submissionFile`
and the page router were already in use on both).

**2026-08-27, fourth pass — the sidebar item, on both supported versions.**
The plugin's page now has a door in the editorial left-hand menu
(`TemplateManager::setupBackendPage`, reading the built menu with
`getState('menu')` and handing back an extended copy). Signed in and clicked
it on **OJS 3.5.0-5** and on **OJS 3.4.0-8**: the item renders in the sidebar
on both, marks itself current on its own page (`app__navItem--isCurrent` on
3.4), and opens the submissions list — populated on 3.5, and correctly
showing the "nothing has reached Copyediting yet" empty state on the 3.4
test journal. The one trap: the class is `APP\template\TemplateManager`,
not `PKP\template\` — with the wrong namespace OJS logs "failed to handle
the hook" and renders the page perfectly without the item, so the breakage
is silent.

**2026-08-27, third pass — the workflow-page button replaced with a
submissions page.** The two entries below fixed the button that injected
into each submission's own workflow page (`injectProductionAction`,
`window.colophonData`, `colophon.js`) so it would render on 3.5 and only on
Copyediting-or-later submissions. That whole mechanism is now removed. The
owner's objection, raised after seeing the fix demonstrated: an action tied
to production readiness had no business appearing on every stage of every
submission a Manager or Sub-editor opens, on a real journal with hundreds of
papers — and even gated correctly to Copyediting+, it still showed on the
*history* sub-tabs (Submission, Review) of an already-eligible paper, which
is not where an editor is deciding to act. Fixing that with more per-tab
logic on top of an already-fragile per-version template hook was the wrong
direction; the plugin now owns a page instead of borrowing OJS's.
`ColophonHandler::submissions()` queries `Repo::submission()->getCollector()
->filterByStageIds([WORKFLOW_STAGE_ID_EDITING, WORKFLOW_STAGE_ID_PRODUCTION])`
directly — one comparison, no per-row client-side stage check needed at
all — and renders a plain table via `templates/submissions.tpl`, reached
from a **Manage submissions** link in the plugin's own settings modal.
`{extends file="layouts/backend.tpl"}` is the same base `templates/admin/
index.tpl` uses for site administration, verified identical on stable-3_4_0
and stable-3_5_0, so there is no per-OJS-version template to track here the
way there was for the workflow page.

Three real bugs found by loading the actual page, not by reading the diff:
`$router->url(..., 'workflow', 'access', $submission->getId())` throws —
`PKPPageRouter::url()`'s `$path` parameter is `?array`, and a bare int
fatals the whole request; it needs `[$submission->getId()]`. The page
rendered with correct data but broken styling and `$` (jQuery) undefined
until `$this->_isBackendPage = true;` was set before calling
`setupTemplate()` — a public property on the base `Handler` that
`TemplateManager::setupBackendPage()` is gated on, easy to miss because nothing
throws when it is false. And a raw `<script>` block written directly inside
`submissions.tpl`'s own `{block name="page"}` was present verbatim in the
server-rendered HTML (confirmed by fetching the page's own response text)
but never executed in the live DOM (confirmed by `document.scripts` not
containing it after load) — almost certainly 3.5's Vue-based page hydration
treating an inline script inside its mounted region as inert content rather
than something to execute. Moved to an external file loaded through
`TemplateManager::addJavaScript()` instead — the same mechanism the
removed per-submission injection used successfully all session on other
full-page loads — and it executed correctly. That file needed its own
`document.body` readiness guard for the same reason `colophon.js` did
before it (see the entry below): loaded in `<head>`, it runs before the
rows it wires exist, and unlike the removed script this one has no
retry/observer, so getting that guard wrong would have silently wired
nothing, permanently, with no error to notice.

Verified live end-to-end on both versions after all three fixes: the page
renders with full native chrome and correct per-row stage/status data on a
3.5.0-5 instance with 17 real eligible submissions and on a 3.4.0-8 instance
whose one real submission is correctly *excluded* (still Submission stage);
Generate and Check status both round-trip through the real handler with the
same success/status messages the removed button produced; the settings
modal's new link resolves to the right URL on both.

**2026-08-27 — the OJS 3.5 workflow button, fixed and verified live**
(mechanism since replaced by the entry above; kept for the record — the
underlying facts here, WORKFLOW_STAGE_ID_EDITING and the 3.5 dashboard's
routing behavior, are still what the current code relies on). Found
2026-08-27 by asking where an editor actually clicks: `injectProductionAction`
hooked `TemplateManager::display` and returned early unless the template was
`workflow/workflow.tpl` — a template that does not exist in 3.5, where the
workflow page became part of the dashboard SPA. The endpoints themselves were
always fine (the whole 2026-08-26 campaign above drove `send`/`start`/
`callback` and produced galleys), but only through POSTs, never through a
button a person pressed. Now closed. What actually changes on 3.5, learned by
driving a live 3.5.0-5 instance rather than reading the SPA's source in
isolation:

- Opening or switching submissions from `dashboard/editorial` is **pure
  client-side routing** — a `GET /api/v1/submissions/{id}`, confirmed on the
  wire — not a page load. `TemplateManager::display` fires exactly once, for
  the dashboard shell itself; a payload with a baked-in submission id would
  have gone stale the moment an editor clicked a second row. The hook now
  emits URL *templates* (a `__SUBMISSION_ID__` placeholder) instead, and
  `colophon.js` substitutes the id it reads from the live URL.
- `colophonArticleCode` (and the plugin's other schema-declared submission
  properties) already ride along in that same `/api/v1/submissions/{id}`
  JSON, because `addToSubmissionSchema` puts them on the schema the REST
  serializer reads — confirmed by inspecting a live response. No new PHP
  endpoint was needed for the client to know whether a submission has
  already been sent.
- The stable insertion point is `[data-cy="sidemodal-header"]` — a Cypress
  test hook, not the Tailwind utility classes wrapping it, which carry no
  semantic meaning and are not something a plugin should anchor to. It
  persists across the Submission/Review/Copyediting/Production sub-tabs of
  one open submission; the per-stage `[data-cy="workflow-action-items"]`
  panel does not (it is absent whenever the current sub-tab has no primary
  action), which is why the button targets the header, not that panel.
- Two real bugs surfaced only by clicking, not by reading the diff: this
  script runs in `<head>`, before the parser reaches `<body>` —
  `document.body` is `null` at that point, and `MutationObserver.observe`
  throws (not returns null) on a null target, which silently aborted every
  listener after it, including the initial render. And a long result
  message ("Colophon is producing the package...") is a full sentence, not
  a button label — in the SPA's narrow slide-in panel (384px), an unbounded
  row pushed the submission title down to a one-word column before the
  injected element was given a max-width and allowed to wrap.
- Verified on both target versions after the fix, not just 3.5: a submission
  switch (18 → 27) re-fetches and re-renders with no duplicate and no stale
  URL; all four stage sub-tabs preserve exactly one button instance; a
  bookmarked/reloaded `?workflowSubmissionId=` URL renders correctly on a
  fresh load; the Generate and Check status clicks round-trip through the
  real handler on both a 3.5.0-5 and a 3.4.0-8 instance with zero console
  errors beyond the target site's own pre-existing, unrelated
  `pkp.context.timeZone` warning. The 3.4 code path's actual logic is
  unchanged — the refactor only extracted the shared button-rendering code
  behind a `state` object so both versions could use it — and this was
  re-verified live rather than assumed safe from the diff alone.

**2026-08-27, second pass — the button was showing on every stage, not just
Copyediting onward** (mechanism since replaced by the submissions-page entry
above; the `stageId >= WORKFLOW_STAGE_ID_EDITING` rule found here is what
that page's own query now enforces directly). Raised by the owner, not
found by testing: the fix
above made the button render correctly, but said nothing about *when* it
should. `injectProductionAction` had no stage check at all, so opening a
submission's workflow page showed "Send to Colophon" whether the paper was
in Submission, Review, Copyediting, or Production — and a real example of
the resulting risk already existed in this repository's own e2e test data:
submission 1 on the 3.4.0-8 instance sits at `stageId=1` (Submission, before
review) yet already carries a `colophonArticleCode` from earlier testing, so
the button rendered there too. Since `send()` falls back to the raw
submitted file when no FINAL-stage file exists yet, clicking it that early
would have sent an unreviewed manuscript. Gated to `stageId >=
WORKFLOW_STAGE_ID_EDITING` (4, Copyediting) now — server-side on the 3.4
branch (checked before any payload is built), client-side on the 3.5 branch
(off the same submission JSON already being fetched for
`colophonArticleCode`, no extra request). Verified live in both directions
on both versions: the existing 3.4 submission 1 (stageId 1) now shows
neither the button nor `window.colophonData` at all; a freshly created 3.5
test submission (stageId 1, confirmed via five repeated API calls before
concluding it wasn't a flaky read) is correctly hidden; the existing
Copyediting-stage submissions on both versions are unaffected. One real
false alarm during this verification, worth recording: the very first
retest showed the button rendering anyway, which held even after a
cmd+shift+r reload — traced to the embedded test browser not honoring that
shortcut as an actual cache-bypass (`performance.getEntriesByType('resource')`
on the failing load was never captured to confirm it, but a subsequent clean
navigation showed a real 4023-byte network transfer, not a 0-byte cache hit,
and passed correctly) rather than a defect in the gate itself — a reminder
that this plugin's static assets are cache-busted by the *OJS version*
(`?v=3.5.0.5`), not by plugin content, so a browser can keep serving an
editor a stale `colophon.js` after an update until it naturally
revalidates.

**2026-08-26 — OJS 3.5.0-5 (official Docker image, MariaDB), live loop.**
Six real manuscripts — an editorial, a 58-reference review, three research
articles (two with multi-panel plate figures), a case report — each ran:
Send button → article + issue created on Colophon → front applied (all
author emails, corresponding author, DOI, pages, history dates) →
references resolved unattended → package built and charged → signed
callback → JATS galley + per-panel TIFF dependents in OJS. The pairing flow
(Connect → confirm page → claim) ran end to end with zero copy-paste.

**2026-08-26, second journal — redelivery replaces, never stacks.** A
re-produce (copy edits applied on the Colophon side) delivers a new package
for the same submission. The handler now deletes the galley and
production-ready files it created on the previous delivery — by their
recorded ids only, never an editor's own galley — before adding the new
ones; verified live with consecutive redeliveries leaving exactly one
galley. Two OJS facts this depends on, both verified against pkp-lib 3.5
source: `Repo::galley()->delete()` cascades to the galley's files and their
dependents, and **a submission setting persists only if the plugin declares
it in the submission schema** — `EntityDAO` sanitizes undeclared properties
away on every save, silently. `colophonLastDelivery` had been undeclared
since the beginning, so webhook delivery dedupe never actually persisted;
declared now, with `colophonAppliedJobId` and `colophonProductionFileIds`.

**2026-08-19 — OJS 3.4.0-8 (official Docker image, MariaDB, CLI-installed).**
1. `php -l` clean under PHP 8.3 for every file.
2. Every OJS API this plugin touches verified against the `stable-3_4_0`
   source (ojs `f031a21` + pkp-lib `5ae1504`): all imports resolve; galley
   shape per `ArticleGalleyForm`; file storage per
   `SubmissionFilesUploadForm`; dependent-file pattern per
   `NativeXmlSubmissionFileFilter`; handler contract per `PKPPageRouter`.
3. Full loop live: a real signed webhook over the network; the plugin
   verified the signature, refused a stale/unknown job, fetched the job over
   the API, downloaded the package, created the galley. The stored galley
   XML was **byte-identical** (sha256) to the package member.
4. The workflow page carries `window.colophonData` and loads
   `js/colophon.js` through OJS's script pipeline.
5. The settings form renders under the component router and a save
   round-trips with CSRF.

Twelve defects were found and fixed by these verifications — the reason
they exist. Still open, honestly:

- The firewall-blocked **Check status** path end-to-end.
- Genres are resolved by registry key (`SUBMISSION`, `IMAGE`); a journal
  that deleted its defaults gets files with no genre — harmless but
  unlabeled.
- OJS caches plugin settings in `cache/fc-pluginSettings-*.php`; configure
  through the settings form (which invalidates it), never by editing the
  database directly.

## License

GPL v3 — see [LICENSE](LICENSE).
