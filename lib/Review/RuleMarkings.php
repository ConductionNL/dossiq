<?php

/**
 * What the triage rules of a case have marked.
 *
 * Over the reviews of a case's documents, without a store: a review as a
 * rule marks it, how many documents each rule decided, how many of its
 * markings a reviewer overturned, and each rule listed with both counts.
 *
 * @category Review
 * @package  OCA\Dossiq\Review
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Review;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Counts what each rule decided and what reviewers overturned of it.
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class RuleMarkings {

	/**
	 * Constructor.
	 *
	 * @param array<string, array<string, mixed>> $reviews The case's reviews, by document.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly array $reviews,
	) {
	}//end __construct()

	/**
	 * The rules, each with how many documents it decided and how many reviewers overturned.
	 *
	 * @param list<array<string, mixed>> $rules The case's rules, in order.
	 *
	 * @return list<array<string, mixed>> The rules with `decided` and `overturned`.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function onRules(array $rules): array {
		$decided = $this->decidedPerRule();
		$overturned = $this->overturnedPerRule();

		$listed = [];
		foreach ($rules as $rule) {
			$name = (string)($rule['name'] ?? '');
			$rule['decided'] = ($decided[$name] ?? 0);
			$rule['overturned'] = ($overturned[$name] ?? 0);
			$listed[] = $rule;
		}

		return $listed;
	}//end onRules()

	/**
	 * A review as the rule marks it: its effect, the rule as source and name, and the moment.
	 *
	 * @param array<string, mixed> $review The review.
	 * @param array<string, mixed> $rule   The rule that matched.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function marked(array $review, array $rule): array {
		$review['relevance'] = (string)$rule['effect'];
		$review['relevanceSource'] = 'rule';
		$review['rule'] = (string)$rule['name'];
		$review['markedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

		return $review;
	}//end marked()

	/**
	 * How many documents each rule decided: reviews whose relevance a rule set.
	 *
	 * @return array<string, int> Count per rule name.
	 */
	private function decidedPerRule(): array {
		$decided = [];
		foreach ($this->reviews as $review) {
			$rule = (string)($review['rule'] ?? '');
			if ($rule !== '' && ($review['relevanceSource'] ?? '') === 'rule') {
				$decided[$rule] = (($decided[$rule] ?? 0) + 1);
			}
		}

		return $decided;
	}//end decidedPerRule()

	/**
	 * How many of each rule's markings a reviewer overturned.
	 *
	 * @return array<string, int> Count per rule name.
	 */
	private function overturnedPerRule(): array {
		$overturned = [];
		foreach ($this->reviews as $review) {
			$over = (string)($review['overturnedRule'] ?? '');
			if ($over !== '') {
				$overturned[$over] = (($overturned[$over] ?? 0) + 1);
			}
		}

		return $overturned;
	}//end overturnedPerRule()
}//end class
