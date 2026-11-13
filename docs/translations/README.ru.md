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
  <a href="README.pt-BR.md">Português (BR)</a> ·
  <b>Русский</b> ·
  <a href="README.tr.md">Türkçe</a> ·
  <a href="README.zh-CN.md">简体中文</a>
</sub></p>

<p align="center">
  <a href="https://escalated.dev"><img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/logo.png" width="72" height="72" alt="Escalated"></a>
</p>

<h1 align="center">Escalated for WordPress</h1>

<p align="center">
  Полноценный хелпдеск прямо внутри WordPress.<br>
  Тикеты, SLA, правила эскалации, входящая почта и клиентский портал, управляемые из wp-admin без внешних сервисов.
</p>

<p align="center">
  <a href="https://escalated.dev"><b>Сайт</b></a> ·
  <a href="https://escalated.dev/docs"><b>Документация</b></a> ·
  <a href="https://escalated.dev/cloud"><b>Cloud</b></a> ·
  <a href="../../CHANGELOG.md"><b>Список изменений</b></a>
</p>

<br>

<img src="../../screenshots/results/ticket-list.png" alt="Список тикетов Escalated в wp-admin с фильтрами по статусу, приоритету, отделу и агенту" width="100%">

## Что он делает

<table>
  <tr>
    <td width="40%">
      <h3>Тикеты, которые агенты обрабатывают в wp-admin</h3>
      Переписка в виде цепочек, внутренние заметки, шаблоны ответов и макросы на экране тикета. Тикеты также приходят по электронной почте через Mailgun, Postmark или Amazon SES.
    </td>
    <td width="60%">
      <img src="../../screenshots/results/ticket-detail.png" alt="Тикет, открытый в wp-admin, с перепиской и формой ответа">
    </td>
  </tr>
  <tr>
    <td width="60%">
      <img src="../../screenshots/results/sla-policies.png" alt="Экран политик SLA с целями по первому ответу и решению">
    </td>
    <td width="40%">
      <h3>SLA и правила эскалации, которые работают сами</h3>
      Цели по первому ответу и решению, правила, которые эскалируют тикеты при отставании, и проверки SLA по расписанию через WP-Cron.
    </td>
  </tr>
</table>

## Возможности

<table>
<tr>
<td width="33%" valign="top">

**Тикеты**
- Переписка цепочками и внутренние заметки
- Хронология действий в каждом тикете
- Шаблоны ответов, макросы и теги
- Объединение дублирующихся тикетов
- Вложения с ограничениями на загрузку

</td>
<td width="33%" valign="top">

**SLA и маршрутизация**
- Маршрутизация и назначение по отделам
- Цели по первому ответу и решению
- Правила эскалации и проверки SLA по расписанию
- Рабочие процессы на событиях в wp-admin или через REST

</td>
<td width="33%" valign="top">

**Клиенты**
- Страницы тикетов на сайте через шорткоды
- Отправка тикетов гостями и защищённый гостевой доступ
- Оценки удовлетворённости

</td>
</tr>
<tr>
<td width="33%" valign="top">

**Роли и безопасность**
- Роли администратора, агента и облегчённого агента
- Необязательные права доступа для отдельных маршрутов
- Необязательная двухфакторная аутентификация TOTP для сотрудников
- Вход через SAML 2.0, OAuth 2 / OIDC или JWT

</td>
<td width="33%" valign="top">

**Почта и вебхуки**
- Входящая почта из Mailgun, Postmark и Amazon SES
- Несколько эндпоинтов вебхуков со своими событиями
- Журнал доставки и повторные попытки

</td>
<td width="33%" valign="top">

**API и отчёты**
- REST API с Bearer-токенами и правами для токенов
- Ограничение частоты запросов для каждого токена
- Отчёты

</td>
</tr>
</table>

<p align="center"><sub><a href="../features.md">Все возможности подробно</a></sub></p>

<p align="center">
  <a href="https://wordpress.org"><img src="https://img.shields.io/badge/WordPress-21759B?style=for-the-badge&logo=wordpress&logoColor=white" alt="WordPress"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP"></a>
  <a href="https://www.mysql.com"><img src="https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL"></a>
</p>

## Быстрый старт

1. Скачайте [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip) из последнего релиза.
2. Распакуйте его в каталог плагинов, чтобы плагин оказался в `wp-content/plugins/escalated`.
3. Активируйте **Escalated** на экране «Плагины» в WordPress.
4. Откройте **Escalated** в wp-admin и настройте отделы, политики SLA, правила эскалации и параметры.

Нужны WordPress 6.0+ и PHP 8.1+. Подробности в [руководстве по установке](../installation.md), а в разделе [кастомизация](../customization.md) перечислены шорткоды для страниц, которые видят клиенты.

## Подробнее

- [Все возможности](../features.md) · [Установка](../installation.md) · [Скриншоты](../screenshots.md)
- [Шорткоды, темы тикетов и переводы](../customization.md) · [REST API и объединение тикетов](../rest-api.md)
- [Управление доступом](../access-control.md) · [Доступ к тикетам и маршрутизация](../ticket-access-and-routing.md) · [Рабочие процессы](../workflows.md)
- [Входящая почта](../inbound-email.md) · [Вебхуки](../webhooks.md) · [Гостевой доступ](../guest-access.md)
- [Встраивание виджета](../embedding-the-widget.md) · [Встраивание админки](../embedding-admin.md)
- [Задачи по расписанию и подключение к базе данных](../operations.md) · [Рассылки](../newsletters.md) · [Разработка](../development.md)

Escalated также доступен для [Laravel](https://github.com/escalated-dev/escalated-laravel), [Rails](https://github.com/escalated-dev/escalated-rails), [Django](https://github.com/escalated-dev/escalated-django), [NestJS](https://github.com/escalated-dev/escalated-nestjs), [AdonisJS](https://github.com/escalated-dev/escalated-adonis), [Symfony](https://github.com/escalated-dev/escalated-symfony), [Phoenix](https://github.com/escalated-dev/escalated-phoenix), [Go](https://github.com/escalated-dev/escalated-go), [.NET](https://github.com/escalated-dev/escalated-dotnet), [Spring](https://github.com/escalated-dev/escalated-spring) и [Filament](https://github.com/escalated-dev/escalated-filament). Интерфейс для остальных бэкендов находится в [escalated](https://github.com/escalated-dev/escalated).

## Лицензия

MIT. Copyright (c) 2026 Escalated.dev. См. [LICENSE](../../LICENSE).
