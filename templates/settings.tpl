<script>
	$(function() {ldelim}
		var testEndpointAction = '{$testEndpointAction|escape:"javascript"}';
		var settingsFormAction = '{$settingsFormAction|escape:"javascript"}';
		var form = $('#ojsbrWebhookSettingsForm');

		form.pkpHandler('$.pkp.controllers.form.AjaxFormHandler');

		$('#ojsbrWebhookAddEndpoint').on('click', function(e) {ldelim}
			e.preventDefault();
			var tbody = $('#ojsbrWebhookEndpointsTable tbody');
			var index = Date.now();
			var row = [
				'<tr>',
					'<td><input type="text" name="endpointUrl[' + index + ']" value="" class="textField" style="width: 100%;"></td>',
					'<td><input type="text" name="endpointSecret[' + index + ']" value="" class="textField" style="width: 100%;"></td>',
					'<td style="text-align: center;"><input type="checkbox" name="endpointSubmission[' + index + ']" value="1" checked></td>',
					'<td style="text-align: center;"><input type="checkbox" name="endpointPublication[' + index + ']" value="1" checked></td>',
					'<td style="text-align: center;"><button type="button" class="pkpButton ojsbrWebhookTestEndpoint">{translate|escape:"javascript" key="plugins.generic.ojsbrWebhook.settings.testEndpoint"}</button><div class="ojsbrWebhookTestResult"></div></td>',
					'<td style="text-align: center;"><button type="button" class="pkpButton ojsbrWebhookRemoveEndpoint">{translate|escape:"javascript" key="common.delete"}</button></td>',
				'</tr>'
			].join('');
			tbody.append(row);
		{rdelim});

		$('#ojsbrWebhookEndpointsTable').on('click', '.ojsbrWebhookRemoveEndpoint', function(e) {ldelim}
			e.preventDefault();
			$(this).closest('tr').remove();
		{rdelim});

		$('#ojsbrWebhookEndpointsTable').on('click', '.ojsbrWebhookTestEndpoint', function(e) {ldelim}
			e.preventDefault();
			var button = $(this);
			var row = button.closest('tr');
			var result = row.find('.ojsbrWebhookTestResult');
			var urlInput = row.find('input[name^="endpointUrl"]');
			var secretInput = row.find('input[name^="endpointSecret"]');
			var match = urlInput.attr('name').match(/\[(.+)\]/);
			var index = match ? match[1] : '';
			var previousAction = form.attr('action');
			var hidden = $('<input type="hidden" name="testEndpointIndex">').val(index);
			var testFlag = $('<input type="hidden" name="testEndpoint">').val('1');
			var directUrl = $('<input type="hidden" name="url">').val(urlInput.val());
			var directSecret = $('<input type="hidden" name="secret">').val(secretInput.val());
			var directEvent = $('<input type="hidden" name="event">').val(row.find('input[name^="endpointSubmission"]').is(':checked') ? '{$eventSubmissionCreated|escape:"javascript"}' : '{$eventPublicationCreated|escape:"javascript"}');

			button.prop('disabled', true);
			result.text('{translate|escape:"javascript" key="plugins.generic.ojsbrWebhook.settings.testing"}');
			form.append(hidden);
			form.append(testFlag);
			form.append(directUrl);
			form.append(directSecret);
			form.append(directEvent);
			form.attr('action', testEndpointAction);

			$.ajax({ldelim}
				url: testEndpointAction,
				method: 'POST',
				data: form.serialize(),
				dataType: 'json'
			{rdelim})
				.done(function(response) {ldelim}
					var ok = response && response.status === true;
					var message = response && response.content ? response.content : (ok ? '{translate|escape:"javascript" key="plugins.generic.ojsbrWebhook.settings.testSent"}' : '{translate|escape:"javascript" key="plugins.generic.ojsbrWebhook.settings.testFailed"}');
					result.text(message).css('color', ok ? 'green' : 'red');
				{rdelim})
				.fail(function(xhr) {ldelim}
					var details = xhr.responseText ? ': ' + xhr.responseText.substring(0, 180) : '';
					result.text('{translate|escape:"javascript" key="plugins.generic.ojsbrWebhook.settings.testFailed"} ' + xhr.status + details).css('color', 'red');
				{rdelim})
				.always(function() {ldelim}
					hidden.remove();
					testFlag.remove();
					directUrl.remove();
					directSecret.remove();
					directEvent.remove();
					form.attr('action', previousAction || settingsFormAction);
					button.prop('disabled', false);
				{rdelim});
		{rdelim});
	{rdelim});
</script>

<form
	class="pkp_form"
	id="ojsbrWebhookSettingsForm"
	method="post"
	action="{$settingsFormAction|escape}"
>
	{csrf}

	{fbvFormArea id="ojsbrWebhookSettings"}
		<p>{translate key="plugins.generic.ojsbrWebhook.settings.endpoints.description"}</p>

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
				{foreach from=$endpoints item=endpoint key=index}
					<tr>
						<td>
							<input type="text" name="endpointUrl[{$index|escape}]" value="{$endpoint.url|escape}" class="textField" style="width: 100%;">
						</td>
						<td>
							<input type="text" name="endpointSecret[{$index|escape}]" value="{$endpoint.secret|escape}" class="textField" style="width: 100%;">
						</td>
						<td style="text-align: center;">
							<input type="checkbox" name="endpointSubmission[{$index|escape}]" value="1"{if in_array($eventSubmissionCreated, $endpoint.events)} checked{/if}>
						</td>
						<td style="text-align: center;">
							<input type="checkbox" name="endpointPublication[{$index|escape}]" value="1"{if in_array($eventPublicationCreated, $endpoint.events)} checked{/if}>
						</td>
						<td style="text-align: center;">
							<button type="button" class="pkpButton ojsbrWebhookTestEndpoint">{translate key="plugins.generic.ojsbrWebhook.settings.testEndpoint"}</button>
							<div class="ojsbrWebhookTestResult"></div>
						</td>
						<td style="text-align: center;">
							<button type="button" class="pkpButton ojsbrWebhookRemoveEndpoint">{translate key="common.delete"}</button>
						</td>
					</tr>
				{/foreach}
			</tbody>
		</table>

		<p>
			<button type="button" class="pkpButton" id="ojsbrWebhookAddEndpoint">
				{translate key="plugins.generic.ojsbrWebhook.settings.addEndpoint"}
			</button>
		</p>

		<h3>{translate key="plugins.generic.ojsbrWebhook.settings.payloadExample"}</h3>
		<pre style="white-space: pre-wrap; word-break: break-word; background: #f7f7f7; border: 1px solid #ddd; padding: 1rem;">{
  "event": "submission.created",
  "occurredAt": "2026-05-23T19:20:00+00:00",
  "contextId": 1,
  "baseUrl": "https://revistaft.com",
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

	{fbvFormButtons submitText="common.save"}
</form>
