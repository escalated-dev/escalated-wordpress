<?php
/**
 * Demo data for the README screenshots.
 *
 * Run with: wp eval-file screenshots/seed.php --allow-root
 *
 * The previous seed lived inline in screenshots.yml and created three tickets,
 * all stamped current_time(), none resolved. That is why the Reports page
 * showed an em dash for Avg. First Response and Avg. Resolution Time, every
 * chart sat at 33.3%, and the ticket list said "48 seconds ago" on every row.
 *
 * Everything here is fixed rather than random, so the screenshots are stable
 * between runs and a diff in the committed PNGs means something actually
 * changed in the UI.
 *
 * Dates are relative to the run, so the screenshots always look current. The
 * models stamp created_at themselves, so rows are backdated in a second pass.
 */

use Escalated\Models\Automation;
use Escalated\Models\CannedResponse;
use Escalated\Models\Macro;
use Escalated\Models\SlaPolicy;

global $wpdb;

$p = $wpdb->prefix . 'escalated_';
$now = current_time('timestamp');

/** Format a timestamp N hours ago as MySQL datetime. */
$ago = static fn (float $hours): string => gmdate('Y-m-d H:i:s', (int) ($now - ($hours * 3600)));

// ---------------------------------------------------------------- agents

$agents = [];
foreach ([
    ['priya.nair', 'Priya Nair', 'priya@example.com'],
    ['tom.okafor', 'Tom Okafor', 'tom@example.com'],
    ['lena.hartmann', 'Lena Hartmann', 'lena@example.com'],
] as [$login, $name, $email]) {
    $id = username_exists($login) ?: wp_create_user($login, wp_generate_password(20), $email);
    if (! is_wp_error($id)) {
        wp_update_user(['ID' => $id, 'display_name' => $name, 'first_name' => explode(' ', $name)[0]]);
        (new WP_User($id))->add_role('administrator');
        $agents[] = (int) $id;
    }
}
$admin = 1;
$all_agents = array_merge([$admin], $agents);

// ---------------------------------------------------------------- departments

$departments = [];
foreach ([
    'General Support',
    'Billing',
    'Technical',
    'Onboarding',
    'Enterprise',
] as $name) {
    $wpdb->insert($p . 'departments', [
        'name' => $name,
        // slug is NOT NULL with no default; leaving it out only works while
        // MySQL is not in strict mode.
        'slug' => sanitize_title($name),
        'is_active' => 1,
        'created_at' => $ago(24 * 60),
        'updated_at' => $ago(24 * 60),
    ]);
    $departments[$name] = (int) $wpdb->insert_id;
}

// ---------------------------------------------------------------- SLA policies

// first_response_hours / resolution_hours are priority => hours maps; the model
// json-encodes them.
SlaPolicy::create([
    'name' => 'Standard Support',
    'description' => 'Default targets for all inbound tickets.',
    'is_default' => 1,
    'first_response_hours' => ['low' => 24, 'medium' => 8, 'high' => 4, 'urgent' => 2, 'critical' => 1],
    'resolution_hours' => ['low' => 120, 'medium' => 72, 'high' => 24, 'urgent' => 8, 'critical' => 4],
    'business_hours_only' => 1,
    'is_active' => 1,
]);

SlaPolicy::create([
    'name' => 'Enterprise 24/7',
    'description' => 'Round-the-clock targets for Enterprise plans.',
    'is_default' => 0,
    'first_response_hours' => ['low' => 8, 'medium' => 4, 'high' => 1, 'urgent' => 1, 'critical' => 1],
    'resolution_hours' => ['low' => 48, 'medium' => 24, 'high' => 8, 'urgent' => 4, 'critical' => 2],
    'business_hours_only' => 0,
    'is_active' => 1,
]);

$default_sla = (int) $wpdb->get_var("SELECT id FROM {$p}sla_policies WHERE is_default = 1 LIMIT 1");

// ---------------------------------------------------------------- tags

$tags = [];
foreach ([
    ['bug', '#e74c3c'],
    ['feature-request', '#3498db'],
    ['billing', '#2ecc71'],
    ['onboarding', '#9b59b6'],
    ['integration', '#f39c12'],
    ['performance', '#1abc9c'],
    ['security', '#c0392b'],
] as [$name, $colour]) {
    $wpdb->insert($p . 'tags', [
        'name' => $name,
        'slug' => sanitize_title($name),
        'color' => $colour,
        'created_at' => $ago(24 * 60),
        'updated_at' => $ago(24 * 60),
    ]);
    $tags[$name] = (int) $wpdb->insert_id;
}

