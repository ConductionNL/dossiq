<?php

/**
 * Dossiq Review contract registrar
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

use OCA\Dossiq\Kernel\Contracts\DocumentRelevanceInterface;
use OCA\Dossiq\Kernel\Contracts\DocumentTextInterface;
use OCA\Dossiq\Kernel\Contracts\DocumentVerdictsInterface;
use OCA\Dossiq\Kernel\Contracts\PagesSeenInterface;
use OCA\Dossiq\Kernel\Contracts\ReviewWritesInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds the Kernel contracts the Review component implements, so callers in
 * other components get the same shared instance through the contract. The
 * contracts that plan and measure a review are bound by
 * {@see ReviewPlanContractRegistrar}, which this one calls.
 *
 * @category Review
 * @package  OCA\Dossiq\Review
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Composition root wiring, no functional requirement.
 */
class ReviewContractRegistrar {
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
		$context->registerServiceAlias(DocumentRelevanceInterface::class, DocumentRelevance::class);
		$context->registerServiceAlias(DocumentTextInterface::class, DocumentText::class);
		$context->registerServiceAlias(DocumentVerdictsInterface::class, DocumentVerdicts::class);
		$context->registerServiceAlias(PagesSeenInterface::class, PagesSeen::class);
		$context->registerServiceAlias(ReviewWritesInterface::class, ReviewWrites::class);
		(new ReviewPlanContractRegistrar())->register(context: $context);
	}//end register()
}//end class
