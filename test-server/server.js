import express from 'express';

const app = express();
const port = Number(process.env.PORT || 3333);
const events = [];

app.use(express.json({ limit: '2mb' }));

app.get('/', (_req, res) => {
  res.type('html').send(renderPage());
});

app.get('/events', (_req, res) => {
  res.json(events);
});

app.post('/webhook/ojs', (req, res) => {
  const event = {
    id: crypto.randomUUID(),
    receivedAt: new Date().toISOString(),
    event: req.get('x-ojsbr-webhook-event') || req.body?.event || null,
    signature: req.get('x-ojsbr-webhook-signature') || null,
    headers: req.headers,
    body: req.body,
  };

  events.unshift(event);
  events.splice(50);

  console.log('\n[OJSBR WEBHOOK]', event.receivedAt);
  console.log(JSON.stringify(event, null, 2));

  res.json({ ok: true, receivedAt: event.receivedAt });
});

app.delete('/events', (_req, res) => {
  events.splice(0);
  res.json({ ok: true });
});

app.listen(port, '0.0.0.0', () => {
  console.log(`OJSBR webhook test server running at http://localhost:${port}`);
  console.log(`Webhook endpoint: http://host.docker.internal:${port}/webhook/ojs`);
});

function renderPage() {
  return `<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>OJSBR Webhook Test Server</title>
  <style>
    body { margin: 0; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #111; color: #f7f7f7; }
    header { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 18px 24px; background: #ffcc00; color: #111; }
    h1 { margin: 0; font-size: 1.2rem; }
    main { padding: 24px; }
    .endpoint { margin: 0 0 18px; padding: 12px 14px; border: 1px solid #333; border-radius: 8px; background: #1b1b1b; }
    code { color: #ffcc00; }
    button { border: 0; border-radius: 999px; padding: 9px 14px; background: #111; color: #ffcc00; cursor: pointer; }
    button:hover { background: #333; }
    .event { margin: 0 0 16px; padding: 16px; border: 1px solid #333; border-radius: 10px; background: #1b1b1b; }
    .event h2 { margin: 0 0 8px; font-size: 1rem; color: #ffcc00; }
    pre { max-height: 420px; overflow: auto; padding: 12px; border-radius: 8px; background: #050505; color: #ddd; }
    .muted { color: #aaa; }
  </style>
</head>
<body>
  <header>
    <h1>OJSBR Webhook Test Server</h1>
    <button id="clear">Limpar eventos</button>
  </header>
  <main>
    <div class="endpoint">
      Configure no plugin:
      <code>http://host.docker.internal:${port}/webhook/ojs</code>
      <div class="muted">Se o OJS estiver fora do Docker, use <code>http://localhost:${port}/webhook/ojs</code>.</div>
    </div>
    <div id="events"></div>
  </main>
  <script>
    async function loadEvents() {
      const res = await fetch('/events');
      const events = await res.json();
      const container = document.getElementById('events');
      if (!events.length) {
        container.innerHTML = '<p class="muted">Nenhum webhook recebido ainda.</p>';
        return;
      }
      container.innerHTML = events.map(event => \`
        <section class="event">
          <h2>\${escapeHtml(event.event || 'evento sem nome')}</h2>
          <div class="muted">Recebido em \${escapeHtml(event.receivedAt)}</div>
          <pre>\${escapeHtml(JSON.stringify(event, null, 2))}</pre>
        </section>
      \`).join('');
    }

    function escapeHtml(value) {
      return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    }

    document.getElementById('clear').addEventListener('click', async () => {
      await fetch('/events', { method: 'DELETE' });
      await loadEvents();
    });

    loadEvents();
    setInterval(loadEvents, 2000);
  </script>
</body>
</html>`;
}
