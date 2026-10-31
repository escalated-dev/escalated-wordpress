# Access control: roles & permissions, two-factor, SSO

Escalated ships three access features as install-level switches
(escalated-developer-context ADR `2026-10-29-configurable-access-features`;
escalated-laravel is the reference). Each one is either enforced or hidden.

| Feature | Constant / environment variable | Default |
|---|---|---|
| Roles & permissions | `ESCALATED_ROLES_PERMISSIONS` | off |
| Two-factor authentication | `ESCALATED_TWO_FACTOR` | off |
| Single sign-on | `ESCALATED_SSO` | off |

Set a switch in `wp-config.php`:

```php
define('ESCALATED_ROLES_PERMISSIONS', true);
```

or as an environment variable of the same name. A host plugin can also use the
`escalated_access_roles_permissions` filter (`escalated_access_two_factor` and
`escalated_access_sso` for the others). The switches are never read from
the settings table or an option: each one changes who may do what, so no stored
setting may flip it, and saving a setting with the same name changes nothing.


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

## Two-factor authentication

```php
define('ESCALATED_TWO_FACTOR', true);
// Optional: every staff user must enrol (the setting below wins once saved).
define('ESCALATED_TWO_FACTOR_REQUIRED', true);
```

This plugin enforces two-factor itself, on the WordPress login it already
uses; no other two-factor plugin is needed (one that challenges at
`wp-login.php` can run alongside: the Escalated challenge only guards the
Escalated screens).

