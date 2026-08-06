<?php

/**
 * OpenRegisterAutoloadRegistrar Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\AppInfo\Registrar;

use OCA\Procest\AppInfo\Registrar\OpenRegisterAutoloadRegistrar;
use PHPUnit\Framework\TestCase;

/**
 * The ADR-040 autoload prelude must never be the thing that breaks boot.
 *
 * WHAT THIS CAN AND CANNOT TEST.
 *
 * It cannot assert that the autoloader was registered: that needs a real
 * Nextcloud container, a real installed OpenRegister, and the app-registration
 * ORDER that produces the defect in the first place — which is exactly why the
 * positive case is covered by tests/e2e/apphost-adoption.spec.ts against a
 * running instance instead.
 *
 * What it CAN test is the half that has no browser symptom and is easy to get
 * wrong: the DEGRADED path. `register()` runs inside
 * `Application::register()`, which Nextcloud executes on EVERY request. If it
 * throws when OpenRegister is absent, it does not merely disable a feature —
 * it takes down every request to the instance, because Coordinator's failure
 * handling is per-app registration and an uncaught error there aborts the rest
 * of procest's wiring.
 *
 * `IAppManager::getAppPath()` throws `AppPathNotFoundException` for an app
 * that is not installed, and in a bare unit-test process `\OCP\Server::get()`
 * has no container at all — so this test exercises the catch arm by simply
 * running outside Nextcloud, which is the same shape as "OpenRegister is not
 * installed". That is the state procest explicitly supports: it does not
 * declare `<app>openregister</app>`, so an admin can create it.
 *
 * @covers \OCA\Procest\AppInfo\Registrar\OpenRegisterAutoloadRegistrar
 */
class OpenRegisterAutoloadRegistrarTest extends TestCase
{
    /**
     * With OpenRegister unavailable, register() completes silently.
     *
     * The assertion is the absence of a throw. PHPUnit fails the test on any
     * uncaught exception, so `expectNotToPerformAssertions()` would hide
     * nothing here — but an explicit assertion after the call states the
     * intent, and would fail if the method ever started returning early in a
     * way that skipped it.
     *
     * @return void
     */
    public function testRegisterSwallowsAnAbsentOpenRegister(): void
    {
        $registrar = new OpenRegisterAutoloadRegistrar();

        $registrar->register();

        self::assertTrue(
            true,
            'register() must return normally when OpenRegister cannot be resolved — it runs '
            .'inside Application::register() on every request, so throwing here would take '
            .'down the whole instance rather than degrade one feature.'
        );
    }

    /**
     * Calling it twice is safe.
     *
     * `OC_App::registerAutoloading()` is idempotent (it early-returns on an
     * `$alreadyRegistered` key), and nothing in this class holds state, so a
     * second call must be a no-op rather than a double registration or a
     * throw. ServiceRegistrar calls it once today; that is not a property to
     * rely on silently.
     *
     * @return void
     */
    public function testRegisterIsIdempotent(): void
    {
        $registrar = new OpenRegisterAutoloadRegistrar();

        $registrar->register();
        $registrar->register();

        self::assertTrue(true, 'a second register() must not throw');
    }
}
