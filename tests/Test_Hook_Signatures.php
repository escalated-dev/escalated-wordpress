<?php

/**
 * Guard: every Escalated action handler is registered for exactly the
 * arguments its hook is fired with.
 *
 * WordPress hands a handler the first `accepted_args` of the arguments given
 * to do_action(), in order. Nothing checks that the two agree. When they drift
 * apart the handler silently receives the wrong values, or throws a TypeError
 * the first time the hook fires. That is how the broadcast handler for
 * escalated_ticket_assigned came to read the new agent id as the old one.
 *
 * This scans includes/ for do_action('escalated_*', ...) and
 * add_action('escalated_*', [$this, 'method'], $priority, $accepted_args), and
 * checks every registration against the calls that fire its hook.
 */
class Test_Hook_Signatures extends WP_UnitTestCase
{
    /** @var array<string, array<int, array{count: int, where: string}>>|null */
    private static ?array $emitters = null;

    /** @var array<int, array{hook: string, class: string, method: ?string, accepted_args: ?int, where: string}>|null */
    private static ?array $registrations = null;

    public function test_scanner_finds_the_plugin_hooks(): void
    {
        // Guards the guard: a scanner that matched nothing would let every
        // other test in this file pass without checking anything.
        $this->assertArrayHasKey('escalated_ticket_assigned', self::emitters());
        $this->assertArrayHasKey('escalated_reply_created', self::emitters());
        $this->assertContains('escalated_ticket_assigned', array_column(self::registrations(), 'hook'));
        $this->assertGreaterThanOrEqual(15, count(self::registrations()));
    }

    public function test_each_hook_is_always_fired_with_the_same_number_of_arguments(): void
    {
        foreach (self::emitters() as $hook => $calls) {
            $this->assertCount(
                1,
                array_unique(array_column($calls, 'count')),
                sprintf("%s is fired with different numbers of arguments:\n%s", $hook, self::describe($calls))
            );
        }
    }

    /**
     * @dataProvider registration_provider
     */
    public function test_handler_is_registered_for_the_arguments_its_hook_passes(string $hook, string $class, ?string $method, ?int $accepted_args, string $where): void
    {
        $this->assertNotNull($method, "{$where}: add_action('{$hook}', ...) uses a callback form this guard cannot read; use [\$this, 'method'].");
        $this->assertNotNull($accepted_args, "{$where}: add_action('{$hook}', ...) must pass accepted_args as an integer literal.");

        $reflection = new ReflectionMethod($class, $method);
        $required = $reflection->getNumberOfRequiredParameters();
        $declared = $reflection->isVariadic() ? PHP_INT_MAX : $reflection->getNumberOfParameters();
        $handler = "{$class}::{$method}() ({$where})";

        $emitters = self::emitters();
        if (! isset($emitters[$hook])) {
            // Nothing in the plugin fires it, so it is a WP-Cron event, and
            // WP-Cron runs scheduled events with no arguments.
            $this->assertSame(0, $required, "{$handler} handles {$hook}, which is fired with no arguments, but requires {$required}.");

            return;
        }

        $passed = $emitters[$hook][0]['count'];
        $fired_at = self::describe($emitters[$hook]);

        $this->assertSame(
            $passed,
            $accepted_args,
            "{$hook} is fired with {$passed} argument(s), but {$handler} is registered with accepted_args {$accepted_args}.\nFired at:\n{$fired_at}"
        );
        $this->assertLessThanOrEqual($accepted_args, $required, "{$handler} requires {$required} arguments but is registered for {$accepted_args}.");
        $this->assertGreaterThanOrEqual($accepted_args, $declared, "{$handler} declares {$declared} parameters but is registered for {$accepted_args}.");
    }

    public static function registration_provider(): array
    {
        $cases = [];
        foreach (self::registrations() as $registration) {
            $key = sprintf('%s -> %s::%s', $registration['hook'], $registration['class'], $registration['method'] ?? '?');
            $cases[$key] = [
                $registration['hook'],
                $registration['class'],
                $registration['method'],
                $registration['accepted_args'],
                $registration['where'],
            ];
        }

        return $cases;
    }

