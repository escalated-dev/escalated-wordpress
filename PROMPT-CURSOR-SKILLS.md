# Cursor task: skills-management parity for escalated-wordpress (greenfield)

Self-contained brief. Read fully before doing anything.

## Goal
Greenfield: implement the canonical Skills-management contract end-to-end on this WordPress plugin.

**Tracking issue:** https://github.com/escalated-dev/escalated-wordpress/issues/55
**Canonical contract:** https://github.com/escalated-dev/escalated-developer-context/blob/main/domain-model/skills-management.md
**ADR:** https://github.com/escalated-dev/escalated-developer-context/blob/main/decisions/2026-05-13-skills-routing-explicit-mapping.md
**Reference impl:** https://github.com/escalated-dev/escalated-laravel/pull/95 (closest PHP cousin) + https://github.com/escalated-dev/escalated-nestjs/pull/45

## Current state
No skills code today. WordPress plugin with REST controllers in `includes/Api/`. Activation hooks create custom tables.

## Deliverables

1. **Schema migration via the plugin's activation/upgrade flow** (look for an `Installer` / `Migrator` / `dbDelta` pattern): create `wp_escalated_skills`, `wp_escalated_agent_skills`, `wp_escalated_skill_routing_tags`, `wp_escalated_skill_routing_departments` (use the WordPress table prefix — these tables already use a prefixed `escalated_` namespace; mirror the existing convention).

2. **REST controller** (`includes/Api/class-skill-controller.php`): 6 routes registered via `register_rest_route()` under `escalated/v1/admin/skills`. Use `permission_callback` to gate on admin role.

3. **Data model layer**: WordPress plugins typically don't use ORMs — write small repository classes or use `$wpdb` directly, matching the conventions in the existing controllers. Wrap multi-table writes in `$wpdb->query('START TRANSACTION')` / `COMMIT` / `ROLLBACK`.

4. **Routing service**: `class-skill-routing-service.php` implementing the explicit-mapping logic.

5. **Inertia render**: the WordPress plugin serves the same shared Vue frontend. Confirm the admin route maps to `Escalated/Admin/Skills/Index` and `Form` page paths via whatever Inertia bridge the plugin uses.

6. **Tests** (`tests/`): integration tests using WP_UnitTestCase (or whatever the repo uses).

## Process

1. `git checkout -b feat/admin-skills-management`.
2. Read the contract + look at how the existing controllers structure CRUD.
3. Implement and commit logically, reference #55.
4. Push, open PR titled `feat(skills): admin skills management parity (#55)`.

## Constraints
- snake_case at the wire.
- The plugin's coding standard (WPCS) — run `composer phpcs` if defined.
- Stop after pushing. Don't include PROMPT-CURSOR-SKILLS.md in the PR.
