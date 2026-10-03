<?php

/**
 * One queue, built from the declared sources.
 *
 * The page asks this class one question and renders the answer. It holds no
 * queries of its own, and that is the property worth protecting: the day
 * somebody adds an eighth mechanism, this file does not change.
 *
 * A source that throws is named on the queue as unavailable. It is not caught
 * and turned into an empty list, because an empty list is a claim: it says
 * nothing is waiting on you. ADR-102.
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
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Assembles a person's queue out of the declared sources.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
class PersonalQueueService {
	/**
	 * Constructor.
	 *
	 * @param QueueSourceCatalogue $catalogue   The declared sources.
	 * @param QueueOrdering        $ordering    The one urgency rule.
	 * @param QueueViewPreferences $preferences What the reader changed about their own view.
	 * @param LoggerInterface      $logger      Logger.
	 * @param QueueItemLifecycle   $lifecycle   Whether an item still stands for this
	 *                                          person. LAST and defaulted, like
	 *                                          `QueueItem::$waiting` and for the same
	 *                                          reason: every existing construction of
	 *                                          this service keeps working unchanged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function __construct(
		private readonly QueueSourceCatalogue $catalogue,
		private readonly QueueOrdering $ordering,
		private readonly QueueViewPreferences $preferences,
		private readonly LoggerInterface $logger,
		private readonly QueueItemLifecycle $lifecycle = new QueueItemLifecycle(),
	) {
	}//end __construct()

	/**
	 * Everything waiting on one person, most pressing first.
	 *
	 * @param string                 $userId The person.
	 * @param DateTimeImmutable|null $now    The moment to score against; the current one when null.
	 *
	 * @return array<string, mixed> The queue: items, groups, unavailable sources and the reader's view settings.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function forPerson(string $userId, ?DateTimeImmutable $now = null): array {
		$now = ($now ?? new DateTimeImmutable());
		$today = $now->format('Y-m-d');

		$resolved = $this->catalogue->resolve();
		$unavailable = $resolved['unavailable'];

		$items = [];
		$closings = [];
		foreach ($resolved['sources'] as $source) {
			$closings[$source->name()] = [
				'label' => $source->label(),
				'closesWhen' => $source->closesWhen(),
			];

			try {
				foreach ($source->itemsFor(userId: $userId) as $item) {
					if ($this->hasClosed(item: $item, userId: $userId) === true) {
						continue;
					}

					$items[] = $item;
				}
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq: the queue source ' . $source->name() . ' could not be read: ' . $e->getMessage()
				);
				$unavailable[] = [
					'source' => $source->name(),
					'label' => $source->label(),
					'reason' => $e->getMessage(),
				];
			}
		}

		$ordered = $this->ordering->order(items: $items, now: $now);
		$groupBy = $this->preferences->groupBy(userId: $userId);
		$hidden = $this->preferences->hiddenGroups(userId: $userId, today: $today);

		return [
			'items' => $ordered,
			'groups' => $this->group(items: $ordered, groupBy: $groupBy, closings: $closings),
			'groupBy' => $groupBy,
			'hiddenGroups' => $hidden,
			'unavailable' => $unavailable,
			'total' => count($ordered),
		];
	}//end forPerson()

	/**
	 * Whether an item has stopped standing for this person.
	 *
	 * `QueueItemLifecycle` has answered this since the queue shipped and
	 * nothing asked it, so a case somebody finished, or handed on, stayed on
	 * their queue until they pressed something. A work list that shows work
	 * already done is one people stop trusting, and then stop reading.
	 *
	 * 🔴 AN ITEM WITH NO SUBJECT IS LEFT ALONE, AND THE EARLY RETURN SAYS SO IN
	 * ONE PLACE. A source that passed nothing is saying it did not read one,
	 * which is a different statement from "the subject is gone". Eight of the
	 * ten declared sources pass nothing today, so the day anybody reads an
	 * absent subject as a closed one, every one of their items disappears and
	 * every source still reports itself available.
	 *
	 * Measured rather than assumed: today `stillStands()` happens to answer TRUE
	 * for an empty array anyway, because it only treats a NULL subject as
	 * withdrawn, so deleting this branch reddens nothing. It stays because that
	 * agreement is incidental, and `testASourceThatPassesNoSubjectKeepsItsItems`
	 * pins the behaviour either way: it reddens when this branch is inverted.
	 *
	 * @param QueueItem $item   The item.
	 * @param string    $userId The person whose queue this is.
	 *
	 * @return bool TRUE when the item should not be shown.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	private function hasClosed(QueueItem $item, string $userId): bool {
		if ($item->subject === []) {
			return false;
		}

		return ($this->lifecycle->stillStands(item: $item, subject: $item->subject, userId: $userId) === false);
	}//end hasClosed()

	/**
	 * Group the ordered items.
	 *
	 * The groups keep the queue's order: a group is named by the first item
	 * that lands in it, so the most pressing work is in the first group and
	 * the reader does not have to scan four headings to find it.
	 *
	 * @param array<int, array<string, mixed>>  $items    The ordered items.
	 * @param string                            $groupBy  One of QueueViewPreferences::GROUPINGS.
	 * @param array<string, array<string, string>> $closings Source name to its label and closing sentence.
	 *
	 * @return array<int, array<string, mixed>> The groups, in queue order.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	private function group(array $items, string $groupBy, array $closings): array {
		$groups = [];

		foreach ($items as $item) {
			$key = $this->groupKeyOf(item: $item, groupBy: $groupBy);
			if (isset($groups[$key]) === false) {
				$groups[$key] = [
					'key' => $key,
					'label' => ($closings[$key]['label'] ?? $key),
					'closesWhen' => ($closings[$key]['closesWhen'] ?? ''),
					'items' => [],
				];
			}

			$groups[$key]['items'][] = $item;
		}

		return array_values($groups);
	}//end group()

	/**
	 * Which group one item lands in.
	 *
	 * @param array<string, mixed> $item    The item.
	 * @param string               $groupBy One of QueueViewPreferences::GROUPINGS.
	 *
	 * @return string The group key.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	private function groupKeyOf(array $item, string $groupBy): string {
		if ($groupBy === 'priority') {
			$priority = trim((string)($item['priority'] ?? ''));
			if ($priority === '') {
				return 'normal';
			}

			return $priority;
		}

		if ($groupBy === 'due') {
			return (string)($item['tier'] ?? 'normal');
		}

		return (string)($item['source'] ?? '');
	}//end groupKeyOf()
}//end class
