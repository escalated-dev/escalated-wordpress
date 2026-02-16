<?php

namespace Escalated\Helpers;

class Sanitizer {

    private const ALLOWED_TAGS = '<p><br><b><strong><i><em><u><a><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><pre><code><table><thead><tbody><tr><th><td><img><hr><div><span><sub><sup>';

    public static function sanitize_html( ?string $html ): string {
        if ( $html === null || trim( $html ) === '' ) {
            return '';
        }

        $clean = strip_tags( $html, self::ALLOWED_TAGS );

        // Remove event handler attributes
        $clean = preg_replace( '/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $clean );
        $clean = preg_replace( '/\s+on\w+\s*=\s*\S+/i', '', $clean );

        // Remove javascript: protocol
        $clean = preg_replace( '/\b(href|src|action)\s*=\s*["\']?\s*javascript\s*:/i', '$1="', $clean );

        // Remove data: URLs except images
        $clean = preg_replace( '/\b(href|src|action)\s*=\s*["\']?\s*data\s*:(?!image\/)/i', '$1="', $clean );

        // Remove style with expression() or javascript
        $clean = preg_replace( '/style\s*=\s*["\'][^"\']*expression\s*\([^"\']*["\']/i', '', $clean );
        $clean = preg_replace( '/style\s*=\s*["\'][^"\']*url\s*\(\s*["\']?\s*javascript:[^"\']*["\']/i', '', $clean );

        return apply_filters( 'escalated_sanitize_html', $clean );
    }
}
