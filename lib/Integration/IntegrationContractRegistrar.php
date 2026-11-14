<?php

/**
 * Dossiq Integration contract registrar
 *
 * @category  Integration
 * @package   OCA\Dossiq\Integration
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Integration;

use OCA\Dossiq\Kernel\Contracts\CaseLeafProviderInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds the Kernel contracts the Integration component implements, so callers in
 * other components get the same shared instance through the contract.
 *
 * @category Integration
 * @package  OCA\Dossiq\Integration
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Composition root wiring, no functional requirement.
 */
class IntegrationContractRegistrar {
	/**
	 * Alias each contract to its implementation.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Composition root wiring, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(CaseLeafProviderInterface::class, CaseLeafProvider::class);
	}//end register()
}//end class
