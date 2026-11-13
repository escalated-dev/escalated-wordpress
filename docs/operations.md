# Operations

## Scheduled Tasks (WP-Cron)

On activation, Escalated schedules:

- `escalated_check_sla` (every minute)
- `escalated_evaluate_escalations` (every 5 minutes)
- `escalated_auto_close` (daily)
- `escalated_purge_activities` (weekly)

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
