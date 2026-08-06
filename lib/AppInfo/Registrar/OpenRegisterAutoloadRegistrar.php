<?php

/**
 * OpenRegister autoload prelude (ADR-040).
 *
 * Puts OpenRegister's PSR-4 prefix on the autoloader before anything in this
 * app's `register()` tries to resolve a class from it. See the class docblock
 * for why that is not automatic.
 *
 * @category AppInfo
 * @package  OCA\Procest\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\AppInfo\Registrar;

/**
 * Registers OpenRegister's autoloader ahead of this app's AppHost adoption.
 *
 * WHY THIS IS NEEDED AT ALL.
 *
 * Apps register ONE AT A TIME, in SORTED order. `OC_App::getEnabledApps()`
 * does `sort($apps)`, and `Coordinator::registerApps()` walks that sorted list
 * calling `OC_App::registerAutoloading($appId)` and then
 * `$application->register()` per app. So every app's `register()` runs BEFORE
 * the PSR-4 prefix of every alphabetically LATER app exists — on a completely
 * healthy instance, with OpenRegister installed and enabled.
 *
 * `procest` sorts after `openregister`, so this happens to work today. By
 * alphabet, not by design. That makes the defect LATENT rather than absent,
 * and latent is the dangerous kind here, because {@see AppHostRegistrar}'s
 * `class_exists()` guard converts it into SILENCE: the guard would answer
 * false, the engine registration would be skipped, and the app would boot,
 * route and look healthy while every AppHost-backed endpoint returned 500.
 *
 * 500 rather than 404 is the part worth internalising. Classes like
 * `Controller\HealthController` exist ONLY as Bootstrap DI aliases onto
 * `AppHost\Controller\GenericHealthController`, so the route still MATCHES and
 * it is the resolution that fails. Renaming this app, or moving the adoption
 * into one that sorts earlier, is all it would take.
 *
 * Measured twice elsewhere in the fleet, both silent: openconnector's own
 * source records `class_exists at register(): false` on a clean install, and
 * doriath's audit listener recorded ZERO dispatched events because an
 * unguarded AppHost reference aborted the rest of `register()` — the app
 * stayed enabled and kept serving with half its wiring missing.
 *
 * WHY IT LIVES IN ITS OWN CLASS. The three references below
 * (`OCP\Server`, `OCP\App\IAppManager`, `OC_App`) are dependencies, and
 * inlining them into {@see AppHostRegistrar} took that class from 12 to 15 on
 * PHPMD's CouplingBetweenObjects — over the limit of 13. A separate registrar
 * keeps the prelude adjacent to what needs it without loading the cost onto a
 * class that already carries nine widget/provider references.
 *
 * `registerAutoloading()` is the right call and `IAppManager::loadApp()` is
 * NOT an acceptable substitute: the former touches only the autoloader and is
 * idempotent (it early-returns on an `$alreadyRegistered` key), while the
 * latter sets `loadedApps[..]=true` and calls `Coordinator::bootApp()`,
 * booting OpenRegister before its own `register()` has run.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class OpenRegisterAutoloadRegistrar
{
    /**
     * Put OpenRegister's PSR-4 prefix on the autoloader.
     *
     * Wrapped in `try/catch(\Throwable)` because `getAppPath()` throws when the
     * app is not installed — a legitimate state for procest, which does not
     * declare `<app>openregister</app>`. That case is then handled by
     * {@see AppHostRegistrar}'s `class_exists()` guard, which after this call
     * answers a question about whether OpenRegister is INSTALLED rather than
     * one about whether this app's id happens to sort after it.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.StaticAccess) OC_App::registerAutoloading() is the ADR-040 prelude; there is no injectable equivalent.
     *
     * @spec openspec/specs/beschikking-generatie/spec.md
     */
    public function register(): void
    {
        // The app id is written as a LITERAL on both lines rather than hoisted
        // into a constant. gate-64 recognises the prelude by matching an
        // 'openregister' literal inside the registerAutoloading() argument
        // list, so a constant here reads to the gate as no prelude at all —
        // verified: with a constant the checker still reported "1 AppHost
        // adoption(s) without the autoload prelude".
        try {
            $path = \OCP\Server::get(\OCP\App\IAppManager::class)
                ->getAppPath('openregister');

            \OC_App::registerAutoloading('openregister', $path);
        } catch (\Throwable) {
            // OpenRegister is not installed. Nothing to autoload; the
            // class_exists() guard in AppHostRegistrar turns this into a clean
            // skip rather than a fatal.
        }
    }//end register()
}//end class
