<?php

namespace Escalated\Services;

use Escalated\Escalated;
use Escalated\Models\Setting;
use Escalated\Models\Ticket;

/** Request-local access context. Host jobs without an actor retain full access. */
final class TicketAccess
{
    private static ?int $actor = null;

    private static int $bypass = 0;

    private static ?\WeakMap $tokens = null;

    public static function register(): void
    {
        add_filter('query', [TicketVisibilitySql::class, 'filter'], PHP_INT_MAX);
        add_filter('rest_request_before_callbacks', static function ($response, $handler, $request) {
            if (self::$tokens !== null) {
                unset(self::$tokens[$request]);
            }

            return $response;
        }, 0, 3);
        add_filter('rest_dispatch_request', static function ($result, $request, $route, $handler) {
            if ($result !== null || ! isset(self::$tokens[$request])) {
                return $result;
            }

            return self::run_as(self::$tokens[$request], static fn () => call_user_func($handler['callback'], $request));
        }, PHP_INT_MAX, 4);
    }

    public static function token_actor(\WP_REST_Request $request, int $user): void
    {
        self::$tokens ??= new \WeakMap;
        self::$tokens[$request] = $user;
    }

    public static function enabled(): bool
    {
        return Setting::get('visibility', 'all') === 'department'
            || (Setting::get_bool('accounts_enabled') && Setting::get_bool('accounts_scope_agents'));
    }

    public static function is_admin(int $user): bool
    {
        $identity = get_userdata($user);

        return $identity && (user_can($identity, 'manage_options') || in_array('escalated_admin', $identity->roles, true));
    }

    public static function is_staff(int $user): bool
    {
        return $user > 0 && (self::is_admin($user) || user_can($user, 'escalated_ticket_view'));
    }

    public static function actor(): int
    {
        return self::$actor ?? get_current_user_id();
    }

    public static function restricted(): bool
    {
        if (self::$bypass > 0) {
            return false;
        }
        $user = self::actor();
        if (self::is_admin($user) || (self::$actor === null && ! self::is_staff($user))) {
            return false;
        }

        return self::enabled();
    }

    public static function run_as(int $user, callable $callback)
    {
        $previous = self::$actor;
        $bypass = self::$bypass;
        self::$actor = $user;
        self::$bypass = 0;
        try {
            return $callback();
        } finally {
            self::$actor = $previous;
            self::$bypass = $bypass;
        }
    }

    /** Trusted internal code only. This never changes recipient authorization. */
    public static function without_restriction(callable $callback)
    {
        self::$bypass++;
        try {
            return $callback();
        } finally {
            self::$bypass--;
        }
    }

    public static function predicate(string $alias): string
    {
        $user = self::actor();
        if (! self::is_staff($user)) {
            return '1=0';
        }
        $db = Escalated::db();
        $where = ['1=1'];
        if (Setting::get('visibility', 'all') === 'department') {
            $memberships = Escalated::table('department_agent');
            $where[] = "EXISTS (SELECT 1 FROM `{$memberships}` access_membership WHERE access_membership.department_id = {$alias}.department_id AND access_membership.user_id = ".(int) $user.')';
        }
        if (Setting::get_bool('accounts_enabled') && Setting::get_bool('accounts_scope_agents')) {
            $account = TicketAccounts::identity(apply_filters('escalated_agent_account', null, $user));
            $where[] = $account ? $db->prepare(
                "CAST({$alias}.account_type AS BINARY) = CAST(%s AS BINARY) AND CAST({$alias}.account_id AS BINARY) = CAST(%s AS BINARY)",
                $account['type'], $account['id']
            ) : '1=0';
        }

        return '('.implode(' AND ', $where).')';
    }

    /** Reload the ticket and memberships, including when called inside a bypass. */
    public static function can_receive(int $ticket, int $user): bool
    {
        if (! self::enabled()) {
            return true;
        }
        if (! self::is_staff($user)) {
            return false;
        }

        return self::run_as($user, static fn () => Ticket::find($ticket) !== null);
    }

    public static function allows(array $ticket, int $user): bool
    {
        if (! self::enabled() || self::is_admin($user)) {
            return true;
        }
        if (! self::is_staff($user)) {
            return false;
        }
        if (Setting::get('visibility', 'all') === 'department') {
            $db = Escalated::db();
            $table = Escalated::table('department_agent');
            if (empty($ticket['department_id']) || ! $db->get_var($db->prepare(
                "SELECT 1 FROM `{$table}` WHERE department_id = %d AND user_id = %d", $ticket['department_id'], $user
            ))) {
                return false;
            }
        }
        if (Setting::get_bool('accounts_enabled') && Setting::get_bool('accounts_scope_agents')) {
            $account = TicketAccounts::identity(apply_filters('escalated_agent_account', null, $user));

            return $account !== null && ($ticket['account_type'] ?? null) === $account['type']
                && (string) ($ticket['account_id'] ?? '') === $account['id'];
        }

        return true;
    }

    public static function require_ticket(int $id): object
    {
        $ticket = Ticket::find($id);
        if (! $ticket) {
            throw new \InvalidArgumentException('Ticket not found.');
        }

        return $ticket;
    }
}
