<?php

namespace Escalated\Services;

use Escalated\Escalated;
use Escalated\Helpers\Enums;
use Escalated\Models\Setting;

class AttachmentService
{
    /**
     * Store a single uploaded file and create an Attachment database record.
     *
     * The file is validated against the blocked extensions and the maximum
     * size, then moved into the private attachment store under a random name
     * (see store_contents()). It is never placed in the public uploads tree.
     *
     * @param  string  $attachable_type  The parent type (e.g., 'reply', 'ticket').
     * @param  int  $attachable_id  The parent record ID.
     * @param  array  $file  A single file array from $_FILES (keys: name, type, tmp_name, error, size).
     * @return object|false The created Attachment record, or false on failure.
     *
     * @throws \InvalidArgumentException If the file fails validation.
     */
    public function store(string $attachable_type, int $attachable_id, array $file)
    {
        $this->validate_file($file);

        $tmp = (string) ($file['tmp_name'] ?? '');
        /**
         * Whether the temporary file really came from this request's upload.
         * Only tests should change this.
         */
        if ($tmp === '' || ! apply_filters('escalated_attachment_is_uploaded_file', is_uploaded_file($tmp), $file)) {
            throw new \InvalidArgumentException('File upload failed: not an uploaded file.');
        }

        $content = file_get_contents($tmp);
        if ($content === false) {
            throw new \InvalidArgumentException('File upload failed: the file could not be read.');
        }

        $stored = $this->store_contents($attachable_type, $attachable_id, (string) ($file['name'] ?? ''), $content);
        if ($stored) {
            @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        return $stored;
    }

    /**
     * Store raw bytes as an attachment.
     *
     * Bytes are written to the private store (storage_dir()) under a random,
     * extension-less name, so neither the sender's file name nor its type
     * decides how a web server would treat the file. The original name is kept
     * only in the database and is used as the download file name. The MIME
     * type is detected from the bytes, not taken from the sender.
     *
     * @return object|false The created Attachment record, or false when the
     *                      file is blocked, empty, too large or cannot be saved.
     */
    public function store_contents(string $attachable_type, int $attachable_id, string $original_filename, string $content)
    {
        $original_filename = sanitize_file_name($original_filename !== '' ? $original_filename : 'unnamed');
        if (self::is_blocked_filename($original_filename) || $content === '') {
            return false;
        }

        $max_size_bytes = (int) Setting::get('max_attachment_size_kb', 10240) * 1024;
        $size = strlen($content);
        if ($size > $max_size_bytes) {
            return false;
        }

        $dir = self::storage_dir().'/'.gmdate('Y/m');
        if (! wp_mkdir_p($dir)) {
            return false;
        }

        $filename = bin2hex(random_bytes(20));
        $path = $dir.'/'.$filename;
        if (file_put_contents($path, $content) === false) {
            return false;
        }
        @chmod($path, 0640); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        $mime_type = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = (string) finfo_buffer($finfo, $content);
            finfo_close($finfo);
        }

        $wpdb = \Escalated\Escalated::db();
        $table = Escalated::table('attachments');
        $result = $wpdb->insert($table, [
            'attachable_type' => sanitize_text_field($attachable_type),
            'attachable_id' => $attachable_id,
            'filename' => $filename,
            'original_filename' => $original_filename,
            'mime_type' => sanitize_mime_type($mime_type !== '' ? $mime_type : 'application/octet-stream'),
            'size' => $size,
            'path' => $path,
            'created_at' => current_time('mysql'),
        ]);

        if ($result === false) {
            wp_delete_file($path);

            return false;
        }

        return $this->find((int) $wpdb->insert_id);
    }

    /**
     * Directory holding attachment bytes.
     *
     * Defaults to wp-content/uploads/escalated-private, which is given
     * deny-all .htaccess / web.config files and random file names. On servers
     * that ignore those files (nginx), point the escalated_attachment_storage_dir
     * filter at a directory outside the web root.
     */
    public static function storage_dir(): string
    {
        $uploads = wp_upload_dir(null, false);
        $dir = untrailingslashit((string) apply_filters(
            'escalated_attachment_storage_dir',
            $uploads['basedir'].'/escalated-private'
        ));

        if (wp_mkdir_p($dir)) {
            $guards = [
                '.htaccess' => "# Escalated attachments are served only through the authenticated download route.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n",
                'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
                'index.php' => "<?php\n// Silence is golden.\n",
            ];
            foreach ($guards as $name => $body) {
                if (! file_exists($dir.'/'.$name)) {
                    file_put_contents($dir.'/'.$name, $body);
                }
            }
        }

        return $dir;
    }