    // =========================================================================
    // Scanner
    // =========================================================================

    private static function emitters(): array
    {
        self::scan();

        return self::$emitters;
    }

    private static function registrations(): array
    {
        self::scan();

        return self::$registrations;
    }

    private static function scan(): void
    {
        if (self::$emitters !== null) {
            return;
        }

        self::$emitters = [];
        self::$registrations = [];

        $root = dirname(__DIR__);
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/includes', FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            if (strpos($source, 'escalated_') === false) {
                continue;
            }

            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
            self::scan_source($source, $relative);
        }
    }

    private static function scan_source(string $source, string $relative): void
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            fn ($token) => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $class = self::class_name($source);

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $function = ltrim($token[1], '\\');
            if ($function !== 'do_action' && $function !== 'add_action') {
                continue;
            }

            // Skip method calls and declarations that happen to share the name.
            $previous = $tokens[$i - 1] ?? null;
            if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                continue;
            }
            if (($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }

            $args = self::split_arguments($tokens, $i + 1);
            $hook = self::string_literal($args[0] ?? []);
            if ($hook === null || strpos($hook, 'escalated_') !== 0) {
                continue;
            }

            $where = $relative.':'.$token[2];

            if ($function === 'do_action') {
                self::$emitters[$hook][] = ['count' => count($args) - 1, 'where' => $where];

                continue;
            }

            self::$registrations[] = [
                'hook' => $hook,
                'class' => $class,
                'method' => self::this_method_callback($args[1] ?? []),
                'accepted_args' => isset($args[3]) ? self::integer_literal($args[3]) : 1,
                'where' => $where,
            ];
        }
    }

    /**
     * Split a call's arguments at top-level commas, starting at its '('.
     *
     * @return array<int, array<int, array|string>> One token list per argument.
     */
    private static function split_arguments(array $tokens, int $open): array
    {
        $args = [];
        $current = [];
        $depth = 0;
        $count = count($tokens);

        for ($i = $open; $i < $count; $i++) {
            $token = $tokens[$i];
            $opens = in_array($token, ['(', '[', '{'], true)
                || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));

            if ($opens) {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif (in_array($token, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    if ($current !== []) {
                        $args[] = $current;
                    }

                    return $args;
                }
            } elseif ($token === ',' && $depth === 1) {
                $args[] = $current;
                $current = [];

                continue;
            }

            $current[] = $token;
        }

        return $args;
    }

    private static function string_literal(array $arg): ?string
    {
        if (count($arg) !== 1 || ! is_array($arg[0]) || $arg[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        return substr($arg[0][1], 1, -1);
    }

    private static function integer_literal(array $arg): ?int
    {
        if (count($arg) !== 1 || ! is_array($arg[0]) || $arg[0][0] !== T_LNUMBER) {
            return null;
        }

        return (int) $arg[0][1];
    }

    /**
     * The method name from a `[$this, 'method']` callback, or null for any
     * other form.
     */
    private static function this_method_callback(array $arg): ?string
    {
        $shape = array_map(fn ($token) => is_array($token) ? $token[1] : $token, $arg);
        if (count($arg) !== 5 || $shape[0] !== '[' || $shape[1] !== '$this' || $shape[2] !== ',' || $shape[4] !== ']') {
            return null;
        }

        return self::string_literal([$arg[3]]);
    }

    private static function class_name(string $source): string
    {
        preg_match('/^namespace\s+([^;\s]+)\s*;/m', $source, $namespace);
        preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m', $source, $class);

        $name = $class[1] ?? '';

        return isset($namespace[1]) ? $namespace[1].'\\'.$name : $name;
    }

    private static function describe(array $calls): string
    {
        return implode("\n", array_map(
            fn ($call) => sprintf('  - %s (%d argument(s))', $call['where'], $call['count']),
            $calls
        ));
    }
}
