<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Validation\Test\Fixture;

/**
 * Fixture: public method annotated with @api — no unused-public error.
 *
 * PHPStan does NOT report `public.method.unused` on intentionalApi() because of @api.
 * Run: php scripts/vendor/bin/phpstan analyse --configuration config/phpstan.neon scripts/src/Validation/Test/Fixture/ApiAnnotatedFixture.php
 *
 * Expected: [OK] No errors
 */
final class ApiAnnotatedFixture
{
    /**
     * @api Called from outside the PHPStan analysis path (e.g. external script or reflection).
     */
    public function intentionalApi(): void
    {
    }
}
