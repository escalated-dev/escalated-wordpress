# Ticket access, accounts and routing

All features are opt-in. Configure them in **Escalated → Settings → Ticket
access and routing**, or through `Escalated\Models\Setting`:

| Setting | Default | Effect |
| --- | --- | --- |
| `visibility` | `all` | `department` limits staff to their department memberships. |
| `accounts_enabled` | `0` | Enables the host ticket-account resolver. |
| `accounts_scope_agents` | `0` | With accounts enabled, also requires the agent's account to match. |
| `targeted_slas` | `0` | Selects active SLAs by department and ticket type. |

## Department visibility

`Department::add_agent($department_id, $wordpress_user_id)` records membership.
Agents may belong to multiple departments. Department and account restrictions
intersect. A ticket with no department is hidden from non-admin agents in
department mode. Accounts missing either half of their identity are hidden when
account restrictions apply.

Administrators (`escalated_admin` or `manage_options`) retain full access. Staff
are identified by `escalated_ticket_view`, including light agents and custom
roles granted that capability. Existing route and capability checks still apply.
Customer ownership and anonymous guest-token authorization remain the
responsibility of their existing routes; these are staff restrictions.

The shared SQL guard covers ticket lists, search, totals, reports, bulk SQL
updates/deletes, REST, widget lookups and direct child queries. Replies,
attachments, activities, subjects, followers, ratings, chat sessions, deferred
jobs, workflow logs and side conversations require a visible parent. Ticket
links require **both** tickets. Inbound rows with a reply require both parents.
Ticket audit entries are scoped; unrelated administrative audit entries retain
their existing permissions.

Validated staff API tokens establish their owner's access context for the
callback, even when the browser session belongs to an administrator. Nonstaff
tokens are rejected when restrictions are enabled. The previous access context
is restored in `finally`; WordPress's logged-in user is not changed.

Assignments validate the recipient, including assignments made by administrators
or jobs. The ticket detail's agent picker, skill routing and auto-assignment
exclude ineligible agents. `GET /escalated/v1/agents?ticket_id=123` returns
eligible candidates. The general agent directory remains a directory.
Notifications recheck recipients against the current ticket and membership
after recipient filters run. Public replies retain requester delivery; internal
notes never use the requester exception. With restrictions enabled, staff mail
recipients must resolve to eligible WordPress staff users. Live event polling
rechecks membership on every delivery.

## Account integration

Tickets have nullable `account_type` and `account_id` strings (191 characters
each), with a compound index. Identities preserve case, trailing spaces and
leading zeroes. The database's default collation does not broaden account
visibility or account conditions.

The host supplies identities using filters:

```php
use Escalated\Models\Setting;

Setting::set('accounts_enabled', '1');

add_filter('escalated_ticket_account', function ($account, array $ticket, ?array $subject) {
    // $subject is null during intake, or ['type' => ..., 'id' => ...] on attach.
    // Resolve from the host's requester/subject data. Return null if unresolved.
    return ['type' => 'Company', 'id' => '001'];
}, 10, 3);

add_filter('escalated_agent_account', function ($account, int $user_id) {
    // Resolve this WordPress user's single account in the host application.
    return ['type' => 'Company', 'id' => '001'];
}, 10, 2);

Setting::set('accounts_scope_agents', '1');
```

Requester resolution happens before SLA selection and lifecycle events.
Attaching a subject fills an empty account using a conditional update; it never
overwrites an existing identity. Splitting a ticket copies its account and type.
The REST intake accepts `ticket_type`; the host resolver owns the account.
Ticket list filters accept a complete `account_type` / `account_id` pair. A
partial or malformed pair returns no matches.

The agent resolver returns **one account**. Use department memberships for
agents working across several accounts. Enable restrictions only after the
host has populated ticket accounts and configured both resolvers. Account
mapping, event intake and external subject models belong to the host.

## Conditions and SLAs

Escalation rules, event workflows and scheduled automations accept
`ticket_type`, `account_type`, `account_id`, and the compound `account` condition:

```json
[
  {"field": "ticket_type", "operator": "equals", "value": "incident"},
  {"field": "account", "operator": "equals", "value": {"type": "Company", "id": "001"}}
]
```

Account pairs support `equals` / `not_equals` (also `=` / `!=` / `<>`). Malformed
pairs and unsupported account operators never match. SQL-based scans use exact
comparisons. Department conditions remain available. Workflows also accept
`change_department` as an alias for `set_department`.

SLA policy forms expose optional department and ticket-type targets. With
`targeted_slas=1`, creation and `SlaService::attach_default_policy()` prefer
department + type, department only, type only, then an untargeted active default.
The lowest policy ID breaks ties. With the option off, the existing active
default policy behavior remains unchanged.

Changing department clears an ineligible assignee and **preserves the current
SLA clock**. To deliberately select another SLA, call `attach_default_policy()`
after the move. Due dates are based on the original `created_at`, including
business-hour calculation, so reselection does not grant extra time.

## Jobs, custom SQL and upgrades

Console/cron work without a staff actor retains full access. Host work running
on behalf of an agent should use
`TicketAccess::run_as($user_id, fn () => /* work */)`.
Trusted maintenance can use
`TicketAccess::without_restriction(fn () => /* work */)`.
Both restore nested contexts on exceptions. Recipient authorization still runs
under the recipient's own context inside a trusted bypass.

The guard uses WordPress's `query` filter and preserves the configured `wpdb`
instance. It recognizes identifiers independently of SQL literals and comments.
Supported restricted queries include SELECT joins, nested selects, unions,
aggregates, ordinary literal INSERTs, and simple UPDATE/DELETE statements.
New child parents are validated on INSERT/reparenting. Raw assignment updates
must go through `Ticket::update()` so the prospective ticket can be checked.
CTEs, executable comments, REPLACE, INSERT…SELECT, multi-statement SQL,
schema-qualified protected tables and multi-table mutations fail closed under
restriction. Use the explicit trusted bypass for administrative SQL outside
this grammar. This is application-level enforcement; code using mysqli
directly or removing the filter must implement its own authorization.

Activation/upgrades add nullable columns and indexes without changing existing
data. A separate schema marker ensures an upgrade is applied even when the
plugin version has not yet changed. Table creation runs on `Escalated::db()`,
including `ESCALATED_DB_NAME` and `ESCALATED_DB_PREFIX`; WordPress users stay on
the host connection. The ticket-links table is installed before any API read.

Run `vendor/bin/phpunit`. CI also sets `ESCALATED_ACCESS_TEST_DB` to exercise
populated upgrades and membership queries with a separate database and a custom
prefix; the test database must already exist and be writable by the test user.
