<?php

namespace Escalated\Services;

use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP (RFC 6238) two-factor authentication service.
 *
 * Pure crypto helpers: secret generation, otpauth:// URI construction,
 * TOTP code generation and verification, and recovery-code generation.
 * Storage/encryption concerns live in the {@see \Escalated\Models\TwoFactor}
 * model; this class holds no state.
 *
 * Ported from the Escalated Laravel reference implementation, on the same
 * maintained library (pragmarx/google2fa) rather than hand-rolled crypto.
 */
class TwoFactorService
{
    /** Steps of clock drift accepted either side of now. */
    public const WINDOW = 1;

    protected Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA;
        $this->google2fa->setWindow(self::WINDOW);
    }

    /**
     * A random 32-character base32 secret (160 bits).
     */
    public function generate_secret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    /**
     * Build an otpauth:// URI for QR-code enrollment.
     *
     * @param  string  $secret  Base32 secret.
     * @param  string  $email  Account label (usually the user's email).
     */
    public function generate_qr_uri(string $secret, string $email): string
    {
        return $this->google2fa->getQRCodeUrl($this->issuer(), $email, $secret);
    }

    /**
     * Whether a submitted code is valid for the secret right now, with one
     * step of drift either side (no replay check: see verify_and_consume).
     */
    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        return (bool) $this->google2fa->verifyKey($secret, $code, self::WINDOW);
    }

    /**
     * Verify a code for an enrolment and record its time step, refusing that
     * step or any earlier one afterwards, so a code works once. The step is
     * claimed with a conditional update, so two requests racing with one
     * code cannot both pass.
     */
    public function verify_and_consume(object $record, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $last = isset($record->last_used_step) && $record->last_used_step !== null ? (int) $record->last_used_step : 0;
        $step = $this->google2fa->verifyKeyNewer(\Escalated\Models\TwoFactor::secret_of($record), $code, $last, self::WINDOW);
        if (! is_int($step) || $step <= $last) {
            return false;
        }

        if (! \Escalated\Models\TwoFactor::claim_step((int) $record->id, $step)) {
            return false;
        }
        $record->last_used_step = $step;

        return true;
    }

    /**
     * The 6-digit TOTP code for a 30-second time slice (RFC 6238, SHA-1).
     *
     * Public so tests can assert against the RFC 6238 test vectors.
     */
    public function generate_totp(string $secret, int $time_slice): string
    {
        return $this->google2fa->oathTotp($secret, $time_slice);
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
