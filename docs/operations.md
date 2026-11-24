# Operations

## Required: schedule the recurring jobs

SLA checks, escalation rules, automations and snooze wake-ups only run when
WP-Cron runs. Escalated schedules its events itself on activation (with
`wp_schedule_event()`), but WP-Cron only fires when something triggers it: a
page view while WP-Cron is on, or a real cron job. A quiet site, or one with
`DISABLE_WP_CRON` set and no cron job, runs none of them.

Add a system cron job that runs WP-Cron every minute:

```cron
* * * * * wp cron event run --due-now --path=/path/to/wordpress
```

(or `* * * * * curl -s https://example.com/wp-cron.php?doing_wp_cron >/dev/null`
if WP-CLI is not available). With that in place you can set
`define('DISABLE_WP_CRON', true);` so page views stop triggering it.

That one job runs each of Escalated's events at its own interval:

| Event | Interval | What it does |
|---|---|---|
| `escalated_check_sla` | every minute | SLA breaches and warnings |
| `escalated_check_snoozed_tickets` | every minute | wakes snoozed tickets |
| `escalated_run_due_deferred_workflow_jobs` | every minute | delayed workflow actions |
| `escalated_process_webhook_deliveries` | every minute | webhook sends and retries |
| `escalated_dispatch_newsletters` | every minute | newsletter sends |
| `escalated_chat_cleanup` | every minute | idle live chats |
| `escalated_evaluate_escalations` | every 5 minutes | escalation rules |
| `escalated_run_automations` | every 5 minutes | time-based automations |
| `escalated_auto_close` | daily | closes resolved tickets |
| `escalated_purge_activities` | weekly | prunes old activity |

**Checking it.** Each SLA check records a heartbeat (the
`escalated_scheduler_heartbeat` option). When it is missing or older than 15
minutes, users who can see Escalated's settings get a warning on every
Escalated screen naming the cron job to add.
`Escalated\Support\SchedulerHealth::status()` returns the same information
as `{ healthy, last_run_at, message, commands }`.

`wp escalated doctor` checks the whole setup: Escalated's tables, roles and
the administrator role's capabilities, whether any user can work tickets,
whether every event above is scheduled, and the heartbeat. It exits non-zero
when anything is wrong. If events are missing (the plugin was loaded without
its activation hook, e.g. as a must-use plugin), deactivate and reactivate it
or call `\Escalated\Activator::activate()` from your installer.

## Database connection

By default Escalated's tables live in your WordPress database. Point them
somewhere else — a schema shared with a legacy system, a separate reporting
store, or simply out of the WordPress database — by defining the connection in
`wp-config.php`:

```php
define('ESCALATED_DB_NAME', 'support');

// Optional. Each falls back to the WordPress value when omitted.
define('ESCALATED_DB_USER', 'support_user');
define('ESCALATED_DB_PASSWORD', 'secret');
define('ESCALATED_DB_HOST', 'db.internal');
define('ESCALATED_DB_PREFIX', 'wp_');
```

Define nothing and Escalated uses the global `$wpdb` — the same instance, the
same prefix, the same queries. An unconfigured site is unchanged.

WordPress has no connection registry, so a second database means a second
`wpdb`. Escalated builds one lazily and reuses it: `wpdb` connects in its
constructor, so resolving per query would open a connection per query.

### Your users stay in WordPress

`ESCALATED_DB_NAME` moves Escalated's own tables and nothing else. The plugin
never queries `wp_users`, `wp_posts` or `wp_options` directly — it reaches user
data through `get_userdata()`, `get_user_by()` and `WP_User_Query`, which use
the WordPress connection as they always have. Escalated stores user ids as plain
unconstrained columns precisely so the two can live on different databases, and
a test asserts no plugin code issues raw SQL against a core table.

### Creating the tables

Activation creates Escalated's tables on whichever connection is configured, so
define the constants **before** activating the plugin. On an existing install,
defining them afterwards does not move any data — create the tables on the new
database and copy the rows across first.
