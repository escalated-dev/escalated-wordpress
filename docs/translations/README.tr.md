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
  <b>Türkçe</b> •
  <a href="README.zh-CN.md">简体中文</a>
</p>

# Escalated for WordPress

[![Tests](https://github.com/escalated-dev/escalated-wordpress/actions/workflows/run-tests.yml/badge.svg)](https://github.com/escalated-dev/escalated-wordpress/actions/workflows/run-tests.yml)
[![Latest Release](https://img.shields.io/github/v/release/escalated-dev/escalated-wordpress)](https://github.com/escalated-dev/escalated-wordpress/releases/latest)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/wordpress-%3E%3D6.0-21759B)](https://wordpress.org/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

WordPress için çoklu rol desteği, SLA takibi, eskalasyon kuralları, gelen e-posta işleme, makrolar ve REST API içeren tam donanımlı bir yardım masası ve bilet sistemi. Harici hizmet gerektirmez.

> **[escalated.dev](https://escalated.dev)** — Daha fazla bilgi edinin, demoları izleyin ve Cloud ile Self-Hosted seçeneklerini karşılaştırın.

## Screenshots

| Bilet Listesi | Bilet Detayı |
|:-----------:|:-------------:|
| ![Bilet Listesi](screenshots/results/ticket-list.png) | ![Bilet Detayı](screenshots/results/ticket-detail.png) |

| Departmanlar | SLA Politikaları |
|:-----------:|:------------:|
| ![Departmanlar](screenshots/results/departments.png) | ![SLA Politikaları](screenshots/results/sla-policies.png) |

| Raporlar | Ayarlar |
|:-------:|:--------:|
| ![Raporlar](screenshots/results/reports.png) | ![Ayarlar](screenshots/results/settings.png) |

| Otomasyonlar | Makrolar |
|:-----------:|:------:|
| ![Otomasyonlar](screenshots/results/automations.png) | ![Makrolar](screenshots/results/macros.png) |

> Ekran görüntüleri her sürümde Playwright ile otomatik olarak oluşturulur. `.github/workflows/screenshots.yml` dosyasına bakın.

## Download

[![Download Latest Release](https://img.shields.io/badge/Download-Latest%20Release-2ea44f?style=for-the-badge&logo=github)](https://github.com/escalated-dev/escalated-wordpress/releases/latest)

- En son eklenti paketi: [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip)
- Tüm sürümler: [Releases](https://github.com/escalated-dev/escalated-wordpress/releases)

## Özellikler

- Zincirleme konuşmalar, dahili notlar ve etkinlik zaman çizelgesi ile bilet yönetimi.
- Özel destek rolleri: `escalated_admin` ve `escalated_agent`.
- Departman bazlı yönlendirme ve atama iş akışları.
- İlk yanıt ve çözüm hedefleri olan SLA politikaları.
- Otomatik eskalasyon kuralları ve zamanlanmış SLA kontrolleri.
- Shortcode'lar ile müşteriye yönelik frontend bilet sayfaları.
- Misafir bilet gönderimi ve güvenli misafir bilet erişimi.
- Mailgun, Postmark ve Amazon SES webhook'ları ile gelen e-posta işleme.
- Hazır yanıtlar, makrolar ve etiket yönetimi.
- Token başına yetkiler ve hız sınırlaması olan Bearer token REST API.
- Yapılandırılabilir yükleme limitleri ile ek dosya desteği.
- Memnuniyet değerlendirmeleri ve rapor görünümleri.

## Gereksinimler

- WordPress `6.0+`
- PHP `8.1+`

## Kurulum

1. Bu eklentiyi WordPress eklenti dizininize yerleştirin:
   - `wp-content/plugins/escalated`
2. WordPress Eklentiler ekranından **Escalated**'ı etkinleştirin.
3. wp-admin'de **Escalated**'a gidin ve yapılandırın:
   - Departmanlar
   - SLA Politikaları
   - Eskalasyon Kuralları
   - Ayarlar

## Frontend Shortcodes

Bu shortcode'ları WordPress sayfalarında kullanın:

- `[escalated_tickets]` - Giriş yapmış kullanıcının bilet listesi.
- `[escalated_create_ticket]` - Giriş yapmış kullanıcılar için yeni bilet formu.
- `[escalated_view_ticket]` - Bilet detay görünümü:
  - Giriş yapmış kullanıcılar: `?ticket=ESC-123` bekler
  - Misafirler: `?guest_token=<token>` bekler
- `[escalated_guest_create]` - Misafir bilet oluşturma formu (ayarlarda etkinleştirildiyse).

## REST API

- Namespace: `/wp-json/escalated/v1`
- Kimlik doğrulama: `Authorization: Bearer <api-token>`
- Varsayılan hız limiti: Token başına dakikada `60` istek (`api_rate_limit` ayarı ile yapılandırılabilir)

Ana rota grupları:

- `/auth/validate`
- `/tickets`
- `/departments`
- `/tags`
- `/canned-responses`
- `/macros`
- `/agents`
- `/dashboard`
- `/admin/api-tokens`

## Gelen E-posta Webhook'ları

Gelen rota deseni:

- `POST /wp-json/escalated/v1/inbound/{adapter}`

Desteklenen adaptörler:

- `mailgun`
- `postmark`
- `ses`

## Zamanlanmış Görevler (WP-Cron)

Etkinleştirmede Escalated şunları zamanlar:

- `escalated_check_sla` (her dakika)
- `escalated_evaluate_escalations` (her 5 dakika)
- `escalated_auto_close` (günlük)
- `escalated_purge_activities` (haftalık)

## Geliştirme

Bağımlılıkları yükleyin:

```bash
composer install
```

Testleri çalıştırın (WordPress test paketi gereklidir):

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```

Gerekirse PHPUnit'i çalıştırmadan önce `WP_TESTS_DIR`'i yerel WordPress test kütüphanesi yolunuza ayarlayın.

## Şunlar İçin de Mevcut

- **[Escalated for Laravel](https://github.com/escalated-dev/escalated-laravel)** — Laravel Composer paketi
- **[Escalated for Rails](https://github.com/escalated-dev/escalated-rails)** — Ruby on Rails Engine
- **[Escalated for Django](https://github.com/escalated-dev/escalated-django)** — Yeniden kullanılabilir Django uygulaması
- **[Escalated for AdonisJS](https://github.com/escalated-dev/escalated-adonis)** — AdonisJS v6 paketi
- **[Escalated for Filament](https://github.com/escalated-dev/escalated-filament)** — Filament v3 yönetim paneli eklentisi
- **[Escalated for WordPress](https://github.com/escalated-dev/escalated-wordpress)** — WordPress eklentisi (buradasınız)
- **[Shared Frontend](https://github.com/escalated-dev/escalated)** — Vue 3 + Inertia.js UI bileşenleri

## Lisans

MIT