// ---------------------------------------------------------------- contacts

$contacts = [];
foreach ([
    ['Sarah Chen', 'sarah@acme.co'],
    ['James Wilson', 'james@corp.io'],
    ['Maria Garcia', 'maria@startup.dev'],
    ['Tom Baker', 'tom@enterprise.com'],
    ['Priya Patel', 'priya@example.com'],
    ['Alex Kim', 'alex@design.co'],
    ['David Liu', 'david@bigcorp.com'],
    ['Nina Petrova', 'nina@saas.io'],
    ['Omar Haddad', 'omar@logistics.net'],
    ['Grace Mwangi', 'grace@fintech.africa'],
] as [$name, $email]) {
    $wpdb->insert($p . 'contacts', [
        'email' => $email,
        'name' => $name,
        'created_at' => $ago(24 * 60),
        'updated_at' => $ago(24 * 60),
    ]);
    $contacts[] = ['id' => (int) $wpdb->insert_id, 'name' => $name, 'email' => $email];
}

// ---------------------------------------------------------------- tickets
//
// [subject, status, priority, department, channel, age_hours,
//  first_response_after_hours|null, resolved_after_hours|null, tags]

$rows = [
    ['Cannot log in after password reset', 'open', 'urgent', 'Technical', 'email', 1.5, 0.4, null, ['bug', 'security']],
    ['CSV export times out on large reports', 'open', 'high', 'Technical', 'web', 3.2, 1.1, null, ['bug', 'performance']],
    ['Billing question about annual upgrade', 'waiting_on_customer', 'low', 'Billing', 'email', 6.0, 2.0, null, ['billing']],
    ['SSO redirect loop on Firefox', 'in_progress', 'urgent', 'Technical', 'web', 8.5, 0.6, null, ['bug', 'security']],
    ['Feature request: dark mode', 'open', 'low', 'General Support', 'web', 11.0, 5.0, null, ['feature-request']],
    ['Webhook deliveries delayed by 15 minutes', 'escalated', 'critical', 'Technical', 'api', 14.0, 0.3, null, ['bug', 'integration']],
    ['Custom field values not persisting', 'in_progress', 'high', 'Technical', 'web', 19.0, 1.8, null, ['bug']],
    ['Knowledge base search returns nothing', 'resolved', 'medium', 'General Support', 'web', 26.0, 2.2, 20.0, ['bug']],
    ['Invoice shows the wrong VAT rate', 'resolved', 'high', 'Billing', 'email', 31.0, 1.0, 12.0, ['billing']],
    ['How do I invite a teammate?', 'closed', 'low', 'Onboarding', 'chat', 38.0, 0.5, 3.0, ['onboarding']],
    ['Mobile app crashes uploading attachments', 'in_progress', 'urgent', 'Technical', 'web', 44.0, 0.8, null, ['bug']],
    ['Automation rule not firing on status change', 'open', 'medium', 'Technical', 'web', 52.0, 4.0, null, ['bug']],
    ['Request: SAML support for Enterprise', 'waiting_on_agent', 'medium', 'Enterprise', 'email', 60.0, 6.0, null, ['feature-request']],
    ['Duplicate notification emails', 'resolved', 'medium', 'General Support', 'email', 68.0, 1.5, 30.0, ['bug']],
    ['Slow dashboard load with 10k tickets', 'escalated', 'high', 'Technical', 'web', 76.0, 2.5, null, ['performance']],
    ['Cannot remove a deactivated agent', 'resolved', 'low', 'General Support', 'web', 84.0, 3.0, 40.0, []],
    ['API token rotation returns 401', 'closed', 'high', 'Technical', 'api', 95.0, 0.7, 18.0, ['integration', 'security']],
    ['Onboarding checklist stuck at step 3', 'closed', 'medium', 'Onboarding', 'chat', 104.0, 1.2, 9.0, ['onboarding']],
    ['Refund not reflected on the invoice', 'resolved', 'high', 'Billing', 'email', 118.0, 0.9, 26.0, ['billing']],
    ['Zapier integration stopped syncing', 'resolved', 'urgent', 'Technical', 'api', 132.0, 0.4, 14.0, ['integration']],
    ['Add bulk reassign to the ticket list', 'open', 'low', 'General Support', 'web', 150.0, 8.0, null, ['feature-request']],
    ['Attachment previews fail for HEIC', 'closed', 'low', 'Technical', 'web', 168.0, 5.5, 60.0, ['bug']],
    ['Enterprise plan seat count mismatch', 'resolved', 'critical', 'Enterprise', 'email', 190.0, 0.2, 6.0, ['billing']],
    ['Satisfaction survey never sent', 'closed', 'medium', 'General Support', 'email', 214.0, 2.8, 48.0, ['bug']],
    ['Timezone wrong on scheduled reports', 'resolved', 'medium', 'Technical', 'web', 240.0, 3.5, 36.0, ['bug']],
    ['Request: audit log export', 'closed', 'low', 'Enterprise', 'web', 280.0, 7.0, 90.0, ['feature-request', 'security']],
    ['Rate limit hit on the public API', 'resolved', 'high', 'Technical', 'api', 320.0, 1.3, 22.0, ['integration', 'performance']],
    ['Cannot merge tickets across departments', 'closed', 'medium', 'General Support', 'web', 360.0, 4.5, 72.0, ['bug']],
];

