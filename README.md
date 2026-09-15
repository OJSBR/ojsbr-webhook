# OJSBR Webhook — OJS plugin

[![OJS](https://img.shields.io/badge/OJS-3.4-brightgreen)](https://pkp.sfu.ca/ojs/)
[![Version](https://img.shields.io/badge/version-1.1.0.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OJS 3.4](https://github.com/OJSBR/ojsbr-webhook/releases/download/1.1.0.0/ojsbrWebhook-1.1.0.0.tar.gz) — or browse all [Releases](../../releases).

A generic plugin for **Open Journal Systems (OJS)** that sends an HTTP webhook when a
submission is created and when an article is actually published, so that other systems
(a CRM, a message queue, an automation service) can react without polling OJS.

> **Developed and maintained by [OJSBR](https://ojsbr.com).** See the
> [Credits & authorship](#credits--authorship) section below.

## Compatibility & branches

| OJS version | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.4.x   | [`stable-3_4_0`](../../tree/stable-3_4_0) *(default)* | 1.1.0.0 |

> **Upgrade from 1.0.0.x.** Earlier versions saved the endpoints and ran the *Test* button
> without checking the form's CSRF token, so a page opened by a logged-in journal manager
> could replace the endpoints and receive the journal's submissions. 1.0.1.0 requires a POST
> with the token, accepts only `http`/`https` endpoints and no longer writes endpoint URLs to
> the server log. Upgrading is strongly recommended.
>
> **1.1.0.0** delivers webhooks from the OJS **job queue** instead of inside the request that
> created the submission or published the article, so the editor no longer waits for the
> endpoints; a failed delivery is tried again twice, a minute apart. The queue must be processed
> (OJS's `job_runner`, on by default, or a worker/cron: see the PKP documentation on jobs).

## The problem

OJS has no outgoing notification for "a submission arrived" or "an article went public".
Systems that need to know either poll the API or depend on e-mail, and both miss the one
case that matters: an article scheduled in a future issue only becomes public when the issue
is published.

## What it does

- **Events:** `submission.created` (a submission is created) and `publication.created` (an
  article becomes public — not when it is merely scheduled in a future issue; it fires when
  that issue is published).
- **Several endpoints** per journal, each receiving one or both events.
- **Signed payloads:** with a secret, every request carries
  `X-OJSBR-Webhook-Signature: sha256=<hmac>`, computed as `hash_hmac('sha256', $body, $secret)`.
- **Test button** per endpoint, which sends a sample payload right away and shows the HTTP
  status.
- Headers `X-OJSBR-Webhook-Event: <event>` and `User-Agent: OJSBR-Webhook/<version>`.
- **Delivered from the OJS job queue**, tried again when the endpoint fails.

## Installation

1. Install via **Settings → Website → Plugins → Upload A New Plugin**, or extract the folder
   into `plugins/generic/` so that you get `plugins/generic/ojsbrWebhook/`.
   Do not rename the folder: OJS derives the plugin's class namespace from the directory name.
2. Enable **OJSBR Webhook** in the *Generic* plugins list.

The package is also published to GitHub Packages on every push to a stable branch
(`ghcr.io/ojsbr/ojsbr-webhook:3.4.latest`, pulled with [ORAS](https://oras.land)).

## Configuration

Open the plugin's **Settings**: one row per endpoint, with its URL, an optional secret, the
events it receives, a *Test* button and a *Delete* button. Only absolute `http` and `https`
URLs are accepted.

Payload:

```json
{
  "event": "submission.created",
  "occurredAt": "2026-05-23T12:00:00+00:00",
  "contextId": 1,
  "baseUrl": "https://journal.example.org",
  "object": {
    "id": 123,
    "class": "APP\\submission\\Submission",
    "submissionId": null,
    "contextId": 1,
    "data": {}
  }
}
```

The endpoints are stored per journal in the plugin setting `webhookEndpoints` (JSON), with a
site-wide fallback in context `0`. The legacy settings `webhookUrl` / `webhookSecret` are still
read when no endpoint list exists.

## How it works (technical)

- Hooks `Submission::add` and `Publication::publish` (the status must be `STATUS_PUBLISHED`; a
  scheduled publication is skipped and announced when its issue is published). The hooks are
  always registered and each callback checks that the plugin is on in the journal of the
  submission, so submissions created by imports, the API or scheduled tasks are announced too.
  An event is queued once per object within a request.
- Each delivery is a queued job (`jobs/SendWebhook.php`, on PKP's `BaseJob`) that posts through
  the HTTP client of the application (`Application::getHttpClient()`, which carries the proxy of
  `config.inc.php`), with a 5 s connection and 15 s total timeout, HTTP and HTTPS only, without
  following redirects. A failure fails the job, so the queue tries again, and is logged with the
  event, the endpoint's host and the status — never the full URL, which can carry a token.
- Settings form on PKP's `Form` with `FormValidatorPost`, `FormValidatorCSRF` and a check that
  every URL is absolute `http`/`https`; the *Test* button requires a POST with the form's CSRF
  token. Adding, removing and testing rows is done by `js/settings.js`.

## Tests

- **PHPUnit** (`tests/*Test.php`, on `PKP\tests\PKPTestCase`): the classes against the installed
  PKP, the plugin found by PKP's plugin registry, the URLs that may receive webhooks, a delivery
  signed and posted through the application's HTTP client without redirects (mocked), errors
  naming the host only, a failed delivery failing the job, endpoint normalization, the settings
  form (validators and rows kept), webhooks queued only in journals where the plugin is on and
  only for publications that become public, the hooks used, the templates and the 38
  translations. From the OJS root:

  ```bash
  lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml --no-coverage "$PWD/plugins/generic/ojsbrWebhook/tests"
  ```

- **Cypress** (`cypress/tests/functional/OjsbrWebhook.cy.js`): enables the plugin, refuses a
  `file://` endpoint in the settings form, runs the *Test* button against a closed local port
  (HTTP 0, without showing the URL's token), saves the endpoint and finds it again, and checks
  that a test request without the form's token is refused. The endpoints are put back after the
  run.
- Verified on OJS 3.4.0.10, including a delivery job dispatched to the database queue and
  received by a local receiver with a valid signature.
- `test-server/` holds a small Node/Express receiver for local development.

Tests are kept in the repository and are not part of the release package.

## Credits & authorship

- **Developed and maintained by** [OJSBR](https://ojsbr.com) — original plugin.
- Distributed under the **GNU GPL v3**.

## AI use

Generative AI (Claude, by Anthropic) was used to write and run tests, improve the code and bring
it in line with PKP standards. Every change is reviewed and tested by OJSBR, which is responsible
for the published releases.

## Contributing

Issues and pull requests are welcome. See [`CONTRIBUTING.md`](CONTRIBUTING.md).

## License

Distributed under the **GNU GPL v3**. See [`LICENSE`](LICENSE) and `docs/COPYING`.

---

## 🇧🇷 Português

Plugin genérico para o **Open Journal Systems (OJS)** que envia um webhook HTTP quando uma
submissão é criada e quando um artigo é de fato publicado, para que outros sistemas (CRM, fila
de mensagens, automação) reajam sem ficar consultando o OJS.

> **Desenvolvido e mantido pela [OJSBR](https://ojsbr.com).** Veja a seção
> [Créditos e autoria](#créditos-e-autoria) abaixo.

### Compatibilidade e branches

| Versão do OJS | Branch | Release do plugin |
|---------------|--------|-------------------|
| OJS 3.4.x     | [`stable-3_4_0`](../../tree/stable-3_4_0) *(padrão)* | 1.1.0.0 |

> **Atualização a partir da 1.0.0.x.** As versões anteriores salvavam os endpoints e rodavam o
> botão *Testar* sem conferir o token CSRF do formulário: uma página aberta por um gestor logado
> podia trocar os endpoints e passar a receber as submissões da revista. A 1.0.1.0 exige POST
> com o token, só aceita endpoints `http`/`https` e não grava mais a URL do endpoint no log do
> servidor. A atualização é fortemente recomendada.
>
> A **1.1.0.0** entrega os webhooks pela **fila de jobs** do OJS, e não mais dentro da requisição
> que criou a submissão ou publicou o artigo: o editor não espera os endpoints, e uma entrega que
> falha é tentada mais duas vezes, com um minuto de intervalo. A fila precisa ser processada
> (`job_runner` do OJS, ligado por padrão, ou worker/cron; veja a documentação da PKP sobre jobs).

### O problema

O OJS não avisa ninguém quando chega uma submissão ou quando um artigo fica público. Quem
precisa saber consulta a API ou depende de e-mail, e os dois perdem o caso que importa: o artigo
agendado numa edição futura só fica público quando a edição é publicada.

### O que faz

- **Eventos:** `submission.created` (submissão criada) e `publication.created` (artigo público —
  não quando só foi agendado numa edição futura; dispara quando essa edição é publicada).
- **Vários endpoints** por revista, cada um com um ou os dois eventos.
- **Payload assinado:** com segredo, cada requisição leva `X-OJSBR-Webhook-Signature: sha256=<hmac>`.
- **Entrega pela fila de jobs** do OJS, com novas tentativas quando o endpoint falha.
- **Botão Testar** por endpoint, que envia um payload de exemplo e mostra o status HTTP.

### Instalação

Instale em **Configurações → Website → Plugins → Enviar um novo plugin**, ou extraia a pasta em
`plugins/generic/` (ficando `plugins/generic/ojsbrWebhook/`). Não renomeie a pasta. Depois ative
o **OJSBR Webhook** na lista de plugins *Genéricos*. O pacote também sai no GitHub Packages
(`ghcr.io/ojsbr/ojsbr-webhook:3.4.latest`).

### Configuração

Nas **Configurações** do plugin, uma linha por endpoint: URL, segredo opcional, eventos, botão
*Testar* e *Excluir*. Só são aceitas URLs absolutas `http` e `https`. A lista fica no setting
`webhookEndpoints` (JSON) da revista, com recuo para o contexto `0`.

### Testes

PHPUnit em `tests/` (sobre `PKP\tests\PKPTestCase`), com o comando da seção em inglês: classes
contra o PKP instalado, plugin encontrado pelo registro de plugins, URLs aceitas, entrega assinada
e enviada pelo cliente HTTP da aplicação sem seguir redirecionamento (simulado), erro só com o
host, entrega que falha derrubando o job, normalização dos endpoints, formulário (validadores e
linhas mantidas), webhook enfileirado só onde o plugin está ligado e só para publicação que fica
pública, hooks usados, templates e as 38 traduções. Cypress em `cypress/tests/functional/`: liga
o plugin, recusa endpoint `file://`, roda o botão Testar contra uma porta local fechada (HTTP 0,
sem mostrar o token da URL), salva o endpoint e o encontra de novo, e confere que o teste sem o
token do formulário é recusado; os endpoints voltam ao que eram no fim. Verificado no OJS
3.4.0.10, inclusive um job de entrega na fila `database` recebido por um receptor local com
assinatura válida. `test-server/` traz um receptor Node/Express para desenvolvimento local.

Os testes ficam no repositório e não fazem parte do pacote da release.

### Créditos e autoria

- **Desenvolvido e mantido pela** [OJSBR](https://ojsbr.com) — plugin autoral.
- Distribuído sob a **GNU GPL v3**.

### Uso de IA

Foi usada IA generativa (Claude, da Anthropic) para escrever e rodar testes, melhorar o código e
alinhá-lo aos padrões da PKP. Toda mudança é revisada e testada pela OJSBR, que responde pelas
releases publicadas.

### Licença

Distribuído sob a **GNU GPL v3**. Veja [`LICENSE`](LICENSE) e `docs/COPYING`.
