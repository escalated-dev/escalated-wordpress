<p align="center">
  <b>العربية</b> •
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
  <a href="README.zh-CN.md">简体中文</a>
</p>

# Escalated for WordPress

[![Tests](https://github.com/escalated-dev/escalated-wordpress/actions/workflows/run-tests.yml/badge.svg)](https://github.com/escalated-dev/escalated-wordpress/actions/workflows/run-tests.yml)
[![Latest Release](https://img.shields.io/github/v/release/escalated-dev/escalated-wordpress)](https://github.com/escalated-dev/escalated-wordpress/releases/latest)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/wordpress-%3E%3D6.0-21759B)](https://wordpress.org/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

نظام مكتب مساعدة وتذاكر متكامل لـ WordPress مع دعم أدوار متعددة، تتبع SLA، قواعد التصعيد، معالجة البريد الوارد، الماكرو، وREST API. لا يتطلب خدمات خارجية.

> **[escalated.dev](https://escalated.dev)** — تعرف على المزيد، شاهد العروض التوضيحية، وقارن بين خيارات Cloud والاستضافة الذاتية.

## Screenshots

| قائمة التذاكر | تفاصيل التذكرة |
|:-----------:|:-------------:|
| ![قائمة التذاكر](screenshots/results/ticket-list.png) | ![تفاصيل التذكرة](screenshots/results/ticket-detail.png) |

| الأقسام | سياسات SLA |
|:-----------:|:------------:|
| ![الأقسام](screenshots/results/departments.png) | ![سياسات SLA](screenshots/results/sla-policies.png) |

| التقارير | الإعدادات |
|:-------:|:--------:|
| ![التقارير](screenshots/results/reports.png) | ![الإعدادات](screenshots/results/settings.png) |

| الأتمتة | الماكرو |
|:-----------:|:------:|
| ![الأتمتة](screenshots/results/automations.png) | ![الماكرو](screenshots/results/macros.png) |

> يتم إنشاء لقطات الشاشة تلقائياً عبر Playwright مع كل إصدار. راجع `.github/workflows/screenshots.yml`.

## Download

[![Download Latest Release](https://img.shields.io/badge/Download-Latest%20Release-2ea44f?style=for-the-badge&logo=github)](https://github.com/escalated-dev/escalated-wordpress/releases/latest)

- أحدث حزمة إضافة: [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip)
- جميع الإصدارات: [Releases](https://github.com/escalated-dev/escalated-wordpress/releases)

## الميزات

- إدارة التذاكر مع محادثات متسلسلة وملاحظات داخلية وجدول زمني للنشاط.
- أدوار دعم مخصصة: `escalated_admin` و `escalated_agent`.
- توجيه قائم على الأقسام وسير عمل التعيين.
- سياسات SLA مع أهداف الاستجابة الأولى والحل.
- قواعد تصعيد آلية وفحوصات SLA مجدولة.
- صفحات تذاكر أمامية للعملاء عبر الأكواد المختصرة.
- إرسال تذاكر الضيوف والوصول الآمن لتذاكر الضيوف.
- استقبال البريد الوارد عبر webhooks من Mailgun وPostmark وAmazon SES.
- ردود جاهزة وماكرو وإدارة الوسوم.
- REST API مع رمز Bearer وصلاحيات لكل رمز وتحديد معدل الطلبات.
- دعم المرفقات مع حدود رفع قابلة للتكوين.
- تقييمات الرضا وعروض التقارير.

## المتطلبات

- WordPress `6.0+`
- PHP `8.1+`

## التثبيت

1. ضع هذه الإضافة في مجلد إضافات WordPress:
   - `wp-content/plugins/escalated`
2. قم بتفعيل **Escalated** من شاشة الإضافات في WordPress.
3. انتقل إلى **Escalated** في wp-admin وقم بتكوين:
   - الأقسام
   - سياسات SLA
   - قواعد التصعيد
   - الإعدادات

## أكواد الواجهة المختصرة

استخدم هذه الأكواد المختصرة في صفحات WordPress:

- `[escalated_tickets]` - قائمة تذاكر مقدم الطلب المسجل.
- `[escalated_create_ticket]` - نموذج تذكرة جديدة لمقدمي الطلبات المسجلين.
- `[escalated_view_ticket]` - عرض تفاصيل التذكرة:
  - المستخدمون المسجلون: يتوقع `?ticket=ESC-123`
  - الضيوف: يتوقع `?guest_token=<token>`
- `[escalated_guest_create]` - نموذج إنشاء تذكرة ضيف (إذا كان مفعلاً في الإعدادات).

## REST API

- مساحة الأسماء: `/wp-json/escalated/v1`
- المصادقة: `Authorization: Bearer <api-token>`
- حد المعدل الافتراضي: `60` طلب/دقيقة لكل رمز (قابل للتكوين عبر إعداد `api_rate_limit`)

مجموعات المسارات الرئيسية:

- `/auth/validate`
- `/tickets`
- `/departments`
- `/tags`
- `/canned-responses`
- `/macros`
- `/agents`
- `/dashboard`
- `/admin/api-tokens`

## webhooks البريد الوارد

نمط مسار البريد الوارد:

- `POST /wp-json/escalated/v1/inbound/{adapter}`

المحولات المدعومة:

- `mailgun`
- `postmark`
- `ses`

## المهام المجدولة (WP-Cron)

عند التفعيل، يقوم Escalated بجدولة:

- `escalated_check_sla` (كل دقيقة)
- `escalated_evaluate_escalations` (كل 5 دقائق)
- `escalated_auto_close` (يومياً)
- `escalated_purge_activities` (أسبوعياً)

## التطوير

تثبيت التبعيات:

```bash
composer install
```

تشغيل الاختبارات (يتطلب مجموعة اختبارات WordPress):

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```

إذا لزم الأمر، قم بتعيين `WP_TESTS_DIR` إلى مسار مكتبة اختبارات WordPress المحلية قبل تشغيل PHPUnit.

## متوفر أيضاً لـ

- **[Escalated for Laravel](https://github.com/escalated-dev/escalated-laravel)** — حزمة Composer لـ Laravel
- **[Escalated for Rails](https://github.com/escalated-dev/escalated-rails)** — محرك Ruby on Rails
- **[Escalated for Django](https://github.com/escalated-dev/escalated-django)** — تطبيق Django قابل لإعادة الاستخدام
- **[Escalated for AdonisJS](https://github.com/escalated-dev/escalated-adonis)** — حزمة AdonisJS v6
- **[Escalated for Filament](https://github.com/escalated-dev/escalated-filament)** — إضافة لوحة إدارة Filament v3
- **[Escalated for WordPress](https://github.com/escalated-dev/escalated-wordpress)** — إضافة WordPress (أنت هنا)
- **[Shared Frontend](https://github.com/escalated-dev/escalated)** — مكونات واجهة Vue 3 + Inertia.js

## الرخصة

MIT
