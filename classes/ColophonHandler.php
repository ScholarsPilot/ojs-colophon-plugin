<?php
/**
 * @file classes/ColophonHandler.php
 *
 * The three operations: start a job, receive the callback, check status.
 *
 * The callback is the sensitive one. It is reachable without an OJS session
 * (Colophon's worker has none), so it authenticates by HMAC signature under the
 * journal's webhook secret and trusts nothing else — not the job id, not the
 * submission id in the URL. A request that does not verify is 401 and does
 * nothing. On a verified notification the handler fetches the job over the
 * authenticated API (never trusting the notification's own claims about the
 * result) and creates the galley.
 */

namespace APP\plugins\generic\colophon;

use APP\core\Application;
use APP\facades\Repo;
use APP\handler\Handler;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\plugins\PluginRegistry;
use PKP\security\authorization\SubmissionAccessPolicy;
use PKP\security\Role;
use PKP\submissionFile\SubmissionFile;

require_once(dirname(__DIR__) . '/classes/ColophonClient.php');

use APP\plugins\generic\colophon\classes\ColophonApiException;
use APP\plugins\generic\colophon\classes\ColophonClient;
use APP\plugins\generic\colophon\classes\ColophonFrontXml;

class ColophonHandler extends Handler
{
    /** @var ColophonPlugin */
    private $plugin;

