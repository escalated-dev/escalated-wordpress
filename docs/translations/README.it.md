<p align="center"><sub>
  <a href="README.ar.md">العربية</a> ·
  <a href="README.de.md">Deutsch</a> ·
  <a href="../../README.md">English</a> ·
  <a href="README.es.md">Español</a> ·
  <a href="README.fr.md">Français</a> ·
  <b>Italiano</b> ·
  <a href="README.ja.md">日本語</a> ·
  <a href="README.ko.md">한국어</a> ·
  <a href="README.nl.md">Nederlands</a> ·
  <a href="README.pl.md">Polski</a> ·
  <a href="README.pt-BR.md">Português (BR)</a> ·
  <a href="README.ru.md">Русский</a> ·
  <a href="README.tr.md">Türkçe</a> ·
  <a href="README.zh-CN.md">简体中文</a>
</sub></p>

<p align="center">
  <a href="https://escalated.dev"><img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/logo.png" width="72" height="72" alt="Escalated"></a>
</p>

<h1 align="center">Escalated for WordPress</h1>

<p align="center">
  Gestisci un helpdesk completo dentro WordPress.<br>
  Ticket, SLA, regole di escalation, email in entrata e un portale clienti, gestiti da wp-admin senza servizi esterni.
</p>

<p align="center">
  <a href="https://escalated.dev"><b>Sito web</b></a> ·
  <a href="https://escalated.dev/docs"><b>Documentazione</b></a> ·
  <a href="https://escalated.dev/cloud"><b>Cloud</b></a> ·
  <a href="../../CHANGELOG.md"><b>Registro delle modifiche</b></a>
</p>

<br>

<img src="../../screenshots/results/ticket-list.png" alt="L'elenco dei ticket di Escalated in wp-admin con filtri per stato, priorità, reparto e agente" width="100%">

## Cosa fa

<table>
  <tr>
    <td width="40%">
      <h3>Ticket che i tuoi agenti gestiscono in wp-admin</h3>
      Conversazioni a thread, note interne, risposte predefinite e macro nella schermata del ticket. I ticket arrivano anche via email tramite Mailgun, Postmark o Amazon SES.
    </td>
    <td width="60%">
      <img src="../../screenshots/results/ticket-detail.png" alt="Un ticket aperto in wp-admin con la conversazione e il modulo di risposta">
    </td>
  </tr>
  <tr>
    <td width="60%">
      <img src="../../screenshots/results/sla-policies.png" alt="La schermata delle policy SLA con obiettivi di prima risposta e di risoluzione">
    </td>
    <td width="40%">
      <h3>SLA e regole di escalation che funzionano da sole</h3>
      Obiettivi di prima risposta e di risoluzione, regole che eseguono l'escalation dei ticket quando un obiettivo slitta e controlli SLA pianificati tramite WP-Cron.
    </td>
  </tr>
</table>

## Funzionalità

<table>
<tr>
<td width="33%" valign="top">

**Ticket**
- Conversazioni a thread e note interne
- Cronologia delle attività su ogni ticket
- Risposte predefinite, macro e tag
- Unione dei ticket duplicati
- Allegati con limiti di caricamento

</td>
<td width="33%" valign="top">

**SLA e instradamento**
- Instradamento e assegnazione per reparto
- Obiettivi di prima risposta e di risoluzione
- Regole di escalation e controlli SLA pianificati
- Workflow basati su eventi in wp-admin o via REST

</td>
<td width="33%" valign="top">

**Clienti**
- Pagine dei ticket nel frontend tramite shortcode
- Invio come ospite e accesso ospite sicuro
- Valutazioni di soddisfazione

</td>
</tr>
<tr>
<td width="33%" valign="top">

**Ruoli e sicurezza**
- Ruoli amministratore, agente e agente light
- Permessi per route opzionali
- Autenticazione a due fattori TOTP opzionale per lo staff
- Accesso con SAML 2.0, OAuth 2 / OIDC o JWT

</td>
<td width="33%" valign="top">

**Email e webhook**
- Email in entrata da Mailgun, Postmark e Amazon SES
- Più endpoint webhook, ognuno con i propri eventi
- Registro delle consegne e nuovi tentativi

</td>
<td width="33%" valign="top">

**API e report**
- API REST con token Bearer e permessi per token
- Limite di richieste per token
- Viste di report

</td>
</tr>
</table>

<p align="center"><sub><a href="../features.md">Tutte le funzionalità nel dettaglio</a></sub></p>

<p align="center">
  <a href="https://wordpress.org"><img src="https://img.shields.io/badge/WordPress-21759B?style=for-the-badge&logo=wordpress&logoColor=white" alt="WordPress"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP"></a>
  <a href="https://www.mysql.com"><img src="https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL"></a>
</p>

## Avvio rapido

1. Scarica [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip) dall'ultima release.
2. Estrailo nella cartella dei plugin, in modo che il plugin si trovi in `wp-content/plugins/escalated`.
3. Attiva **Escalated** dalla schermata Plugin di WordPress.
4. Vai su **Escalated** in wp-admin e configura reparti, policy SLA, regole di escalation e impostazioni.

Richiede WordPress 6.0+ e PHP 8.1+. La [guida all'installazione](../installation.md) contiene i dettagli e [personalizzazione](../customization.md) elenca gli shortcode per le pagine rivolte ai clienti.

## Per saperne di più

- [Tutte le funzionalità](../features.md) · [Installazione](../installation.md) · [Screenshot](../screenshots.md)
- [Shortcode, oggetti dei ticket e traduzioni](../customization.md) · [API REST e unione dei ticket](../rest-api.md)
- [Controllo degli accessi](../access-control.md) · [Accesso ai ticket e instradamento](../ticket-access-and-routing.md) · [Workflow](../workflows.md)
- [Email in entrata](../inbound-email.md) · [Webhook](../webhooks.md) · [Accesso ospite](../guest-access.md)
- [Incorporare il widget](../embedding-the-widget.md) · [Incorporare l'amministrazione](../embedding-admin.md)
- [Attività pianificate e connessione al database](../operations.md) · [Newsletter](../newsletters.md) · [Sviluppo](../development.md)

Escalated è disponibile anche per [Laravel](https://github.com/escalated-dev/escalated-laravel), [Rails](https://github.com/escalated-dev/escalated-rails), [Django](https://github.com/escalated-dev/escalated-django), [NestJS](https://github.com/escalated-dev/escalated-nestjs), [AdonisJS](https://github.com/escalated-dev/escalated-adonis), [Symfony](https://github.com/escalated-dev/escalated-symfony), [Phoenix](https://github.com/escalated-dev/escalated-phoenix), [Go](https://github.com/escalated-dev/escalated-go), [.NET](https://github.com/escalated-dev/escalated-dotnet), [Spring](https://github.com/escalated-dev/escalated-spring) e [Filament](https://github.com/escalated-dev/escalated-filament). L'interfaccia per gli altri backend si trova in [escalated](https://github.com/escalated-dev/escalated).

## Licenza

MIT. Copyright (c) 2026 Escalated.dev. Vedi [LICENSE](../../LICENSE).
