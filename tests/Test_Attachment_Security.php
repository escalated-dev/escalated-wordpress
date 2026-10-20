<?php

/**
 * Attachment storage and downloads.
 *
 * Inbound email and form uploads used to be written into the public uploads
 * tree under the sender's file name and linked directly, so an emailed
 * invoice.html or logo.svg ran script on the WordPress origin when an agent
 * opened it. Bytes now go to a private store under random names, and are
 * served only by an authenticated download route as an attachment with
 * nosniff.
 */

use Escalated\Api\Attachment_Controller;
use Escalated\Escalated;
use Escalated\Models\Reply;
use Escalated\Models\Ticket;
use Escalated\Services\AttachmentService;
use Escalated\Services\InboundEmailService;

class Test_Attachment_Security extends WP_UnitTestCase
{
    private int $agent;

    private int $requester;

    private int $stranger;

    private object $ticket;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        $this->agent = $this->factory->user->create(['role' => 'escalated_agent']);
        $this->requester = $this->factory->user->create(['role' => 'subscriber']);
        $this->stranger = $this->factory->user->create(['role' => 'subscriber']);

        $id = Ticket::create([
            'reference' => 'ESC-'.wp_rand(10000, 99999),
            'subject' => 'Printer',
            'description' => 'It jams.',
            'status' => 'open',
            'priority' => 'medium',
            'requester_id' => $this->requester,
        ]);
        $this->ticket = Ticket::find($id);

