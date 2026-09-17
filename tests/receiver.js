/**
 * @file plugins/generic/ojsbrWebhook/tests/receiver.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief A receiver for the browser tests: it keeps what it is sent and hands it
 *        back, so a test can see that a delivery really arrived and how it was
 *        signed. Node only, no dependency to install, started by
 *        .github/actions/tests.sh before the tests and stopped after them.
 *
 * Usage: node receiver.js [port]   (default 3399)
 *   POST /hook     stores the delivery
 *   GET  /events   the deliveries, newest first
 *   DELETE /events forgets them
 */

const http = require('http');

const port = Number(process.argv[2] || process.env.OJSBR_WEBHOOK_TEST_PORT || 3399);
const deliveries = [];

const server = http.createServer((request, response) => {
	const send = (status, body) => {
		const text = JSON.stringify(body);
		response.writeHead(status, {
			'Content-Type': 'application/json',
			'Content-Length': Buffer.byteLength(text),
			// The tests read this from the page of the journal.
			'Access-Control-Allow-Origin': '*',
		});
		response.end(text);
	};

	if (request.method === 'GET' && request.url.startsWith('/events')) {
		return send(200, deliveries);
	}
	if (request.method === 'DELETE' && request.url.startsWith('/events')) {
		deliveries.splice(0);

		return send(200, {ok: true});
	}
	if (request.method !== 'POST') {
		return send(405, {error: 'method not allowed'});
	}

	let body = '';
	request.on('data', (chunk) => {
		body += chunk;
		if (body.length > 2 * 1024 * 1024) {
			request.destroy();
		}
	});
	request.on('end', () => {
		deliveries.unshift({
			receivedAt: new Date().toISOString(),
			url: request.url,
			event: request.headers['x-ojsbr-webhook-event'] || null,
			signature: request.headers['x-ojsbr-webhook-signature'] || null,
			contentType: request.headers['content-type'] || null,
			body: body,
		});
		deliveries.splice(50);
		send(200, {ok: true});
	});
});

server.listen(port, '127.0.0.1', () => {
	process.stdout.write('receiver listening on http://127.0.0.1:' + port + '\n');
});
