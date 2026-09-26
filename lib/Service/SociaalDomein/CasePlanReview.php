<?php

/**
 * Reviewing a gezinsplan, and saying when one has gone stale.
 *
 * 🔴 THE PLAN THAT IS NEVER REVIEWED IS THE PLAN THAT HARMS (D-5). A household
 * whose situation moved on six months ago is still being worked to a plan that
 * describes the household of last winter, and nothing on the page says so
 * because there is nothing to say it with. A review date and a record of what
 * changed at each review cost one field each and make staleness visible.
 *
 * 🔑 THE REVIEW RECORDS WHAT CHANGED, NOT THAT IT HAPPENED. "Reviewed on 3
 * March by A. Jansen" is a tick. The changes are what a later reader, or the
 * household, can actually check the plan against, and they are what makes a
 * review that changed nothing visible AS a review that changed nothing.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\SociaalDomein
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\SociaalDomein;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;

/**
 * Record a plan review, and answer whether a plan is due for one.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
 */
class CasePlanReview {

	/**
	 * The schema slug a family plan is stored under.
	 *
	 * @var string
	 */
	public const SCHEMA = 'gezinsplan';

	/**
	 * Constructor.
	 *
	 * @param SociaalDomeinStore $store The one reader and writer of these schemas.
	 */
	public function __construct(
		private readonly SociaalDomeinStore $store,
	) {
	}//end __construct()

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
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-plan-is-reviewed-and-the-review-is-recorded-req-cpn-03
	 */
	public function isDue(array $plan, string $today = ''): bool {
		$due = trim((string)($plan['reviewDate'] ?? ''));
		if ($due === '') {
			return false;
		}

		if ($today === '') {
			$today = (new DateTimeImmutable())->format('Y-m-d');
		}


		return ($due < $today);
	}//end isDue()

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
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-plan-is-reviewed-and-the-review-is-recorded-req-cpn-03
	 */
	public function record(
		string $planId,
		string $reviewedBy,
		array $changes,
		string $nextReviewDate = '',
		string $onDate = '',
	): array {
		if ($onDate === '') {
			$onDate = (new DateTimeImmutable())->format('Y-m-d');
		}

		$plan = $this->store->read(schema: self::SCHEMA, id: $planId);
		if ($plan === null) {
			throw new RefusedException(
				rule: 'plan-not-found',
				sentence: 'That family plan could not be found.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		if (trim($reviewedBy) === '') {
			throw new RefusedException(
				rule: 'review-needs-a-reviewer',
				sentence: 'A review records who did it.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$written = [];
		foreach ($changes as $change) {
			$change = trim((string)$change);
			if ($change !== '') {
				$written[] = $change;
			}
		}

		if ($written === []) {
			throw new RefusedException(
				rule: 'review-needs-what-changed',
				sentence: 'Write down what this review changed. A review that says only that it happened '
					. 'tells the next reader nothing they can check.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$reviews = $this->reviewsOf(plan: $plan);
		$reviews[] = [
			'reviewedBy' => trim($reviewedBy),
			'reviewDate' => $onDate,
			'changes' => $written,
			'nextReviewDate' => $nextReviewDate,
		];

		$plan['reviews'] = $reviews;
		if (trim($nextReviewDate) !== '') {
			$plan['reviewDate'] = $nextReviewDate;
		}

		return $this->store->write(schema: self::SCHEMA, object: $plan);
	}//end record()

	/**
	 * The reviews a plan already carries.
	 *
	 * @param array<string, mixed> $plan The plan.
	 *
	 * @return array<int, array<string, mixed>> The reviews.
	 */
	private function reviewsOf(array $plan): array {
		$raw = ($plan['reviews'] ?? []);
		if (is_string($raw) === true && trim($raw) !== '') {
			$decoded = json_decode($raw, true);
			$raw = [];
			if (is_array($decoded) === true) {
				$raw = $decoded;
			}
		}

		if (is_array($raw) === false) {
			return [];
		}

		return array_values(array_filter($raw, static fn ($row): bool => is_array($row) === true));
	}//end reviewsOf()
}//end class
