<p align="center"><sub>
  <b>العربية</b> ·
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
  <a href="README.zh-CN.md">简体中文</a>
</sub></p>

<p align="center">
  <a href="https://escalated.dev"><img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/logo.png" width="72" height="72" alt="Escalated"></a>
</p>

<h1 align="center">Escalated for WordPress</h1>

<p align="center">
  شغّل مكتب دعم متكاملًا داخل WordPress.<br>
  تذاكر، واتفاقيات مستوى الخدمة (SLA)، وقواعد تصعيد، وبريد وارد، وبوابة للعملاء، تُدار كلها من wp-admin دون أي خدمات خارجية.
</p>

<p align="center">
  <a href="https://escalated.dev"><b>الموقع</b></a> ·
  <a href="https://escalated.dev/docs"><b>التوثيق</b></a> ·
  <a href="https://escalated.dev/cloud"><b>Cloud</b></a> ·
  <a href="../../CHANGELOG.md"><b>سجل التغييرات</b></a>
</p>

<br>

<img src="../../screenshots/results/ticket-list.png" alt="قائمة التذاكر في Escalated داخل wp-admin مع عوامل تصفية حسب الحالة والأولوية والقسم والوكيل" width="100%">

## ما الذي يقدمه

<table>
  <tr>
    <td width="40%">
      <h3>تذاكر يتولاها وكلاؤك داخل wp-admin</h3>
      محادثات متسلسلة وملاحظات داخلية وردود جاهزة ووحدات ماكرو في شاشة التذكرة. تصل التذاكر أيضًا عبر البريد الإلكتروني من خلال Mailgun أو Postmark أو Amazon SES.
    </td>
    <td width="60%">
      <img src="../../screenshots/results/ticket-detail.png" alt="تذكرة مفتوحة في wp-admin تعرض المحادثة ونموذج الرد">
    </td>
  </tr>
  <tr>
    <td width="60%">
      <img src="../../screenshots/results/sla-policies.png" alt="شاشة سياسات SLA مع أهداف الاستجابة الأولى والحل">
    </td>
    <td width="40%">
      <h3>اتفاقيات SLA وقواعد تصعيد تعمل من تلقاء نفسها</h3>
      أهداف للاستجابة الأولى والحل، وقواعد تصعّد التذاكر عند التأخر، وفحوصات SLA مجدولة عبر WP-Cron.
    </td>
  </tr>
</table>

## الميزات

<table>
<tr>
<td width="33%" valign="top">

**التذاكر**
- محادثات متسلسلة وملاحظات داخلية
- سجل زمني للنشاط في كل تذكرة
- ردود جاهزة ووحدات ماكرو ووسوم
- دمج التذاكر المكررة
- مرفقات مع حدود للرفع

</td>
<td width="33%" valign="top">

**SLA والتوجيه**
- التوجيه والإسناد حسب القسم
- أهداف الاستجابة الأولى والحل
- قواعد تصعيد وفحوصات SLA مجدولة
- مسارات عمل قائمة على الأحداث في wp-admin أو عبر REST

</td>
<td width="33%" valign="top">

**العملاء**
- صفحات تذاكر في واجهة الموقع عبر الرموز القصيرة (shortcodes)
- إرسال التذاكر كضيف ووصول آمن للضيوف
- تقييمات الرضا

</td>
</tr>
<tr>
<td width="33%" valign="top">

**الأدوار والأمان**
- أدوار المدير والوكيل والوكيل المحدود
- صلاحيات اختيارية لكل مسار
- مصادقة ثنائية اختيارية عبر TOTP للموظفين
- تسجيل الدخول عبر SAML 2.0 أو OAuth 2 / OIDC أو JWT

</td>
<td width="33%" valign="top">

**البريد وخطافات الويب**
- بريد وارد من Mailgun وPostmark وAmazon SES
- عدة نقاط نهاية لخطافات الويب لكل منها أحداثها الخاصة
- سجل للتسليم وإعادة المحاولة

</td>
<td width="33%" valign="top">

**الواجهة البرمجية والتقارير**
- واجهة REST API برموز Bearer مع صلاحيات لكل رمز
- تحديد معدل الطلبات لكل رمز
- شاشات التقارير

</td>
</tr>
</table>

<p align="center"><sub><a href="../features.md">كل الميزات بالتفصيل</a></sub></p>

<p align="center">
  <a href="https://wordpress.org"><img src="https://img.shields.io/badge/WordPress-21759B?style=for-the-badge&logo=wordpress&logoColor=white" alt="WordPress"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP"></a>
  <a href="https://www.mysql.com"><img src="https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL"></a>
</p>

## البدء السريع

1. نزّل [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip) من أحدث إصدار.
2. فك ضغطه في مجلد الإضافات بحيث تكون الإضافة في `wp-content/plugins/escalated`.
3. فعّل **Escalated** من شاشة الإضافات في WordPress.
4. انتقل إلى **Escalated** في wp-admin وأعدّ الأقسام وسياسات SLA وقواعد التصعيد والإعدادات.

يتطلب WordPress 6.0+ وPHP 8.1+. يحتوي [دليل التثبيت](../installation.md) على التفاصيل، وتسرد صفحة [التخصيص](../customization.md) الرموز القصيرة لصفحاتك الموجهة للعملاء.

## اعرف المزيد

- [كل الميزات](../features.md) · [التثبيت](../installation.md) · [لقطات الشاشة](../screenshots.md)
- [الرموز القصيرة وعناوين التذاكر والترجمات](../customization.md) · [REST API ودمج التذاكر](../rest-api.md)
- [التحكم في الوصول](../access-control.md) · [الوصول إلى التذاكر وتوجيهها](../ticket-access-and-routing.md) · [مسارات العمل](../workflows.md)
- [البريد الوارد](../inbound-email.md) · [خطافات الويب](../webhooks.md) · [وصول الضيوف](../guest-access.md)
- [تضمين الأداة](../embedding-the-widget.md) · [تضمين لوحة الإدارة](../embedding-admin.md)
- [المهام المجدولة والاتصال بقاعدة البيانات](../operations.md) · [النشرات البريدية](../newsletters.md) · [التطوير](../development.md)

يتوفر Escalated أيضًا لـ [Laravel](https://github.com/escalated-dev/escalated-laravel)، [Rails](https://github.com/escalated-dev/escalated-rails)، [Django](https://github.com/escalated-dev/escalated-django)، [NestJS](https://github.com/escalated-dev/escalated-nestjs)، [AdonisJS](https://github.com/escalated-dev/escalated-adonis)، [Symfony](https://github.com/escalated-dev/escalated-symfony)، [Phoenix](https://github.com/escalated-dev/escalated-phoenix)، [Go](https://github.com/escalated-dev/escalated-go)، [.NET](https://github.com/escalated-dev/escalated-dotnet)، [Spring](https://github.com/escalated-dev/escalated-spring) و[Filament](https://github.com/escalated-dev/escalated-filament). توجد واجهة الخلفيات الأخرى في [escalated](https://github.com/escalated-dev/escalated).

## الترخيص

MIT. Copyright (c) 2026 Escalated.dev. راجع [LICENSE](../../LICENSE).
