# REST API

## REST API

- Namespace: `/wp-json/escalated/v1`
- Auth: `Authorization: Bearer <api-token>`
- Default rate limit: `60` requests/minute per token (configurable via `api_rate_limit` setting)

Main route groups:

- `/auth/validate`
- `/tickets`
- `/departments`
- `/tags`
- `/canned-responses`
- `/macros`
- `/agents`
- `/dashboard`
- `/admin/api-tokens`
- `/admin/tickets/{ref}/subjects` (attach/detach ticket subjects)
- `/tickets/{ref}/merge` and `/tickets/merge-search` (see [Ticket merge](#ticket-merge))

## Ticket merge

An agent with the `escalated_ticket_merge` capability can merge a duplicate into another ticket, from the **Merge** box on the ticket screen or over the API:

- `GET /wp-json/escalated/v1/tickets/merge-search?q=&exclude=` (`tickets:read`) lists up to 10 unmerged tickets whose reference or subject matches: `{tickets: [{id, reference, subject, status, created_at}]}`.
- `POST /wp-json/escalated/v1/tickets/{ref}/merge` with `{target_reference}` (`tickets:update`) merges `{ref}` into the target.

A merge moves every reply (with its attachments) to the target, adds an internal system note to both tickets, and closes the source with `merged_into_id` set, all in one transaction. It then fires the usual close hooks for the source and `escalated_ticket_merged`. Merging a ticket into itself or merging an already-merged ticket is refused (422), and a merged ticket cannot be a target (404).
