<?php
/**
 * @file classes/ColophonSettingsForm.php
 *
 * Per-journal settings: API base (for staging), the API key, and the webhook
 * signing secret — both minted on Colophon's API-keys page and shown once
 * there. Stored via the plugin-settings store; never echoed back in full
 * (the form redisplays only a masked tail), never logged.
 */

namespace APP\plugins\generic\colophon;

use PKP\form\Form;
use PKP\form\validation\FormValidator;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;
use PKP\form\validation\FormValidatorUrl;

class ColophonSettingsForm extends Form
{
    /** @copydoc Form::fetch() — the template's action URL needs the plugin name. */
    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = \APP\template\TemplateManager::getManager($request);
        $dispatcher = $request->getDispatcher();
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            // The device-flow pairing: one button, no key-copying. These are
            // page-router ops on the colophon handler — built through the
            // dispatcher with ROUTE_PAGE, because this form renders inside a
            // *component* request (the plugins grid's settings modal) and the
            // current request's router would read 'colophon' as a component
            // name and die on its Handler-suffix assert. Found the first time
            // a person actually opened the modal (2026-08-26).
            'colophonConnectStartUrl' => $dispatcher->url(
                $request, \PKP\core\PKPApplication::ROUTE_PAGE, null, 'colophon', 'connectStart',
            ),
            'colophonConnectPollUrl' => $dispatcher->url(
                $request, \PKP\core\PKPApplication::ROUTE_PAGE, null, 'colophon', 'connectPoll',
            ),
            'colophonPanelOpUrl' => $dispatcher->url(
                $request, \PKP\core\PKPApplication::ROUTE_PAGE, null, 'colophon', 'panel',
            ),
            'colophonCreditsOpUrl' => $dispatcher->url(
                $request, \PKP\core\PKPApplication::ROUTE_PAGE, null, 'colophon', 'credits',
            ),
            // A plain page link, not a fetch()-then-open op like the ones
            // above: this is a real page the browser navigates to, not a
            // JSON action.
            'colophonManageUrl' => $dispatcher->url(
                $request, \PKP\core\PKPApplication::ROUTE_PAGE, null, 'colophon', 'submissions',
            ),
            // 3.4's PKP session exposes getCSRFToken(); 3.5's Laravel
            // session store exposes token(). Same value, different name.
            'colophonCsrfToken' => method_exists($request->getSession(), 'token')
                ? $request->getSession()->token()
                : $request->getSession()->getCSRFToken(),
            'colophonJournalName' => (string) $this->plugin->getSetting(
                $this->contextId, ColophonPlugin::SETTING_JOURNAL_NAME,
            ),
            'colophonDeliveryOptions' => [
                ColophonPlugin::DELIVERY_DOWNLOAD => __('plugins.generic.colophon.settings.delivery.download'),
                ColophonPlugin::DELIVERY_GALLEY => __('plugins.generic.colophon.settings.delivery.galley'),
            ],
        ]);
        return parent::fetch($request, $template, $display);
    }

    private ColophonPlugin $plugin;
    private int $contextId;

    public function __construct(ColophonPlugin $plugin, int $contextId)
    {
        parent::__construct($plugin->getTemplateResource('settings.tpl'));
        $this->plugin = $plugin;
        $this->contextId = $contextId;
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
        $this->addCheck(new FormValidatorUrl($this, 'apiBase', 'optional', 'plugins.generic.colophon.settings.apiBaseInvalid'));
    }

    public function initData(): void
    {
        $this->setData('apiBase', $this->plugin->getApiBase($this->contextId));
        $this->setData('delivery', $this->plugin->getDelivery($this->contextId));
        // Never round-trip the secrets into the form; show a masked marker so
        // the operator can see something is configured without re-exposing it.
        $this->setData('apiKeySet', $this->plugin->getApiKey($this->contextId) !== '');
        $this->setData('webhookSecretSet', $this->plugin->getWebhookSecret($this->contextId) !== '');
    }

    public function readInputData(): void
    {
        $this->readUserVars(['apiBase', 'apiKey', 'webhookSecret', 'delivery']);
    }

    public function execute(...$functionArgs)
    {
        $this->plugin->updateSetting($this->contextId, ColophonPlugin::SETTING_API_BASE, trim((string) $this->getData('apiBase')));
        // Anything but the explicit galley choice is the default: a form
        // value nobody typed cannot switch the journal into writing galleys.
        $delivery = (string) $this->getData('delivery') === ColophonPlugin::DELIVERY_GALLEY
            ? ColophonPlugin::DELIVERY_GALLEY
            : ColophonPlugin::DELIVERY_DOWNLOAD;
        $this->plugin->updateSetting($this->contextId, ColophonPlugin::SETTING_DELIVERY, $delivery);
        // Blank means "keep the stored one": the form never shows the secret, so
        // an untouched field must not erase it.
        foreach ([ColophonPlugin::SETTING_API_KEY => 'apiKey',
                  ColophonPlugin::SETTING_WEBHOOK_SECRET => 'webhookSecret'] as $setting => $field) {
            $value = trim((string) $this->getData($field));
            if ($value !== '') {
                $this->plugin->updateSetting($this->contextId, $setting, $value);
            }
        }
        return parent::execute(...$functionArgs);
    }
}
