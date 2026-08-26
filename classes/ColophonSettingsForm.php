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
        $router = $request->getRouter();
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            // The device-flow pairing: one button, no key-copying. These are
            // page-router ops on the colophon handler, journal context.
            'colophonConnectStartUrl' => $router->url($request, null, 'colophon', 'connectStart'),
            'colophonConnectPollUrl' => $router->url($request, null, 'colophon', 'connectPoll'),
            'colophonCsrfToken' => $request->getSession()->getCSRFToken(),
            'colophonJournalName' => (string) $this->plugin->getSetting(
                $this->contextId, ColophonPlugin::SETTING_JOURNAL_NAME,
            ),
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
        // Never round-trip the secrets into the form; show a masked marker so
        // the operator can see something is configured without re-exposing it.
        $this->setData('apiKeySet', $this->plugin->getApiKey($this->contextId) !== '');
        $this->setData('webhookSecretSet', $this->plugin->getWebhookSecret($this->contextId) !== '');
    }

    public function readInputData(): void
    {
        $this->readUserVars(['apiBase', 'apiKey', 'webhookSecret']);
    }

    public function execute(...$functionArgs)
    {
        $this->plugin->updateSetting($this->contextId, ColophonPlugin::SETTING_API_BASE, trim((string) $this->getData('apiBase')));
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
