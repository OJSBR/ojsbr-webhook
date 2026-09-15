{**
 * plugins/generic/ojsbrWebhook/templates/settings.tpl
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Webhook endpoints of the journal: URL, secret, events, test and removal. Adding,
 * removing and testing rows is done by js/settings.js.
 *}
<script>
	$(function() {ldelim}
		$('#ojsbrWebhookSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>
<script src="{$settingsScriptUrl|escape}"></script>

<form
	class="pkp_form"
	id="ojsbrWebhookSettingsForm"
	method="post"
	action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}"
	data-test-url="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="test"}"
	data-testing="{"plugins.generic.ojsbrWebhook.settings.testing"|translate|escape}"
	data-test-failed="{"plugins.generic.ojsbrWebhook.settings.testFailed"|translate|escape}"
	data-event-submission="{$eventSubmissionCreated|escape}"
	data-event-publication="{$eventPublicationCreated|escape}"
>
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="ojsbrWebhookSettingsFormNotification"}
	{include file="common/formErrors.tpl"}

	{fbvFormArea id="ojsbrWebhookSettings"}
		<p>{translate key="plugins.generic.ojsbrWebhook.settings.endpoints.description"}</p>
		<ul>
			<li>{translate key="plugins.generic.ojsbrWebhook.settings.webhookUrl.description"}</li>
			<li>{translate key="plugins.generic.ojsbrWebhook.settings.webhookSecret.description"}</li>
		</ul>

		<table class="pkpTable" id="ojsbrWebhookEndpointsTable">
			<thead>
				<tr>
					<th>{translate key="plugins.generic.ojsbrWebhook.settings.webhookUrl"}</th>
					<th>{translate key="plugins.generic.ojsbrWebhook.settings.webhookSecret"}</th>
					<th>{translate key="plugins.generic.ojsbrWebhook.event.submissionCreated"}</th>
					<th>{translate key="plugins.generic.ojsbrWebhook.event.publicationCreated"}</th>
					<th>{translate key="plugins.generic.ojsbrWebhook.settings.testEndpoint"}</th>
					<th>{translate key="common.delete"}</th>
				</tr>
			</thead>
			<tbody>
				{foreach from=$endpointRows item=endpoint key=row}
					<tr class="ojsbrWebhookEndpoint">
						<td><input type="text" name="endpointUrl[{$row|escape}]" value="{$endpoint.url|escape}" class="textField" aria-label="{"plugins.generic.ojsbrWebhook.settings.webhookUrl"|translate|escape}"></td>
						<td><input type="text" name="endpointSecret[{$row|escape}]" value="{$endpoint.secret|escape}" class="textField" autocomplete="off" aria-label="{"plugins.generic.ojsbrWebhook.settings.webhookSecret"|translate|escape}"></td>
						<td><input type="checkbox" name="endpointSubmission[{$row|escape}]" value="1"{if in_array($eventSubmissionCreated, $endpoint.events)} checked{/if} aria-label="{"plugins.generic.ojsbrWebhook.event.submissionCreated"|translate|escape}"></td>
						<td><input type="checkbox" name="endpointPublication[{$row|escape}]" value="1"{if in_array($eventPublicationCreated, $endpoint.events)} checked{/if} aria-label="{"plugins.generic.ojsbrWebhook.event.publicationCreated"|translate|escape}"></td>
						<td>
							<button type="button" class="pkpButton ojsbrWebhookTestEndpoint">{translate key="plugins.generic.ojsbrWebhook.settings.testEndpoint"}</button>
							<div class="ojsbrWebhookTestResult" role="status"></div>
						</td>
						<td><button type="button" class="pkpButton ojsbrWebhookRemoveEndpoint">{translate key="common.delete"}</button></td>
					</tr>
				{/foreach}
			</tbody>
		</table>

		<p>
			<button type="button" class="pkpButton" id="ojsbrWebhookAddEndpoint">{translate key="plugins.generic.ojsbrWebhook.settings.addEndpoint"}</button>
		</p>

		<h3>{translate key="plugins.generic.ojsbrWebhook.settings.payloadExample"}</h3>
		<pre class="ojsbrWebhookPayload">{
  "event": "submission.created",
  "occurredAt": "2026-05-23T19:20:00+00:00",
  "contextId": 1,
  "baseUrl": "https://journal.example.org",
  "object": {
    "id": 123,
    "class": "APP\\submission\\Submission",
    "submissionId": null,
    "contextId": 1,
    "data": {
      "contextId": 1,
      "status": 1,
      "locale": "pt_BR"
    }
  }
}</pre>
	{/fbvFormArea}

	{fbvFormButtons submitText="common.save" hideCancel=true}
</form>
