<p align="center"><sub>
  <a href="README.ar.md">العربية</a> ·
  <a href="README.de.md">Deutsch</a> ·
  <a href="../../README.md">English</a> ·
  <a href="README.es.md">Español</a> ·
  <a href="README.fr.md">Français</a> ·
  <a href="README.it.md">Italiano</a> ·
  <a href="README.ja.md">日本語</a> ·
  <a href="README.ko.md">한국어</a> ·
  <a href="README.nl.md">Nederlands</a> ·
  <a href="README.pl.md">Polski</a> ·
  <b>Português (BR)</b> ·
  <a href="README.ru.md">Русский</a> ·
  <a href="README.tr.md">Türkçe</a> ·
  <a href="README.zh-CN.md">简体中文</a>
</sub></p>

<p align="center">
  <a href="https://escalated.dev"><img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/logo.png" width="72" height="72" alt="Escalated"></a>
</p>

<h1 align="center">Escalated for WordPress</h1>

<p align="center">
  Tenha um helpdesk completo dentro do WordPress.<br>
  Tickets, SLAs, regras de escalonamento, e-mail de entrada e um portal do cliente, gerenciados pelo wp-admin sem serviços externos.
</p>

<p align="center">
  <a href="https://escalated.dev"><b>Site</b></a> ·
  <a href="https://escalated.dev/docs"><b>Documentação</b></a> ·
  <a href="https://escalated.dev/cloud"><b>Cloud</b></a> ·
  <a href="../../CHANGELOG.md"><b>Histórico de mudanças</b></a>
</p>

<br>

<img src="../../screenshots/results/ticket-list.png" alt="A lista de tickets do Escalated no wp-admin com filtros de status, prioridade, departamento e agente" width="100%">

## O que ele faz

<table>
  <tr>
    <td width="40%">
      <h3>Tickets que seus agentes atendem no wp-admin</h3>
      Conversas em thread, notas internas, respostas prontas e macros na tela do ticket. Os tickets também chegam por e-mail via Mailgun, Postmark ou Amazon SES.
    </td>
    <td width="60%">
      <img src="../../screenshots/results/ticket-detail.png" alt="Um ticket aberto no wp-admin com a conversa e o formulário de resposta">
    </td>
  </tr>
  <tr>
    <td width="60%">
      <img src="../../screenshots/results/sla-policies.png" alt="A tela de políticas de SLA com metas de primeira resposta e de resolução">
    </td>
    <td width="40%">
      <h3>SLAs e regras de escalonamento que funcionam sozinhos</h3>
      Metas de primeira resposta e de resolução, regras que escalonam tickets quando uma meta atrasa e verificações de SLA agendadas pelo WP-Cron.
    </td>
  </tr>
</table>

## Recursos

<table>
<tr>
<td width="33%" valign="top">

**Tickets**
- Conversas em thread e notas internas
- Linha do tempo de atividades em cada ticket
- Respostas prontas, macros e tags
- Mesclagem de tickets duplicados
- Anexos com limites de upload

</td>
<td width="33%" valign="top">

**SLAs e roteamento**
- Roteamento e atribuição por departamento
- Metas de primeira resposta e de resolução
- Regras de escalonamento e verificações de SLA agendadas
- Workflows orientados a eventos no wp-admin ou via REST

</td>
<td width="33%" valign="top">

**Clientes**
- Páginas de tickets no frontend via shortcodes
- Envio como convidado e acesso seguro para convidados
- Avaliações de satisfação

</td>
</tr>
<tr>
<td width="33%" valign="top">

**Funções e segurança**
- Funções de administrador, agente e agente leve
- Permissões por rota opcionais
- Autenticação em dois fatores TOTP opcional para a equipe
- Login com SAML 2.0, OAuth 2 / OIDC ou JWT

</td>
<td width="33%" valign="top">

**E-mail e webhooks**
- Entrada pelo Mailgun, Postmark e Amazon SES
- Vários endpoints de webhook com eventos próprios
- Log de entregas e novas tentativas

</td>
<td width="33%" valign="top">

**API e relatórios**
- API REST com tokens Bearer e permissões por token
- Limite de requisições por token
- Telas de relatórios

</td>
</tr>
</table>

<p align="center"><sub><a href="../features.md">Todos os recursos em detalhe</a></sub></p>

<p align="center">
  <a href="https://wordpress.org"><img src="https://img.shields.io/badge/WordPress-21759B?style=for-the-badge&logo=wordpress&logoColor=white" alt="WordPress"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP"></a>
  <a href="https://www.mysql.com"><img src="https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL"></a>
</p>

## Início rápido

1. Baixe o [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip) da versão mais recente.
2. Descompacte-o no diretório de plugins, para que o plugin fique em `wp-content/plugins/escalated`.
3. Ative o **Escalated** na tela Plugins do WordPress.
4. Acesse **Escalated** no wp-admin e configure departamentos, políticas de SLA, regras de escalonamento e configurações.

Requer WordPress 6.0+ e PHP 8.1+. O [guia de instalação](../installation.md) traz os detalhes, e [personalização](../customization.md) lista os shortcodes para as páginas voltadas ao cliente.

## Saiba mais

- [Todos os recursos](../features.md) · [Instalação](../installation.md) · [Capturas de tela](../screenshots.md)
- [Shortcodes, assuntos de tickets e traduções](../customization.md) · [API REST e mesclagem de tickets](../rest-api.md)
- [Controle de acesso](../access-control.md) · [Acesso a tickets e roteamento](../ticket-access-and-routing.md) · [Workflows](../workflows.md)
- [E-mail de entrada](../inbound-email.md) · [Webhooks](../webhooks.md) · [Acesso de convidados](../guest-access.md)
- [Incorporando o widget](../embedding-the-widget.md) · [Incorporando o painel administrativo](../embedding-admin.md)
- [Tarefas agendadas e conexão com o banco de dados](../operations.md) · [Newsletters](../newsletters.md) · [Desenvolvimento](../development.md)

O Escalated também está disponível para [Laravel](https://github.com/escalated-dev/escalated-laravel), [Rails](https://github.com/escalated-dev/escalated-rails), [Django](https://github.com/escalated-dev/escalated-django), [NestJS](https://github.com/escalated-dev/escalated-nestjs), [AdonisJS](https://github.com/escalated-dev/escalated-adonis), [Symfony](https://github.com/escalated-dev/escalated-symfony), [Phoenix](https://github.com/escalated-dev/escalated-phoenix), [Go](https://github.com/escalated-dev/escalated-go), [.NET](https://github.com/escalated-dev/escalated-dotnet), [Spring](https://github.com/escalated-dev/escalated-spring) e [Filament](https://github.com/escalated-dev/escalated-filament). A interface para os outros backends fica em [escalated](https://github.com/escalated-dev/escalated).

## Licença

MIT. Copyright (c) 2026 Escalated.dev. Veja [LICENSE](../../LICENSE).
