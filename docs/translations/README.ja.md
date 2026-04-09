<p align="center">
  <a href="README.ar.md">العربية</a> •
  <a href="README.de.md">Deutsch</a> •
  <a href="../../README.md">English</a> •
  <a href="README.es.md">Español</a> •
  <a href="README.fr.md">Français</a> •
  <a href="README.it.md">Italiano</a> •
  <b>日本語</b> •
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

WordPress向けのフル機能ヘルプデスク・チケットシステム。マルチロール対応、SLAトラッキング、エスカレーションルール、受信メール処理、マクロ、REST APIを備えています。外部サービス不要。

> **[escalated.dev](https://escalated.dev)** — 詳細、デモの閲覧、Cloud vs セルフホストオプションの比較はこちら。

## Screenshots

| チケット一覧 | チケット詳細 |
|:-----------:|:-------------:|
| ![チケット一覧](screenshots/results/ticket-list.png) | ![チケット詳細](screenshots/results/ticket-detail.png) |

| 部門 | SLAポリシー |
|:-----------:|:------------:|
| ![部門](screenshots/results/departments.png) | ![SLAポリシー](screenshots/results/sla-policies.png) |

| レポート | 設定 |
|:-------:|:--------:|
| ![レポート](screenshots/results/reports.png) | ![設定](screenshots/results/settings.png) |

| 自動化 | マクロ |
|:-----------:|:------:|
| ![自動化](screenshots/results/automations.png) | ![マクロ](screenshots/results/macros.png) |

> スクリーンショットはリリースごとにPlaywrightで自動生成されます。`.github/workflows/screenshots.yml` を参照してください。

## Download

[![Download Latest Release](https://img.shields.io/badge/Download-Latest%20Release-2ea44f?style=for-the-badge&logo=github)](https://github.com/escalated-dev/escalated-wordpress/releases/latest)

- 最新プラグインパッケージ: [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip)
- 全リリース: [Releases](https://github.com/escalated-dev/escalated-wordpress/releases)

## 機能

- スレッド形式の会話、内部メモ、アクティビティタイムラインを備えたチケット管理。
- カスタムサポートロール: `escalated_admin` と `escalated_agent`。
- 部門ベースのルーティングと割り当てワークフロー。
- 初回応答と解決目標を持つSLAポリシー。
- 自動エスカレーションルールとスケジュールされたSLAチェック。
- ショートコードによる顧客向けフロントエンドチケットページ。
- ゲストチケット送信と安全なゲストチケットアクセス。
- Mailgun、Postmark、Amazon SESのWebhookによる受信メール取り込み。
- 定型応答、マクロ、タグ管理。
- トークンごとの権限とレート制限を備えたBearerトークンREST API。
- 設定可能なアップロード制限付きの添付ファイルサポート。
- 満足度評価とレポートビュー。

## 要件

- WordPress `6.0+`
- PHP `8.1+`

## インストール

1. このプラグインをWordPressのプラグインディレクトリに配置します:
   - `wp-content/plugins/escalated`
2. WordPressのプラグイン画面から **Escalated** を有効化します。
3. wp-adminで **Escalated** に移動し、以下を設定します:
   - 部門
   - SLAポリシー
   - エスカレーションルール
   - 設定

## フロントエンドショートコード

WordPressページでこれらのショートコードを使用します:

- `[escalated_tickets]` - ログイン済みリクエスターのチケット一覧。
- `[escalated_create_ticket]` - ログイン済みリクエスターの新規チケットフォーム。
- `[escalated_view_ticket]` - チケット詳細ビュー:
  - ログインユーザー: `?ticket=ESC-123` を期待
  - ゲスト: `?guest_token=<token>` を期待
- `[escalated_guest_create]` - ゲストチケット作成フォーム（設定で有効な場合）。

## REST API

- Namespace: `/wp-json/escalated/v1`
- 認証: `Authorization: Bearer <api-token>`
- デフォルトレート制限: トークンあたり `60` リクエスト/分（`api_rate_limit` 設定で変更可能）

主要ルートグループ:

- `/auth/validate`
- `/tickets`
- `/departments`
- `/tags`
- `/canned-responses`
- `/macros`
- `/agents`
- `/dashboard`
- `/admin/api-tokens`

## 受信メールWebhook

受信ルートパターン:

- `POST /wp-json/escalated/v1/inbound/{adapter}`

対応アダプター:

- `mailgun`
- `postmark`
- `ses`

## スケジュールタスク (WP-Cron)

有効化時に、Escalatedは以下をスケジュールします:

- `escalated_check_sla`（毎分）
- `escalated_evaluate_escalations`（5分ごと）
- `escalated_auto_close`（毎日）
- `escalated_purge_activities`（毎週）

## 開発

依存関係のインストール:

```bash
composer install
```

テストの実行（WordPressテストスイートが必要）:

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```

必要に応じて、PHPUnitを実行する前に `WP_TESTS_DIR` をローカルのWordPressテストライブラリパスに設定してください。

## 他のフレームワーク向け

- **[Escalated for Laravel](https://github.com/escalated-dev/escalated-laravel)** — Laravel Composerパッケージ
- **[Escalated for Rails](https://github.com/escalated-dev/escalated-rails)** — Ruby on Rails Engine
- **[Escalated for Django](https://github.com/escalated-dev/escalated-django)** — Django再利用可能アプリ
- **[Escalated for AdonisJS](https://github.com/escalated-dev/escalated-adonis)** — AdonisJS v6パッケージ
- **[Escalated for Filament](https://github.com/escalated-dev/escalated-filament)** — Filament v3管理パネルプラグイン
- **[Escalated for WordPress](https://github.com/escalated-dev/escalated-wordpress)** — WordPressプラグイン（現在のページ）
- **[Shared Frontend](https://github.com/escalated-dev/escalated)** — Vue 3 + Inertia.js UIコンポーネント

## ライセンス

MIT
