<?php

/**
 * Procest listener registrar.
 *
 * The composite over every event listener Application::register() wires up:
 * the cross-subsystem object-lifecycle listeners, the bezwaar / parafering
 * listeners, and the termijn + decision workflow listeners. It owns no
 * registration itself — only which specialised registrars run.
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

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Runs every event-listener registrar.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/bezwaar-lifecycle/spec.md
 */
class ListenerRegistrar
{
    /**
     * Register every procest event listener.
     *
     * The bezwaar listeners that declare a register/schema interest are NOT
     * registered here — they are subscribed from boot() by
     * {@see BezwaarSubscriptionRegistrar}, because the OpenRegister
     * `ObjectEventSubscription` guard only resolves once every app's register()
     * has run.
     *
     * @param IRegistrationContext $context The registration context.
     *
     * @return void
     *
     * @spec openspec/specs/bezwaar-lifecycle/spec.md
     */
    public function register(IRegistrationContext $context): void
    {
        (new ObjectListenerRegistrar())->register(context: $context);
        (new ImmutabilityListenerRegistrar())->register(context: $context);
        (new BezwaarListenerRegistrar())->register(context: $context);
        (new WorkflowListenerRegistrar())->register(context: $context);
    }//end register()
}//end class
