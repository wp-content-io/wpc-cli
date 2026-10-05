<?php

namespace WpContent\Cli\Tests;

/**
 * Sets an environment variable the way the code reads it: in all three sources
 * at once, and restores all three afterwards.
 *
 * A test that only calls `putenv()` is shadowed by the same variable being
 * genuinely exported — {@see \WpContent\Cli\Env} reads `$_ENV`/`$_SERVER`
 * first — so `WPC_ACCENT_COLOR=cyan composer test` used to fail seven of the ten
 * theme tests, on exactly the machine of anyone using the documented knobs. And
 * a test that only sets `$_ENV` misses `getenv()`, which is what a wrapper
 * script uses.
 */
trait EnvGuardTrait
{
    /** @var array<string, array{string|null, mixed, mixed}> */
    private array $envGuard = [];

    /** Set a variable everywhere, or unset it everywhere when $value is null. */
    protected function setEnv(string $name, ?string $value): void
    {
        $this->envGuard[$name] ??= [
            getenv($name) === false ? null : (string) getenv($name),
            $_ENV[$name] ?? null,
            $_SERVER[$name] ?? null,
        ];

        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }

        putenv("$name=$value");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    /** Put every variable this test touched back the way it was. */
    protected function restoreEnv(): void
    {
        foreach ($this->envGuard as $name => [$shell, $env, $server]) {
            $shell === null ? putenv($name) : putenv("$name=$shell");

            unset($_ENV[$name], $_SERVER[$name]);
            if ($env !== null) {
                $_ENV[$name] = $env;
            }
            if ($server !== null) {
                $_SERVER[$name] = $server;
            }
        }

        $this->envGuard = [];
    }

    /**
     * Run $assertions with $name set to $value, restored whatever happens.
     */
    protected function withEnv(string $name, ?string $value, callable $assertions): void
    {
        $this->setEnv($name, $value);

        try {
            $assertions();
        } finally {
            $this->restoreEnv();
        }
    }
}
