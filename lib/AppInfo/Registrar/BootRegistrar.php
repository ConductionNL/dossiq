<?php

/**
 * Procest boot-time registrar.
 *
 * The composite over everything that must happen in Application::boot() rather
 * than register(): the narrowed bezwaar subscriptions (which need every app's
 * register() to have completed) and the map Content-Security-Policy allowlist.
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
 * @spec openspec/specs/bezwaar-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\AppInfo\Registrar;

use OCP\EventDispatcher\IEventDispatcher;

/**
 * Runs every boot-time registrar.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/bezwaar-lifecycle/spec.md
 */
class BootRegistrar
{
    /**
     * Run the boot-time registrations.
     *
     * @param IEventDispatcher $dispatcher The live event dispatcher.
     * @param mixed            $server     Server container (passed in from boot()).
     *
     * @return void
     *
     * @spec openspec/specs/bezwaar-lifecycle/spec.md
     */
    public function boot(IEventDispatcher $dispatcher, $server): void
    {
        (new BezwaarSubscriptionRegistrar())->subscribe(dispatcher: $dispatcher);
        (new MapCspRegistrar())->register(server: $server);
    }//end boot()
}//end class