$ticket_ids = [];
$i = 0;

foreach ($rows as [$subject, $status, $priority, $dept, $channel, $age, $fr, $res, $row_tags]) {
    $contact = $contacts[$i % count($contacts)];

    // Unassigned only where it reads naturally: a few untouched open tickets.
    $assignee = in_array($status, ['open'], true) && $i % 4 === 0
        ? null
        : $all_agents[$i % count($all_agents)];

    $wpdb->insert($p . 'tickets', [
        'reference' => sprintf('ESC-%05d', $i + 1),
        'subject' => $subject,
        'description' => $subject . '. Reported by ' . $contact['name'] . '.',
        'status' => $status,
        'priority' => $priority,
        'requester_id' => $admin,
        'contact_id' => $contact['id'],
        'assigned_to' => $assignee,
        'department_id' => $departments[$dept] ?? null,
        'sla_policy_id' => $default_sla ?: null,
        'channel' => $channel,
        'created_at' => $ago($age),
        'updated_at' => $ago(max(0.1, $age - ($res ?? $fr ?? 0))),
        'first_response_at' => $fr === null ? null : $ago($age - $fr),
        'resolved_at' => $res === null ? null : $ago($age - $res),
        'closed_at' => ($res !== null && in_array($status, ['closed'], true)) ? $ago($age - $res) : null,
        // A couple of deliberate breaches so the SLA counters are not all zero.
        'sla_first_response_breached' => ($fr !== null && $fr > 4) ? 1 : 0,
        'sla_resolution_breached' => ($res !== null && $res > 48) ? 1 : 0,
    ]);

    $tid = (int) $wpdb->insert_id;
    $ticket_ids[] = $tid;

    foreach ($row_tags as $t) {
        if (isset($tags[$t])) {
            $wpdb->insert($p . 'ticket_tag', ['ticket_id' => $tid, 'tag_id' => $tags[$t]]);
        }
    }

    // Replies: the requester opens, an agent answers, longer threads get more.
    $thread = [
        [$admin, 'Hi, I am hitting this consistently. Anything you need from me?', 0, 'reply', $age - 0.1],
    ];
    if ($fr !== null) {
        $thread[] = [$assignee ?? $admin, 'Thanks for the detail. Reproducing it now and will report back.', 0, 'reply', $age - $fr];
        $thread[] = [$assignee ?? $admin, 'Narrowed it down. Raising with engineering.', 1, 'note', $age - $fr - 0.2];
    }
    if ($res !== null) {
        $thread[] = [$assignee ?? $admin, 'This is fixed and shipping in the next release. Closing out.', 0, 'reply', $age - $res];
    }

    foreach ($thread as [$author, $body, $internal, $type, $at]) {
        $wpdb->insert($p . 'replies', [
            'ticket_id' => $tid,
            'author_id' => $author,
            'body' => $body,
            'is_internal_note' => $internal,
            'type' => $type,
            'created_at' => $ago(max(0.05, $at)),
            'updated_at' => $ago(max(0.05, $at)),
        ]);
    }

    $i++;
}

// ---------------------------------------------------------------- canned responses

