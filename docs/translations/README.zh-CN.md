<p align="center">
  <a href="README.ar.md">العربية</a> •
  <a href="README.de.md">Deutsch</a> •
  <a href="../../README.md">English</a> •
  <a href="README.es.md">Español</a> •
  <a href="README.fr.md">Français</a> •
  <a href="README.it.md">Italiano</a> •
  <a href="README.ja.md">日本語</a> •
  <a href="README.ko.md">한국어</a> •
  <a href="README.nl.md">Nederlands</a> •
  <a href="README.pl.md">Polski</a> •
  <a href="README.pt-BR.md">Português (BR)</a> •
  <a href="README.ru.md">Русский</a> •
  <a href="README.tr.md">Türkçe</a> •
  <b>简体中文</b>
</p>

# Escalated for WordPress

[![Tests](https://github.com/escalated-dev/escalated-wordpress/actions/workflows/run-tests.yml/badge.svg)](https://github.com/escalated-dev/escalated-wordpress/actions/workflows/run-tests.yml)
[![Latest Release](https://img.shields.io/github/v/release/escalated-dev/escalated-wordpress)](https://github.com/escalated-dev/escalated-wordpress/releases/latest)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/wordpress-%3E%3D6.0-21759B)](https://wordpress.org/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

WordPress 全功能帮助台和工单系统，支持多角色、SLA 跟踪、升级规则、入站邮件处理、宏和 REST API。无需外部服务。

> **[escalated.dev](https://escalated.dev)** — 了解更多、查看演示并比较 Cloud 与自托管选项。

## Screenshots

| 工单列表 | 工单详情 |
|:-----------:|:-------------:|
| ![工单列表](screenshots/results/ticket-list.png) | ![工单详情](screenshots/results/ticket-detail.png) |

| 部门 | SLA 策略 |
|:-----------:|:------------:|
| ![部门](screenshots/results/departments.png) | ![SLA 策略](screenshots/results/sla-policies.png) |

| 报告 | 设置 |
|:-------:|:--------:|
| ![报告](screenshots/results/reports.png) | ![设置](screenshots/results/settings.png) |

| 自动化 | 宏 |
|:-----------:|:------:|
| ![自动化](screenshots/results/automations.png) | ![宏](screenshots/results/macros.png) |

> 截图在每次发布时通过 Playwright 自动生成。参见 `.github/workflows/screenshots.yml`。

## Download

[![Download Latest Release](https://img.shields.io/badge/Download-Latest%20Release-2ea44f?style=for-the-badge&logo=github)](https://github.com/escalated-dev/escalated-wordpress/releases/latest)

- 最新插件包：[escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip)
- 所有版本：[Releases](https://github.com/escalated-dev/escalated-wordpress/releases)

## 功能特性

- 支持线程对话、内部备注和活动时间线的工单管理。
- 自定义支持角色：`escalated_admin` 和 `escalated_agent`。
- 基于部门的路由和分配工作流。
- 具有首次响应和解决目标的 SLA 策略。
- 自动化升级规则和定时 SLA 检查。
- 通过短代码提供面向客户的前端工单页面。
- 访客工单提交和安全的访客工单访问。
- 通过 Mailgun、Postmark 和 Amazon SES webhook 接收入站邮件。
- 预设回复、宏和标签管理。
- 具有按令牌权限和速率限制的 Bearer 令牌 REST API。
- 支持附件，可配置上传限制。
- 满意度评分和报告视图。

## 系统要求

- WordPress `6.0+`
- PHP `8.1+`

## 安装

1. 将此插件放置在 WordPress 插件目录中：
   - `wp-content/plugins/escalated`
2. 从 WordPress 插件界面激活 **Escalated**。
3. 在 wp-admin 中进入 **Escalated** 并配置：
   - 部门
   - SLA 策略
   - 升级规则
   - 设置

## 前端短代码

在 WordPress 页面中使用这些短代码：

- `[escalated_tickets]` - 已登录请求者的工单列表。
- `[escalated_create_ticket]` - 已登录请求者的新工单表单。
- `[escalated_view_ticket]` - 工单详情视图：
  - 已登录用户：需要 `?ticket=ESC-123`
  - 访客：需要 `?guest_token=<token>`
- `[escalated_guest_create]` - 访客工单创建表单（如果在设置中启用）。

## REST API

- 命名空间：`/wp-json/escalated/v1`
- 认证：`Authorization: Bearer <api-token>`
- 默认速率限制：每个令牌 `60` 次请求/分钟（可通过 `api_rate_limit` 设置配置）

主要路由组：

- `/auth/validate`
- `/tickets`
- `/departments`
- `/tags`
- `/canned-responses`
- `/macros`
- `/agents`
- `/dashboard`
- `/admin/api-tokens`

## 入站邮件 Webhook

入站路由模式：

- `POST /wp-json/escalated/v1/inbound/{adapter}`

支持的适配器：

- `mailgun`
- `postmark`
- `ses`

## 定时任务 (WP-Cron)

激活时，Escalated 会安排以下任务：

- `escalated_check_sla`（每分钟）
- `escalated_evaluate_escalations`（每 5 分钟）
- `escalated_auto_close`（每天）
- `escalated_purge_activities`（每周）

## 开发

安装依赖：

```bash
composer install
```

运行测试（需要 WordPress 测试套件）：

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```

如果需要，在运行 PHPUnit 之前将 `WP_TESTS_DIR` 设置为本地 WordPress 测试库路径。

## 其他框架版本

- **[Escalated for Laravel](https://github.com/escalated-dev/escalated-laravel)** — Laravel Composer 包
- **[Escalated for Rails](https://github.com/escalated-dev/escalated-rails)** — Ruby on Rails Engine
- **[Escalated for Django](https://github.com/escalated-dev/escalated-django)** — Django 可复用应用
- **[Escalated for AdonisJS](https://github.com/escalated-dev/escalated-adonis)** — AdonisJS v6 包
- **[Escalated for Filament](https://github.com/escalated-dev/escalated-filament)** — Filament v3 管理面板插件
- **[Escalated for WordPress](https://github.com/escalated-dev/escalated-wordpress)** — WordPress 插件（当前页面）
- **[Shared Frontend](https://github.com/escalated-dev/escalated)** — Vue 3 + Inertia.js UI 组件

## 许可证

MIT
