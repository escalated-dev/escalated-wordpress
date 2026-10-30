# Access control: roles & permissions, two-factor, SSO

Escalated ships three access features as install-level switches
(escalated-developer-context ADR `2026-10-29-configurable-access-features`;
escalated-laravel is the reference). Each one is either enforced or hidden.

| Feature | Constant / environment variable | Default |
|---|---|---|
| Roles & permissions | `ESCALATED_ROLES_PERMISSIONS` | off |
| Two-factor authentication | not yet | off |
| Single sign-on | not yet | off |

Set a switch in `wp-config.php`:

```php
define('ESCALATED_ROLES_PERMISSIONS', true);
```

or as an environment variable of the same name. A host plugin can also use the
`escalated_access_roles_permissions` filter. The switches are never read from
the settings table or an option: each one changes who may do what, so no stored
setting may flip it, and saving a setting with the same name changes nothing.

Two-factor and SSO are not enforced by this plugin yet, so they report off and
their screens are not served.

## How this maps onto WordPress roles and capabilities

WordPress has its own user, role and capability system, and this plugin has
always used it:

- **Permissions are capabilities.** Each of escalated-laravel's permission
  slugs is a WordPress capability: `ticket.view` is `escalated_ticket_view`,
  `reply.create_internal` is `escalated_reply_create_internal`, and so on
  (`.` becomes `_`, prefixed `escalated_`). The plugin also has
  `skill.view` / `skill.manage` (`escalated_skill_*`) for its skills screen.
- **Roles are WordPress roles.** Activation creates `escalated_admin` (every
  Escalated capability), `escalated_agent` and `escalated_light_agent` with
  escalated-laravel's `admin`, `agent` and `light_agent` permission sets, and
  gives the WordPress `administrator` role every Escalated capability. Any
  other role that holds an Escalated capability is an Escalated role too
  (a role editor plugin works as well as the Roles screen below).
- **A user's permissions** are the capabilities their roles give them
  (`user_can()`), as Laravel's are the union of their roles' permissions.
- **The gates** are the checks each screen and route already had: the wp-admin
  menu capability of each screen, the REST routes' API token check (any staff
  token) and the capability checks some actions made.

### With the switch off (the default)

Exactly what every install had before: the wp-admin screens need the
capability they are registered with, and the REST routes and ticket actions
keep their own checks. Some of those are coarser than escalated-laravel's map
(any staff API token reaches every ticket route; the ticket screen's status,
priority, department and tag changes need only `escalated_reply_create`).
The Roles screen is not in the menu and answers 404, and the Users screen has
no role column.

### With the switch on

Enforced as the ADR describes, with WordPress capabilities as the permissions:

- Every staff REST route (`escalated/v1`, except sign-in, guests, the widget,
  the mobile guest API, inbound mail, the newsletter provider webhooks and
  attachment downloads, which are authorized per ticket) also needs its
  permission, after its own permission callback. The map is
  `Escalated\Support\RoutePermissions` (escalated-laravel's, keyed by method
  and route). A staff route missing from the map is refused, and a test
  enumerates the REST server so a new route cannot ship without an entry.
  Missing permission: `403`.
- A status change to `resolved`, `closed` or `reopened` also needs
  `ticket.close`; applying a macro needs `macro.view` and `ticket.edit`.
- API token requests are checked as the token's owner (`user_can($owner, ...)`),
  so a token can do no more than the person it belongs to can now.
- The wp-admin ticket screen's actions need their own permissions: status,
  priority, department and tags `ticket.edit` (plus `ticket.close` for a
  closing status), assign `ticket.assign`, reply `reply.create`, note
  `reply.create_internal`, merge `ticket.merge`.
- Passing the admin gate grants nothing on its own: a user whose role has
  `manage_options` but no Escalated capability is refused. WordPress
  administrators keep working because the `administrator` role holds every
  Escalated capability.
- There are no bulk ticket actions in this plugin, so there is nothing to
  check per bulk action.

The `escalatedAdmin` script object on Escalated's wp-admin screens carries
`features` (`roles_permissions`, `two_factor`, `sso`) and `permissions`: the
user's slugs with the switch on, `["*"]` for staff and `[]` for anyone else
with it off.

### Screens

While the switch is on:

- **Escalated > Roles** (`escalated_role_view` to see, `escalated_role_manage`
  to change) lists the Escalated roles with their user counts, and creates,
  renames and edits a role's Escalated capabilities (capabilities that are not
  Escalated's are left alone). The system roles (`administrator`,
  `escalated_admin`, `escalated_agent`, `escalated_light_agent`) cannot be
  deleted. It is the WordPress-native counterpart of the shared
  `Admin/Roles/Index` and `Form` pages; this plugin renders its own wp-admin
  screens, not the shared Vue pages.
- **Escalated > Users** gets a Roles column: tick the Escalated roles a user
  holds (escalated-laravel's `PUT users/{user}/roles`). Their other roles are
  kept, and `administrator` is managed in WordPress itself. You cannot remove
  your own role management.

### Turning it on in an existing install

1. Make sure every staff user holds an Escalated role (`escalated_admin`,
   `escalated_agent`, `escalated_light_agent` or one of yours). From WP-CLI:
   `wp user add-role <user> escalated_agent`.
2. Check that the roles hold the capabilities you expect (Users > Roles in a
   role editor, or Escalated > Roles once the switch is on).
3. Set `ESCALATED_ROLES_PERMISSIONS`.
