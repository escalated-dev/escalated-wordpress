=== Escalated - Helpdesk & Ticketing System ===
Contributors: escalated
Tags: helpdesk, support, tickets, customer support, SLA, ticketing system
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A full-featured helpdesk and ticketing system for WordPress with multi-role support, SLA tracking, escalation rules, inbound email, macros, and REST API.

== Description ==

Escalated is a comprehensive helpdesk and support ticket system that runs entirely within WordPress. It provides everything you need to manage customer support requests from a single dashboard.

**Key Features:**

* **Ticket Management** - Create, assign, track, and resolve support tickets with full conversation threading.
* **Multi-Role Support** - Built-in Escalated Admin and Escalated Agent roles with granular capabilities. Integrates with WordPress administrators.
* **Departments** - Organize tickets by department and assign agents to specific departments for round-robin or manual assignment.
* **SLA Policies** - Define service level agreements with first-response and resolution targets per priority. Supports both calendar and business hours.
* **Escalation Rules** - Automatically escalate tickets based on conditions like SLA breach, time without response, or priority level.
* **Customer Portal** - Frontend shortcode-based portal where customers can create tickets, view conversations, and rate support.
* **Guest Tickets** - Allow unauthenticated users to submit and track tickets via secure guest tokens.
* **Inbound Email** - Receive and process emails into tickets and replies via Mailgun, Postmark, or Amazon SES webhooks.
* **Macros** - Define reusable actions (status changes, assignments, replies) and apply them to tickets in one click.
* **Canned Responses** - Save common reply templates for quick insertion by agents.
* **Tags** - Categorize tickets with color-coded tags for easy filtering.
* **Activity Timeline** - Full audit log of every action taken on a ticket.
* **Satisfaction Ratings** - Collect star ratings and feedback from customers after ticket resolution.
* **REST API** - Full-featured REST API with Bearer token authentication, rate limiting, and granular ability scoping.
* **Attachments** - Secure file uploads on tickets and replies with blocked extension validation.
* **Automated Cleanup** - Auto-close stale resolved tickets and purge old activity logs on configurable schedules.

**Shortcodes:**

* `[escalated_portal]` - Renders the full customer portal (ticket list, creation form, ticket view).
* `[escalated_guest_form]` - Renders the guest ticket creation form.

== Installation ==

1. Upload the `escalated` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Escalated** in the admin sidebar to configure departments, SLA policies, and settings.
4. Create a page and add the `[escalated_portal]` shortcode to provide a customer-facing support portal.
5. Optionally create a page with `[escalated_guest_form]` for guest ticket submission.

== Frequently Asked Questions ==

= What PHP version is required? =

Escalated requires PHP 8.1 or later.

= What WordPress version is required? =

Escalated requires WordPress 6.0 or later.

= Can guests submit tickets without an account? =

Yes. Enable guest tickets in the Escalated settings and use the `[escalated_guest_form]` shortcode. Guests receive a unique link to track and reply to their tickets.

= How does SLA tracking work? =

Create SLA policies with first-response and resolution targets per priority level. When a policy is attached to a ticket, due dates are calculated automatically. The plugin checks for breaches every minute via WP-Cron and fires actions you can hook into for notifications.

= Can I use the REST API? =

Yes. Generate API tokens from the Escalated admin panel. Authenticate requests using a Bearer token in the Authorization header. The API supports ticket CRUD, replies, notes, status/priority changes, tag management, macros, and more.

= How do I set up inbound email? =

Configure your email provider (Mailgun, Postmark, or Amazon SES) in the Escalated settings. Point the provider's webhook URL to your site's webhook endpoint. Incoming emails will automatically create new tickets or be appended as replies to existing ones.

= Can I customize the frontend portal styles? =

Yes. The frontend styles use CSS custom properties (variables). Override the `--escalated-primary`, `--escalated-success`, and other variables in your theme's CSS to match your branding.

= How do escalation rules work? =

Escalation rules run on a schedule and evaluate conditions against open tickets. When conditions are met (e.g., SLA breached, no response in X hours), the configured actions are applied (e.g., change priority, reassign, notify).

= Can agents have private discussions on a ticket? =

Yes. Internal notes are visible only to agents and admins. They are never shown on the customer portal or to guest users.

== Screenshots ==

1. Admin dashboard with ticket stats overview.
2. Ticket list with filtering and bulk actions.
3. Ticket detail view with conversation thread and sidebar.
4. SLA policy configuration.
5. Customer portal ticket list.
6. Customer portal ticket view with reply form.

== Changelog ==

= 1.0.0 =
* Initial release.
* Ticket management with full CRUD and status transitions.
* Multi-role support (Escalated Admin, Escalated Agent).
* Departments with agent assignment.
* SLA policies with calendar and business hours support.
* Escalation rules with condition-based triggers.
* Customer portal via shortcodes.
* Guest ticket support with token-based access.
* Inbound email via Mailgun, Postmark, and Amazon SES.
* Macros and canned responses.
* Tag management with color coding.
* Activity timeline and audit log.
* Satisfaction ratings.
* REST API with Bearer token auth and rate limiting.
* File attachment support with security validation.
* Auto-close and activity purge cron jobs.

== Upgrade Notice ==

= 1.0.0 =
Initial release of Escalated.
