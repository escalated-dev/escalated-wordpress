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
     * Uses wp_handle_upload() for WordPress-standard file handling. Validates
     * the file against blocked extensions and maximum size before processing.
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
        // Validate the file before processing.
        $this->validate_file($file);

        // Ensure the upload handler is available.
        if (! function_exists('wp_handle_upload')) {
            require_once ABSPATH.'wp-admin/includes/file.php';
        }

        $upload_overrides = [
            'test_form' => false,
            'test_type' => false,
        ];

        $uploaded = wp_handle_upload($file, $upload_overrides);

        if (isset($uploaded['error'])) {
            throw new \InvalidArgumentException(
                sprintf('File upload failed: %s', $uploaded['error'])
            );
        }

        global $wpdb;

        $table = Escalated::table('attachments');
        $now = current_time('mysql');

        // Generate a unique filename for storage reference.
        $original_filename = sanitize_file_name($file['name']);
        $filename = wp_unique_filename(dirname($uploaded['file']), $original_filename);

        $attachment_data = [
            'attachable_type' => sanitize_text_field($attachable_type),
            'attachable_id' => $attachable_id,
            'filename' => $filename,
            'original_filename' => $original_filename,
            'mime_type' => sanitize_mime_type($uploaded['type']),
            'size' => $file['size'],
            'path' => $uploaded['file'],
            'created_at' => $now,
        ];

        $result = $wpdb->insert($table, $attachment_data);

        if ($result === false) {
            // Clean up the uploaded file if DB insert fails.
            if (file_exists($uploaded['file'])) {
                wp_delete_file($uploaded['file']);
            }

            return false;
        }

        $attachment_id = $wpdb->insert_id;

        return $this->find($attachment_id);
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
        global $wpdb;
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
        global $wpdb;

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
        global $wpdb;

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
     * Convert an absolute file path to its public URL.
     *
     * The stored path is an absolute server path produced by wp_handle_upload().
     * This method replaces the upload basedir prefix with the corresponding
     * baseurl so the file can be referenced from the browser.
     *
     * @param  string  $absolute_path  Absolute path on disk.
     * @return string|null Public URL, or null if the path is outside the uploads directory.
     */
    public static function path_to_url(string $absolute_path): ?string
    {
        $uploads = wp_upload_dir();
        $basedir = wp_normalize_path($uploads['basedir']);
        $normalized = wp_normalize_path($absolute_path);

        if (strpos($normalized, $basedir) !== 0) {
            return null;
        }

        $relative = substr($normalized, strlen($basedir));

        return $uploads['baseurl'].$relative;
    }

    /**
     * Format an attachment record for JSON serialization.
     *
     * Adds a `url` field so consumers can link directly to the file.
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
            'url' => self::path_to_url($attachment->path ?? ''),
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
