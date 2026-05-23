# OJSBR Webhook

Plugin genérico para **OJS 3.4** que dispara webhooks HTTP quando submissões são criadas e artigos são publicados de fato.

| Recurso | Link |
| --- | --- |
| Repositório | [github.com/OJSBR/ojsbr-webhook](https://github.com/OJSBR/ojsbr-webhook) |
| Releases (público) | [github.com/OJSBR/ojsbr-webhook/releases](https://github.com/OJSBR/ojsbr-webhook/releases) |
| GitHub Packages | [GitHub Packages — ojsbr-webhook](https://github.com/orgs/OJSBR/packages/container/ojsbr-webhook) |
| Organização | [github.com/OJSBR](https://github.com/OJSBR) |

## Branches

| Branch | Uso |
| --- | --- |
| `master` | Desenvolvimento ativo |
| `stable-3_4_0` | Compatível com OJS 3.4.x — gera pacote `3.4.build.*` no [GitHub Packages](https://github.com/orgs/OJSBR/packages/container/ojsbr-webhook) |
| `stable-3_5_0` | (futuro) Compatível com OJS 3.5.x — gera pacote `3.5.build.*` |

## Eventos

- `submission.created` — nova submissão criada.
- `publication.created` — artigo publicado de fato (não dispara para artigos apenas agendados em edições futuras).

## Instalação

### Opção 1 — Release (público, recomendado)

Baixe o `.tar.gz` na página de [Releases](https://github.com/OJSBR/ojsbr-webhook/releases) (tag `3.4.latest` para OJS 3.4):

```bash
curl -L -o ojsbr-webhook.tar.gz \
  https://github.com/OJSBR/ojsbr-webhook/releases/download/3.4.latest/ojsbrWebhook-3.4.build.N.tar.gz
```

> Substitua `3.4.build.N` pela build desejada (veja os assets da release).

```bash
tar -xzf ojsbrWebhook-*.tar.gz -C /caminho/do/ojs/plugins/generic/
```

### Opção 2 — GitHub Packages (GHCR)

Requer pacote com visibilidade **Public**. Use a [CLI ORAS](https://oras.land/docs/installation):

```bash
oras pull ghcr.io/ojsbr/ojsbr-webhook:3.4.latest
tar -xzf ojsbrWebhook-*.tar.gz -C /caminho/do/ojs/plugins/generic/
```

Cada branch `stable-3_X_0` publica tags `{X.Y}.build.{número}` e `{X.Y}.latest`.

#### Tornar o pacote GHCR público (admin da org, uma vez)

1. Abra [Package settings — ojsbr-webhook](https://github.com/orgs/OJSBR/packages/container/ojsbr-webhook/settings)
2. Role até **Danger Zone** → **Change visibility** → **Public**

Ou via terminal (após `gh auth refresh -h github.com -s read:packages,write:packages`):

```bash
./scripts/set-package-public.sh
```

Para builds futuras já nascerem públicas: **Organization settings → Packages → Package creation → Public**.

Substitua `/caminho/do/ojs` pelo diretório raiz da sua instalação OJS. O arquivo extraído deve ficar em:

```text
plugins/generic/ojsbrWebhook/
```

### Opção 3 — Clone do repositório

Use a branch `stable-3_4_0` para instalações OJS 3.4:

```bash
git clone --branch stable-3_4_0 --depth 1 https://github.com/OJSBR/ojsbr-webhook.git plugins/generic/ojsbrWebhook
```

### Após instalar

1. Limpe o cache do OJS.
2. Habilite o plugin em **Configurações do Website > Plugins > Plugins Genéricos > OJSBR Webhook**.

## Configuração

Configure os endpoints em:

```text
Configurações do Website > Plugins > Plugins Genéricos > OJSBR Webhook > Configurações
```

Cada endpoint pode receber:

- apenas `submission.created`;
- apenas `publication.created`;
- ambos os eventos.

Cada linha da tabela possui botão **Testar** para enviar um payload de exemplo imediatamente.

## Configuração via banco

O plugin salva a lista de endpoints em `webhookEndpoints` como JSON.

Exemplo:

```sql
INSERT INTO plugin_settings (plugin_name, locale, context_id, setting_name, setting_value, setting_type)
VALUES (
  'ojsbrwebhookplugin',
  '',
  0,
  'webhookEndpoints',
  '[{"url":"https://example.com/webhook/submission","secret":"sub-secret","events":["submission.created"]},{"url":"https://example.com/webhook/publication","secret":"pub-secret","events":["publication.created"]},{"url":"https://example.com/webhook/all","secret":"all-secret","events":["submission.created","publication.created"]}]',
  'string'
);
```

O plugin ainda aceita, como fallback, as opções legadas:

- `webhookUrl`: URL que receberá os eventos.
- `webhookSecret`: segredo opcional para assinar o payload.

## Assinatura

Quando um segredo estiver configurado, o plugin envia:

```text
X-OJSBR-Webhook-Signature: sha256=<hmac>
```

O HMAC é calculado com `hash_hmac('sha256', $body, $secret)`.

## Payload

```json
{
  "event": "submission.created",
  "occurredAt": "2026-05-23T12:00:00+00:00",
  "contextId": 1,
  "baseUrl": "https://revistaft.com",
  "object": {
    "id": 123,
    "class": "APP\\submission\\Submission",
    "submissionId": null,
    "contextId": 1,
    "data": {}
  }
}
```

## Comportamento com edições futuras

O plugin registra `Submission::add`/`Submission::insert` para novas submissões e `Publication::publish` para artigos publicados. Quando um artigo é agendado em uma edição futura, o OJS marca a publicação como `STATUS_SCHEDULED`; o webhook só é enviado quando o status passa a `STATUS_PUBLISHED` — o que ocorre imediatamente em edições já publicadas ou quando a edição futura é publicada no painel de edições.

## Servidor de teste

Este projeto inclui um servidor Node/Express em `test-server/` para visualizar webhooks recebidos em uma tela web (uso local/desenvolvimento).

```bash
cd test-server
npm install
npm start
```

Acesse `http://localhost:3333` e configure o endpoint no plugin como:

```text
http://host.docker.internal:3333/webhook/ojs
```

## Licença

Este plugin é distribuído sob a [GNU General Public License v3.0 ou posterior](LICENSE).
