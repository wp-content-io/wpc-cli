<?php

namespace WpContent\Cli\Tests;

use PHPUnit\Framework\TestCase;
use WpContent\Cli\Env;

/**
 * The single reader of the environment.
 *
 * There used to be two — `$_ENV`/`$_SERVER` for the configuration, `getenv()`
 * for the TUI switches — so the same variable was honoured or ignored depending
 * on which one set it, and on the `variables_order` of the running php.ini.
 */
final class EnvTest extends TestCase
{
    private const NAME = 'WPC_TEST_ENV_READER';

    protected function tearDown(): void
    {
        putenv(self::NAME);
        unset($_ENV[self::NAME], $_SERVER[self::NAME]);
    }

    public function testAnUnsetVariableIsNull(): void
    {
        self::assertNull(Env::get(self::NAME));
        self::assertFalse(Env::isTruthy(self::NAME));
    }

    public function testASuperglobalIsRead(): void
    {
        $_ENV[self::NAME] = 'from-env';
        self::assertSame('from-env', Env::get(self::NAME));

        unset($_ENV[self::NAME]);
        $_SERVER[self::NAME] = 'from-server';
        self::assertSame('from-server', Env::get(self::NAME));
    }

    public function testPutenvIsReadToo(): void
    {
        // The configuration used to miss this one entirely.
        putenv(self::NAME . '=from-putenv');

        self::assertSame('from-putenv', Env::get(self::NAME));
    }

    public function testTheSuperglobalsWinOverGetenv(): void
    {
        putenv(self::NAME . '=from-putenv');
        $_ENV[self::NAME] = 'from-env';

        self::assertSame('from-env', Env::get(self::NAME));
    }

    /**
     * WPC_NO_TUI=0 must behave like the variable being unset: PHP would
     * otherwise read the string "0" as falsy in a bare boolean test, and an
     * explicit opt-out of the opt-out would silently do the opposite.
     */
    public function testFalsyValuesCountAsUnset(): void
    {
        foreach (['', '0', 'false', 'FALSE', 'off', 'no', ' no '] as $value) {
            putenv(self::NAME . '=' . $value);

            self::assertFalse(Env::isTruthy(self::NAME), "\"$value\" should read as off");
        }
    }

    public function testAnythingElseCountsAsOn(): void
    {
        foreach (['1', 'true', 'yes', 'on', 'whatever'] as $value) {
            putenv(self::NAME . '=' . $value);

            self::assertTrue(Env::isTruthy(self::NAME), "\"$value\" should read as on");
        }
    }
}
