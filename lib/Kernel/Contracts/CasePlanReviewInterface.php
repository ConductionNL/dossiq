<?php

/**
 * Contract of CasePlanReview
 *
 * The public surface of CasePlanReview that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
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

namespace OCA\Dossiq\Kernel\Contracts;

use OCA\Dossiq\Exception\RefusedException;

/**
 * Contract of CasePlanReview.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CasePlanReviewInterface {

	/**
	 * The schema slug a family plan is stored under.
	 *
	 * @var string
	 */
	public const SCHEMA = 'gezinsplan';

	/**
	 * Whether this plan's review date has passed.
	 *
	 * A plan with NO review date is not due: it has never been given one, which
	 * is a different problem from a plan that is late, and reporting the two as
	 * one would put every plan written before this change into the overdue
	 * list on the day it ships.
	 *
	 * @param array<string, mixed> $plan  The plan.
	 * @param string               $today The day, `Y-m-d`, or '' for today.
	 *
	 * @return boolean True when it is due for review.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-plan-is-reviewed-and-the-review-is-recorded-req-cpn-03
	 */
	public function isDue(array $plan, string $today = ''): bool;

	/**
	 * Record a review of one plan.
	 *
	 * @param string             $planId         The plan uuid.
	 * @param string             $reviewedBy     Who reviewed it.
	 * @param array<int, string> $changes        What changed, in sentences.
	 * @param string             $nextReviewDate The new review date, or '' to leave it.
	 * @param string             $onDate         The day of the review, or '' for today.
	 *
	 * @return array<string, mixed> The plan, with the review on it.
	 *
	 * @throws RefusedException When the plan cannot be read or nothing was recorded.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-plan-is-reviewed-and-the-review-is-recorded-req-cpn-03
	 */
	public function record(
		string $planId,
		string $reviewedBy,
		array $changes,
		string $nextReviewDate = '',
		string $onDate = '',
	): array;
}//end interface
