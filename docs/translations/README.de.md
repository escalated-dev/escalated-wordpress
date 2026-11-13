<p align="center"><sub>
  <a href="README.ar.md">العربية</a> ·
  <b>Deutsch</b> ·
  <a href="../../README.md">English</a> ·
  <a href="README.es.md">Español</a> ·
  <a href="README.fr.md">Français</a> ·
  <a href="README.it.md">Italiano</a> ·
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
  Betreibe einen vollständigen Helpdesk direkt in WordPress.<br>
  Tickets, SLAs, Eskalationsregeln, eingehende E-Mails und ein Kundenportal, verwaltet in wp-admin und ohne externe Dienste.
</p>

<p align="center">
  <a href="https://escalated.dev"><b>Website</b></a> ·
  <a href="https://escalated.dev/docs"><b>Dokumentation</b></a> ·
  <a href="https://escalated.dev/cloud"><b>Cloud</b></a> ·
  <a href="../../CHANGELOG.md"><b>Änderungsprotokoll</b></a>
</p>

<br>

<img src="../../screenshots/results/ticket-list.png" alt="Die Ticketliste von Escalated in wp-admin mit Filtern für Status, Priorität, Abteilung und Agent" width="100%">

## Was es kann

<table>
  <tr>
    <td width="40%">
      <h3>Tickets, die deine Agenten in wp-admin bearbeiten</h3>
      Konversationen als Verlauf, interne Notizen, Textbausteine und Makros auf der Ticketseite. Tickets kommen auch per E-Mail über Mailgun, Postmark oder Amazon SES an.
    </td>
    <td width="60%">
      <img src="../../screenshots/results/ticket-detail.png" alt="Ein geöffnetes Ticket in wp-admin mit der Konversation und dem Antwortformular">
    </td>
  </tr>
  <tr>
    <td width="60%">
      <img src="../../screenshots/results/sla-policies.png" alt="Die Seite mit den SLA-Richtlinien und den Zielen für Erstreaktion und Lösung">
    </td>
    <td width="40%">
      <h3>SLAs und Eskalationsregeln, die von selbst laufen</h3>
      Ziele für Erstreaktion und Lösung, Regeln, die Tickets eskalieren, wenn ein Ziel verfehlt wird, und SLA-Prüfungen, die über WP-Cron geplant werden.
    </td>
  </tr>
</table>

## Funktionen

<table>
<tr>
<td width="33%" valign="top">

**Tickets**
- Konversationen als Verlauf und interne Notizen
- Aktivitätsverlauf an jedem Ticket
- Textbausteine, Makros und Tags
- Doppelte Tickets zusammenführen
- Anhänge mit Upload-Limits

</td>
<td width="33%" valign="top">

**SLAs und Routing**
- Routing und Zuweisung nach Abteilung
- Ziele für Erstreaktion und Lösung
- Eskalationsregeln und geplante SLA-Prüfungen
- Ereignisgesteuerte Workflows in wp-admin oder per REST

</td>
<td width="33%" valign="top">

**Kunden**
- Ticketseiten im Frontend über Shortcodes
- Tickets als Gast einreichen und sicherer Gastzugang
- Zufriedenheitsbewertungen

</td>
</tr>
<tr>
<td width="33%" valign="top">

**Rollen und Sicherheit**
- Rollen für Admin, Agent und Light-Agent
- Optionale Berechtigungen pro Route
- Optionale TOTP-Zwei-Faktor-Anmeldung für Mitarbeitende
- Anmeldung per SAML 2.0, OAuth 2 / OIDC oder JWT

</td>
<td width="33%" valign="top">

**E-Mail und Webhooks**
- Eingang über Mailgun, Postmark und Amazon SES
- Mehrere Webhook-Endpunkte mit eigenen Events
- Zustellprotokoll und Wiederholungen

</td>
<td width="33%" valign="top">

**API und Berichte**
- REST-API mit Bearer-Token und Berechtigungen pro Token
- Ratenbegrenzung pro Token
- Berichtsansichten

</td>
</tr>
</table>

<p align="center"><sub><a href="../features.md">Alle Funktionen im Detail</a></sub></p>

<p align="center">
  <a href="https://wordpress.org"><img src="https://img.shields.io/badge/WordPress-21759B?style=for-the-badge&logo=wordpress&logoColor=white" alt="WordPress"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP"></a>
  <a href="https://www.mysql.com"><img src="https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL"></a>
</p>

## Schnellstart

1. Lade [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip) aus dem neuesten Release herunter.
2. Entpacke es in dein Plugin-Verzeichnis, sodass das Plugin in `wp-content/plugins/escalated` liegt.
3. Aktiviere **Escalated** auf der Seite Plugins in WordPress.
4. Öffne **Escalated** in wp-admin und richte Abteilungen, SLA-Richtlinien, Eskalationsregeln und Einstellungen ein.

Voraussetzungen: WordPress 6.0+ und PHP 8.1+. Die [Installationsanleitung](../installation.md) enthält die Details, und unter [Anpassung](../customization.md) findest du die Shortcodes für deine Kundenseiten.

## Mehr erfahren

- [Alle Funktionen](../features.md) · [Installation](../installation.md) · [Screenshots](../screenshots.md)
- [Shortcodes, Ticket-Betreffs und Übersetzungen](../customization.md) · [REST-API und Zusammenführen von Tickets](../rest-api.md)
- [Zugriffskontrolle](../access-control.md) · [Ticketzugriff und Routing](../ticket-access-and-routing.md) · [Workflows](../workflows.md)
- [Eingehende E-Mails](../inbound-email.md) · [Webhooks](../webhooks.md) · [Gastzugang](../guest-access.md)
- [Widget einbetten](../embedding-the-widget.md) · [Adminbereich einbetten](../embedding-admin.md)
- [Geplante Aufgaben und Datenbankverbindung](../operations.md) · [Newsletter](../newsletters.md) · [Entwicklung](../development.md)

Escalated gibt es auch für [Laravel](https://github.com/escalated-dev/escalated-laravel), [Rails](https://github.com/escalated-dev/escalated-rails), [Django](https://github.com/escalated-dev/escalated-django), [NestJS](https://github.com/escalated-dev/escalated-nestjs), [AdonisJS](https://github.com/escalated-dev/escalated-adonis), [Symfony](https://github.com/escalated-dev/escalated-symfony), [Phoenix](https://github.com/escalated-dev/escalated-phoenix), [Go](https://github.com/escalated-dev/escalated-go), [.NET](https://github.com/escalated-dev/escalated-dotnet), [Spring](https://github.com/escalated-dev/escalated-spring) und [Filament](https://github.com/escalated-dev/escalated-filament). Die Oberfläche für die anderen Backends liegt in [escalated](https://github.com/escalated-dev/escalated).

## Lizenz

MIT. Copyright (c) 2026 Escalated.dev. Siehe [LICENSE](../../LICENSE).
