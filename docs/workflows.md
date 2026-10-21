# Workflows

A workflow runs its actions when an event happens on a ticket and the ticket matches its conditions. Workflows are event-driven. Time-based rules are Automations, and one-click agent bundles are Macros.

Manage them under **Escalated > Workflows** (needs `escalated_automation_manage`). The screen lists workflows in position order with Enable/Disable and Delete buttons. The form has:

- **When**: the trigger event
- **If**: all or any of a JSON list of conditions
- **Then**: a JSON list of actions
- **Position**
- **Options**: active, and stop running later workflows when this one matches

## REST

The routes follow the shared workflow admin contract (`domain-model/workflow-admin-contract.md` in escalated-developer-context). They need a signed-in user with `escalated_automation_manage`, and a REST nonce for cookie auth.

| Method | Path (under `/wp-json/escalated/v1`) | |
|---|---|---|
| GET | `/admin/workflows` | `{workflows: [...]}` |
| GET | `/admin/workflows/new` | `{workflow: null, trigger_events, action_types, operators, condition_fields}` |
| GET | `/admin/workflows/{id}/edit` | the same, with `workflow` |
| POST | `/admin/workflows` | create; 201 `{workflow}` or 422 `{errors}` |
| PUT | `/admin/workflows/{id}` | replace; PATCH updates only the keys sent |
| POST | `/admin/workflows/{id}/toggle` | flips `is_active` |
| POST | `/admin/workflows/reorder` | `{workflow_ids: [3, 1, 2]}` |
| DELETE | `/admin/workflows/{id}` | |

The body:

```json
{
  "name": "Route refunds to billing",
  "trigger_event": "ticket.created",
  "conditions": { "all": [{ "field": "subject", "operator": "contains", "value": "refund" }] },
  "actions": [{ "type": "change_priority", "value": "high" }, { "type": "set_department", "value": "4" }],
  "is_active": true
}
```

- `name`, `trigger_event` and at least one action are required.
- Omitted conditions match every ticket.
- A legacy trigger name (`ticket.tagged`, `ticket.replied`, ...) is stored under its canonical name.

## Triggers, conditions, actions

- **Triggers**: the canonical event names, listed in `trigger_events`. `reply.agent_reply` is a WordPress extra: a public reply by someone other than the requester.
- **Condition fields**: any ticket column, plus `tags` (tag names, comma separated), `hours_since_created` and `hours_since_updated`.
- **Operators**: `equals`, `not_equals`, `contains`, `not_contains`, `starts_with`, `ends_with`, `greater_than`, `less_than`, `greater_or_equal`, `less_or_equal`, `is_empty`, `is_not_empty`.
- **Actions**: `change_status`, `change_priority`, `add_tag`, `remove_tag`, `set_department`, `assign_agent`, `add_note`, `insert_canned_reply`, `add_follower`, `delay` (minutes), `send_webhook` and `set_type`. `add_note` and `insert_canned_reply` fill in `{{field}}` from the ticket.

A workflow's actions never fire the same event on the same ticket again. For example, a `ticket.updated` workflow that changes the priority runs once, not in a loop. Other events still chain.
