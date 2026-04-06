<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Base Doctrine fixtures — currently empty, serves as placeholder for future seed data.
 */
class AppFixtures extends Fixture
{
    /**
     * Loads fixture data into the current persistence manager.
     */
    public function load(ObjectManager $manager): void
    {
        // $product = new Product();
        // $manager->persist($product);

        $manager->flush();
    }
}
