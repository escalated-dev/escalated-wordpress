<p align="center"><sub>
  <a href="README.ar.md">العربية</a> ·
  <a href="README.de.md">Deutsch</a> ·
  <a href="../../README.md">English</a> ·
  <a href="README.es.md">Español</a> ·
  <a href="README.fr.md">Français</a> ·
  <a href="README.it.md">Italiano</a> ·
  <a href="README.ja.md">日本語</a> ·
  <b>한국어</b> ·
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
  WordPress 안에서 완전한 헬프데스크를 운영하세요.<br>
  티켓, SLA, 에스컬레이션 규칙, 수신 이메일, 고객 포털을 외부 서비스 없이 wp-admin에서 관리합니다.
</p>

<p align="center">
  <a href="https://escalated.dev"><b>웹사이트</b></a> ·
  <a href="https://escalated.dev/docs"><b>문서</b></a> ·
  <a href="https://escalated.dev/cloud"><b>Cloud</b></a> ·
  <a href="../../CHANGELOG.md"><b>변경 내역</b></a>
</p>

<br>

<img src="../../screenshots/results/ticket-list.png" alt="wp-admin의 Escalated 티켓 목록과 상태, 우선순위, 부서, 상담원 필터" width="100%">

## 주요 기능

<table>
  <tr>
    <td width="40%">
      <h3>상담원이 wp-admin에서 처리하는 티켓</h3>
      스레드형 대화, 내부 메모, 상용구 답변, 매크로를 티켓 화면에서 사용할 수 있습니다. 티켓은 Mailgun, Postmark 또는 Amazon SES를 통해 이메일로도 들어옵니다.
    </td>
    <td width="60%">
      <img src="../../screenshots/results/ticket-detail.png" alt="wp-admin에서 연 티켓의 대화와 답변 양식">
    </td>
  </tr>
  <tr>
    <td width="60%">
      <img src="../../screenshots/results/sla-policies.png" alt="첫 응답 및 해결 목표가 있는 SLA 정책 화면">
    </td>
    <td width="40%">
      <h3>알아서 동작하는 SLA와 에스컬레이션 규칙</h3>
      첫 응답 및 해결 목표, 목표가 지연되면 티켓을 에스컬레이션하는 규칙, WP-Cron으로 예약되는 SLA 점검을 제공합니다.
    </td>
  </tr>
</table>

## 기능 목록

<table>
<tr>
<td width="33%" valign="top">

**티켓**
- 스레드형 대화와 내부 메모
- 모든 티켓의 활동 타임라인
- 상용구 답변, 매크로, 태그
- 중복 티켓 병합
- 업로드 제한이 있는 첨부 파일

</td>
<td width="33%" valign="top">

**SLA와 라우팅**
- 부서별 라우팅과 할당
- 첫 응답 및 해결 목표
- 에스컬레이션 규칙과 예약된 SLA 점검
- wp-admin 또는 REST로 설정하는 이벤트 기반 워크플로

</td>
<td width="33%" valign="top">

**고객**
- 쇼트코드로 만드는 프론트엔드 티켓 페이지
- 게스트 제출과 안전한 게스트 접근
- 만족도 평가

</td>
</tr>
<tr>
<td width="33%" valign="top">

**역할과 보안**
- 관리자, 상담원, 라이트 상담원 역할
- 라우트별 권한 (선택)
- 직원용 TOTP 2단계 인증 (선택)
- SAML 2.0, OAuth 2 / OIDC 또는 JWT 로그인

</td>
<td width="33%" valign="top">

**이메일과 웹훅**
- Mailgun, Postmark, Amazon SES 수신
- 각자 이벤트를 지정하는 여러 웹훅 엔드포인트
- 전송 로그와 재시도

</td>
<td width="33%" valign="top">

**API와 보고서**
- 권한을 지정하는 Bearer 토큰 REST API
- 토큰별 요청 제한
- 보고서 화면

</td>
</tr>
</table>

<p align="center"><sub><a href="../features.md">모든 기능 자세히 보기</a></sub></p>

<p align="center">
  <a href="https://wordpress.org"><img src="https://img.shields.io/badge/WordPress-21759B?style=for-the-badge&logo=wordpress&logoColor=white" alt="WordPress"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP"></a>
  <a href="https://www.mysql.com"><img src="https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL"></a>
</p>

## 빠른 시작

1. 최신 릴리스에서 [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip)을 내려받습니다.
2. 플러그인 디렉터리에 압축을 풀어 플러그인이 `wp-content/plugins/escalated`에 위치하도록 합니다.
3. WordPress 플러그인 화면에서 **Escalated**를 활성화합니다.
4. wp-admin에서 **Escalated**로 이동해 부서, SLA 정책, 에스컬레이션 규칙, 설정을 구성합니다.

WordPress 6.0+와 PHP 8.1+가 필요합니다. 자세한 내용은 [설치 가이드](../installation.md)를, 고객용 페이지의 쇼트코드는 [커스터마이징](../customization.md)을 참고하세요.

## 더 알아보기

- [전체 기능](../features.md) · [설치](../installation.md) · [스크린샷](../screenshots.md)
- [쇼트코드, 티켓 제목, 번역](../customization.md) · [REST API와 티켓 병합](../rest-api.md)
- [접근 제어](../access-control.md) · [티켓 접근과 라우팅](../ticket-access-and-routing.md) · [워크플로](../workflows.md)
- [수신 이메일](../inbound-email.md) · [웹훅](../webhooks.md) · [게스트 접근](../guest-access.md)
- [위젯 삽입](../embedding-the-widget.md) · [관리자 화면 삽입](../embedding-admin.md)
- [예약 작업과 데이터베이스 연결](../operations.md) · [뉴스레터](../newsletters.md) · [개발](../development.md)

Escalated는 [Laravel](https://github.com/escalated-dev/escalated-laravel), [Rails](https://github.com/escalated-dev/escalated-rails), [Django](https://github.com/escalated-dev/escalated-django), [NestJS](https://github.com/escalated-dev/escalated-nestjs), [AdonisJS](https://github.com/escalated-dev/escalated-adonis), [Symfony](https://github.com/escalated-dev/escalated-symfony), [Phoenix](https://github.com/escalated-dev/escalated-phoenix), [Go](https://github.com/escalated-dev/escalated-go), [.NET](https://github.com/escalated-dev/escalated-dotnet), [Spring](https://github.com/escalated-dev/escalated-spring), [Filament](https://github.com/escalated-dev/escalated-filament)용으로도 제공됩니다. 다른 백엔드용 UI는 [escalated](https://github.com/escalated-dev/escalated) 저장소에 있습니다.

## 라이선스

MIT. Copyright (c) 2026 Escalated.dev. [LICENSE](../../LICENSE)를 참고하세요.
