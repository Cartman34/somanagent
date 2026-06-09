<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script;

use Sowapps\Backlog\Application\AbstractBacklogApplication;
use Sowapps\SoManAgent\Script\SoManAgentServiceFactory;

/**
 * SoManAgent host application.
 *
 * Concrete application posed by the scripts bootstrap. It inherits the backlog capability layer
 * (which itself extends the toolkit layer), giving every runner access to the toolkit and backlog
 * bridges. SoManAgent adds no bridge of its own.
 */
final class SoManAgentApplication extends AbstractBacklogApplication
{
    private ?SoManAgentServiceFactory $hostServiceFactory = null;

    /**
     * Covariant accessor so consumers reach the posed host application with its concrete type.
     */
    public static function getInstance(): self
    {
        $application = parent::getInstance();
        assert($application instanceof self);

        return $application;
    }

    /**
     * Narrows the service factory covariantly to the host factory (complete inheritance chain).
     */
    public function getServiceFactory(): SoManAgentServiceFactory
    {
        return $this->hostServiceFactory ??= new SoManAgentServiceFactory($this);
    }
}
