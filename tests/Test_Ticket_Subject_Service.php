<?php

/**
 * Tests for ticket subjects (host entities a ticket is about).
 */

use Escalated\Contracts\TicketSubject;
use Escalated\Models\Ticket;
use Escalated\Models\TicketSubjectLink;
use Escalated\Services\TicketService;
use Escalated\Services\TicketSubjectService;

class Fake_Ticket_Subject implements TicketSubject
{
    public function __construct(
        private string $id,
        private string $name,
        private ?string $account = null
    ) {}

    public function ticketSubjectTitle(): string
    {
        return $this->name;
    }

    public function ticketSubjectSubtitle(): ?string
    {
        return $this->account !== null ? 'Project · '.$this->account : null;
    }

    public function ticketSubjectUrl(): ?string
    {
        return 'https://example.test/projects/'.$this->id;
    }

    public function ticketSubjectColor(): ?string
    {
        return '#2563eb';
    }

    public function ticketSubjectIcon(): ?string
    {
        return 'folder';
    }
}

class Test_Ticket_Subject_Service extends WP_UnitTestCase
{
    private TicketService $tickets;

    private int $user_id;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        $this->tickets = new TicketService;
        $this->user_id = $this->factory->user->create(['role' => 'subscriber']);

        update_option(TicketSubjectService::OPTION_ALLOWLIST, ['project']);

        add_filter(
            TicketSubjectService::FILTER_RESOLVE,
            function ($subject, $type, $id) {
                if ($type !== 'project') {
                    return $subject;
                }

                $map = [
                    'prj_9f1c' => ['name' => 'Acme Redesign', 'account' => 'Acme'],
                    '7' => ['name' => 'Acme Redesign', 'account' => 'Acme'],
                    'p1' => ['name' => 'A', 'account' => null],
                    'a' => ['name' => 'A', 'account' => null],
                    'b' => ['name' => 'B', 'account' => null],
                    'c' => ['name' => 'C', 'account' => null],
                    '1' => ['name' => 'A', 'account' => null],
                ];

                if (! isset($map[$id])) {
                    return null;
                }

                return new Fake_Ticket_Subject($id, $map[$id]['name'], $map[$id]['account']);
            },
            10,
            3
        );
    }

    public function tear_down(): void
    {
        remove_all_filters(TicketSubjectService::FILTER_RESOLVE);
        delete_option(TicketSubjectService::OPTION_ALLOWLIST);
        parent::tear_down();
    }

    private function create_ticket(): object
    {
        return $this->tickets->create($this->user_id, [
            'subject' => 'Help',
            'description' => 'Body',
        ]);
    }

    public function test_attach_preserves_string_subject_id(): void
    {
        $ticket = $this->create_ticket();

        $link = TicketSubjectService::attach((int) $ticket->id, 'project', 'prj_9f1c', 'project');

        $this->assertSame('project', $link->subject_type);
        $this->assertSame('prj_9f1c', $link->subject_id);
        $this->assertSame('project', $link->role);
        $this->assertCount(1, TicketSubjectLink::for_ticket((int) $ticket->id));
    }

    public function test_attach_is_idempotent_and_updates_role(): void
    {
        $ticket = $this->create_ticket();

        TicketSubjectService::attach((int) $ticket->id, 'project', 'p1');
        TicketSubjectService::attach((int) $ticket->id, 'project', 'p1', 'account');

        $links = TicketSubjectLink::for_ticket((int) $ticket->id);
        $this->assertCount(1, $links);
        $this->assertSame('account', $links[0]->role);
    }

    public function test_serialize_subjects_on_enriched_ticket(): void
    {
        $ticket = $this->create_ticket();
        TicketSubjectService::attach((int) $ticket->id, 'project', '7', 'project');

        $enriched = Ticket::enrich($ticket);

        $this->assertCount(1, $enriched->subjects);
        $this->assertSame('project', $enriched->subjects[0]['type']);
        $this->assertSame('7', $enriched->subjects[0]['id']);
        $this->assertSame('Acme Redesign', $enriched->subjects[0]['title']);
        $this->assertSame('Project · Acme', $enriched->subjects[0]['subtitle']);
        $this->assertFalse($enriched->subjects[0]['missing']);
    }

    public function test_missing_subject_uses_fallback_title(): void
    {
        $ticket = $this->create_ticket();
        TicketSubjectLink::upsert((int) $ticket->id, 'project', 'unknown-id');

        $row = TicketSubjectService::serialize_link(TicketSubjectLink::for_ticket((int) $ticket->id)[0]);

        $this->assertTrue($row['missing']);
        $this->assertSame('project#unknown-id', $row['title']);
    }

    public function test_detach_removes_link(): void
    {
        $ticket = $this->create_ticket();
        TicketSubjectService::attach((int) $ticket->id, 'project', '1');

        $this->assertSame(1, TicketSubjectService::detach((int) $ticket->id, 'project', '1'));
        $this->assertCount(0, TicketSubjectLink::for_ticket((int) $ticket->id));
    }

    public function test_sync_replaces_subjects_in_order(): void
    {
        $ticket = $this->create_ticket();
        TicketSubjectService::attach((int) $ticket->id, 'project', 'a');

        TicketSubjectService::sync((int) $ticket->id, [
            ['type' => 'project', 'id' => 'b', 'role' => 'primary'],
            ['type' => 'project', 'id' => 'c'],
        ]);

        $links = TicketSubjectLink::for_ticket((int) $ticket->id);
        $this->assertCount(2, $links);
        $this->assertSame('b', $links[0]->subject_id);
        $this->assertSame('primary', $links[0]->role);
        $this->assertSame(0, (int) $links[0]->position);
        $this->assertSame('c', $links[1]->subject_id);
        $this->assertSame(1, (int) $links[1]->position);
    }

    public function test_rejects_type_outside_allowlist(): void
    {
        update_option(TicketSubjectService::OPTION_ALLOWLIST, ['customer']);

        $ticket = $this->create_ticket();

        $this->expectException(\InvalidArgumentException::class);
        TicketSubjectService::attach((int) $ticket->id, 'project', '1');
    }

    public function test_allows_any_type_when_allowlist_empty_programmatically(): void
    {
        update_option(TicketSubjectService::OPTION_ALLOWLIST, []);

        $ticket = $this->create_ticket();
        $link = TicketSubjectService::attach((int) $ticket->id, 'project', '1');

        $this->assertNotNull($link->id);
    }

    public function test_api_attach_rejects_when_allowlist_empty(): void
    {
        update_option(TicketSubjectService::OPTION_ALLOWLIST, []);

        $ticket = $this->create_ticket();

        $this->expectException(\InvalidArgumentException::class);
        TicketSubjectService::attach_for_api((int) $ticket->id, 'project', '1');
    }
}
