<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Validation\Test\Fixture;

/**
 * Fixture: public method with no callers.
 *
 * PHPStan reports `public.method.unused` on unusedMethod() when this file is analysed.
 * Run: php scripts/vendor/bin/phpstan analyse --configuration config/phpstan-fixture.neon scripts/src/Validation/Test/Fixture/DeadPublicMethodFixture.php
 *
 * Expected: [ERROR] Found 1 error — public.method.unused on unusedMethod()
 */
final class DeadPublicMethodFixture
{
    /**
     * @return void
     */
    public function unusedMethod(): void
    {
    }
}
