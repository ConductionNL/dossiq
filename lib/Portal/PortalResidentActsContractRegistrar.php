<?php

/**
 * Dossiq portal resident acts contract registrar
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
use OCA\Dossiq\Kernel\Contracts\PortalWooAnswerInterface;
use OCA\Dossiq\Kernel\Contracts\ResidentQuestionTaskInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds the Kernel contracts for what a resident does on their own case in
 * the portal: an act on the case, the answer to the organisation's question,
 * and that question as a task. Called by {@see PortalContractRegistrar}, which
 * binds the rest of the Portal component's contracts.
 *
 * @category Portal
 * @package  OCA\Dossiq\Portal
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class PortalResidentActsContractRegistrar {
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
		$context->registerServiceAlias(ApplicantPortalActsInterface::class, ApplicantPortalActs::class);
		$context->registerServiceAlias(PortalWooAnswerInterface::class, PortalWooAnswer::class);
		$context->registerServiceAlias(ResidentQuestionTaskInterface::class, ResidentQuestionTask::class);
	}//end register()
}//end class