    /**
     * Whether a file name has a blocked extension.
     */
    public static function is_blocked_filename(string $filename): bool
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($extension, Enums::blocked_extensions(), true);
    }

    /**
     * Store multiple uploaded files.
     *
     * @param  string  $attachable_type  The parent type (e.g., 'reply', 'ticket').
     * @param  int  $attachable_id  The parent record ID.
     * @param  array  $files  Array of file arrays from $_FILES.
     * @return array Array of created Attachment records (false entries for failures).
     */
    public function store_many(string $attachable_type, int $attachable_id, array $files): array
    {
        $max_attachments = (int) Setting::get('max_attachments_per_reply', 5);
        $results = [];

        $count = 0;
        foreach ($files as $file) {
            if ($count >= $max_attachments) {
                break;
            }

            try {
                $results[] = $this->store($attachable_type, $attachable_id, $file);
            } catch (\InvalidArgumentException $e) {
                $results[] = false;
            }

            $count++;
        }

        return $results;
    }

    /**
     * Delete an attachment record and its file from disk.
     *
     * @param  int  $attachment_id  Attachment record ID.
     * @return bool True on success, false on failure.
     */
    public function delete(int $attachment_id): bool
    {
        $attachment = $this->find($attachment_id);
        if (! $attachment) {
            return false;
        }

        // Delete the file from disk.
        if (! empty($attachment->path) && file_exists($attachment->path)) {
            wp_delete_file($attachment->path);
        }

        // Remove the database record.
        $wpdb = \Escalated\Escalated::db();
        $table = Escalated::table('attachments');

        return $wpdb->delete($table, ['id' => $attachment_id]) !== false;
    }

    /**
     * Validate a file upload against security and size restrictions.
     *
     * Checks the file extension against the blocked extensions list and
     * verifies the file size does not exceed the configured maximum.
     *
     * @param  array  $file  A single file array from $_FILES.
     *
     * @throws \InvalidArgumentException If the file extension is blocked or the file is too large.
     */
    public function validate_file(array $file): void
    {
        // Check for upload errors.
        if (! empty($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
            $error_messages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds the server upload size limit.',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds the form upload size limit.',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload directory.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
            ];

            $message = $error_messages[$file['error']] ?? 'Unknown upload error.';
            throw new \InvalidArgumentException($message);
        }

        // Validate file extension.
        $filename = $file['name'] ?? '';
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $blocked = Enums::blocked_extensions();
        if (in_array($extension, $blocked, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'File type ".%s" is not allowed for security reasons.',
                    esc_html($extension)
                )
            );
        }

        // Validate file size.
        $max_size_kb = (int) Setting::get('max_attachment_size_kb', 10240);
        $max_size_bytes = $max_size_kb * 1024;

        if ($file['size'] > $max_size_bytes) {
            throw new \InvalidArgumentException(
                sprintf(
                    'File size (%s KB) exceeds the maximum allowed size of %s KB.',
                    number_format($file['size'] / 1024, 1),
                    number_format($max_size_kb)
                )
            );
        }
    }

    /**
     * Find an attachment by ID.
     *
     * @param  int  $id  Attachment record ID.
     * @return object|null The attachment record, or null if not found.
     */
    public function find(int $id): ?object
    {
        $wpdb = \Escalated\Escalated::db();

        $table = Escalated::table('attachments');

        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)
        );

        return $result ?: null;
    }

    /**
     * Get all attachments for a given parent record.
     *
     * @param  string  $attachable_type  The parent type.
     * @param  int  $attachable_id  The parent record ID.
     * @return array Array of attachment objects.
     */
    public function get_for(string $attachable_type, int $attachable_id): array
    {
        $wpdb = \Escalated\Escalated::db();

        $table = Escalated::table('attachments');

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE attachable_type = %s AND attachable_id = %d ORDER BY created_at ASC",
                $attachable_type,
                $attachable_id
            )
        );

        return $results ?: [];
    }

    /**
     * The authenticated download URL for an attachment.
     *
     * Attachments are never linked by their storage path. A signed-in browser
     * session gets a REST nonce in the URL (cookie auth needs one); API
     * clients send their bearer token with the request instead.
     */
    public static function download_url(object $attachment): string
    {
        $url = rest_url('escalated/v1/attachments/'.(int) $attachment->id.'/download');

        if (is_user_logged_in()) {
            $url = add_query_arg('_wpnonce', wp_create_nonce('wp_rest'), $url);
        }

        return $url;
    }

    /**
     * A download URL for a guest holding an active access grant (the mobile
     * guest API). It carries a signature over the attachment, the ticket's
     * current grant hash and guest email, and the grant's expiry, so it stops
     * working when the grant expires, rotates or is revoked, or the guest
     * email changes. The grant itself is never put in the URL.
     */
    public static function guest_download_url(object $attachment, object $ticket): string
    {
        $expires = (int) strtotime((string) $ticket->guest_access_expires_at.' UTC');

        return add_query_arg([
            'guest' => self::guest_signature($attachment, $ticket, $expires),
            'expires' => $expires,
        ], rest_url('escalated/v1/attachments/'.(int) $attachment->id.'/download'));
    }

    /**
     * Whether a guest download link (guest_download_url()) is still good for
     * this attachment. Internal-note attachments are never served to guests.
     */
    public static function guest_link_valid(object $attachment, object $ticket, bool $internal, string $signature, int $expires): bool
    {
        if ($internal || $signature === '' || empty($ticket->guest_access_hash) || empty($ticket->guest_email)
            || empty($ticket->guest_access_expires_at) || $expires <= time()
            || (int) strtotime((string) $ticket->guest_access_expires_at.' UTC') <= time()) {
            return false;
        }

        return hash_equals(self::guest_signature($attachment, $ticket, $expires), $signature);
    }

    private static function guest_signature(object $attachment, object $ticket, int $expires): string
    {
        return hash_hmac(
            'sha256',
            (int) $attachment->id.'|'.(int) $ticket->id.'|'.$ticket->guest_access_hash.'|'.GuestEmailVerification::email_digest((string) $ticket->guest_email).'|'.$expires,
            wp_salt('auth').'|escalated-guest-attachment'
        );
    }

    /**
     * Whether a stored path is one this plugin wrote: inside the private
     * store or the legacy public uploads/escalated tree.
     */
    public static function is_servable_path(string $path): bool
    {
        $real = $path !== '' ? realpath($path) : false;
        if ($real === false || ! is_file($real)) {
            return false;
        }

        $real = wp_normalize_path($real);
        $uploads = wp_upload_dir(null, false);
        foreach ([self::storage_dir(), $uploads['basedir'].'/escalated'] as $root) {
            $root = realpath($root);
            if ($root !== false && strpos($real, trailingslashit(wp_normalize_path($root))) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format an attachment record for JSON serialization.
     *
     * The `url` is the authenticated download route, never a storage URL.
     *
     * @param  object  $attachment  A raw DB row from the attachments table.
     * @return array Serializable attachment array including `url`.
     */
    public static function format_attachment(object $attachment): array
    {
        return [
            'id' => (int) $attachment->id,
            'attachable_type' => $attachment->attachable_type,
            'attachable_id' => (int) $attachment->attachable_id,
            'filename' => $attachment->filename,
            'original_filename' => $attachment->original_filename,
            'mime_type' => $attachment->mime_type,
            'size' => (int) $attachment->size,
            'url' => self::download_url($attachment),
            'created_at' => $attachment->created_at,
        ];
    }

    /**
     * Format an array of attachment records for JSON serialization.
     *
     * @param  array  $attachments  Array of raw DB attachment objects.
     * @return array Array of serializable attachment arrays.
     */
    public static function format_many(array $attachments): array
    {
        return array_map([self::class, 'format_attachment'], $attachments);
    }
}
