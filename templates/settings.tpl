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
	{if $apiKeySet}
	<button type="button" class="pkp_button" id="colophonPanelBtn">{translate key="plugins.generic.colophon.settings.openPanel"}</button>
	<span id="colophonCredits" style="margin-inline-start:0.75rem"></span>
	<button type="button" class="pkp_button" id="colophonTopUpBtn" hidden>{translate key="plugins.generic.colophon.settings.topUp"}</button>
	{/if}
	<a href="{$colophonManageUrl|escape}" class="pkp_button" style="margin-inline-start:0.75rem">
		{translate key="plugins.generic.colophon.settings.manageLink"}
	</a>
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
(function () {ldelim}
	// The door back into the panel: Colophon mints a short-lived signed
	// link over the authenticated API and the browser lands signed in as
	// the journal owner — no password was ever created to type.
	var btn = document.getElementById('colophonPanelBtn');
	if (!btn) return;
	btn.addEventListener('click', function () {ldelim}
		btn.disabled = true;
		var body = new URLSearchParams();
		body.set('csrfToken', {$colophonCsrfToken|json_encode});
		fetch({$colophonPanelOpUrl|json_encode}, {ldelim} method: 'POST', credentials: 'same-origin',
			headers: {ldelim} 'Content-Type': 'application/x-www-form-urlencoded' {rdelim},
			body: body.toString() {rdelim})
		.then(function (r) {ldelim} return r.json(); {rdelim})
		.then(function (resp) {ldelim}
			btn.disabled = false;
			var c = (resp && resp.content) || {ldelim}{rdelim};
			if (c.url) {ldelim} window.open(c.url, '_blank'); {rdelim}
			else if (typeof resp.content === 'string') {ldelim} alert(resp.content); {rdelim}
		{rdelim})
		.catch(function () {ldelim} btn.disabled = false; {rdelim});
	{rdelim});
{rdelim})();
{capture assign=colophonCreditsLabel}{translate key="plugins.generic.colophon.settings.creditsRemaining" n="COUNT"}{/capture}
(function () {ldelim}
	// The balance, in the block itself: how much is left, and one button to
	// top up — it rides the same signed hand-off, landing on /credits/buy/.
	var el = document.getElementById('colophonCredits');
	var topUp = document.getElementById('colophonTopUpBtn');
	if (!el || !topUp) return;
	function post(url, extra) {ldelim}
		var body = new URLSearchParams();
		body.set('csrfToken', {$colophonCsrfToken|json_encode});
		if (extra) Object.keys(extra).forEach(function (k) {ldelim} body.set(k, extra[k]); {rdelim});
		return fetch(url, {ldelim} method: 'POST', credentials: 'same-origin',
			headers: {ldelim} 'Content-Type': 'application/x-www-form-urlencoded' {rdelim},
			body: body.toString() {rdelim}).then(function (r) {ldelim} return r.json(); {rdelim});
	{rdelim}
	post({$colophonCreditsOpUrl|json_encode}).then(function (resp) {ldelim}
		var c = (resp && resp.content) || {ldelim}{rdelim};
		if (typeof c.available === 'number') {ldelim}
			el.textContent = {$colophonCreditsLabel|json_encode}.replace('COUNT', c.available);
			topUp.hidden = false;
		{rdelim}
	{rdelim}).catch(function () {ldelim}{rdelim});
	topUp.addEventListener('click', function () {ldelim}
		topUp.disabled = true;
		post({$colophonPanelOpUrl|json_encode}, {ldelim} next: '/credits/buy/' {rdelim}).then(function (resp) {ldelim}
			topUp.disabled = false;
			var c = (resp && resp.content) || {ldelim}{rdelim};
			if (c.url) window.open(c.url, '_blank');
		{rdelim}).catch(function () {ldelim} topUp.disabled = false; {rdelim});
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
		{fbvFormSection label="plugins.generic.colophon.settings.delivery" description="plugins.generic.colophon.settings.deliveryDescription"}
			{fbvElement type="select" id="delivery" from=$colophonDeliveryOptions selected=$delivery translate=false}
		{/fbvFormSection}
	{/fbvFormArea}
	{fbvFormButtons submitText="common.save"}
</form>
