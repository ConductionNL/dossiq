<?php

/**
 * The order a personal queue is read in: the next thing to do, first.
 *
 * IT DOES NOT INVENT A RANKING. `WorkQueueService::scoreItem()` already
 * answers "how pressing is this" for dossiq, in business days, with the tier
 * names the cards already draw and the priority weights the case list already
 * sorts by. A second ranking here would be a queue that sorts differently from
 * the way it reads, which is the failure `case-priority-impact-urgency` wrote
 * down when it put the three priority answers in one function.
 *
 * So this class is a thin adapter: it hands each item's date and priority to
 * the existing scorer and sorts on what comes back. What it adds is the tie
 * break, because a queue fed by seven mechanisms will produce ties the
 * two-mechanism version never did, and two items swapping places between reads
 * is how a reader loses their place.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

use DateTimeImmutable;
use OCA\Dossiq\Service\WorkQueueService;

/**
 * Scores and orders queue items so the next thing to do is first.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
class QueueOrdering {
	/**
	 * Constructor.
	 *
	 * @param WorkQueueService $scorer The one urgency rule dossiq has.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function __construct(
		private readonly WorkQueueService $scorer,
	) {
	}//end __construct()

	/**
	 * Score one item.
	 *
	 * @param QueueItem         $item The item.
	 * @param DateTimeImmutable $now  The moment to score against.
	 *
	 * @return array<string, mixed> The scorer's verdict: tier, days, score and breakdown.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function score(QueueItem $item, DateTimeImmutable $now): array {
		return $this->scorer->scoreItem(
			deadline: $item->dueAt,
			priority: $item->priority,
			referenceDate: null,
			now: $now
		);
	}//end score()

	/**
	 * Sort the items, most pressing first, and attach each one's score.
	 *
	 * @param array<int, QueueItem> $items The items to order.
	 * @param DateTimeImmutable     $now   The moment to score against.
	 *
	 * @return array<int, array<string, mixed>> The ordered items, each as its wire shape plus its score.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function order(array $items, DateTimeImmutable $now): array {
		$scored = [];
		foreach ($items as $item) {
			$verdict = $this->score(item: $item, now: $now);
			$scored[] = array_merge($item->jsonSerialize(), $verdict);
		}

		usort(
			$scored,
			static function (array $left, array $right): int {
				$byScore = ($right['score'] <=> $left['score']);
				if ($byScore !== 0) {
					return $byScore;
				}

				// Stable on the id, so two equally pressing items keep their
				// places between reads instead of trading them.
				return strcmp((string)$left['id'], (string)$right['id']);
			}
		);

		return $scored;
	}//end order()
}//end class
