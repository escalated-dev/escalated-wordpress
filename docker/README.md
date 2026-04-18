# Escalated WordPress — Docker demo (scaffold, not end-to-end)

Draft. Mounts the plugin into a stock `wordpress:6.6-php8.3-apache` image alongside MariaDB and Mailpit.

**Differs from the other escalated-* demos: uses MariaDB, not Postgres.** WordPress is a MySQL-family application and swapping its DB layer is out of scope for a demo.

**Not end-to-end.** Missing:

- WP-CLI based setup script: `wp core install`, `wp user create`, plugin activation, seed demo tickets via an `escalated` CLI subcommand if one exists.
- `/demo` picker — could be a WP page template that lists seeded users with WP auth cookie click-login via `wp_set_auth_cookie()`.
- Environment to reset the whole WP install on each `docker compose up` so the demo is reproducible.

See the PR body for the punch list.
