# Features

## Features

Configure opt-in department/account access and targeted SLAs using the
[ticket access and routing guide](ticket-access-and-routing.md).

- Ticket management with threaded conversations, internal notes, and activity timeline.
- Custom support roles: `escalated_admin`, `escalated_agent` and `escalated_light_agent`, with
  optional per-route roles & permissions enforcement ([access control](access-control.md)).
- Optional two-factor authentication (TOTP) for staff, challenged after the WordPress login ([access control](access-control.md)).
- Optional single sign-on through SAML 2.0, OAuth 2 / OIDC or JWT ([access control](access-control.md)).
- Department-based routing and assignment workflows.
- SLA policies with first-response and resolution targets.
- Automated escalation rules and scheduled SLA checks.
- Event-driven workflows, managed in wp-admin or over REST ([guide](workflows.md)).
- Customer-facing frontend ticket pages via shortcodes.
- Guest ticket submission and secure guest ticket access.
- Inbound email ingestion via Mailgun, Postmark, and Amazon SES webhooks.
- Outbound webhooks: several endpoints with their own events and secrets, a delivery log and retries ([guide](webhooks.md)).
- Canned responses, macros, and tag management.
- Bearer token REST API with per-token abilities and rate limiting.
- Attachment support with configurable upload limits.
- Satisfaction ratings and reporting views.
