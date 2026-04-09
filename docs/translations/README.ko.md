<p align="center">
  <a href="README.ar.md">العربية</a> •
  <a href="README.de.md">Deutsch</a> •
  <a href="../../README.md">English</a> •
  <a href="README.es.md">Español</a> •
  <a href="README.fr.md">Français</a> •
  <a href="README.it.md">Italiano</a> •
  <a href="README.ja.md">日本語</a> •
  <b>한국어</b> •
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

WordPress용 다기능 헬프데스크 및 티켓 시스템. 다중 역할 지원, SLA 추적, 에스컬레이션 규칙, 수신 이메일 처리, 매크로, REST API를 제공합니다. 외부 서비스가 필요 없습니다.

> **[escalated.dev](https://escalated.dev)** — 자세히 알아보고, 데모를 보고, Cloud vs 셀프 호스팅 옵션을 비교하세요.

## Screenshots

| 티켓 목록 | 티켓 상세 |
|:-----------:|:-------------:|
| ![티켓 목록](screenshots/results/ticket-list.png) | ![티켓 상세](screenshots/results/ticket-detail.png) |

| 부서 | SLA 정책 |
|:-----------:|:------------:|
| ![부서](screenshots/results/departments.png) | ![SLA 정책](screenshots/results/sla-policies.png) |

| 리포트 | 설정 |
|:-------:|:--------:|
| ![리포트](screenshots/results/reports.png) | ![설정](screenshots/results/settings.png) |

| 자동화 | 매크로 |
|:-----------:|:------:|
| ![자동화](screenshots/results/automations.png) | ![매크로](screenshots/results/macros.png) |

> 스크린샷은 매 릴리스마다 Playwright로 자동 생성됩니다. `.github/workflows/screenshots.yml`을 참조하세요.

## Download

[![Download Latest Release](https://img.shields.io/badge/Download-Latest%20Release-2ea44f?style=for-the-badge&logo=github)](https://github.com/escalated-dev/escalated-wordpress/releases/latest)

- 최신 플러그인 패키지: [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip)
- 모든 릴리스: [Releases](https://github.com/escalated-dev/escalated-wordpress/releases)

## 기능

- 스레드 대화, 내부 노트, 활동 타임라인을 갖춘 티켓 관리.
- 커스텀 지원 역할: `escalated_admin` 및 `escalated_agent`.
- 부서 기반 라우팅 및 배정 워크플로우.
- 첫 응답 및 해결 목표가 있는 SLA 정책.
- 자동화된 에스컬레이션 규칙 및 예약된 SLA 검사.
- 숏코드를 통한 고객용 프론트엔드 티켓 페이지.
- 게스트 티켓 제출 및 안전한 게스트 티켓 접근.
- Mailgun, Postmark, Amazon SES 웹훅을 통한 수신 이메일 처리.
- 미리 작성된 응답, 매크로, 태그 관리.
- 토큰별 권한 및 속도 제한이 있는 Bearer 토큰 REST API.
- 설정 가능한 업로드 제한이 있는 첨부 파일 지원.
- 만족도 평가 및 리포트 뷰.

## 요구 사항

- WordPress `6.0+`
- PHP `8.1+`

## 설치

1. 이 플러그인을 WordPress 플러그인 디렉토리에 배치하세요:
   - `wp-content/plugins/escalated`
2. WordPress 플러그인 화면에서 **Escalated**를 활성화하세요.
3. wp-admin에서 **Escalated**로 이동하여 설정하세요:
   - 부서
   - SLA 정책
   - 에스컬레이션 규칙
   - 설정

## 프론트엔드 숏코드

WordPress 페이지에서 다음 숏코드를 사용하세요:

- `[escalated_tickets]` - 로그인한 요청자의 티켓 목록.
- `[escalated_create_ticket]` - 로그인한 요청자의 새 티켓 양식.
- `[escalated_view_ticket]` - 티켓 상세 보기:
  - 로그인 사용자: `?ticket=ESC-123` 필요
  - 게스트: `?guest_token=<token>` 필요
- `[escalated_guest_create]` - 게스트 티켓 생성 양식 (설정에서 활성화된 경우).

## REST API

- Namespace: `/wp-json/escalated/v1`
- 인증: `Authorization: Bearer <api-token>`
- 기본 속도 제한: 토큰당 `60` 요청/분 (`api_rate_limit` 설정으로 변경 가능)

주요 라우트 그룹:

- `/auth/validate`
- `/tickets`
- `/departments`
- `/tags`
- `/canned-responses`
- `/macros`
- `/agents`
- `/dashboard`
- `/admin/api-tokens`

## 수신 이메일 웹훅

수신 라우트 패턴:

- `POST /wp-json/escalated/v1/inbound/{adapter}`

지원 어댑터:

- `mailgun`
- `postmark`
- `ses`

## 예약 작업 (WP-Cron)

활성화 시, Escalated는 다음을 예약합니다:

- `escalated_check_sla` (매분)
- `escalated_evaluate_escalations` (5분마다)
- `escalated_auto_close` (매일)
- `escalated_purge_activities` (매주)

## 개발

의존성 설치:

```bash
composer install
```

테스트 실행 (WordPress 테스트 스위트 필요):

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```

필요한 경우, PHPUnit을 실행하기 전에 `WP_TESTS_DIR`을 로컬 WordPress 테스트 라이브러리 경로로 설정하세요.

## 다른 프레임워크에서도 사용 가능

- **[Escalated for Laravel](https://github.com/escalated-dev/escalated-laravel)** — Laravel Composer 패키지
- **[Escalated for Rails](https://github.com/escalated-dev/escalated-rails)** — Ruby on Rails Engine
- **[Escalated for Django](https://github.com/escalated-dev/escalated-django)** — Django 재사용 가능한 앱
- **[Escalated for AdonisJS](https://github.com/escalated-dev/escalated-adonis)** — AdonisJS v6 패키지
- **[Escalated for Filament](https://github.com/escalated-dev/escalated-filament)** — Filament v3 관리 패널 플러그인
- **[Escalated for WordPress](https://github.com/escalated-dev/escalated-wordpress)** — WordPress 플러그인 (현재 페이지)
- **[Shared Frontend](https://github.com/escalated-dev/escalated)** — Vue 3 + Inertia.js UI 컴포넌트

## 라이선스

MIT
