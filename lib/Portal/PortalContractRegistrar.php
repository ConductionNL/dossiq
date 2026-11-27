<?php

/**
 * Dossiq Portal contract registrar
 *
 * @category  Portal
 * @package   OCA\Dossiq\Portal
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

namespace OCA\Dossiq\Portal;

use OCA\Dossiq\Kernel\Contracts\CitizenManifestInterface;
use OCA\Dossiq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Dossiq\Kernel\Contracts\PortalContributionProviderInterface;
use OCA\Dossiq\Kernel\Contracts\PortalSubmissionLedgerInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds the Kernel contracts the Portal component implements, so callers in
 * other components get the same shared instance through the contract. What a
 * resident does on their own case is bound by
 * {@see PortalResidentActsContractRegistrar}, which this one calls.
 *
 * @category Portal
 * @package  OCA\Dossiq\Portal
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Composition root wiring, no functional requirement.
 */
class PortalContractRegistrar {
	/**
	 * Alias each contract to its implementation.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(CitizenManifestInterface::class, CitizenManifest::class);
		$context->registerServiceAlias(PortalAssertionVerifierInterface::class, PortalAssertionVerifier::class);
		$context->registerServiceAlias(PortalContributionProviderInterface::class, PortalContributionProvider::class);
		$context->registerServiceAlias(PortalSubmissionLedgerInterface::class, PortalSubmissionLedger::class);
		(new PortalResidentActsContractRegistrar())->register(context: $context);
	}//end register()
}//end class
