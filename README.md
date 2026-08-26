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

## How it works

- **Send to Colophon** (Production stage) uploads the accepted manuscript
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
  (it never trusts the notification's own claims), downloads the package
  from `GET /api/v1/articles/{code}/package`, and creates the JATS galley:
  the XML as the galley file, every other ZIP member as a dependent file so
  in-XML hrefs resolve (`fileStage DEPENDENT`, `assocType SUBMISSION_FILE` —
  the Texture pattern). The XML also lands as a PRODUCTION_READY file.
- Deliveries are at-least-once: the handler dedupes on `X-Colophon-Delivery`.
- **Check status** polls `GET /api/v1/jobs/{id}` and applies a finished job
  the same way — the fallback when inbound webhooks are blocked.

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

**2026-08-26 — OJS 3.5.0-5 (official Docker image, MariaDB), live loop.**
Six real manuscripts — an editorial, a 58-reference review, three research
articles (two with multi-panel plate figures), a case report — each ran:
Send button → article + issue created on Colophon → front applied (all
author emails, corresponding author, DOI, pages, history dates) →
references resolved unattended → package built and charged → signed
callback → JATS galley + per-panel TIFF dependents in OJS. The pairing flow
(Connect → confirm page → claim) ran end to end with zero copy-paste.

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

- A person clicking the *workflow* buttons in a real browser (the JS
  inserts into the Vue DOM defensively; the payload and endpoints are
  verified, pixels are not). The settings modal has now been opened by a
  person (2026-08-26) — which immediately found two defects the scripted
  E2E could not: pairing URLs built through the component router (Handler
  assert), and the 3.4-only CSRF accessor. Both fixed; the list works.
- The firewall-blocked **Check status** path end-to-end.
- Genres are resolved by registry key (`SUBMISSION`, `IMAGE`); a journal
  that deleted its defaults gets files with no genre — harmless but
  unlabeled.
- OJS caches plugin settings in `cache/fc-pluginSettings-*.php`; configure
  through the settings form (which invalidates it), never by editing the
  database directly.

## License

GPL v3 — see [LICENSE](LICENSE).
