<?php

namespace Escalated\Services;

/**
 * TOTP (RFC 6238) two-factor authentication service.
 *
 * Pure crypto helpers: secret generation, otpauth:// URI construction,
 * TOTP code generation and verification, and recovery-code generation.
 * Storage/encryption concerns live in the {@see \Escalated\Models\TwoFactor}
 * model; this class holds no state.
 *
 * Ported from the Escalated Laravel reference implementation. Implemented
 * with the SPL/hash extension only — no external Composer dependency.
 */
class TwoFactorService
{
    /**
     * Base32 alphabet (RFC 4648) used for secret encoding.
     *
     * @var string
     */
    protected $base32_chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a random 16-character base32 secret.
     */
    public function generate_secret(): string
    {
        $secret = '';
        $bytes = random_bytes(16);

        for ($i = 0; $i < 16; $i++) {
            $secret .= $this->base32_chars[ord($bytes[$i]) % 32];
        }

        return $secret;
    }

    /**
     * Build an otpauth:// URI for QR-code enrollment.
     *
     * @param  string  $secret  Base32 secret.
     * @param  string  $email  Account label (usually the user's email).
     */
    public function generate_qr_uri(string $secret, string $email): string
    {
        $issuer = $this->issuer();
        $label = rawurlencode($issuer.':'.$email);
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ]);

        return "otpauth://totp/{$label}?{$params}";
    }

    /**
     * Verify a submitted TOTP code against a secret.
     *
     * Accepts the current 30-second window and +/- 1 period to tolerate
     * clock drift, matching the Laravel reference. Uses a constant-time
     * comparison to avoid timing oracles.
     *
     * @param  string  $secret  Base32 secret.
     * @param  string  $code  User-supplied 6-digit code.
     */
    public function verify(string $secret, string $code): bool
    {
        $code = trim($code);

        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $time_slice = (int) floor(time() / 30);

        for ($i = -1; $i <= 1; $i++) {
            $calculated = $this->generate_totp($secret, $time_slice + $i);

            if (hash_equals($calculated, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate the 6-digit TOTP code for a given 30-second time slice.
     *
     * Public so tests can assert against the RFC 6238 test vectors.
     *
     * @param  string  $secret  Base32 secret.
     * @param  int  $time_slice  Unix time divided by the 30-second period.
     * @return string Zero-padded 6-digit code.
     */
    public function generate_totp(string $secret, int $time_slice): string
    {
        $secret_key = $this->base32_decode($secret);

        // Pack the counter as a 64-bit big-endian integer.
        $time = pack('N*', 0, $time_slice);

        $hmac = hash_hmac('sha1', $time, $secret_key, true);

        // RFC 4226 dynamic truncation.
        $offset = ord($hmac[strlen($hmac) - 1]) & 0x0F;
        $code = (
            ((ord($hmac[$offset]) & 0x7F) << 24) |
            ((ord($hmac[$offset + 1]) & 0xFF) << 16) |
            ((ord($hmac[$offset + 2]) & 0xFF) << 8) |
            (ord($hmac[$offset + 3]) & 0xFF)
        ) % 1000000;

        return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Decode a base32 (RFC 4648) string into raw bytes.
     */
    protected function base32_decode(string $input): string
    {
        $map = array_flip(str_split($this->base32_chars));
        $input = strtoupper($input);
        $input = rtrim($input, '=');

        $binary = '';
        foreach (str_split($input) as $char) {
            if (! isset($map[$char])) {
                continue;
            }
            $binary .= str_pad(decbin($map[$char]), 5, '0', STR_PAD_LEFT);
        }

        $output = '';
        for ($i = 0; $i + 8 <= strlen($binary); $i += 8) {
            $output .= chr(bindec(substr($binary, $i, 8)));
        }

        return $output;
    }

    /**
     * Generate an array of eight single-use recovery codes.
     *
     * Format: 8 hex chars, a dash, 8 hex chars (e.g. "A1B2C3D4-E5F6A7B8").
     *
     * @return string[]
     */
    public function generate_recovery_codes(): array
    {
        $codes = [];

        for ($i = 0; $i < 8; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4))).'-'.strtoupper(bin2hex(random_bytes(4)));
        }

        return $codes;
    }

    /**
     * Resolve the issuer name shown in authenticator apps.
     */
    protected function issuer(): string
    {
        $name = function_exists('get_bloginfo') ? get_bloginfo('name') : '';

        return $name !== '' ? $name : 'Escalated';
    }
}
