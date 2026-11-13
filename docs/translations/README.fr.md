<p align="center"><sub>
  <a href="README.ar.md">العربية</a> ·
  <a href="README.de.md">Deutsch</a> ·
  <a href="../../README.md">English</a> ·
  <a href="README.es.md">Español</a> ·
  <b>Français</b> ·
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
  Faites tourner un helpdesk complet dans WordPress.<br>
  Tickets, SLA, règles d'escalade, e-mails entrants et portail client, gérés depuis wp-admin sans service externe.
</p>

<p align="center">
  <a href="https://escalated.dev"><b>Site web</b></a> ·
  <a href="https://escalated.dev/docs"><b>Documentation</b></a> ·
  <a href="https://escalated.dev/cloud"><b>Cloud</b></a> ·
  <a href="../../CHANGELOG.md"><b>Journal des modifications</b></a>
</p>

<br>

<img src="../../screenshots/results/ticket-list.png" alt="La liste des tickets d'Escalated dans wp-admin avec des filtres par statut, priorité, département et agent" width="100%">

## Ce qu'il fait

<table>
  <tr>
    <td width="40%">
      <h3>Des tickets que vos agents traitent dans wp-admin</h3>
      Conversations en fil, notes internes, réponses prédéfinies et macros sur l'écran du ticket. Les tickets arrivent aussi par e-mail via Mailgun, Postmark ou Amazon SES.
    </td>
    <td width="60%">
      <img src="../../screenshots/results/ticket-detail.png" alt="Un ticket ouvert dans wp-admin avec la conversation et le formulaire de réponse">
    </td>
  </tr>
  <tr>
    <td width="60%">
      <img src="../../screenshots/results/sla-policies.png" alt="L'écran des politiques SLA avec les objectifs de première réponse et de résolution">
    </td>
    <td width="40%">
      <h3>Des SLA et des règles d'escalade qui tournent tout seuls</h3>
      Des objectifs de première réponse et de résolution, des règles qui escaladent les tickets quand un objectif glisse, et des vérifications SLA planifiées via WP-Cron.
    </td>
  </tr>
</table>

## Fonctionnalités

<table>
<tr>
<td width="33%" valign="top">

**Tickets**
- Conversations en fil et notes internes
- Historique d'activité sur chaque ticket
- Réponses prédéfinies, macros et tags
- Fusion des tickets en double
- Pièces jointes avec limites de téléversement

</td>
<td width="33%" valign="top">

**SLA et routage**
- Routage et attribution par département
- Objectifs de première réponse et de résolution
- Règles d'escalade et vérifications SLA planifiées
- Workflows déclenchés par événement dans wp-admin ou via REST

</td>
<td width="33%" valign="top">

**Clients**
- Pages de tickets côté site via des shortcodes
- Soumission en invité et accès invité sécurisé
- Notes de satisfaction

</td>
</tr>
<tr>
<td width="33%" valign="top">

**Rôles et sécurité**
- Rôles administrateur, agent et agent léger
- Permissions par route en option
- Double authentification TOTP en option pour l'équipe
- Connexion via SAML 2.0, OAuth 2 / OIDC ou JWT

</td>
<td width="33%" valign="top">

**E-mail et webhooks**
- Réception depuis Mailgun, Postmark et Amazon SES
- Plusieurs endpoints de webhook, chacun avec ses propres événements
- Journal de livraison et nouvelles tentatives

</td>
<td width="33%" valign="top">

**API et rapports**
- API REST avec jetons Bearer et permissions par jeton
- Limitation de débit par jeton
- Vues de rapports

</td>
</tr>
</table>

<p align="center"><sub><a href="../features.md">Toutes les fonctionnalités en détail</a></sub></p>

<p align="center">
  <a href="https://wordpress.org"><img src="https://img.shields.io/badge/WordPress-21759B?style=for-the-badge&logo=wordpress&logoColor=white" alt="WordPress"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP"></a>
  <a href="https://www.mysql.com"><img src="https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL"></a>
</p>

## Démarrage rapide

1. Téléchargez [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip) depuis la dernière version.
2. Décompressez-le dans votre dossier d'extensions, pour que l'extension se trouve dans `wp-content/plugins/escalated`.
3. Activez **Escalated** depuis l'écran Extensions de WordPress.
4. Ouvrez **Escalated** dans wp-admin et configurez les départements, les politiques SLA, les règles d'escalade et les réglages.

Nécessite WordPress 6.0+ et PHP 8.1+. Le [guide d'installation](../installation.md) donne les détails, et la page [personnalisation](../customization.md) liste les shortcodes pour vos pages destinées aux clients.

## En savoir plus

- [Toutes les fonctionnalités](../features.md) · [Installation](../installation.md) · [Captures d'écran](../screenshots.md)
- [Shortcodes, sujets de tickets et traductions](../customization.md) · [API REST et fusion de tickets](../rest-api.md)
- [Contrôle d'accès](../access-control.md) · [Accès aux tickets et routage](../ticket-access-and-routing.md) · [Workflows](../workflows.md)
- [E-mails entrants](../inbound-email.md) · [Webhooks](../webhooks.md) · [Accès invité](../guest-access.md)
- [Intégrer le widget](../embedding-the-widget.md) · [Intégrer l'administration](../embedding-admin.md)
- [Tâches planifiées et connexion à la base de données](../operations.md) · [Newsletters](../newsletters.md) · [Développement](../development.md)

Escalated est aussi disponible pour [Laravel](https://github.com/escalated-dev/escalated-laravel), [Rails](https://github.com/escalated-dev/escalated-rails), [Django](https://github.com/escalated-dev/escalated-django), [NestJS](https://github.com/escalated-dev/escalated-nestjs), [AdonisJS](https://github.com/escalated-dev/escalated-adonis), [Symfony](https://github.com/escalated-dev/escalated-symfony), [Phoenix](https://github.com/escalated-dev/escalated-phoenix), [Go](https://github.com/escalated-dev/escalated-go), [.NET](https://github.com/escalated-dev/escalated-dotnet), [Spring](https://github.com/escalated-dev/escalated-spring) et [Filament](https://github.com/escalated-dev/escalated-filament). L'interface des autres backends se trouve dans [escalated](https://github.com/escalated-dev/escalated).

## Licence

MIT. Copyright (c) 2026 Escalated.dev. Voir [LICENSE](../../LICENSE).
