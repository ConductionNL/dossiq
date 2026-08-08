<?php

/**
 * Tests for the canonical AppHost route table's method contract.
 *
 * @category Test
 * @package  OCA\Procest\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The canonical AppHost route table routes a fixed set of names into THIS
 * app's controller namespace, and OpenRegister only substitutes its generic
 * controller when this app does not ship a class of that name.
 *
 * `OCA\OpenRegister\AppHost\Bootstrap::aliasControllerUnlessLeafDefinesIt()`
 * registers the DI alias `OCA\Procest\Controller\XController` ->
 * `OCA\OpenRegister\AppHost\Controller\GenericXController` ONLY when the leaf
 * class does not exist. So the seam has two sides, and they fail differently:
 *
 *   - Leaf does NOT ship the class  -> the alias binds, the generic serves
 *     every canonical method. Nothing is owed. (This is why the absence of
 *     HealthController / MetricsController / PreferencesController on disk is
 *     correct and must not be "fixed" by creating them.)
 *   - Leaf DOES ship the class      -> the alias is skipped and the generic is
 *     never constructed, so the leaf owes EVERY method the canonical table
 *     routes to that controller. A missing one is not a 404: the router
 *     matches the URL, the dispatcher reflects the method, and the request
 *     dies with a 500.
 *
 * Measured 2026-08-08: procest ships its own SettingsController with
 * `index/create/load` but no `update()`, while the canonical table routes
 * `PUT /api/settings` to `settings#update` — and `src/store/modules/
 * enforcement.js::saveLhsMatrix()` sends exactly that request. Saving the LHS
 * matrix 500'd.
 *
 * This test asserts the ITEM (each individual method), never the container
 * (the controller class merely existing).
 */
class CanonicalRouteMethodContractTest extends TestCase
{

    /**
     * The canonical route names supplied by the AppHost table, as reproduced
     * verbatim by `appinfo/routes.php`'s openregister-absent fallback.
     *
     * Keyed `controllerPrefix => [method, ...]`.
     *
     * @var array<string, array<int, string>>
     */
    private const CANONICAL_ROUTES = [
        'Dashboard'   => ['page', 'catchAll'],
        'Settings'    => ['index', 'create', 'update', 'load'],
        'Preferences' => ['getPreference', 'setPreference'],
        'Metrics'     => ['index'],
        'Health'      => ['index'],
    ];


    /**
     * Every canonical route name must be reproduced by the local fallback.
     *
     * This is the positive control for the test below: if `routes.php` ever
     * stopped declaring these names, the method assertions would pass
     * vacuously because there would be nothing left to route. Read a green
     * from the next test only together with a green here.
     *
     * @return void
     */
    public function testFallbackRouteTableStillDeclaresEveryCanonicalName(): void
    {
        $routesSource = file_get_contents(__DIR__.'/../../../appinfo/routes.php');
        $this->assertIsString($routesSource, 'appinfo/routes.php must be readable');

        foreach (self::CANONICAL_ROUTES as $prefix => $methods) {
            foreach ($methods as $method) {
                $routeName = lcfirst($prefix).'#'.$method;

                // dashboard#page / dashboard#catchAll are declared in the
                // fallback as a literal entry and a $catchAllRoute variable
                // respectively; both mention the name.
                $this->assertStringContainsString(
                    $routeName,
                    $routesSource,
                    sprintf(
                        'appinfo/routes.php no longer mentions the canonical route "%s". '
                        .'Either the AppHost table changed and this test is stale, or a '
                        .'canonical route was silently dropped.',
                        $routeName
                    )
                );
            }
        }
    }//end testFallbackRouteTableStillDeclaresEveryCanonicalName()


    /**
     * A controller this app ships itself must implement every canonical
     * method routed to it — the AppHost generic will not fill the gap.
     *
     * @return void
     */
    public function testLeafOwnedControllersImplementEveryCanonicalMethodRoutedToThem(): void
    {
        $inspected = 0;
        $missing   = [];

        foreach (self::CANONICAL_ROUTES as $prefix => $methods) {
            $class = 'OCA\\Procest\\Controller\\'.$prefix.'Controller';

            // The class file existing on disk is what makes the AppHost skip
            // the alias. `class_exists()` alone would be satisfied by the DI
            // alias target in a booted container, which is precisely the case
            // this test must NOT treat as leaf-owned.
            $file = __DIR__.'/../../../lib/Controller/'.$prefix.'Controller.php';
            if (file_exists($file) === false) {
                continue;
            }

            $this->assertTrue(
                class_exists($class),
                sprintf('%s exists on disk but does not autoload as %s', $file, $class)
            );

            $reflection = new ReflectionClass($class);

            foreach ($methods as $method) {
                $inspected++;
                if ($reflection->hasMethod($method) === false) {
                    $missing[] = $prefix.'Controller::'.$method.'()';
                    continue;
                }

                $this->assertTrue(
                    $reflection->getMethod($method)->isPublic(),
                    sprintf('%s::%s() must be public to be dispatchable', $class, $method)
                );
            }
        }//end foreach

        // Positive control: a null result here ("no missing methods") is only
        // meaningful if something was actually inspected. Zero inspections
        // would mean the file-existence probe above silently matched nothing.
        $this->assertGreaterThan(
            0,
            $inspected,
            'No leaf-owned canonical controller was inspected — the lib/Controller '
            .'path probe is broken, so the empty finding list means nothing.'
        );

        $this->assertSame(
            [],
            $missing,
            sprintf(
                "The canonical AppHost route table routes to %s, but this app ships the "
                ."controller itself so no generic is aliased in. Each of these is a 500, "
                ."not a 404.\n  - %s",
                'these method(s)',
                implode("\n  - ", $missing)
            )
        );
    }//end testLeafOwnedControllersImplementEveryCanonicalMethodRoutedToThem()


}//end class
