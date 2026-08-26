{**
 * Colophon settings: one-click pairing first; manual key entry stays as the
 * fallback for odd networks. Secrets write-only.
 *}
<script>
	$(function() {ldelim}
		$('#colophonSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>
<div id="colophonConnect" style="margin-bottom:1rem">
	{if $colophonJournalName}
		<p id="colophonConnectState">✓ {translate key="plugins.generic.colophon.settings.connectedAs" name=$colophonJournalName}</p>
	{else}
		<p id="colophonConnectState">{translate key="plugins.generic.colophon.settings.notConnected"}</p>
	{/if}
	<button type="button" class="pkp_button" id="colophonConnectBtn">{translate key="plugins.generic.colophon.settings.connect"}</button>
</div>
<script>
(function () {ldelim}
	var btn = document.getElementById('colophonConnectBtn');
	if (!btn) return;
	var stateEl = document.getElementById('colophonConnectState');
	var polling = null;
	function post(url) {ldelim}
		var body = new URLSearchParams();
		body.set('csrfToken', {$colophonCsrfToken|json_encode});
		return fetch(url, {ldelim} method: 'POST', credentials: 'same-origin',
			headers: {ldelim} 'Content-Type': 'application/x-www-form-urlencoded' {rdelim},
			body: body.toString() {rdelim}).then(function (r) {ldelim} return r.json(); {rdelim});
	{rdelim}
	btn.addEventListener('click', function () {ldelim}
		btn.disabled = true;
		post({$colophonConnectStartUrl|json_encode}).then(function (resp) {ldelim}
			var c = (resp && resp.content) || {ldelim}{rdelim};
			if (!c.confirmUrl) {ldelim} stateEl.textContent = (resp && resp.content) || 'error'; btn.disabled = false; return; {rdelim}
			window.open(c.confirmUrl, '_blank');
			stateEl.textContent = '⏳';
			if (polling) clearInterval(polling);
			polling = setInterval(function () {ldelim}
				post({$colophonConnectPollUrl|json_encode}).then(function (poll) {ldelim}
					var p = (poll && poll.content) || {ldelim}{rdelim};
					if (p.state === 'connected') {ldelim}
						clearInterval(polling);
						stateEl.textContent = '✓ ' + (p.journalName || '') + ' · ' + (p.credits || 0);
						btn.disabled = false;
					{rdelim} else if (p.state === 'expired' || p.state === 'denied') {ldelim}
						clearInterval(polling);
						stateEl.textContent = p.state;
						btn.disabled = false;
					{rdelim}
				{rdelim});
			{rdelim}, 3000);
		{rdelim}).catch(function () {ldelim} stateEl.textContent = 'error'; btn.disabled = false; {rdelim});
	{rdelim});
{rdelim})();
</script>
<form class="pkp_form" id="colophonSettingsForm" method="post"
      action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	{fbvFormArea id="colophonSettingsFormArea"}
		{fbvFormSection}
			{fbvElement type="text" id="apiBase" value=$apiBase label="plugins.generic.colophon.settings.apiBase"}
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="text" id="apiKey" password=true label="plugins.generic.colophon.settings.apiKey"}
			{if $apiKeySet}<p>{translate key="plugins.generic.colophon.settings.secretStored"}</p>{/if}
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="text" id="webhookSecret" password=true label="plugins.generic.colophon.settings.webhookSecret"}
			{if $webhookSecretSet}<p>{translate key="plugins.generic.colophon.settings.secretStored"}</p>{/if}
		{/fbvFormSection}
	{/fbvFormArea}
	{fbvFormButtons submitText="common.save"}
</form>