foreach ([
    ['Asking for reproduction steps', 'Thanks for reporting this. Could you share the exact steps, the browser you are using and a screenshot if you have one?', 'Triage'],
    ['Escalating to engineering', 'I have reproduced this and raised it with engineering. I will update you as soon as I have a fix or a workaround.', 'Triage'],
    ['Refund processed', 'Your refund has been processed and should appear on your statement within five working days.', 'Billing'],
    ['Plan upgrade guidance', 'You can upgrade from Settings, Billing, Change plan. The new rate is prorated from today.', 'Billing'],
    ['Resolved, pending confirmation', 'This should now be resolved on your account. Let me know if you still see it and I will reopen.', 'Closing'],
    ['Closing for inactivity', 'I have not heard back, so I am closing this. Reply any time and it will reopen automatically.', 'Closing'],
] as [$title, $body, $category]) {
    CannedResponse::create([
        'title' => $title,
        'body' => $body,
        'category' => $category,
        'created_by' => $admin,
        'is_shared' => 1,
    ]);
}

// ---------------------------------------------------------------- macros
// actions: [{type, value}] — see Services/MacroService.

foreach ([
    ['Escalate to Technical', 'Reassign, raise priority and flag for engineering.', [
        ['type' => 'change_department', 'value' => $departments['Technical']],
        ['type' => 'change_priority', 'value' => 'high'],
        ['type' => 'add_tags', 'value' => 'bug'],
    ]],
    ['Close as resolved', 'Mark resolved and thank the requester.', [
        ['type' => 'change_status', 'value' => 'resolved'],
        ['type' => 'reply', 'value' => 'Glad that sorted it. Closing this out, but reply any time to reopen.'],
    ]],
    ['Send to Billing', 'Move to Billing and note why.', [
        ['type' => 'change_department', 'value' => $departments['Billing']],
        ['type' => 'add_tags', 'value' => 'billing'],
        ['type' => 'note', 'value' => 'Routed to Billing: account or invoice question.'],
    ]],
    ['Wait on customer', 'Park the ticket pending a reply.', [
        ['type' => 'change_status', 'value' => 'waiting_on_customer'],
    ]],
    ['Take ownership', 'Assign to me and start work.', [
        ['type' => 'assign_to', 'value' => $admin],
        ['type' => 'change_status', 'value' => 'in_progress'],
    ]],
] as $order => [$name, $description, $actions]) {
    Macro::create([
        'name' => $name,
        'description' => $description,
        'actions' => $actions,
        'created_by' => $admin,
        'is_shared' => 1,
        'sort_order' => $order,
    ]);
}

// ---------------------------------------------------------------- automations
// conditions: [{field, operator, value}], actions: [{type, value}]
// — see Services/AutomationRunner.

foreach ([
    ['Escalate untouched urgent tickets',
        [['field' => 'priority', 'operator' => '=', 'value' => 'urgent'],
            ['field' => 'hours_since_created', 'operator' => '>', 'value' => 2]],
        [['type' => 'change_status', 'value' => 'escalated'],
            ['type' => 'add_note', 'value' => 'Auto-escalated: urgent and unanswered for over two hours.']]],
    ['Close stale waiting-on-customer tickets',
        [['field' => 'status', 'operator' => '=', 'value' => 'waiting_on_customer'],
            ['field' => 'hours_since_updated', 'operator' => '>', 'value' => 120]],
        [['type' => 'change_status', 'value' => 'closed'],
            ['type' => 'add_note', 'value' => 'Auto-closed after five days with no reply.']]],
    ['Flag unassigned tickets after an hour',
        [['field' => 'assigned', 'operator' => '=', 'value' => 'no'],
            ['field' => 'hours_since_created', 'operator' => '>', 'value' => 1]],
        [['type' => 'add_tag', 'value' => 'bug'],
            ['type' => 'change_priority', 'value' => 'high']]],
    ['Raise priority on billing keywords',
        [['field' => 'subject_contains', 'operator' => 'contains', 'value' => 'refund']],
        [['type' => 'change_priority', 'value' => 'high'],
            ['type' => 'add_tag', 'value' => 'billing']]],
] as $pos => [$name, $conditions, $actions]) {
    Automation::create([
        'name' => $name,
        'conditions' => $conditions,
        'actions' => $actions,
        'active' => 1,
        'position' => $pos,
    ]);
}

// ---------------------------------------------------------------- summary

printf(
    "Seeded: %d departments, %d SLA policies, %d tags, %d contacts, %d tickets, %d replies, %d canned responses, %d macros, %d automations\n",
    count($departments),
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}sla_policies"),
    count($tags),
    count($contacts),
    count($ticket_ids),
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}replies"),
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}canned_responses"),
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}macros"),
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}automations")
);