- **TOTP** is RFC 6238 (SHA-1, 6 digits, 30 s) through
  [`pragmarx/google2fa`](https://github.com/antonioribeiro/google2fa), as
  escalated-laravel, with one step of drift either side. The accepted time
  step is recorded (a conditional update on `last_used_step`), so a code works
  once. Secrets are stored encrypted and recovery codes as hashes.
- **Enrolment** is Escalated > Two-Factor, open to every staff user (agents
  and light agents too) and needing no permission. Setting up shows the
  `otpauth://` URI and eight recovery codes once; confirming takes a 6-digit
  code and counts as passing the challenge for this login; disabling removes
  the enrolment. It is the WordPress-native counterpart of the shared
  `Admin/Settings/TwoFactor` page.
- **The challenge** sits between the WordPress login and the Escalated
  screens: a staff user with a confirmed enrolment who has not passed it in
  this login session is redirected from every Escalated wp-admin screen to it
  (the WordPress-native counterpart of `Auth/TwoFactorChallenge`), and the
  staff REST routes they call with their login cookie answer `403` with
  `two_factor_required`. It takes a TOTP code or a recovery code, allows five
  attempts a minute per user, and returns to the screen that was asked for.
  The pass is kept in the WordPress login session (`WP_Session_Tokens`), so a
  new login is challenged again and logging out ends it; when the response
  can still set cookies, passing gives the login a new session token.
- **Recovery codes**: eight, single use; a code that signs someone in is
  removed.
- **Requiring it**: `ESCALATED_TWO_FACTOR_REQUIRED` (or the
  `escalated_access_two_factor_required` filter), or the "Require it" button
  on the Two-Factor screen for users with `escalated_settings_manage`, which
  saves the `two_factor_required` setting on its own (the setting wins once
  saved). When required, staff without a confirmed enrolment are sent to the
  Two-Factor screen and can use no other Escalated screen.
- **Lost device**: `wp escalated two-factor-reset <user>` clears an
  enrolment.
- **API tokens** are not challenged: the token API is not session-based. The
  token-authenticated `admin/two-factor` REST routes (self-service
  enrolment for API clients) answer 404 while the switch is off.

With the switch off nobody is challenged, the Two-Factor screen, the challenge
and the `admin/two-factor` REST routes answer 404, and enrolments are kept.

### Turning it on in an existing install

1. Update the plugin (the schema upgrade adds `two_factors.last_used_step`).
2. Set `ESCALATED_TWO_FACTOR`. Users who enrolled through the REST API are
   challenged from their next visit.
3. Require it only after telling staff: everyone without an enrolment is sent
   to enrol.

## Single sign-on

```php
define('ESCALATED_SSO', true);
// Optional, as escalated-laravel's escalated.sso.* (constant, environment
// variable, or the escalated_sso_{name} filter):
define('ESCALATED_SSO_CREATE_USERS', false);     // create users for unknown emails
define('ESCALATED_SSO_SYNC_ROLES', false);       // map the IdP role onto Escalated roles
define('ESCALATED_SSO_ALLOW_IDP_INITIATED', false);
define('ESCALATED_SSO_CLOCK_SKEW', 120);         // JWT leeway, at most 300
// ESCALATED_SSO_SP_ENTITY_ID (default: the metadata URL),
// ESCALATED_SSO_JWT_AUDIENCE (default: home_url()),
// ESCALATED_SSO_REDIRECT_TO (default: the ticket list for staff, else home).
```

This plugin signs people in itself, on the same maintained libraries as
escalated-laravel: [`onelogin/php-saml`](https://github.com/SAML-Toolkits/php-saml)
(strict), [`league/oauth2-client`](https://github.com/thephpleague/oauth2-client)
and [`firebase/php-jwt`](https://github.com/firebase/php-jwt). A successful
sign-in is a normal WordPress login (`wp_set_auth_cookie`, then the
`wp_login` action), so two-factor still challenges it. The WordPress password
login stays as it is.

The provider is picked at runtime in **Escalated > Single Sign-On**
(`escalated_settings_view` to see, `escalated_settings_manage` to save; the
WordPress-native counterpart of `Admin/Settings/SsoSettings`): the `sso_*`
settings, with `sso_jwt_secret` and `sso_oauth_client_secret` shown as
`********` (posting the mask back keeps the stored secret), and the endpoints
to give the IdP. With `sso_provider` `none`, `sso/login` answers 404.

| Route (under `/wp-json/escalated/v1`) | Use |
|---|---|
| `GET sso/login` | Start sign-in with the chosen provider |
| `GET sso/saml/metadata` | SP metadata XML; its URL is the default SP entity ID |
| `POST sso/saml/acs` | SAML assertion consumer (HTTP-POST) |
| `GET sso/oauth/callback` | OAuth 2 / OIDC redirect URI |
| `GET\|POST sso/jwt/callback` | JWT hand-off (`jwt` parameter) |

Each answers 404 unless its provider is the chosen one, and every refusal is
the same `403 Single sign-on failed.` with the reason in the PHP error log.
SAML compares the response's Destination with the ACS URL, so use pretty
permalinks (the ACS URL must not carry a `?rest_route=` query).

- **SAML**: the IdP certificate must be configured (without it every response
  is refused); the assertion itself must be signed by it (a signed Response
  alone is not enough, keys in `KeyInfo` are not trusted, encrypted
  assertions are refused); Issuer, Audience, Destination, Recipient and the
  validity windows (php-saml's fixed allowance) are checked; `InResponseTo`
  must be an AuthnRequest this site issued in the last 10 minutes, consumed on
  use and stored server-side in `escalated_sso_message_ids`; unsolicited
  responses only with `ESCALATED_SSO_ALLOW_IDP_INITIATED`; an assertion ID is
  accepted once.
- **OAuth 2 / OIDC**: authorization code with PKCE (`S256`) and a single-use
  `state`, kept server-side (a transient) under an HttpOnly, SameSite=Lax
  cookie for 10 minutes; the code is exchanged server to server and the
  profile read from the userinfo URL. A missing or mismatched state, an IdP
  `error`, a failed exchange or `email_verified: false` is refused.
- **JWT**: the algorithm is pinned to `sso_jwt_algorithm` (HS* secret or RS*
  public key), so `alg: none` and algorithm switching are refused; `exp` is
  required, `nbf`/`iat` honoured with the clock skew, `aud` must be
  `ESCALATED_SSO_JWT_AUDIENCE` (default `home_url()`), and `jti` is required
  and single-use.

**Users**: the email comes from `sso_attr_email` (SAML falls back to the
NameID), the name from `sso_attr_name`, the role from `sso_attr_role`. The
user is matched by email, case-insensitively. No match is refused unless
`ESCALATED_SSO_CREATE_USERS`, which creates a WordPress user with the
default role and an unusable random password. With roles & permissions on
and `ESCALATED_SSO_SYNC_ROLES`, a role naming an Escalated role
(`light_agent` or `escalated_light_agent`, or one of yours) replaces the
user's Escalated roles; an unknown value changes nothing.

With the switch off, every `sso/*` route and the Single Sign-On screen
answer 404.

### Turning it on in an existing install

1. Update the plugin (the schema upgrade adds `escalated_sso_message_ids`).
2. Set `ESCALATED_SSO`, decide on `ESCALATED_SSO_CREATE_USERS` and
   `ESCALATED_SSO_SYNC_ROLES`.
3. Pick the provider in Escalated > Single Sign-On and give the IdP the
   endpoints listed there; a SAML install needs the IdP certificate.

## API token abilities

The token-authenticated REST API (`escalated/v1`) checks a token's abilities
the way escalated-laravel's API does. A token is issued with one or more of:

| Ability | Reaches |
|---|---|
| `agent` | the agent routes: tickets (list, show, create, reply, note, status, priority, assign, tags, follow, macros, actions, merge, split, snooze), agents, departments, tags, canned responses, macros, dashboard, saved views, chat, events, attachments, `auth/*`, `admin/two-factor` |
| `admin` | the admin routes: `DELETE tickets/{ref}`, `automations`, `admin/api-tokens` |
| `*` | both |

An admin-only token cannot reach the agent routes; give a token both `agent`
and `admin` (or `*`) for both. A valid token without the ability is answered
403; a missing, unknown or expired token 401. Route permissions and
capability checks (for example `escalated_api_token_manage` on
`admin/api-tokens`) still apply on top.

New tokens take only `agent`, `admin` and `*`: the wp-admin form offers those,
and `POST admin/api-tokens` answers 422 for anything else. Tokens issued
before 1.6.9 with the old form's scoped abilities keep working at the level
they describe, and never reach the admin routes:

| Old ability | Now grants |
|---|---|
| `tickets:write` | `agent` |
| `tickets:read`, `reports:read` | `agent`, read-only (`GET` / `HEAD`) |
| `replies:write` | `agent`, read-only, plus `POST tickets/{ref}/reply` and `/note` |

Re-issue such a token with `agent` to give it the full agent API.