    public function __construct()
    {
        parent::__construct();
        $this->plugin = PluginRegistry::getPlugin('generic', 'colophonplugin');
        // send/start/status are editor actions on a submission; the connect ops
        // are journal-management actions with no submission; callback is
        // public-by-signature.
        $this->addRoleAssignment(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT],
            ['send', 'start', 'status']
        );
        $this->addRoleAssignment(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN],
            ['connectStart', 'connectPoll', 'panel']
        );
    }

    /** @copydoc PKPHandler::authorize() */
    public function authorize($request, &$args, $roleAssignments): bool
    {
        $op = $request->getRouter()->getRequestedOp($request);
        if ($op === 'callback') {
            return true; // authenticated by signature inside the op itself
        }
        if (in_array($op, ['connectStart', 'connectPoll', 'panel'], true)) {
            // Journal-level, no submission to authorize against: the canonical
            // context policy bundles the role-based op check with the context.
            $this->addPolicy(new \PKP\security\authorization\ContextAccessPolicy($request, $roleAssignments));
            return parent::authorize($request, $args, $roleAssignments);
        }
        $this->addPolicy(new SubmissionAccessPolicy($request, $args, $roleAssignments));
        return parent::authorize($request, $args, $roleAssignments);
    }

    // ----- connect (device-flow pairing) ------------------------------------

    /**
     * POST: begin pairing. Sends the journal's own metadata to Colophon,
     * stores the short-lived pairing code in the plugin settings, and returns
     * the confirm URL for the editor's browser. No credentials move here.
     */
    public function connectStart(array $args, $request): JSONMessage
    {
        if (!$request->checkCSRF()) {
            return new JSONMessage(false, __('form.csrfInvalid'));
        }
        $context = $request->getContext();
        $apiBase = $this->plugin->getApiBase($context->getId());
        if ($apiBase === '') {
            return new JSONMessage(false, __('plugins.generic.colophon.error.noApiBase'));
        }
        $client = new ColophonClient($apiBase, '');
        try {
            $started = $client->connectStart([
                'ojs_url' => $request->getRouter()->url($request, $context->getPath()),
                'journal_path' => (string) $context->getPath(),
                'title' => (string) $context->getLocalizedName(),
                'issn_print' => (string) $context->getData('printIssn'),
                'issn_electronic' => (string) $context->getData('onlineIssn'),
                'publisher_name' => (string) $context->getData('publisherInstitution'),
                'contact_email' => (string) $context->getData('contactEmail'),
                'contact_name' => (string) $context->getData('contactName'),
                'locale' => (string) $context->getPrimaryLocale(),
                'ojs_version' => \PKP\site\VersionCheck::getCurrentCodeVersion()->getVersionString(),
                'plugin_version' => '2.0.0',
            ]);
        } catch (ColophonApiException $e) {
            return new JSONMessage(false, __('plugins.generic.colophon.error.api', ['message' => $e->getMessage()]));
        }
        $this->plugin->updateSetting($context->getId(), ColophonPlugin::SETTING_PAIRING_CODE, (string) ($started['pairing_code'] ?? ''));
        return new JSONMessage(true, [
            'confirmUrl' => $started['confirm_url'] ?? '',
            'expiresIn' => $started['expires_in'] ?? 0,
        ]);
    }

    /**
     * POST: poll the pairing. When Colophon reports it confirmed, claim the
     * credentials (single delivery), store them in the plugin settings, and
     * clear the pairing code — nothing was ever copied by hand.
     */
    public function connectPoll(array $args, $request): JSONMessage
    {
        if (!$request->checkCSRF()) {
            return new JSONMessage(false, __('form.csrfInvalid'));
        }
        $context = $request->getContext();
        $contextId = $context->getId();
        $code = (string) $this->plugin->getSetting($contextId, ColophonPlugin::SETTING_PAIRING_CODE);
        if ($code === '') {
            return new JSONMessage(true, ['state' => 'none']);
        }
        $client = new ColophonClient($this->plugin->getApiBase($contextId), '');
        $state = $client->connectState($code);
        if (($state['state'] ?? '') !== 'confirmed') {
            if (in_array($state['state'] ?? '', ['expired', 'denied'], true)) {
                $this->plugin->updateSetting($contextId, ColophonPlugin::SETTING_PAIRING_CODE, '');
            }
            return new JSONMessage(true, ['state' => $state['state'] ?? 'pending']);
        }
        $claim = $client->connectClaim($code);
        if (($claim['api_key'] ?? '') === '') {
            return new JSONMessage(true, ['state' => $claim['state'] ?? 'pending']);
        }
        $this->plugin->updateSetting($contextId, ColophonPlugin::SETTING_API_KEY, (string) $claim['api_key']);
        $this->plugin->updateSetting($contextId, ColophonPlugin::SETTING_WEBHOOK_SECRET, (string) ($claim['webhook_secret'] ?? ''));
        $this->plugin->updateSetting($contextId, ColophonPlugin::SETTING_JOURNAL_NAME, (string) ($claim['journal_name'] ?? ''));
        $this->plugin->updateSetting($contextId, ColophonPlugin::SETTING_PAIRING_CODE, '');
        return new JSONMessage(true, [
            'state' => 'connected',
            'journalName' => $claim['journal_name'] ?? '',
            'credits' => $claim['credits'] ?? 0,
        ]);
    }

    /**
     * POST: ask Colophon for a signed panel link and hand it to the browser.
     * The journal's API key vouches for the manager holding this page — the
     * same trust pairing established — so the owner lands signed in without
     * a password that pairing deliberately never created.
     */
    public function panel(array $args, $request): JSONMessage
    {
        if (!$request->checkCSRF()) {
            return new JSONMessage(false, __('form.csrfInvalid'));
        }
        $context = $request->getContext();
        $contextId = $context->getId();
        $apiKey = $this->plugin->getApiKey($contextId);
        $apiBase = $this->plugin->getApiBase($contextId);
        if ($apiKey === '' || $apiBase === '') {
            return new JSONMessage(false, __('plugins.generic.colophon.error.notConfigured'));
        }
        $client = new ColophonClient($apiBase, $apiKey);
        try {
            $link = $client->panelLink();
        } catch (ColophonApiException $e) {
            return new JSONMessage(false, __('plugins.generic.colophon.error.api', ['message' => $e->getMessage()]));
        }
        return new JSONMessage(true, ['url' => (string) ($link['url'] ?? '')]);
    }

    // ----- send (one-call intake from the Copyediting stage) ----------------

    /**
     * POST: send the accepted manuscript (FINAL file stage, falling back to the
     * original submission file) to Colophon's one-call intake, with a JATS
     * front built from the publication so author emails, DOI and issue data
     * arrive with it. Stores the returned article code + job against the
     * submission; the existing status/callback machinery takes over from there.
     */
    public function send(array $args, $request): JSONMessage
    {
        if (!$request->checkCSRF()) {
            return new JSONMessage(false, __('form.csrfInvalid'));
        }
        $context = $request->getContext();
        $submission = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION);
        if (!$submission) {
            return new JSONMessage(false, __('plugins.generic.colophon.error.noSubmission'));
        }
        $apiKey = $this->plugin->getApiKey($context->getId());
        if ($apiKey === '') {
            return new JSONMessage(false, __('plugins.generic.colophon.error.notConfigured'));
        }

        $file = $this->latestFile($submission, SubmissionFile::SUBMISSION_FILE_FINAL)
            ?: $this->latestFile($submission, SubmissionFile::SUBMISSION_FILE_SUBMISSION);
        if (!$file) {
            return new JSONMessage(false, __('plugins.generic.colophon.error.noManuscript'));
        }
        $fileService = \APP\core\Services::get('file');
        $stored = $fileService->get((int) $file->getData('fileId'));
        $filesDir = rtrim(\PKP\config\Config::getVar('files', 'files_dir'), '/');
        $path = $filesDir . '/' . $stored->path;
        $name = $file->getLocalizedData('name') ?: ('submission-' . $submission->getId() . '.docx');

        $meta = $this->collectFrontMeta($request, $submission);
        require_once(dirname(__DIR__) . '/classes/ColophonFrontXml.php');
        $front = ColophonFrontXml::build($meta);

        $router = $request->getRouter();
        $callbackUrl = $router->url($request, null, 'colophon', 'callback', null,
            ['submissionId' => $submission->getId()]);
        $idempotencyKey = sprintf('ojs-send-%d-%d', $submission->getId(), (int) $file->getId());

        $client = new ColophonClient($this->plugin->getApiBase($context->getId()), $apiKey);
        try {
            $created = $client->createArticle($path, $name, [
                'issue_volume' => (string) ($meta['volume'] ?? ''),
                'issue_number' => (string) ($meta['issue'] ?? ''),
                'issue_year' => (string) ($meta['year'] ?? ''),
                'article_type' => $meta['article_type'] ?? 'research-article',
                'submission_ref' => sprintf('ojs-%s-%d', $context->getPath(), $submission->getId()),
                'front' => $front,
                'callback_url' => $callbackUrl,
            ], $idempotencyKey);
        } catch (ColophonApiException $e) {
            return new JSONMessage(false, __('plugins.generic.colophon.error.api', ['message' => $e->getMessage()]));
        }

        $submission->setData('colophonArticleCode', (string) ($created['code'] ?? ''));
        if (!empty($created['job_id'])) {
            $this->rememberJob($submission, $created);
        } else {
            Repo::submission()->edit($submission, []);
        }
        return new JSONMessage(true, [
            'articleCode' => $created['code'] ?? '',
            'jobId' => $created['job_id'] ?? null,
            'created' => $created['created'] ?? null,
            'message' => __('plugins.generic.colophon.sent'),
        ]);
    }

    /** The newest submission file at one stage, or null. */
    private function latestFile($submission, int $fileStage)
    {
        $files = Repo::submissionFile()->getCollector()
            ->filterBySubmissionIds([$submission->getId()])
            ->filterByFileStages([$fileStage])
            ->getMany();
        $latest = null;
        foreach ($files as $f) {
            if ($latest === null || $f->getId() > $latest->getId()) {
                $latest = $f;
            }
        }
        return $latest;
    }

    /** Publication metadata → the array ColophonFrontXml::build() consumes. */
    private function collectFrontMeta($request, $submission): array
    {
        $context = $request->getContext();
        $publication = $submission->getCurrentPublication();
        $issue = null;
        $issueId = (int) $publication->getData('issueId');
        if ($issueId) {
            $issue = Repo::issue()->get($issueId);
        }

        $sectionTitle = '';
        $sectionId = (int) $publication->getData('sectionId');
        if ($sectionId) {
            $section = Repo::section()->get($sectionId);
            $sectionTitle = $section ? (string) $section->getLocalizedTitle() : '';
        }

        $primaryContactId = (int) $publication->getData('primaryContactId');
        $authors = [];
        foreach ($publication->getData('authors') ?? [] as $author) {
            $affiliations = [];
            foreach ($author->getData('affiliations') ?? [] as $affiliation) {
                if (is_object($affiliation)) {
                    $name = method_exists($affiliation, 'getLocalizedName')
                        ? $affiliation->getLocalizedName()
                        : ($affiliation->getLocalizedData('name') ?? '');
                } else {
                    $name = is_array($affiliation)
                        ? (string) (current($affiliation['name'] ?? []) ?: '')
                        : (string) $affiliation;
                }
                if ($name !== '') {
                    $affiliations[] = $name;
                }
            }
            $authors[] = [
                'given' => (string) $author->getLocalizedGivenName(),
                'family' => (string) $author->getLocalizedFamilyName(),
                'email' => (string) $author->getData('email'),
                'orcid' => (string) ($author->getData('orcid') ?? ''),
                'corresponding' => $author->getId() === $primaryContactId,
                'affiliations' => $affiliations,
            ];
        }

        $pages = (string) $publication->getData('pages');
        $fpage = $lpage = '';
        if ($pages !== '' && preg_match('/^\s*(\w+)\s*[-–]\s*(\w+)\s*$/u', $pages, $m)) {
            [$fpage, $lpage] = [$m[1], $m[2]];
        } elseif ($pages !== '') {
            $fpage = trim($pages);
        }

        $doi = '';
        if (method_exists($publication, 'getDoi')) {
            $doi = (string) ($publication->getDoi() ?? '');
        }
        if ($doi === '') {
            $doi = (string) ($publication->getStoredPubId('doi') ?? '');
        }

        // OJS knows the history the deposit needs: dateSubmitted is the
        // received date, and the latest ACCEPT decision is the acceptance
        // date. Carrying them removes the missing-received-date blocker for
        // every article that comes through this door.
        $received = substr((string) ($submission->getData('dateSubmitted') ?? ''), 0, 10);
        $accepted = '';
        try {
            $decisions = Repo::decision()->getCollector()
                ->filterBySubmissionIds([$submission->getId()])
                ->getMany();
            foreach ($decisions as $decision) {
                if ((int) $decision->getData('decision') === \PKP\decision\Decision::ACCEPT) {
                    $date = substr((string) ($decision->getData('dateDecided') ?? ''), 0, 10);
                    if ($date !== '' && ($accepted === '' || $date > $accepted)) {
                        $accepted = $date;
                    }
                }
            }
        } catch (\Throwable $e) {
            // The dates are enrichment; a decision store this plugin cannot
            // read must not stop the send.
        }

        return [
            'journal_title' => (string) $context->getLocalizedName(),
            'issn_print' => (string) $context->getData('printIssn'),
            'issn_electronic' => (string) $context->getData('onlineIssn'),
            'article_title' => (string) $publication->getLocalizedTitle(),
            'doi' => $doi,
            'volume' => $issue ? (string) $issue->getData('volume') : '',
            'issue' => $issue ? (string) $issue->getData('number') : '',
            'year' => $issue ? (string) $issue->getData('year') : '',
            'fpage' => $fpage,
            'lpage' => $lpage,
            'date_received' => $received,
            'date_accepted' => $accepted,
            'authors' => $authors,
            'article_type' => $this->mapSectionToArticleType($sectionTitle),
        ];
    }

    /** Crude but honest mapping from an OJS section name to a JATS article type. */
    private function mapSectionToArticleType(string $sectionTitle): string
    {
        $lower = mb_strtolower($sectionTitle);
        foreach ([
            'review' => 'review-article',
            'case' => 'case-report',
            'editorial' => 'editorial',
            'letter' => 'letter-to-the-editor',
        ] as $needle => $code) {
            if ($lower !== '' && str_contains($lower, $needle)) {
                return $code;
            }
        }
        return 'research-article';
    }

    // ----- start ------------------------------------------------------------

    /**
     * POST: send the submission's primary manuscript to Colophon and remember
     * the job id against the submission. Idempotency-Key is derived from the
     * submission + the manuscript file's revision, so a double-click or a retried
     * request cannot start two jobs for the same manuscript.
     */
    public function start(array $args, $request): JSONMessage
    {
        if (!$request->checkCSRF()) {
            return new JSONMessage(false, __('form.csrfInvalid'));
        }
        $context = $request->getContext();
        $submission = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION);
        if (!$submission) {
            return new JSONMessage(false, __('plugins.generic.colophon.error.noSubmission'));
        }

        $apiKey = $this->plugin->getApiKey($context->getId());
        if ($apiKey === '') {
            return new JSONMessage(false, __('plugins.generic.colophon.error.notConfigured'));
        }

        // The article code on Colophon's side. Stored on the submission once
        // the article exists there (created via the Colophon API or UI); the
        // plugin does not create articles from metadata alone — enrich-only.
        $articleCode = (string) $submission->getData('colophonArticleCode');
        if ($articleCode === '') {
            return new JSONMessage(false, __('plugins.generic.colophon.error.noArticleCode'));
        }

        $router = $request->getRouter();
        $callbackUrl = $router->url($request, null, 'colophon', 'callback', null,
            ['submissionId' => $submission->getId()]);
        // Keyed on the last job this plugin saw: a double-click replays the
        // same run, while pressing Produce again after that run finished
        // (blocked, then the editor fixed something) starts a fresh one —
        // a static key swallowed every retry by replaying the finished job.
        $lastJobId = (int) $submission->getData('colophonJobId');
        $idempotencyKey = sprintf(
            'ojs-%d-%d-after-%d',
            $submission->getId(),
            $this->manuscriptRevision($submission),
            $lastJobId,
        );

        $client = new ColophonClient($this->plugin->getApiBase($context->getId()), $apiKey);
        try {
            $job = $client->startJob($articleCode, $idempotencyKey, $callbackUrl);
        } catch (ColophonApiException $e) {
            return new JSONMessage(false, __('plugins.generic.colophon.error.api', ['message' => $e->getMessage()]));
        }

        $this->rememberJob($submission, $job);
        return new JSONMessage(true, [
            'jobId' => $job['job_id'] ?? null,
            'status' => $job['status'] ?? 'pending',
            'message' => __('plugins.generic.colophon.started'),
        ]);
    }

    // ----- callback ---------------------------------------------------------

    /**
     * POST from Colophon when the job finishes. Signature first; everything
     * else second. Returns 200 fast so Colophon marks the delivery done; the
     * galley work happens synchronously here because fetching one package and
     * creating one galley is quick — if it ever is not, queue it with OJS's
     * own job system and still return 200.
     */
    public function callback(array $args, $request): void
    {
        $rawBody = file_get_contents('php://input') ?: '';
        $signature = $_SERVER['HTTP_X_COLOPHON_SIGNATURE'] ?? '';
        $submissionId = (int) $request->getUserVar('submissionId');
        $submission = $submissionId ? Repo::submission()->get($submissionId) : null;
        if (!$submission) {
            $this->respond(404, ['error' => 'unknown submission']);
            return;
        }
        $contextId = $submission->getData('contextId');
        $secret = $this->plugin->getWebhookSecret($contextId);
        if ($secret === '' || !ColophonClient::verifySignature($secret, $rawBody, $signature)) {
            $this->respond(401, ['error' => 'bad signature']);
            return;
        }

        $event = json_decode($rawBody, true) ?: [];
        $jobId = (int) ($event['job_id'] ?? 0);
        $known = (int) $submission->getData('colophonJobId');
        if ($jobId === 0 || $jobId !== $known) {
            // A valid signature but not our job: acknowledge (so Colophon stops
            // retrying) and do nothing — never act on a job we did not start.
            $this->respond(200, ['ok' => true, 'ignored' => 'job not known to this submission']);
            return;
        }

        // Idempotent on the delivery id: at-least-once means we may see it twice.
        $deliveryId = $_SERVER['HTTP_X_COLOPHON_DELIVERY'] ?? '';
        if ($deliveryId !== '' && $deliveryId === $submission->getData('colophonLastDelivery')) {
            $this->respond(200, ['ok' => true, 'duplicate' => true]);
            return;
        }

        try {
            $this->applyFinishedJob($submission, $jobId);
            $submission->setData('colophonLastDelivery', $deliveryId);
            Repo::submission()->edit($submission, []);
        } catch (\Throwable $e) {
            // Tell Colophon to retry: a 5xx is a transient failure in its ledger.
            $this->respond(500, ['error' => $e->getMessage()]);
            return;
        }
        $this->respond(200, ['ok' => true]);
    }

    // ----- status (poll fallback) -------------------------------------------

    /**
     * GET: re-read the job over the API. The fallback for servers whose
     * firewalls block inbound webhooks: "Check status" does the same work the
     * callback would have, from the consumer's side.
     */
    public function status(array $args, $request): JSONMessage
    {
        $context = $request->getContext();
        $submission = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION);
        $jobId = (int) $submission->getData('colophonJobId');
        if (!$jobId) {
            return new JSONMessage(true, ['status' => 'none']);
        }
        $client = new ColophonClient($this->plugin->getApiBase($context->getId()), $this->plugin->getApiKey($context->getId()));
        try {
            $job = $client->getJob($jobId);
        } catch (ColophonApiException $e) {
            return new JSONMessage(false, $e->getMessage());
        }
        if (in_array($job['status'] ?? '', ['completed', 'failed'], true)
            && !$submission->getData('colophonGalleyId')) {
            try {
                $this->applyFinishedJob($submission, $jobId, $job);
            } catch (\Throwable $e) {
                return new JSONMessage(false, $e->getMessage());
            }
        }
        return new JSONMessage(true, [
            'status' => $job['status'] ?? 'unknown',
            'phase' => $job['phase'] ?? '',
            'progress' => $job['progress'] ?? null,
            'result_message' => $job['result_message'] ?? '',
        ]);
    }

    // ----- shared -----------------------------------------------------------

    /**
     * Fetch the finished job (authoritatively, over the API) and attach the
     * package as a JATS galley. Called from both the callback and the poll.
     */
    private function applyFinishedJob($submission, int $jobId, ?array $job = null): void
    {
        $contextId = $submission->getData('contextId');
        $client = new ColophonClient($this->plugin->getApiBase($contextId), $this->plugin->getApiKey($contextId));
        $job = $job ?: $client->getJob($jobId);
        if (($job['status'] ?? '') !== 'completed') {
            // A failed job is recorded, not attached: the editor sees the message.
            $submission->setData('colophonLastResult', $job['error_message'] ?? $job['result_message'] ?? 'failed');
            Repo::submission()->edit($submission, []);
            return;
        }
        // Package download: the result_url is the job; the package itself is the
        // article's final package endpoint. This fetch is where the ZIP comes from.
        $packageBytes = $this->downloadPackage($submission, $job);
        // Delivery lands twice, by decision ت۴ of the approved plan: the XML
        // and PDF members as PRODUCTION_READY files (the editor's working
        // copies in the Production stage) and the whole package as a JATS
        // galley with dependent members (what publishes).
        $this->addProductionReadyFiles($submission, $packageBytes);
        $galleyId = $this->createJatsGalley($submission, $packageBytes);
        $submission->setData('colophonGalleyId', $galleyId);
        $submission->setData('colophonLastResult', $job['result_message'] ?? 'completed');
        Repo::submission()->edit($submission, []);
    }

    private function downloadPackage($submission, array $job): string
    {
        // GET /api/v1/articles/{code}/package — the recorded final package ZIP,
        // served by the same safe storage service as the web download button.
        $contextId = $submission->getData('contextId');
        $code = (string) $submission->getData('colophonArticleCode');
        $client = new ColophonClient($this->plugin->getApiBase($contextId), $this->plugin->getApiKey($contextId));
        return $client->downloadPackage($code);
    }

    /**
     * Create a JATS galley from the package bytes.
     *
     * Every call below is verified against OJS stable-3_4_0 source
     * (ojs f031a21 + pkp-lib 5ae1504, checked 2026-08-19):
     * - galley shape/add: ArticleGalleyForm::execute, PKP\galley\Repository
     * - file storage: Services::get('file')->add(LOCAL PATH, dir/uniqid.ext) —
     *   it fopen()s the first argument, so bytes are staged to a temp file
     *   first; it returns the integer file id (SubmissionFilesUploadForm)
     * - submission dir: Repo::submissionFile()->getSubmissionDir(ctx, sub)
     * - dependent-file pattern: SUBMISSION_FILE_DEPENDENT +
     *   ASSOC_TYPE_SUBMISSION_FILE (NativeXmlSubmissionFileFilter)
     * - genres resolved by registry key via GenreDAO::getByKey; the XML file
     *   is the SUBMISSION genre, members are IMAGE (registry/genres.xml)
     *
     * Two passes over the ZIP on purpose: the dependent members need the XML
     * submission file's id as their assocId, and ZIP order guarantees nothing.
     * Still to verify on a live install: end-to-end behavior only (see README).
     */
    private function createJatsGalley($submission, string $packageBytes): int
    {
        $publication = $submission->getCurrentPublication();
        $contextId = (int) $submission->getData('contextId');
        $locale = $publication->getData('locale');
        $galley = Repo::galley()->newDataObject([
            'publicationId' => $publication->getId(),
            'label' => 'JATS XML',
            'locale' => $locale,
            'seq' => 0,
        ]);
        $galleyId = Repo::galley()->add($galley);

        $tmp = tempnam(sys_get_temp_dir(), 'colophon');
        file_put_contents($tmp, $packageBytes);
        $zip = new \ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('package is not a readable zip');
        }

        $genreDao = DAORegistry::getDAO('GenreDAO');
        $xmlGenre = $genreDao->getByKey('SUBMISSION', $contextId);
        $imageGenre = $genreDao->getByKey('IMAGE', $contextId);
        $submissionDir = Repo::submissionFile()->getSubmissionDir($contextId, $submission->getId());
        $uploaderId = Application::get()->getRequest()->getUser()?->getId();

        $addFile = function (string $name, string $bytes, array $data) use ($submissionDir, $submission, $locale, $uploaderId) {
            // Services::get('file')->add() copies from a local path and
            // returns the integer file id; bytes are staged first.
            $staged = tempnam(sys_get_temp_dir(), 'colophon-member');
            file_put_contents($staged, $bytes);
            $extension = pathinfo($name, PATHINFO_EXTENSION) ?: 'bin';
            $fileId = \APP\core\Services::get('file')->add(
                $staged,
                $submissionDir . '/' . uniqid() . '.' . $extension
            );
            @unlink($staged);
            $sf = Repo::submissionFile()->newDataObject($data + [
                'fileId' => $fileId,
                'name' => [$locale => basename($name)],
                'submissionId' => $submission->getId(),
                'uploaderUserId' => $uploaderId,
            ]);
            return Repo::submissionFile()->add($sf);
        };

        // Pass 1: the JATS XML becomes the galley's main file.
        $xmlFileId = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('/\.xml$/i', $name) !== 1) {
                continue;
            }
            $xmlFileId = $addFile($name, $zip->getFromIndex($i), [
                'fileStage' => SubmissionFile::SUBMISSION_FILE_PROOF,
                'assocType' => Application::ASSOC_TYPE_REPRESENTATION,
                'assocId' => $galleyId,
                'genreId' => $xmlGenre?->getId(),
            ]);
            $galley->setData('submissionFileId', $xmlFileId);
            Repo::galley()->edit($galley, []);
            break;
        }
        if ($xmlFileId === null) {
            $zip->close();
            @unlink($tmp);
            throw new \RuntimeException('package holds no XML member');
        }

        // Pass 2: every other member is a DEPENDENT file of the XML — the
        // "JATS href == ZIP member" invariant, so in-XML hrefs resolve in OJS.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('/\.xml$/i', $name) === 1) {
                continue;
            }
            $addFile($name, $zip->getFromIndex($i), [
                'fileStage' => SubmissionFile::SUBMISSION_FILE_DEPENDENT,
                'assocType' => Application::ASSOC_TYPE_SUBMISSION_FILE,
                'assocId' => $xmlFileId,
                'genreId' => $imageGenre?->getId(),
            ]);
        }
        $zip->close();
        @unlink($tmp);
        return $galleyId;
    }

    /**
     * Add the package's XML and PDF as PRODUCTION_READY submission files —
     * fileStage 11, the "ready for layout/publication" shelf editors work from.
     * Same storage recipe as the galley path below.
     */
    private function addProductionReadyFiles($submission, string $packageBytes): void
    {
        $contextId = (int) $submission->getData('contextId');
        $publication = $submission->getCurrentPublication();
        $locale = $publication->getData('locale');
        $tmp = tempnam(sys_get_temp_dir(), 'colophon-pr');
        file_put_contents($tmp, $packageBytes);
        $zip = new \ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            return; // the galley path reports the unreadable-zip case
        }
        $genreDao = DAORegistry::getDAO('GenreDAO');
        $xmlGenre = $genreDao->getByKey('SUBMISSION', $contextId);
        $submissionDir = Repo::submissionFile()->getSubmissionDir($contextId, $submission->getId());
        $uploaderId = Application::get()->getRequest()->getUser()?->getId();

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('/\.(xml|pdf)$/i', $name) !== 1) {
                continue;
            }
            $staged = tempnam(sys_get_temp_dir(), 'colophon-pr-member');
            file_put_contents($staged, $zip->getFromIndex($i));
            $extension = pathinfo($name, PATHINFO_EXTENSION) ?: 'bin';
            $fileId = \APP\core\Services::get('file')->add(
                $staged,
                $submissionDir . '/' . uniqid() . '.' . $extension
            );
            @unlink($staged);
            $sf = Repo::submissionFile()->newDataObject([
                'fileId' => $fileId,
                'name' => [$locale => basename($name)],
                'submissionId' => $submission->getId(),
                'uploaderUserId' => $uploaderId,
                'fileStage' => SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY,
                'genreId' => $xmlGenre?->getId(),
            ]);
            Repo::submissionFile()->add($sf);
        }
        $zip->close();
        @unlink($tmp);
    }

    private function manuscriptRevision($submission): int
    {
        // The primary manuscript's file id doubles as a revision marker: a new
        // upload is a new id, so the idempotency key changes with it.
        $files = Repo::submissionFile()->getCollector()
            ->filterBySubmissionIds([$submission->getId()])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_SUBMISSION])
            ->getMany();
        $max = 0;
        foreach ($files as $f) {
            $max = max($max, (int) $f->getId());
        }
        return $max;
    }

    private function rememberJob($submission, array $job): void
    {
        $submission->setData('colophonJobId', (int) ($job['job_id'] ?? 0));
        $submission->setData('colophonStatusUrl', (string) ($job['status_url'] ?? ''));
        $submission->setData('colophonGalleyId', null);
        Repo::submission()->edit($submission, []);
    }

    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($body);
    }
}
