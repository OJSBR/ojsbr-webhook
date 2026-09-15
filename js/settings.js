/**
 * @file plugins/generic/ojsbrWebhook/js/settings.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Rows of the webhook settings form: add, remove and test an endpoint. The form is
 * loaded in a modal each time it is opened, so the handlers are delegated and bound
 * once per page.
 */
(function($) {
	'use strict';

	if (!$ || window.ojsbrWebhookSettingsBound) {
		return;
	}
	window.ojsbrWebhookSettingsBound = true;

	var formSelector = '#ojsbrWebhookSettingsForm';

	$(document).on('click', formSelector + ' #ojsbrWebhookAddEndpoint', function(e) {
		e.preventDefault();
		var tbody = $(this).closest('form').find('#ojsbrWebhookEndpointsTable tbody');
		var template = tbody.find('tr.ojsbrWebhookEndpoint').last();
		var row = template.clone();
		var index = 'new' + Date.now();
		row.find('input').each(function() {
			var input = $(this);
			input.attr('name', input.attr('name').replace(/\[[^\]]*\]$/, '[' + index + ']'));
			if (input.is(':checkbox')) {
				input.prop('checked', true);
			} else {
				input.val('');
			}
		});
		row.find('.ojsbrWebhookTestResult').text('');
		tbody.append(row);
	});

	$(document).on('click', formSelector + ' .ojsbrWebhookRemoveEndpoint', function(e) {
		e.preventDefault();
		var rows = $(this).closest('tbody').find('tr.ojsbrWebhookEndpoint');
		var row = $(this).closest('tr');
		if (rows.length > 1) {
			row.remove();
		} else {
			// The last row is emptied, so there is always one to fill in.
			row.find('input:text').val('');
		}
	});

	$(document).on('click', formSelector + ' .ojsbrWebhookTestEndpoint', function(e) {
		e.preventDefault();
		var button = $(this);
		var form = button.closest('form');
		var row = button.closest('tr');
		var result = row.find('.ojsbrWebhookTestResult');

		button.prop('disabled', true);
		result.text(form.data('testing')).removeClass('ojsbrWebhookTestOk ojsbrWebhookTestError');

		$.ajax({
			url: form.data('testUrl'),
			method: 'POST',
			dataType: 'json',
			data: {
				csrfToken: form.find('input[name="csrfToken"]').val(),
				url: row.find('input[name^="endpointUrl"]').val(),
				secret: row.find('input[name^="endpointSecret"]').val(),
				event: row.find('input[name^="endpointSubmission"]').is(':checked') ? form.data('eventSubmission') : form.data('eventPublication')
			}
		})
			.done(function(response) {
				var ok = response && response.status === true;
				result.text(response && response.content ? response.content : form.data('testFailed'))
					.addClass(ok ? 'ojsbrWebhookTestOk' : 'ojsbrWebhookTestError');
			})
			.fail(function(xhr) {
				result.text(form.data('testFailed') + ' ' + xhr.status).addClass('ojsbrWebhookTestError');
			})
			.always(function() {
				button.prop('disabled', false);
			});
	});
})(window.jQuery);
