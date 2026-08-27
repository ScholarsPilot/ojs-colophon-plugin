<?php
/**
 * @file ColophonPlugin.php
 *
 * Colophon for OJS: a Production-stage action that sends the submission's
 * manuscript to Colophon and attaches the returned JATS package as a galley.
 *
 * Design, in one paragraph. This plugin adds an explicit action where a JATS
 * galley belongs — the Production stage — and touches nothing upstream: no
 * hook on editorial decisions, no automatic trigger on "accept". An editor
 * presses "Generate JATS with Colophon"; the plugin posts the manuscript to the
 * Colophon API and gets a 202 with a job id; when the job finishes, Colophon
 * POSTs a signed notification to this plugin's callback handler, which
 * verifies the signature, fetches the result over the API, and creates the
 * galley. A "Check status" button polls the same job as a fallback for journal
 * servers whose firewalls block inbound webhooks. An agent drives it; a person
 * decides.
 *
 * Targets OJS 3.4+ (Repo / Laravel-style APIs). 3.3 LTS uses DAOs and is not
 * supported by this code.
 */

namespace APP\plugins\generic\colophon;

use APP\core\Application;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class ColophonPlugin extends GenericPlugin
{
    public const SETTING_API_BASE = 'colophonApiBase';
    public const SETTING_API_KEY = 'colophonApiKey';
    public const SETTING_WEBHOOK_SECRET = 'colophonWebhookSecret';
    //: The short-lived device-flow pairing code, held only between Connect and
    //: the claim; cleared the moment credentials arrive (or the code dies).
    public const SETTING_PAIRING_CODE = 'colophonPairingCode';
    //: The connected Colophon journal's display name, for the settings page.
    public const SETTING_JOURNAL_NAME = 'colophonJournalName';

    /** @copydoc Plugin::register() */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            // Route our handlers: /index.php/{journal}/colophon/{op}
            Hook::add('LoadHandler', [$this, 'setupHandler']);
            // Offer the action in the Production stage's publication galleys grid.
            Hook::add('TemplateManager::display', [$this, 'injectProductionAction']);
            // Declare our submission properties on the schema. EntityDAO drops
            // any settings row whose name the schema does not declare (verified
            // in stable-3_4_0 EntityDAO::fromRow, and observed live: a stored
            // colophonJobId loaded back as null until this hook existed).
            Hook::add('Schema::get::submission', [$this, 'addToSubmissionSchema']);
        }
        return $success;
    }

    public function getDisplayName(): string
    {
        return __('plugins.generic.colophon.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.generic.colophon.description');
    }

    /**
     * Declare the plugin's submission properties, so setData/getData round-trip.
     */
    public function addToSubmissionSchema(string $hookName, array $args): bool
    {
        $schema = &$args[0];
        $schema->properties->colophonArticleCode = (object) [
            'type' => 'string', 'validation' => ['nullable'],
        ];
        $schema->properties->colophonJobId = (object) [
            'type' => 'integer', 'validation' => ['nullable'],
        ];
        $schema->properties->colophonStatusUrl = (object) [
            'type' => 'string', 'validation' => ['nullable'],
        ];
        $schema->properties->colophonGalleyId = (object) [
            'type' => 'integer', 'validation' => ['nullable'],
        ];
        $schema->properties->colophonLastResult = (object) [
            'type' => 'string', 'validation' => ['nullable'],
        ];
        // Undeclared properties are STRIPPED by EntityDAO's schema sanitize on
        // every save — setData succeeds in memory and the write silently never
        // lands. colophonLastDelivery had been undeclared since the beginning,
        // which means the webhook delivery dedupe never actually persisted;
        // found while tracing why replace-on-redelivery read galleyId=0.
        $schema->properties->colophonLastDelivery = (object) [
            'type' => 'string', 'validation' => ['nullable'],
        ];
        $schema->properties->colophonAppliedJobId = (object) [
            'type' => 'integer', 'validation' => ['nullable'],
        ];
        $schema->properties->colophonProductionFileIds = (object) [
            'type' => 'string', 'validation' => ['nullable'],
        ];
        return Hook::CONTINUE;
    }

    /**
     * Route /colophon/* to our handler. The callback endpoint lives here; it
     * authenticates by HMAC signature, not by OJS session, and says so.
     */
    public function setupHandler(string $hookName, array $args): bool
    {
        $page = $args[0];
        if ($page !== 'colophon') {
            return false;
        }
        // $args[3] is the router's &$handler; assigning the instance is the
        // 3.4 contract (define('HANDLER_CLASS') still works but is deprecated,
        // pkp-lib#6019 — verified against PKPPageRouter in stable-3_4_0).
        require_once($this->getPluginPath() . '/classes/ColophonHandler.php');
        $args[3] = new ColophonHandler();
        return true;
    }

    /**
     * Add "Generate JATS with Colophon" to the production stage. Rendered as
     * a LinkAction so it sits beside the native galley controls rather than
     * replacing or reordering them.
     */
    public function injectProductionAction(string $hookName, array $args): bool
    {
        $templateMgr = $args[0];
        $template = $args[1];
        if ($template !== 'workflow/workflow.tpl') {
            return false;
        }
        $request = Application::get()->getRequest();
        // PKPWorkflowHandler assigns 'submission' before displaying
        // workflow/workflow.tpl (verified in stable-3_4_0).
        $submission = $templateMgr->getTemplateVars('submission');
        if (!$submission) {
            return false;
        }
        $router = $request->getRouter();
        // The 3.4 workflow page is a Vue app: a template var is rendered by
        // nothing a plugin controls. colophon.js inserts the button into the
        // workflow header instead, from the bootstrap payload below.
        $payload = json_encode([
            'submissionId' => $submission->getId(),
            'hasArticleCode' => (string) $submission->getData('colophonArticleCode') !== '',
            'sendUrl' => $router->url($request, null, 'colophon', 'send', null,
                ['submissionId' => $submission->getId()]),
            'startUrl' => $router->url($request, null, 'colophon', 'start', null,
                ['submissionId' => $submission->getId()]),
            'statusUrl' => $router->url($request, null, 'colophon', 'status', null,
                ['submissionId' => $submission->getId()]),
            // 3.4's PKP session exposes getCSRFToken(); 3.5's is a Laravel
            // Illuminate\Session\Store, which has token() and throws
            // BadMethodCallException for the old name. The settings form was
            // fixed for this; the workflow injection was not, so opening a
            // submission's workflow page on 3.5 fataled before any button
            // could appear — the one page the editor needs.
            'csrfToken' => method_exists($request->getSession(), 'token')
                ? $request->getSession()->token()
                : $request->getSession()->getCSRFToken(),
            'labels' => [
                'send' => __('plugins.generic.colophon.action.send'),
                'generate' => __('plugins.generic.colophon.action.generate'),
                'checkStatus' => __('plugins.generic.colophon.action.checkStatus'),
            ],
        ]);
        $templateMgr->addJavaScript(
            'colophonData',
            'window.colophonData = ' . $payload . ';',
            ['contexts' => 'backend', 'inline' => true]
        );
        $templateMgr->addJavaScript(
            'colophon',
            $request->getBaseUrl() . '/' . $this->getPluginPath() . '/js/colophon.js',
            ['contexts' => 'backend']
        );
        return false;
    }

    // ----- Settings -------------------------------------------------------

    /** @copydoc Plugin::getActions() */
    public function getActions($request, $actionArgs): array
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }
        $router = $request->getRouter();
        array_unshift($actions, new LinkAction(
            'settings',
            new AjaxModal(
                $router->url($request, null, null, 'manage', null,
                    ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        ));
        return $actions;
    }

    /** @copydoc Plugin::manage() */
    public function manage($args, $request): JSONMessage
    {
        if ($request->getUserVar('verb') !== 'settings') {
            return parent::manage($args, $request);
        }
        require_once($this->getPluginPath() . '/classes/ColophonSettingsForm.php');
        $form = new ColophonSettingsForm($this, $request->getContext()->getId());
        if ($request->getUserVar('save')) {
            $form->readInputData();
            if ($form->validate()) {
                $form->execute();
                return new JSONMessage(true);
            }
        } else {
            $form->initData();
        }
        return new JSONMessage(true, $form->fetch($request));
    }

    // ----- Settings accessors (the only place the secrets are read) -------

    public function getApiBase(int $contextId): string
    {
        // Deliberately no default host. A baked-in domain would send pairing
        // requests — and, after pairing, API keys — to whoever holds that
        // domain on the day the journal clicks Connect. The operator types
        // their Colophon server's address once; everything refuses politely
        // until then.
        return rtrim((string) $this->getSetting($contextId, self::SETTING_API_BASE), '/');
    }

    public function getApiKey(int $contextId): string
    {
        return (string) $this->getSetting($contextId, self::SETTING_API_KEY);
    }

    public function getWebhookSecret(int $contextId): string
    {
        return (string) $this->getSetting($contextId, self::SETTING_WEBHOOK_SECRET);
    }
}