        global $wp_rest_server;
        $wp_rest_server = null;
    }

    public function tear_down(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user(0);

        parent::tear_down();
    }

    private function inbound(array $attachment): array
    {
        return (new InboundEmailService)->store_inbound_attachments('ticket', (int) $this->ticket->id, [$attachment]);
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function download(int $attachment_id, array $headers = []): array
    {
        global $wp_rest_server;
        $server = $wp_rest_server = new Spy_REST_Server;
        do_action('rest_api_init', $server);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        foreach ($headers as $name => $value) {
            $_SERVER['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $server->serve_request('/escalated/v1/attachments/'.$attachment_id.'/download');
        foreach (array_keys($headers) as $name) {
            unset($_SERVER['HTTP_'.strtoupper(str_replace('-', '_', $name))]);
        }

        return ['status' => (int) $server->status, 'headers' => $server->sent_headers, 'body' => (string) $server->sent_body];
    }

    private function legacy_row(string $path, string $mime, string $type = 'ticket', ?int $parent = null): int
    {
        global $wpdb;
        $wpdb->insert(Escalated::table('attachments'), [
            'attachable_type' => $type,
            'attachable_id' => $parent ?? (int) $this->ticket->id,
            'filename' => basename($path),
            'original_filename' => basename($path),
            'mime_type' => $mime,
            'size' => (int) @filesize($path),
            'path' => $path,
            'created_at' => current_time('mysql'),
        ]);

        return (int) $wpdb->insert_id;
    }

    // =========================================================================
    // Storage
    // =========================================================================

    public function test_inbound_html_and_svg_attachments_are_refused(): void
    {
        foreach (['invoice.html', 'invoice.HTM', 'logo.svg', 'page.xhtml', 'shell.php8'] as $name) {
            $this->assertSame([false], $this->inbound([
                'name' => $name,
                'type' => 'text/plain',
                'content' => '<script>alert(document.cookie)</script>',
            ]), $name);
        }
    }

    public function test_inbound_attachment_is_stored_privately_under_a_random_name(): void
    {
        [$stored] = $this->inbound(['name' => 'error-log.txt', 'type' => 'text/html', 'content' => "paper jam\n"]);

        $this->assertIsObject($stored);
        $this->assertSame('error-log.txt', $stored->original_filename);
        // The sender's claimed type is ignored; the bytes decide.
        $this->assertSame('text/plain', $stored->mime_type);

        $dir = wp_normalize_path(AttachmentService::storage_dir());
        $path = wp_normalize_path($stored->path);
        $this->assertStringStartsWith($dir.'/', $path);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', basename($path));
        $this->assertSame(basename($path), $stored->filename);
        $this->assertStringNotContainsString('error-log', $path);
        $this->assertSame("paper jam\n", file_get_contents($path));

        $this->assertStringContainsString('Require all denied', (string) file_get_contents($dir.'/.htaccess'));
        $this->assertFileExists($dir.'/web.config');
        $this->assertFileExists($dir.'/index.php');
    }

    public function test_form_upload_uses_the_private_store(): void
    {
        $tmp = wp_tempnam('upload');
        file_put_contents($tmp, 'PDF-ish bytes');
        add_filter('escalated_attachment_is_uploaded_file', '__return_true');

        $stored = (new AttachmentService)->store('ticket', (int) $this->ticket->id, [
            'name' => 'report.txt', 'type' => 'text/html', 'tmp_name' => $tmp, 'error' => 0, 'size' => 13,
        ]);
        remove_filter('escalated_attachment_is_uploaded_file', '__return_true');

        $this->assertIsObject($stored);
        $this->assertStringStartsWith(wp_normalize_path(AttachmentService::storage_dir()).'/', wp_normalize_path($stored->path));
        $this->assertFileDoesNotExist($tmp);
    }

    public function test_form_upload_refuses_a_file_that_was_not_uploaded(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new AttachmentService)->store('ticket', (int) $this->ticket->id, [
            'name' => 'passwd.txt', 'type' => 'text/plain', 'tmp_name' => ABSPATH.'wp-config-sample.php', 'error' => 0, 'size' => 10,
        ]);
    }

    public function test_form_upload_refuses_svg(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new AttachmentService)->validate_file(['name' => 'logo.svg', 'size' => 10, 'error' => 0]);
    }

    public function test_serialized_url_is_the_download_route(): void
    {
        [$stored] = $this->inbound(['name' => 'a.txt', 'content' => 'x']);

        $url = AttachmentService::format_attachment($stored)['url'];

        $this->assertStringContainsString('escalated/v1/attachments/'.(int) $stored->id.'/download', $url);
        $this->assertStringNotContainsString(wp_upload_dir()['baseurl'], $url);
    }

    // =========================================================================
    // Downloads
    // =========================================================================

    public function test_agent_downloads_as_an_attachment_with_nosniff(): void
    {
        [$stored] = $this->inbound(['name' => 'error-log.txt', 'content' => "paper jam\n"]);
        wp_set_current_user($this->agent);

        $response = $this->download((int) $stored->id);

        $this->assertSame(200, $response['status']);
        $this->assertSame("paper jam\n", $response['body']);
        $this->assertSame('text/plain', $response['headers']['Content-Type']);
        $this->assertStringStartsWith('attachment; filename="error-log.txt"', $response['headers']['Content-Disposition']);
        $this->assertSame('nosniff', $response['headers']['X-Content-Type-Options']);
        $this->assertStringContainsString('no-store', $response['headers']['Cache-Control']);
        $this->assertStringContainsString('private', $response['headers']['Cache-Control']);
        $this->assertStringContainsString('sandbox', $response['headers']['Content-Security-Policy']);
    }

    public function test_existing_public_html_attachment_is_served_as_octet_stream(): void
    {
        // A file stored by an earlier version under uploads/escalated keeps
        // working, through the download route only.
        $dir = wp_upload_dir()['basedir'].'/escalated/2026/01';
        wp_mkdir_p($dir);
        $path = $dir.'/invoice-'.wp_rand().'.html';
        file_put_contents($path, '<script>alert(1)</script>');
        $id = $this->legacy_row($path, 'text/html');
        wp_set_current_user($this->agent);

        $response = $this->download($id);
        @unlink($path);

        $this->assertSame(200, $response['status']);
        $this->assertSame('application/octet-stream', $response['headers']['Content-Type']);
        $this->assertStringStartsWith('attachment;', $response['headers']['Content-Disposition']);
        $this->assertSame('nosniff', $response['headers']['X-Content-Type-Options']);
    }

    public function test_paths_outside_the_attachment_stores_are_not_served(): void
    {
        $id = $this->legacy_row(ABSPATH.'wp-config-sample.php', 'text/plain');
        wp_set_current_user($this->agent);

        $this->assertSame(404, $this->download($id)['status']);
    }

    public function test_anonymous_download_is_refused(): void
    {
        [$stored] = $this->inbound(['name' => 'a.txt', 'content' => 'secret']);

        $response = $this->download((int) $stored->id);

        $this->assertSame(401, $response['status']);
        $this->assertStringNotContainsString('secret', $response['body']);
    }

    public function test_requester_may_download_but_another_customer_may_not(): void
    {
        [$stored] = $this->inbound(['name' => 'a.txt', 'content' => 'mine']);

        wp_set_current_user($this->requester);
        $this->assertSame(200, $this->download((int) $stored->id)['status']);

        wp_set_current_user($this->stranger);
        $this->assertSame(403, $this->download((int) $stored->id)['status']);
    }

    public function test_internal_note_attachments_are_staff_only(): void
    {
        $note = Reply::create([
            'ticket_id' => (int) $this->ticket->id,
            'author_id' => $this->agent,
            'body' => 'internal',
            'is_internal_note' => 1,
            'type' => 'note',
        ]);
        [$stored] = (new InboundEmailService)->store_inbound_attachments('reply', (int) $note, [['name' => 'n.txt', 'content' => 'note']]);

        wp_set_current_user($this->requester);
        $this->assertSame(403, $this->download((int) $stored->id)['status']);

        wp_set_current_user($this->agent);
        $this->assertSame(200, $this->download((int) $stored->id)['status']);
    }

    public function test_download_headers_force_octet_stream_for_unsafe_types(): void
    {
        foreach (['text/html', 'image/svg+xml', 'application/xhtml+xml', 'text/xml', ''] as $mime) {
            $headers = Attachment_Controller::headers((object) ['mime_type' => $mime, 'original_filename' => 'x'], 1);
            $this->assertSame('application/octet-stream', $headers['Content-Type'], $mime);
        }

        $headers = Attachment_Controller::headers((object) ['mime_type' => 'image/png', 'original_filename' => "a\"b\r\n.png"], 1);
        $this->assertSame('image/png', $headers['Content-Type']);
        $this->assertStringNotContainsString("\n", $headers['Content-Disposition']);
        $this->assertStringStartsWith('attachment; filename="a_b__.png"', $headers['Content-Disposition']);
    }

    public function test_admin_ticket_page_links_through_the_download_route(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__).'/templates/admin/ticket-detail.php');

        $this->assertStringNotContainsString('path_to_url', $template);
        $this->assertSame(2, substr_count($template, 'AttachmentService::download_url($att)'));
    }
}
