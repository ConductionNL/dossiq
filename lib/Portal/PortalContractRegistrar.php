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

use OCA\Dossiq\Kernel\Contracts\ApplicantPortalActsInterface;
use OCA\Dossiq\Kernel\Contracts\CitizenManifestInterface;
use OCA\Dossiq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Dossiq\Kernel\Contracts\PortalSubmissionLedgerInterface;
use OCA\Dossiq\Kernel\Contracts\PortalWooAnswerInterface;
use OCA\Dossiq\Kernel\Contracts\ResidentQuestionTaskInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds the Kernel contracts the Portal component implements, so callers in
 * other components get the same shared instance through the contract.
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
	 * @spec exclude Composition root wiring, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(ApplicantPortalActsInterface::class, ApplicantPortalActs::class);
		$context->registerServiceAlias(CitizenManifestInterface::class, CitizenManifest::class);
		$context->registerServiceAlias(PortalAssertionVerifierInterface::class, PortalAssertionVerifier::class);
		$context->registerServiceAlias(PortalSubmissionLedgerInterface::class, PortalSubmissionLedger::class);
		$context->registerServiceAlias(PortalWooAnswerInterface::class, PortalWooAnswer::class);
		$context->registerServiceAlias(ResidentQuestionTaskInterface::class, ResidentQuestionTask::class);
	}//end register()
}//end class
