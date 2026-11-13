<p align="center"><sub>
  <a href="README.ar.md">العربية</a> ·
  <a href="README.de.md">Deutsch</a> ·
  <a href="../../README.md">English</a> ·
  <b>Español</b> ·
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
  Ejecuta un helpdesk completo dentro de WordPress.<br>
  Tickets, SLA, reglas de escalado, correo entrante y un portal de clientes, gestionados desde wp-admin sin servicios externos.
</p>

<p align="center">
  <a href="https://escalated.dev"><b>Sitio web</b></a> ·
  <a href="https://escalated.dev/docs"><b>Documentación</b></a> ·
  <a href="https://escalated.dev/cloud"><b>Cloud</b></a> ·
  <a href="../../CHANGELOG.md"><b>Registro de cambios</b></a>
</p>

<br>

<img src="../../screenshots/results/ticket-list.png" alt="La lista de tickets de Escalated en wp-admin con filtros por estado, prioridad, departamento y agente" width="100%">

## Qué hace

<table>
  <tr>
    <td width="40%">
      <h3>Tickets que tus agentes gestionan en wp-admin</h3>
      Conversaciones en hilo, notas internas, respuestas predefinidas y macros en la pantalla del ticket. Los tickets también llegan por correo electrónico a través de Mailgun, Postmark o Amazon SES.
    </td>
    <td width="60%">
      <img src="../../screenshots/results/ticket-detail.png" alt="Un ticket abierto en wp-admin con la conversación y el formulario de respuesta">
    </td>
  </tr>
  <tr>
    <td width="60%">
      <img src="../../screenshots/results/sla-policies.png" alt="La pantalla de políticas de SLA con objetivos de primera respuesta y de resolución">
    </td>
    <td width="40%">
      <h3>SLA y reglas de escalado que funcionan solos</h3>
      Objetivos de primera respuesta y de resolución, reglas que escalan los tickets cuando no se cumplen y comprobaciones de SLA programadas con WP-Cron.
    </td>
  </tr>
</table>

## Funciones

<table>
<tr>
<td width="33%" valign="top">

**Tickets**
- Conversaciones en hilo y notas internas
- Cronología de actividad en cada ticket
- Respuestas predefinidas, macros y etiquetas
- Fusión de tickets duplicados
- Adjuntos con límites de subida

</td>
<td width="33%" valign="top">

**SLA y enrutamiento**
- Enrutamiento y asignación por departamento
- Objetivos de primera respuesta y de resolución
- Reglas de escalado y comprobaciones de SLA programadas
- Workflows basados en eventos en wp-admin o por REST

</td>
<td width="33%" valign="top">

**Clientes**
- Páginas de tickets en el frontend mediante shortcodes
- Envío como invitado y acceso seguro para invitados
- Valoraciones de satisfacción

</td>
</tr>
<tr>
<td width="33%" valign="top">

**Roles y seguridad**
- Roles de administrador, agente y agente ligero
- Permisos opcionales por ruta
- Verificación en dos pasos TOTP opcional para el personal
- Inicio de sesión con SAML 2.0, OAuth 2 / OIDC o JWT

</td>
<td width="33%" valign="top">

**Correo y webhooks**
- Entrada desde Mailgun, Postmark y Amazon SES
- Varios endpoints de webhook, cada uno con sus propios eventos
- Registro de entregas y reintentos

</td>
<td width="33%" valign="top">

**API e informes**
- API REST con tokens Bearer y permisos por token
- Límite de solicitudes por token
- Vistas de informes

</td>
</tr>
</table>

<p align="center"><sub><a href="../features.md">Todas las funciones en detalle</a></sub></p>

<p align="center">
  <a href="https://wordpress.org"><img src="https://img.shields.io/badge/WordPress-21759B?style=for-the-badge&logo=wordpress&logoColor=white" alt="WordPress"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP"></a>
  <a href="https://www.mysql.com"><img src="https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL"></a>
</p>

## Inicio rápido

1. Descarga [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip) de la última versión.
2. Descomprímelo en tu directorio de plugins, de modo que el plugin quede en `wp-content/plugins/escalated`.
3. Activa **Escalated** en la pantalla Plugins de WordPress.
4. Ve a **Escalated** en wp-admin y configura departamentos, políticas de SLA, reglas de escalado y ajustes.

Requiere WordPress 6.0+ y PHP 8.1+. La [guía de instalación](../installation.md) tiene los detalles y [personalización](../customization.md) enumera los shortcodes para tus páginas de cara al cliente.

## Más información

- [Todas las funciones](../features.md) · [Instalación](../installation.md) · [Capturas de pantalla](../screenshots.md)
- [Shortcodes, asuntos de tickets y traducciones](../customization.md) · [API REST y fusión de tickets](../rest-api.md)
- [Control de acceso](../access-control.md) · [Acceso a tickets y enrutamiento](../ticket-access-and-routing.md) · [Workflows](../workflows.md)
- [Correo entrante](../inbound-email.md) · [Webhooks](../webhooks.md) · [Acceso de invitados](../guest-access.md)
- [Insertar el widget](../embedding-the-widget.md) · [Insertar el panel de administración](../embedding-admin.md)
- [Tareas programadas y conexión a la base de datos](../operations.md) · [Boletines](../newsletters.md) · [Desarrollo](../development.md)

Escalated también está disponible para [Laravel](https://github.com/escalated-dev/escalated-laravel), [Rails](https://github.com/escalated-dev/escalated-rails), [Django](https://github.com/escalated-dev/escalated-django), [NestJS](https://github.com/escalated-dev/escalated-nestjs), [AdonisJS](https://github.com/escalated-dev/escalated-adonis), [Symfony](https://github.com/escalated-dev/escalated-symfony), [Phoenix](https://github.com/escalated-dev/escalated-phoenix), [Go](https://github.com/escalated-dev/escalated-go), [.NET](https://github.com/escalated-dev/escalated-dotnet), [Spring](https://github.com/escalated-dev/escalated-spring) y [Filament](https://github.com/escalated-dev/escalated-filament). La interfaz para los demás backends está en [escalated](https://github.com/escalated-dev/escalated).

## Licencia

MIT. Copyright (c) 2026 Escalated.dev. Consulta [LICENSE](../../LICENSE).
