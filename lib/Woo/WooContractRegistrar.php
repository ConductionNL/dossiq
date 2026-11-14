<?php

/**
 * Dossiq Woo contract registrar
 *
 * @category  Woo
 * @package   OCA\Dossiq\Woo
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

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\Kernel\Contracts\WooCaseDocumentsInterface;
use OCA\Dossiq\Kernel\Contracts\WooCollectionInterface;
use OCA\Dossiq\Kernel\Contracts\WooCollectionQueriesInterface;
use OCA\Dossiq\Kernel\Contracts\WooDeliveredSetVerifierInterface;
use OCA\Dossiq\Kernel\Contracts\WooDeliveredSetWriterInterface;
use OCA\Dossiq\Kernel\Contracts\WooGatherAddInterface;
use OCA\Dossiq\Kernel\Contracts\WooReceivedAnswersInterface;
use OCA\Dossiq\Kernel\Contracts\WooReportReadLogInterface;
use OCA\Dossiq\Kernel\Contracts\WooRequestIntakeInterface;
use OCA\Dossiq\Kernel\Contracts\WooSearchPlansInterface;
use OCA\Dossiq\Kernel\Contracts\WooSourcesInterface;
use OCA\Dossiq\Kernel\Contracts\WooThroughputReportInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds the Kernel contracts the Woo component implements, so callers in
 * other components get the same shared instance through the contract.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Composition root wiring, no functional requirement.
 */
class WooContractRegistrar {
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
		$context->registerServiceAlias(WooCaseDocumentsInterface::class, WooCaseDocuments::class);
		$context->registerServiceAlias(WooCollectionInterface::class, WooCollection::class);
		$context->registerServiceAlias(WooCollectionQueriesInterface::class, WooCollectionQueries::class);
		$context->registerServiceAlias(WooDeliveredSetVerifierInterface::class, WooDeliveredSetVerifier::class);
		$context->registerServiceAlias(WooDeliveredSetWriterInterface::class, WooDeliveredSetWriter::class);
		$context->registerServiceAlias(WooGatherAddInterface::class, WooGatherAdd::class);
		$context->registerServiceAlias(WooReceivedAnswersInterface::class, WooReceivedAnswers::class);
		$context->registerServiceAlias(WooReportReadLogInterface::class, WooReportReadLog::class);
		$context->registerServiceAlias(WooRequestIntakeInterface::class, WooRequestIntake::class);
		$context->registerServiceAlias(WooSearchPlansInterface::class, WooSearchPlans::class);
		$context->registerServiceAlias(WooSourcesInterface::class, WooSources::class);
		$context->registerServiceAlias(WooThroughputReportInterface::class, WooThroughputReport::class);
	}//end register()
}//end class
