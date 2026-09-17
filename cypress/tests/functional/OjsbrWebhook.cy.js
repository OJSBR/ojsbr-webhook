/**
 * @file cypress/tests/functional/OjsbrWebhook.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests: the webhook settings of a journal.
 *
 * Parameters (--env): contextPath, adminUser, adminPassword (captcha on login
 * must be off for the run). The defaults match the data set of PKP's continuous
 * integration. The first test enables the plugin when it is off; the endpoints
 * of the journal are put back as they were after the run. The endpoint used is a
 * closed local port, so nothing leaves the server.
 */

describe('OJSBR Webhook plugin', function() {
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';

	const row = 'ojsbrwebhookplugin';
	const form = '#ojsbrWebhookSettingsForm';
	const endpoint = 'http://127.0.0.1:9/ojsbr-webhook-cypress?token=secret';
	let originalEndpoints = null;

	// ---- OJSBR spec helpers (padrão v2): work on OJS/OMP 3.3, 3.4 and 3.5 and in PKP's CI ----

	const pageUrl = (path) => '/index.php/' + contextPath + (path ? '/' + path : '');

	// Same as PKP's cy.waitJQuery(), which the support files of OJS 3.3 test sites may lack.
	// The Plugins tab can keep requests open for a while (the plugin gallery), hence the timeout.
	const waitJQuery = () => cy.window().its('jQuery.active', {timeout: 60000}).should('eq', 0);

	// Requests carry the browser's User-Agent: OJS 3.3 drops a session whose agent changes.
	const request = (options) => cy.window({log: false}).then((win) => cy.request(Object.assign(
		typeof options === 'string' ? {url: options} : options,
		{headers: Object.assign({'User-Agent': win.navigator.userAgent}, (typeof options === 'string' ? {} : options.headers) || {})}
	)));

	// Signs in through requests (the login page can re-render while it is typed into), then
	// falls back to the form when the session did not stick (OJS 3.3 cookie handling).
	const login = (username, password) => {
		cy.clearCookies();
		request(pageUrl('login')).then((response) => {
			const token = /name="csrfToken" value="([^"]+)"/.exec(response.body)[1];
			// The form posts to the URL with the language: a redirect would turn the POST into a GET.
			const action = /<form[^>]*id="login"[^>]*action="([^"]+)"/.exec(response.body)[1];
			request({method: 'POST', url: action, form: true, body: {csrfToken: token, username: username, password: password}, log: false});
		});
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		cy.get('body').then(($body) => {
			if ($body.find('form#login').length) {
				cy.get('form#login input[name="username"]').type(username, {delay: 0});
				cy.get('form#login input[name="password"]').type(password, {delay: 0, log: false});
				cy.get('form#login').submit();
				cy.get('form#login', {timeout: 30000}).should('not.exist');
			}
		});
	};

	// REST API calls made from the page itself, so they carry the browser's own session.
	const api = (path, options = {}) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(path, Object.assign({credentials: 'same-origin'}, options)).then((response) => {
			if (!response.ok) {
				return response.text().then((text) => {
					throw new Error(path + ' answered ' + response.status + ': ' + text.slice(0, 300));
				});
			}
			return response.json();
		}),
		{log: false, timeout: 30000}
	));

	// The website settings page on its Plugins tab (a new query string forces a load). Load it
	// once per test: loading it again while its plugin gallery request is pending stalls the
	// web server of PKP's CI; API calls and settings modals work on the page already open.
	const openPluginsTab = () => {
		cy.visit(pageUrl('management/settings/website') + '?reload=' + Date.now() + '#plugins');
		cy.get('button[id="plugins-button"]', {timeout: 60000}).click();
		cy.get('button[id="plugins-button"]').should('have.attr', 'aria-selected', 'true');
		waitJQuery();
	};

	// Enables the plugin in the grid when it is off (never turns it off).
	const enablePlugin = (rowName) => {
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]', {timeout: 30000}).then(($checkbox) => {
			if (!$checkbox.is(':checked')) {
				cy.wrap($checkbox).click();
				waitJQuery();
			}
		});
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]').should('be.checked');
	};

	// Opens the settings modal from the grid, without reloading the page: a reload right
	// after saving can stall the web server of PKP's CI. The form is fetched each time.
	const openPluginSettings = (rowName, formSelector) => {
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]', {timeout: 30000}).then(($link) => {
			if (!$link.is(':visible')) {
				cy.get('tr[id$="-row-' + rowName + '"] a.show_extras').first().click();
			}
		});
		// The grid may still be animating the extras row: the link is clicked once it exists.
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]').first().click({force: true});
		waitJQuery();
		cy.window().should((win) => {
			expect(win.jQuery(formSelector).data('pkp.handler')).to.exist;
		});
	};

	// ---- end of helpers ----

	// The URL fields of the settings form, in order.
	const urlInputs = () => cy.get(form + ' input[name^="endpointUrl"]');

	// Saves the form as it is and waits for the modal to close.
	const saveForm = () => {
		cy.get(form + ' button[id^="submitFormButton"]').click();
		waitJQuery();
		cy.get(form).should('not.exist');
	};

	it('Enables the plugin', function() {
		login(adminUser, adminPassword);
		openPluginsTab();
		enablePlugin(row);
	});

	it('Saves an endpoint, tests it, and refuses a test without the form token', function() {
		login(adminUser, adminPassword);
		openPluginsTab();
		openPluginSettings(row, form);
		// Kept from the first attempt: a retried test finds the endpoint it saved before.
		urlInputs().then(($inputs) => {
			if (originalEndpoints === null) {
				originalEndpoints = $inputs.toArray().map((input) => input.value).filter((value) => value !== '');
			}
		});

		// A URL that is not http(s) is refused by the form.
		urlInputs().last().invoke('val', 'file:///etc/passwd');
		cy.get(form + ' button[id^="submitFormButton"]').click();
		waitJQuery();
		cy.get(form + ' #formErrors').should('contain', 'http');

		// The form comes back with the refused row, which gets a valid URL. The test button of
		// the row answers with the HTTP status the endpoint gave (0: nothing listens).
		urlInputs().filter((index, input) => input.value.startsWith('file:')).should('have.length', 1).invoke('val', endpoint);
		urlInputs().filter((index, input) => input.value === endpoint).closest('tr').as('endpointRow');
		cy.get('@endpointRow').find('.ojsbrWebhookTestEndpoint').click();
		cy.get('@endpointRow').find('.ojsbrWebhookTestResult').should('have.class', 'ojsbrWebhookTestError')
			.invoke('text').should('match', /\b0\b/).and('not.contain', '##').and('not.contain', 'secret');

		// Saved, and shown again with a blank row after it.
		saveForm();
		openPluginSettings(row, form);
		urlInputs().then(($inputs) => {
			const values = $inputs.toArray().map((input) => input.value);
			expect(values).to.include(endpoint);
			expect(values[values.length - 1]).to.eq('');
		});

		// The test request sends data out: without the token of the form it is refused.
		cy.get(form).invoke('attr', 'data-test-url').then((testUrl) => {
			request({method: 'POST', url: testUrl, form: true, body: {url: endpoint, event: 'submission.created'}}).then((response) => {
				const answer = typeof response.body === 'string' ? JSON.parse(response.body) : response.body;
				expect(answer.status).to.eq(false);
				expect(answer.content).to.not.match(/\bHTTP\b/);
			});
		});
	});


	// A delivery that is really received. The receiver is a small server of the
	// plugin's own (tests/receiver.js), started by the script the continuous
	// integration calls; where it is not running, the test says so instead of
	// passing for nothing. What is checked is what arrived: the event, the body,
	// and the signature, recomputed here from the secret the journal saved.
	it('Delivers a signed payload that a receiver really gets', function() {
		const receiver = Cypress.env('receiverUrl') || 'http://127.0.0.1:3399';
		const secret = 'ojsbr-cypress-secret';

		cy.request({url: receiver + '/events', failOnStatusCode: false, timeout: 10000}).then((alive) => {
			expect(alive.status, 'the receiver of the tests has to be running at ' + receiver
				+ ' (node plugins/generic/ojsbrWebhook/tests/receiver.js)').to.eq(200);

			return cy.request({method: 'DELETE', url: receiver + '/events', failOnStatusCode: false});
		});

		login(adminUser, adminPassword);
		openPluginsTab();
		openPluginSettings(row, form);
		urlInputs().then(($inputs) => {
			if (originalEndpoints === null) {
				originalEndpoints = $inputs.toArray().map((input) => input.value).filter((value) => value !== '');
			}
		});

		// The endpoint of the receiver, with a secret of its own.
		urlInputs().last().invoke('val', receiver + '/hook');
		urlInputs().filter((index, input) => input.value === receiver + '/hook').closest('tr').as('receiverRow');
		cy.get('@receiverRow').find('input[name^="endpointSecret"]').invoke('val', secret);
		cy.get('@receiverRow').find('.ojsbrWebhookTestEndpoint').click();

		// The journal says the endpoint answered.
		cy.get('@receiverRow').find('.ojsbrWebhookTestResult', {timeout: 30000})
			.invoke('text').should('match', /\b200\b/);

		// And the receiver has it, signed with that secret.
		cy.request({url: receiver + '/events', timeout: 10000}).then((response) => {
			const events = typeof response.body === 'string' ? JSON.parse(response.body) : response.body;
			expect(events, 'nothing reached the receiver').to.not.be.empty;
			const delivery = events[0];
			expect(delivery.contentType, 'the delivery is json').to.contain('application/json');
			expect(delivery.event, 'the delivery names the event').to.match(/\S/);
			expect(delivery.signature, 'the delivery is signed').to.match(/^sha256=[0-9a-f]{64}$/);
			expect(JSON.parse(delivery.body), 'the body is the payload of an event').to.be.an('object');

			return cy.window({log: false}).then((win) => cy.wrap(
				(async () => {
					const encoder = new win.TextEncoder();
					const key = await win.crypto.subtle.importKey(
						'raw', encoder.encode(secret), {name: 'HMAC', hash: 'SHA-256'}, false, ['sign']
					);
					const mac = await win.crypto.subtle.sign('HMAC', key, encoder.encode(delivery.body));

					return 'sha256=' + [...new Uint8Array(mac)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
				})(),
				{log: false}
			)).then((expected) => {
				expect(delivery.signature, 'the signature is the one the secret of the journal makes').to.eq(expected);
			});
		});
	});

	// Puts the endpoints of the journal back as they were, also when a test failed.
	after(function() {
		if (originalEndpoints === null) {
			return;
		}
		login(adminUser, adminPassword);
		openPluginsTab();
		openPluginSettings(row, form);
		cy.get(form + ' tr.ojsbrWebhookEndpoint').then(($rows) => {
			$rows.toArray().forEach((tr) => {
				const url = tr.querySelector('input[name^="endpointUrl"]');
				if (!originalEndpoints.includes(url.value)) {
					url.value = '';
				}
			});
		});
		saveForm();
	});
});
