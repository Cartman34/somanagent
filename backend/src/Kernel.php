<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * Application kernel — bootstraps the Symfony framework with micro-kernel trait.
 */
class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
