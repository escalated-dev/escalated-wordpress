# Outbound webhooks

Escalated > Webhooks lists the endpoints that receive ticket events. Each webhook has:

- **URL**: http or https. Every address the host resolves to must be public. Loopback, private, link-local (cloud metadata) and other reserved ranges are refused when you save, and checked again before every delivery.
- **Secret** (optional): signs each body with HMAC-SHA256, sent hex-encoded in `X-Escalated-Signature`.
- **Events**: the events it receives (below).
- **Active**: an inactive webhook gets nothing.

Managing webhooks needs the `escalated_webhook_manage` capability. Creating, editing and deleting a webhook is written to the audit log; the secret never is.

## Delivery

Events are queued, not sent inside the request that caused them. WP-Cron sends the queue: a one-off event right after the change, and an every-minute sweep for retries and anything missed. With [Action Scheduler](https://actionscheduler.org/) installed, the one-off send runs as an async action instead.

Each request:

- is a `POST` with `Content-Type: application/json`, `X-Escalated-Event`, `X-Escalated-Delivery` (the delivery id) and, with a secret, `X-Escalated-Signature`
- has the body `{"event", "payload", "timestamp", "occurred_at"}`. `occurred_at` is ISO-8601 UTC; `timestamp` is the older site-timezone Unix value
- times out after 10 seconds, never follows a redirect, and connects to the address that passed the check

A 2xx answer marks the delivery `delivered`. Anything else is retried after 120 seconds and again after 240 seconds, then marked `failed`. A URL the guard refuses is marked `failed` at once.

**Deliveries** on each webhook shows the log: event, status, response code, attempts and the first 2,000 characters of the response. **Retry** queues a fresh copy of a delivery.

The `escalated_webhook_args` filter can change the request arguments. `escalated_webhook_sent` and `escalated_webhook_failed` fire after each attempt, with the webhook row as the last argument.

## Events

The canonical names from the shared event vocabulary:

`ticket.created`, `ticket.updated`, `ticket.status_changed`, `ticket.resolved`, `ticket.closed`, `ticket.reopened`, `ticket.assigned`, `ticket.unassigned`, `ticket.escalated`, `ticket.priority_changed`, `ticket.department_changed`, `reply.created`, `note.created`, `sla.breached`, `sla.warning`, `ticket.tag_added`, `ticket.tag_removed`.

A webhook saved with an older name (`ticket.tagged`, `internal_note.added`, ...) receives the event under its current name.

## Upgrading from the single webhook URL

Earlier versions had one Webhook URL and secret on the Settings page. On upgrade that URL becomes a webhook subscribed to every event, with the same secret, and the setting is cleared. A URL written to the `webhook_url` setting from code is moved the same way the next time an event fires.
