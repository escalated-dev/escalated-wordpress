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
  <b>Türkçe</b> ·
  <a href="README.zh-CN.md">简体中文</a>
</sub></p>

<p align="center">
  <a href="https://escalated.dev"><img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/logo.png" width="72" height="72" alt="Escalated"></a>
</p>

<h1 align="center">Escalated for WordPress</h1>

<p align="center">
  WordPress içinde eksiksiz bir yardım masası çalıştırın.<br>
  Talepler, SLA'lar, eskalasyon kuralları, gelen e-posta ve bir müşteri portalı, harici hizmet gerektirmeden wp-admin'den yönetilir.
</p>

<p align="center">
  <a href="https://escalated.dev"><b>Web sitesi</b></a> ·
  <a href="https://escalated.dev/docs"><b>Dokümantasyon</b></a> ·
  <a href="https://escalated.dev/cloud"><b>Cloud</b></a> ·
  <a href="../../CHANGELOG.md"><b>Değişiklik günlüğü</b></a>
</p>

<br>

<img src="../../screenshots/results/ticket-list.png" alt="wp-admin'deki Escalated talep listesi; durum, öncelik, departman ve temsilci filtreleriyle" width="100%">

## Neler yapar

<table>
  <tr>
    <td width="40%">
      <h3>Temsilcilerinizin wp-admin'de yönettiği talepler</h3>
      Talep ekranında yazışma dizileri, dahili notlar, hazır yanıtlar ve makrolar. Talepler Mailgun, Postmark veya Amazon SES üzerinden e-postayla da gelir.
    </td>
    <td width="60%">
      <img src="../../screenshots/results/ticket-detail.png" alt="wp-admin'de açılmış bir talep, yazışma ve yanıt formuyla">
    </td>
  </tr>
  <tr>
    <td width="60%">
      <img src="../../screenshots/results/sla-policies.png" alt="İlk yanıt ve çözüm hedeflerini içeren SLA politikaları ekranı">
    </td>
    <td width="40%">
      <h3>Kendi kendine çalışan SLA'lar ve eskalasyon kuralları</h3>
      İlk yanıt ve çözüm hedefleri, hedef kaçtığında talepleri eskale eden kurallar ve WP-Cron ile zamanlanan SLA kontrolleri.
    </td>
  </tr>
</table>

## Özellikler

<table>
<tr>
<td width="33%" valign="top">

**Talepler**
- Yazışma dizileri ve dahili notlar
- Her talepte etkinlik zaman çizelgesi
- Hazır yanıtlar, makrolar ve etiketler
- Yinelenen talepleri birleştirme
- Yükleme sınırlarıyla ekler

</td>
<td width="33%" valign="top">

**SLA'lar ve yönlendirme**
- Departmana göre yönlendirme ve atama
- İlk yanıt ve çözüm hedefleri
- Eskalasyon kuralları ve zamanlanmış SLA kontrolleri
- wp-admin'de veya REST ile olay tabanlı iş akışları

</td>
<td width="33%" valign="top">

**Müşteriler**
- Kısa kodlarla ön yüzde talep sayfaları
- Misafir olarak talep gönderme ve güvenli misafir erişimi
- Memnuniyet puanları

</td>
</tr>
<tr>
<td width="33%" valign="top">

**Roller ve güvenlik**
- Yönetici, temsilci ve hafif temsilci rolleri
- İsteğe bağlı rota bazlı izinler
- Personel için isteğe bağlı TOTP iki aşamalı doğrulama
- SAML 2.0, OAuth 2 / OIDC veya JWT ile oturum açma

</td>
<td width="33%" valign="top">

**E-posta ve webhook'lar**
- Mailgun, Postmark ve Amazon SES üzerinden gelen e-posta
- Her biri kendi olaylarına sahip birden fazla webhook uç noktası
- Teslim günlüğü ve yeniden denemeler

</td>
<td width="33%" valign="top">

**API ve raporlar**
- Yetkileri tanımlanabilen Bearer token'lı REST API
- Token başına istek sınırlama
- Rapor görünümleri

</td>
</tr>
</table>

<p align="center"><sub><a href="../features.md">Tüm özellikler ayrıntılı olarak</a></sub></p>

<p align="center">
  <a href="https://wordpress.org"><img src="https://img.shields.io/badge/WordPress-21759B?style=for-the-badge&logo=wordpress&logoColor=white" alt="WordPress"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP"></a>
  <a href="https://www.mysql.com"><img src="https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL"></a>
</p>

## Hızlı başlangıç

1. En son sürümden [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip) dosyasını indirin.
2. Eklentinin `wp-content/plugins/escalated` içinde yer alması için dosyayı eklentiler dizininize çıkarın.
3. WordPress Eklentiler ekranından **Escalated** eklentisini etkinleştirin.
4. wp-admin'de **Escalated** bölümüne gidin ve departmanları, SLA politikalarını, eskalasyon kurallarını ve ayarları yapılandırın.

WordPress 6.0+ ve PHP 8.1+ gerekir. Ayrıntılar [kurulum kılavuzunda](../installation.md) yer alır; [özelleştirme](../customization.md) sayfası müşteriye yönelik sayfalarınız için kısa kodları listeler.

## Daha fazla bilgi

- [Tüm özellikler](../features.md) · [Kurulum](../installation.md) · [Ekran görüntüleri](../screenshots.md)
- [Kısa kodlar, talep konuları ve çeviriler](../customization.md) · [REST API ve talep birleştirme](../rest-api.md)
- [Erişim denetimi](../access-control.md) · [Talep erişimi ve yönlendirme](../ticket-access-and-routing.md) · [İş akışları](../workflows.md)
- [Gelen e-posta](../inbound-email.md) · [Webhook'lar](../webhooks.md) · [Misafir erişimi](../guest-access.md)
- [Widget'ı yerleştirme](../embedding-the-widget.md) · [Yönetim panelini yerleştirme](../embedding-admin.md)
- [Zamanlanmış görevler ve veritabanı bağlantısı](../operations.md) · [Bültenler](../newsletters.md) · [Geliştirme](../development.md)

Escalated, [Laravel](https://github.com/escalated-dev/escalated-laravel), [Rails](https://github.com/escalated-dev/escalated-rails), [Django](https://github.com/escalated-dev/escalated-django), [NestJS](https://github.com/escalated-dev/escalated-nestjs), [AdonisJS](https://github.com/escalated-dev/escalated-adonis), [Symfony](https://github.com/escalated-dev/escalated-symfony), [Phoenix](https://github.com/escalated-dev/escalated-phoenix), [Go](https://github.com/escalated-dev/escalated-go), [.NET](https://github.com/escalated-dev/escalated-dotnet), [Spring](https://github.com/escalated-dev/escalated-spring) ve [Filament](https://github.com/escalated-dev/escalated-filament) için de kullanılabilir. Diğer backend'lerin arayüzü [escalated](https://github.com/escalated-dev/escalated) deposunda bulunur.

## Lisans

MIT. Copyright (c) 2026 Escalated.dev. Ayrıntılar için [LICENSE](../../LICENSE) dosyasına bakın.
