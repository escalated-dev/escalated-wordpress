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
  <a href="README.ru.md">Русский</a> ·
  <a href="README.tr.md">Türkçe</a> ·
  <b>简体中文</b>
</sub></p>

<p align="center">
  <a href="https://escalated.dev"><img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/logo.png" width="72" height="72" alt="Escalated"></a>
</p>

<h1 align="center">Escalated for WordPress</h1>

<p align="center">
  在 WordPress 内运行完整的帮助台。<br>
  工单、SLA、升级规则、入站邮件和客户门户，都在 wp-admin 中管理，无需外部服务。
</p>

<p align="center">
  <a href="https://escalated.dev"><b>官网</b></a> ·
  <a href="https://escalated.dev/docs"><b>文档</b></a> ·
  <a href="https://escalated.dev/cloud"><b>Cloud</b></a> ·
  <a href="../../CHANGELOG.md"><b>更新日志</b></a>
</p>

<br>

<img src="../../screenshots/results/ticket-list.png" alt="wp-admin 中的 Escalated 工单列表，可按状态、优先级、部门和客服筛选" width="100%">

## 功能

<table>
  <tr>
    <td width="40%">
      <h3>客服在 wp-admin 中处理的工单</h3>
      工单页面上提供线程式对话、内部备注、预设回复和宏。工单也可以通过 Mailgun、Postmark 或 Amazon SES 以邮件形式进入。
    </td>
    <td width="60%">
      <img src="../../screenshots/results/ticket-detail.png" alt="在 wp-admin 中打开的工单，显示对话和回复表单">
    </td>
  </tr>
  <tr>
    <td width="60%">
      <img src="../../screenshots/results/sla-policies.png" alt="SLA 策略页面，包含首次响应和解决目标">
    </td>
    <td width="40%">
      <h3>自动运行的 SLA 和升级规则</h3>
      首次响应和解决目标、在目标延误时升级工单的规则，以及通过 WP-Cron 定时执行的 SLA 检查。
    </td>
  </tr>
</table>

## 功能一览

<table>
<tr>
<td width="33%" valign="top">

**工单**
- 线程式对话和内部备注
- 每个工单的活动时间线
- 预设回复、宏和标签
- 合并重复工单
- 带上传限制的附件

</td>
<td width="33%" valign="top">

**SLA 与路由**
- 按部门路由和分配
- 首次响应和解决目标
- 升级规则和定时 SLA 检查
- 在 wp-admin 或通过 REST 配置的事件驱动工作流

</td>
<td width="33%" valign="top">

**客户**
- 通过短代码提供前台工单页面
- 访客提交和安全的访客访问
- 满意度评分

</td>
</tr>
<tr>
<td width="33%" valign="top">

**角色与安全**
- 管理员、客服和轻量客服角色
- 可选的按路由权限
- 可选的员工 TOTP 双重验证
- 通过 SAML 2.0、OAuth 2 / OIDC 或 JWT 登录

</td>
<td width="33%" valign="top">

**邮件与 Webhook**
- 从 Mailgun、Postmark 和 Amazon SES 接收邮件
- 多个 Webhook 端点，各自订阅事件
- 投递日志和重试

</td>
<td width="33%" valign="top">

**API 与报表**
- 带权限范围的 Bearer 令牌 REST API
- 按令牌限流
- 报表视图

</td>
</tr>
</table>

<p align="center"><sub><a href="../features.md">查看全部功能详情</a></sub></p>

<p align="center">
  <a href="https://wordpress.org"><img src="https://img.shields.io/badge/WordPress-21759B?style=for-the-badge&logo=wordpress&logoColor=white" alt="WordPress"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP"></a>
  <a href="https://www.mysql.com"><img src="https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL"></a>
</p>

## 快速开始

1. 从最新发布版本下载 [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip)。
2. 将其解压到插件目录，使插件位于 `wp-content/plugins/escalated`。
3. 在 WordPress 插件页面启用 **Escalated**。
4. 进入 wp-admin 中的 **Escalated**，设置部门、SLA 策略、升级规则和其他设置。

需要 WordPress 6.0+ 和 PHP 8.1+。详细步骤见[安装指南](../installation.md)，面向客户页面的短代码列在[自定义](../customization.md)中。

## 了解更多

- [全部功能](../features.md) · [安装](../installation.md) · [截图](../screenshots.md)
- [短代码、工单主题和翻译](../customization.md) · [REST API 与工单合并](../rest-api.md)
- [访问控制](../access-control.md) · [工单访问与路由](../ticket-access-and-routing.md) · [工作流](../workflows.md)
- [入站邮件](../inbound-email.md) · [Webhook](../webhooks.md) · [访客访问](../guest-access.md)
- [嵌入小部件](../embedding-the-widget.md) · [嵌入管理后台](../embedding-admin.md)
- [计划任务与数据库连接](../operations.md) · [邮件通讯](../newsletters.md) · [开发](../development.md)

Escalated 也提供 [Laravel](https://github.com/escalated-dev/escalated-laravel)、[Rails](https://github.com/escalated-dev/escalated-rails)、[Django](https://github.com/escalated-dev/escalated-django)、[NestJS](https://github.com/escalated-dev/escalated-nestjs)、[AdonisJS](https://github.com/escalated-dev/escalated-adonis)、[Symfony](https://github.com/escalated-dev/escalated-symfony)、[Phoenix](https://github.com/escalated-dev/escalated-phoenix)、[Go](https://github.com/escalated-dev/escalated-go)、[.NET](https://github.com/escalated-dev/escalated-dotnet)、[Spring](https://github.com/escalated-dev/escalated-spring) 和 [Filament](https://github.com/escalated-dev/escalated-filament) 版本。其他后端使用的界面代码位于 [escalated](https://github.com/escalated-dev/escalated)。

## 许可证

MIT。Copyright (c) 2026 Escalated.dev。详见 [LICENSE](../../LICENSE)。
