<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Base class for backend unit tests that must stay runnable from WSL without Docker services.
 *
 * Local unit tests must remain isolated:
 * - no Symfony kernel boot
 * - no database, Redis, or Messenger runtime dependency
 * - no real external HTTP or API call
 */
abstract class LocalUnitTestCase extends TestCase
{
}
