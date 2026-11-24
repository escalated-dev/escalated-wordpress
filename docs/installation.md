# Installation

## Download

[![Download Latest Release](https://img.shields.io/badge/Download-Latest%20Release-2ea44f?style=for-the-badge&logo=github)](https://github.com/escalated-dev/escalated-wordpress/releases/latest)

- Latest plugin package: [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip)
- All releases: [Releases](https://github.com/escalated-dev/escalated-wordpress/releases)

## Requirements

- WordPress `6.0+`
- PHP `8.1+`

## Installation

1. Place this plugin in your WordPress plugins directory:
   - `wp-content/plugins/escalated`
2. Activate **Escalated** from the WordPress Plugins screen.
3. Go to **Escalated** in wp-admin and configure:
   - Departments
   - SLA Policies
   - Escalation Rules
   - Settings
4. **Required:** schedule the recurring jobs. Add a system cron job that runs
   WP-Cron every minute (`* * * * * wp cron event run --due-now --path=/path/to/wordpress`).
   Without it SLA checks, escalation rules, automations and snooze wake-ups
   stop whenever the site is quiet. See
   [Required: schedule the recurring jobs](operations.md#required-schedule-the-recurring-jobs).
5. Run `wp escalated doctor` to check the setup.
