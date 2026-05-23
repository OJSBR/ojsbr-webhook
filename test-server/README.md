# OJSBR Webhook Test Server

Servidor Node/Express simples para testar se o plugin está enviando webhooks.

## Rodar

```bash
npm install
npm start
```

Acesse:

```text
http://localhost:3333
```

## URL para configurar no plugin

Se o OJS estiver rodando em Docker:

```text
http://host.docker.internal:3333/webhook/ojs
```

Se o OJS estiver rodando diretamente na máquina:

```text
http://localhost:3333/webhook/ojs
```

Os eventos recebidos aparecem na tela e também no console do terminal.
