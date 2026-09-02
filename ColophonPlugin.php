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
 * opens the plugin's own submissions page (Website → Plugins → Colophon →
 * "Manage submissions"), picks an eligible article, and presses "Generate
 * JATS with Colophon"; the plugin posts the manuscript to the Colophon API
 * and gets a 202 with a job id; when the job finishes, Colophon POSTs a
 * signed notification to this plugin's callback handler, which verifies the
 * signature, fetches the result over the API, and creates the galley. A
 * "Check status" button polls the same job as a fallback for journal servers
 * whose firewalls block inbound webhooks. An agent drives it; a person
 * decides.
 *
 * The submissions page (ColophonHandler::submissions) replaced an earlier
 * design that injected this same button directly into each submission's own
 * workflow page via TemplateManager::display. That approach had no way to
 * know a submission's stage without hooking a template specific to one OJS
 * version — 3.4's workflow/workflow.tpl does not exist on 3.5, which
 * rebuilt the page as a client-side dashboard SPA — and even once patched
 * to fire on both, it showed the button on every stage of every submission,
 * including ones still awaiting review, because there was nowhere clean to
 * gate on stage for the SPA case. A plugin-owned list page sidesteps all of
 * that: one Smarty template extending the same layouts/backend.tpl every
 * OJS version already renders admin pages with, and one server-side query
 * (stageId >= WORKFLOW_STAGE_ID_EDITING) instead of a client-side per-row
 * check. Owner decision, 2026-08-27.
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
use PKP\security\Role;
use APP\template\TemplateManager;

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
    //: How a finished package reaches the editor. 'download' (the default)
    //: writes nothing into OJS: the row on the plugin's page offers the ZIP
    //: and the PDF to download, and "Attach as galley" is an explicit button.
    //: 'galley' is the earlier behaviour — every finished job replaces the
    //: plugin's own galley and production-ready files automatically.
    public const SETTING_DELIVERY = 'colophonDelivery';
    public const DELIVERY_DOWNLOAD = 'download';
    public const DELIVERY_GALLEY = 'galley';

    /** @copydoc Plugin::register() */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            // Route our handlers: /index.php/{journal}/colophon/{op}
            Hook::add('LoadHandler', [$this, 'setupHandler']);
            // Declare our submission properties on the schema. EntityDAO drops
            // any settings row whose name the schema does not declare (verified
            // in stable-3_4_0 EntityDAO::fromRow, and observed live: a stored
            // colophonJobId loaded back as null until this hook existed).
            Hook::add('Schema::get::submission', [$this, 'addToSubmissionSchema']);
            // A door in the editorial sidebar, so the page is somewhere a
            // person can find rather than behind the settings modal.
            Hook::add('TemplateManager::setupBackendPage', [$this, 'addMenuItem']);
        }
        return $success;
    }

    /**
     * Add "Colophon" to the editorial backend's left-hand menu.
     *
     * The hook fires *after* PKPTemplateManager::setupBackendPage has called
     * setState(['menu' => …]) and *before* the state is assigned to the Vue
     * app, which is the one moment a plugin can read the built menu and hand
     * back an extended copy (setState array_merges, so the whole 'menu' key is
     * replaced by ours). Verified against 3.5.0-5's PKPTemplateManager.
     *
     * This replaces hooking a template that each OJS version renames: the
     * plugin owns its page, and this only advertises it.
     */
    public function addMenuItem(string $hookName, array $args): bool
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) {
            return false;   // Site-level pages have no journal to work in.
        }
        // Only the people who may actually run a conversion see the door.
        $user = $request->getUser();
        if (!$user) {
            return false;
        }
        $roles = $user->getRoles($context->getId());
        $allowed = [
            Role::ROLE_ID_MANAGER,
            Role::ROLE_ID_SUB_EDITOR,
            Role::ROLE_ID_ASSISTANT,
        ];
        $permitted = false;
        foreach ($roles as $role) {
            if (in_array($role->getId(), $allowed, true)) {
                $permitted = true;
                break;
            }
        }
        if (!$permitted) {
            return false;
        }

        $templateMgr = TemplateManager::getManager($request);
        $menu = $templateMgr->getState('menu');
        if (!is_array($menu)) {
            return false;   // A page that builds no menu is not ours to alter.
        }
        $router = $request->getRouter();
        $menu['colophon'] = [
            'name' => __('plugins.generic.colophon.menu'),
            'url' => $request->getDispatcher()->url(
                $request, \PKP\core\PKPApplication::ROUTE_PAGE, null, 'colophon', 'submissions',
            ),
            'isCurrent' => $router->getRequestedPage($request) === 'colophon',
            // Icons come from OJS's own set; 'Tools' is the closest fit for a
            // page that does production work on submissions.
            'icon' => 'Tools',
        ];
        $templateMgr->setState(['menu' => $menu]);
        return false;
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
        // Whether the last finished job is waiting on a person, and the
        // Colophon path that clears it — what the row's "Needs your review"
        // badge and button are drawn from after a page reload.
        $schema->properties->colophonNeedsPerson = (object) [
            'type' => 'string', 'validation' => ['nullable'],
        ];
        $schema->properties->colophonReviewPath = (object) [
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

    /** 'download' unless the journal chose 'galley' — the least OJS touched by default. */
    public function getDelivery(int $contextId): string
    {
        $value = (string) $this->getSetting($contextId, self::SETTING_DELIVERY);
        return $value === self::DELIVERY_GALLEY ? self::DELIVERY_GALLEY : self::DELIVERY_DOWNLOAD;
    }
}
