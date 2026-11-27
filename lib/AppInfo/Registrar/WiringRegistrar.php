<?php

/**
 * Dossiq wiring registrar.
 *
 * The composite over the wiring Application::register() hands to registrars:
 * what this app gives OpenRegister's lifecycle engine, the services, each
 * component's Kernel contracts, and the event listeners, in that order. What
 * Application::register() states itself (the OpenRegister interface alias, the
 * setup checks, the capability, the MCP scan alias, the search provider and the
 * config lexicon) stays there, where the gates and the tests read it.
 *
 * @category AppInfo
 * @package  OCA\Dossiq\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Lifecycle\LifecycleRegistrar;
use OCA\Dossiq\Listener\Registrar\ListenerRegistrar;
use OCA\Dossiq\Service\Registrar\ServiceRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Runs the lifecycle, service, contract and listener registrars.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class WiringRegistrar {
	/**
	 * Run the wiring registrars, in order.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function register(IRegistrationContext $context): void {
		// What this app hands to OpenRegister's lifecycle engine.
		(new LifecycleRegistrar())->register(context: $context);
		(new ServiceRegistrar())->register(context: $context);
		(new ComponentContractRegistrar())->register(context: $context);
		(new ListenerRegistrar())->register(context: $context);
	}//end register()
}//end class
