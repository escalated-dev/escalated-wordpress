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
  <b>Polski</b> ·
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
  Uruchom pełny helpdesk wewnątrz WordPressa.<br>
  Zgłoszenia, SLA, reguły eskalacji, poczta przychodząca i portal klienta, zarządzane z wp-admin bez zewnętrznych usług.
</p>

<p align="center">
  <a href="https://escalated.dev"><b>Strona</b></a> ·
  <a href="https://escalated.dev/docs"><b>Dokumentacja</b></a> ·
  <a href="https://escalated.dev/cloud"><b>Cloud</b></a> ·
  <a href="../../CHANGELOG.md"><b>Lista zmian</b></a>
</p>

<br>

<img src="../../screenshots/results/ticket-list.png" alt="Lista zgłoszeń Escalated w wp-admin z filtrami statusu, priorytetu, działu i agenta" width="100%">

## Co robi

<table>
  <tr>
    <td width="40%">
      <h3>Zgłoszenia, które Twoi agenci obsługują w wp-admin</h3>
      Wątki rozmów, notatki wewnętrzne, gotowe odpowiedzi i makra na ekranie zgłoszenia. Zgłoszenia przychodzą też e-mailem przez Mailgun, Postmark lub Amazon SES.
    </td>
    <td width="60%">
      <img src="../../screenshots/results/ticket-detail.png" alt="Zgłoszenie otwarte w wp-admin z rozmową i formularzem odpowiedzi">
    </td>
  </tr>
  <tr>
    <td width="60%">
      <img src="../../screenshots/results/sla-policies.png" alt="Ekran polityk SLA z celami pierwszej odpowiedzi i rozwiązania">
    </td>
    <td width="40%">
      <h3>SLA i reguły eskalacji, które działają same</h3>
      Cele pierwszej odpowiedzi i rozwiązania, reguły eskalujące zgłoszenia, gdy termin się przesuwa, oraz kontrole SLA planowane przez WP-Cron.
    </td>
  </tr>
</table>

## Funkcje

<table>
<tr>
<td width="33%" valign="top">

**Zgłoszenia**
- Wątki rozmów i notatki wewnętrzne
- Oś czasu aktywności w każdym zgłoszeniu
- Gotowe odpowiedzi, makra i tagi
- Scalanie zduplikowanych zgłoszeń
- Załączniki z limitami przesyłania

</td>
<td width="33%" valign="top">

**SLA i routing**
- Routing i przypisywanie według działów
- Cele pierwszej odpowiedzi i rozwiązania
- Reguły eskalacji i zaplanowane kontrole SLA
- Workflowy sterowane zdarzeniami w wp-admin lub przez REST

</td>
<td width="33%" valign="top">

**Klienci**
- Strony zgłoszeń we frontendzie przez shortcode'y
- Zgłoszenia od gości i bezpieczny dostęp dla gości
- Oceny zadowolenia

</td>
</tr>
<tr>
<td width="33%" valign="top">

**Role i bezpieczeństwo**
- Role administratora, agenta i agenta z ograniczonym dostępem
- Opcjonalne uprawnienia dla poszczególnych tras
- Opcjonalne uwierzytelnianie dwuskładnikowe TOTP dla zespołu
- Logowanie przez SAML 2.0, OAuth 2 / OIDC lub JWT

</td>
<td width="33%" valign="top">

**E-mail i webhooki**
- Poczta przychodząca z Mailgun, Postmark i Amazon SES
- Wiele endpointów webhooków z własnymi zdarzeniami
- Dziennik dostarczeń i ponowne próby

</td>
<td width="33%" valign="top">

**API i raporty**
- REST API z tokenami Bearer i uprawnieniami tokenów
- Limity żądań dla każdego tokena
- Widoki raportów

</td>
</tr>
</table>

<p align="center"><sub><a href="../features.md">Wszystkie funkcje w szczegółach</a></sub></p>

<p align="center">
  <a href="https://wordpress.org"><img src="https://img.shields.io/badge/WordPress-21759B?style=for-the-badge&logo=wordpress&logoColor=white" alt="WordPress"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP"></a>
  <a href="https://www.mysql.com"><img src="https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL"></a>
</p>

## Szybki start

1. Pobierz [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip) z najnowszego wydania.
2. Rozpakuj go do katalogu wtyczek, tak aby wtyczka znalazła się w `wp-content/plugins/escalated`.
3. Włącz **Escalated** na ekranie Wtyczki w WordPressie.
4. Przejdź do **Escalated** w wp-admin i skonfiguruj działy, polityki SLA, reguły eskalacji i ustawienia.

Wymaga WordPressa 6.0+ i PHP 8.1+. Szczegóły zawiera [przewodnik instalacji](../installation.md), a [dostosowywanie](../customization.md) opisuje shortcode'y dla stron przeznaczonych dla klientów.

## Dowiedz się więcej

- [Wszystkie funkcje](../features.md) · [Instalacja](../installation.md) · [Zrzuty ekranu](../screenshots.md)
- [Shortcode'y, tematy zgłoszeń i tłumaczenia](../customization.md) · [REST API i scalanie zgłoszeń](../rest-api.md)
- [Kontrola dostępu](../access-control.md) · [Dostęp do zgłoszeń i routing](../ticket-access-and-routing.md) · [Workflowy](../workflows.md)
- [Poczta przychodząca](../inbound-email.md) · [Webhooki](../webhooks.md) · [Dostęp dla gości](../guest-access.md)
- [Osadzanie widżetu](../embedding-the-widget.md) · [Osadzanie panelu administracyjnego](../embedding-admin.md)
- [Zadania zaplanowane i połączenie z bazą danych](../operations.md) · [Newslettery](../newsletters.md) · [Rozwój](../development.md)

Escalated jest dostępny również dla [Laravel](https://github.com/escalated-dev/escalated-laravel), [Rails](https://github.com/escalated-dev/escalated-rails), [Django](https://github.com/escalated-dev/escalated-django), [NestJS](https://github.com/escalated-dev/escalated-nestjs), [AdonisJS](https://github.com/escalated-dev/escalated-adonis), [Symfony](https://github.com/escalated-dev/escalated-symfony), [Phoenix](https://github.com/escalated-dev/escalated-phoenix), [Go](https://github.com/escalated-dev/escalated-go), [.NET](https://github.com/escalated-dev/escalated-dotnet), [Spring](https://github.com/escalated-dev/escalated-spring) i [Filament](https://github.com/escalated-dev/escalated-filament). Interfejs dla pozostałych backendów znajduje się w [escalated](https://github.com/escalated-dev/escalated).

## Licencja

MIT. Copyright (c) 2026 Escalated.dev. Zobacz [LICENSE](../../LICENSE).
