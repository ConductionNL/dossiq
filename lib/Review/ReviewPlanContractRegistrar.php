<?php

/**
 * Dossiq review plan contract registrar
 *
 * @category  Review
 * @package   OCA\Dossiq\Review
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

namespace OCA\Dossiq\Review;

use OCA\Dossiq\Kernel\Contracts\BatchSelectionInterface;
use OCA\Dossiq\Kernel\Contracts\RecallSamplesInterface;
use OCA\Dossiq\Kernel\Contracts\ReviewBatchesInterface;
use OCA\Dossiq\Kernel\Contracts\ReviewSummaryInterface;
use OCA\Dossiq\Kernel\Contracts\StoppingRulesInterface;
use OCA\Dossiq\Kernel\Contracts\TriageRulesInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds the Kernel contracts that plan and measure a review: the batches and
 * what each one takes, the triage rules, the stopping rule with its recall
 * sample, and the case summary of relevance. Called by
 * {@see ReviewContractRegistrar}, which binds the contracts for reviewing one
 * document.
 *
 * @category Review
 * @package  OCA\Dossiq\Review
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class ReviewPlanContractRegistrar {
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
		$context->registerServiceAlias(BatchSelectionInterface::class, BatchSelection::class);
		$context->registerServiceAlias(RecallSamplesInterface::class, RecallSamples::class);
		$context->registerServiceAlias(ReviewBatchesInterface::class, ReviewBatches::class);
		$context->registerServiceAlias(ReviewSummaryInterface::class, ReviewSummary::class);
		$context->registerServiceAlias(StoppingRulesInterface::class, StoppingRules::class);
		$context->registerServiceAlias(TriageRulesInterface::class, TriageRules::class);
	}//end register()
}//end class
