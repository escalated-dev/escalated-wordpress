<p align="center"><sub>
  <a href="README.ar.md">العربية</a> ·
  <a href="README.de.md">Deutsch</a> ·
  <a href="../../README.md">English</a> ·
  <a href="README.es.md">Español</a> ·
  <a href="README.fr.md">Français</a> ·
  <a href="README.it.md">Italiano</a> ·
  <b>日本語</b> ·
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
  WordPress の中で本格的なヘルプデスクを運用できます。<br>
  チケット、SLA、エスカレーションルール、受信メール、カスタマーポータルを、外部サービスなしで wp-admin から管理できます。
</p>

<p align="center">
  <a href="https://escalated.dev"><b>ウェブサイト</b></a> ·
  <a href="https://escalated.dev/docs"><b>ドキュメント</b></a> ·
  <a href="https://escalated.dev/cloud"><b>Cloud</b></a> ·
  <a href="../../CHANGELOG.md"><b>変更履歴</b></a>
</p>

<br>

<img src="../../screenshots/results/ticket-list.png" alt="wp-admin の Escalated チケット一覧。ステータス、優先度、部署、エージェントで絞り込めます" width="100%">

## できること

<table>
  <tr>
    <td width="40%">
      <h3>エージェントが wp-admin で対応するチケット</h3>
      スレッド形式の会話、内部メモ、定型返信、マクロをチケット画面にまとめています。チケットは Mailgun、Postmark、Amazon SES 経由のメールでも届きます。
    </td>
    <td width="60%">
      <img src="../../screenshots/results/ticket-detail.png" alt="wp-admin で開いたチケット。会話と返信フォームが表示されています">
    </td>
  </tr>
  <tr>
    <td width="60%">
      <img src="../../screenshots/results/sla-policies.png" alt="初回応答と解決の目標を設定する SLA ポリシー画面">
    </td>
    <td width="40%">
      <h3>自動で動く SLA とエスカレーションルール</h3>
      初回応答と解決の目標、目標に遅れたチケットをエスカレーションするルール、WP-Cron で定期実行される SLA チェックを備えています。
    </td>
  </tr>
</table>

## 機能

<table>
<tr>
<td width="33%" valign="top">

**チケット**
- スレッド形式の会話と内部メモ
- すべてのチケットにアクティビティ履歴
- 定型返信、マクロ、タグ
- 重複チケットの統合
- アップロード上限付きの添付ファイル

</td>
<td width="33%" valign="top">

**SLA とルーティング**
- 部署ごとのルーティングと割り当て
- 初回応答と解決の目標
- エスカレーションルールと定期 SLA チェック
- wp-admin または REST で設定するイベント駆動ワークフロー

</td>
<td width="33%" valign="top">

**顧客**
- ショートコードによるフロントエンドのチケットページ
- ゲストによる送信と安全なゲストアクセス
- 満足度評価

</td>
</tr>
<tr>
<td width="33%" valign="top">

**ロールとセキュリティ**
- 管理者、エージェント、ライトエージェントのロール
- ルートごとの権限 (任意)
- スタッフ向け TOTP 二要素認証 (任意)
- SAML 2.0、OAuth 2 / OIDC、JWT によるサインイン

</td>
<td width="33%" valign="top">

**メールと Webhook**
- Mailgun、Postmark、Amazon SES からの受信
- それぞれ独自のイベントを持つ複数の Webhook エンドポイント
- 配信ログと再試行

</td>
<td width="33%" valign="top">

**API とレポート**
- 権限を設定できる Bearer トークン方式の REST API
- トークンごとのレート制限
- レポート画面

</td>
</tr>
</table>

<p align="center"><sub><a href="../features.md">すべての機能の詳細</a></sub></p>

<p align="center">
  <a href="https://wordpress.org"><img src="https://img.shields.io/badge/WordPress-21759B?style=for-the-badge&logo=wordpress&logoColor=white" alt="WordPress"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP"></a>
  <a href="https://www.mysql.com"><img src="https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL"></a>
</p>

## クイックスタート

1. 最新リリースから [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip) をダウンロードします。
2. プラグインディレクトリに展開し、プラグインが `wp-content/plugins/escalated` に置かれるようにします。
3. WordPress のプラグイン画面で **Escalated** を有効化します。
4. wp-admin の **Escalated** を開き、部署、SLA ポリシー、エスカレーションルール、設定を構成します。

WordPress 6.0+ と PHP 8.1+ が必要です。詳しくは[インストールガイド](../installation.md)を、顧客向けページのショートコードは[カスタマイズ](../customization.md)を参照してください。

## 詳しく知る

- [すべての機能](../features.md) · [インストール](../installation.md) · [スクリーンショット](../screenshots.md)
- [ショートコード、チケットの件名、翻訳](../customization.md) · [REST API とチケットの統合](../rest-api.md)
- [アクセス制御](../access-control.md) · [チケットのアクセスとルーティング](../ticket-access-and-routing.md) · [ワークフロー](../workflows.md)
- [受信メール](../inbound-email.md) · [Webhook](../webhooks.md) · [ゲストアクセス](../guest-access.md)
- [ウィジェットの埋め込み](../embedding-the-widget.md) · [管理画面の埋め込み](../embedding-admin.md)
- [スケジュールタスクとデータベース接続](../operations.md) · [ニュースレター](../newsletters.md) · [開発](../development.md)

Escalated は [Laravel](https://github.com/escalated-dev/escalated-laravel)、[Rails](https://github.com/escalated-dev/escalated-rails)、[Django](https://github.com/escalated-dev/escalated-django)、[NestJS](https://github.com/escalated-dev/escalated-nestjs)、[AdonisJS](https://github.com/escalated-dev/escalated-adonis)、[Symfony](https://github.com/escalated-dev/escalated-symfony)、[Phoenix](https://github.com/escalated-dev/escalated-phoenix)、[Go](https://github.com/escalated-dev/escalated-go)、[.NET](https://github.com/escalated-dev/escalated-dotnet)、[Spring](https://github.com/escalated-dev/escalated-spring)、[Filament](https://github.com/escalated-dev/escalated-filament) 向けにも提供しています。ほかのバックエンド向けの UI は [escalated](https://github.com/escalated-dev/escalated) にあります。

## ライセンス

MIT。Copyright (c) 2026 Escalated.dev。詳細は [LICENSE](../../LICENSE) を参照してください。
