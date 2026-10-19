<?php

namespace Escalated\Services;

use WP_REST_Request;

/**
 * Host parameters an embedding page sends with a widget ticket or chat
 * (order number, plan, account id, the page URL, ...). They are visitor
 * supplied, so only a flat map of short scalar values is kept.
 */
class WidgetMetadata
{
    public const MAX_ENTRIES = 25;

    public const MAX_KEYS = 50;

    public const MAX_VALUE_LENGTH = 500;

    public const MAX_URL_LENGTH = 2048;

    /**
     * REST arg definition for the optional `metadata` parameter.
     *
     * @return array<string, mixed>
     */
    public static function rest_arg(): array
    {
        return [
            'description' => __('Host parameters from the embedding page (a flat object).', 'escalated'),
            'validate_callback' => [static::class, 'validate'],
        ];
    }

    /**
     * Reject a `metadata` value that is not an object or has too many keys.
     *
     * @param  mixed  $value
     * @return true|\WP_Error
     */
    public static function validate($value)
    {
        if ($value === null) {
            return true;
        }

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            return new \WP_Error('rest_invalid_param', __('metadata must be an object.', 'escalated'), ['status' => 400]);
        }

        if (count($value) > static::MAX_KEYS) {
            return new \WP_Error(
                'rest_invalid_param',
                /* translators: %d: maximum number of metadata keys. */
                sprintf(__('metadata may have at most %d keys.', 'escalated'), static::MAX_KEYS),
                ['status' => 400]
            );
        }

        return true;
    }

    /**
     * The sanitised metadata for a widget request, or null when nothing is kept.
     *
     * @return array<string, scalar>|null
     */
    public static function from_request(WP_REST_Request $request): ?array
    {
        $metadata = $request->get_param('metadata');

        $clean = [];
        foreach (is_array($metadata) ? $metadata : [] as $key => $value) {
            if (count($clean) >= static::MAX_ENTRIES) {
                break;
            }
            $key = (string) $key;
            if (! preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $key)) {
                continue;
            }
            if (is_string($value)) {
                $value = mb_substr(trim($value), 0, static::MAX_VALUE_LENGTH);
            } elseif (! is_int($value) && ! is_float($value) && ! is_bool($value)) {
                continue;
            }
            $clean[$key] = $value;
        }

        if (isset($clean['page_url']) && ! static::is_web_url($clean['page_url'])) {
            unset($clean['page_url']);
        }

        // Recorded by the server from the request's Origin header, so the
        // page cannot claim another site.
        unset($clean['embedded_on']);
        $origin = WidgetOrigins::allowed_cross_origin($request);
        if ($origin !== null) {
            $clean['embedded_on'] = $origin;
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * @param  mixed  $value
     */
    private static function is_web_url($value): bool
    {
        return is_string($value) && strlen($value) <= static::MAX_URL_LENGTH
            && filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) wp_parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
