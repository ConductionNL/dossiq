<?php

/**
 * The daily digest, and the decision not to send one.
 *
 * A digest that arrives every morning saying nothing trains people to delete
 * it unread, and then the one that matters is deleted too. So an empty queue
 * composes nothing at all, and the job has nothing to send. That is the whole
 * rule, and it is here rather than in the job so it can be tested without a
 * cron runner.
 *
 * IT IS NOT THE ASSIGNMENT NOTICE. The notice says "this is now yours" at the
 * moment it became yours; the digest says "here is everything" at a moment the
 * reader chose. They are different messages and this one never repeats the
 * other: it names counts and the first few items, and links into the queue.
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

/**
 * Composes one person's digest, or decides there is none.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
class DailyDigestComposer {
	/**
	 * How many items the digest names before it says "and the rest".
	 *
	 * @var int
	 */
	public const NAMED_ITEMS = 5;

	/**
	 * Constructor.
	 *
	 * @param PersonalQueueService $queue The person's queue.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function __construct(
		private readonly PersonalQueueService $queue,
	) {
	}//end __construct()

	/**
	 * Compose the digest for one person, or nothing when there is nothing to say.
	 *
	 * @param string            $userId The person.
	 * @param DateTimeImmutable $now    The moment the digest is for.
	 *
	 * @return array<string, mixed>|null The digest, or null when the queue is empty.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function compose(string $userId, DateTimeImmutable $now): ?array {
		$queue = $this->queue->forPerson(userId: $userId, now: $now);
		$items = $queue['items'];

		if ($items === []) {
			return null;
		}

		$late = array_values(
			array_filter($items, static fn (array $item): bool => (($item['tier'] ?? '') === 'overdue'))
		);

		$named = [];
		foreach (array_slice($items, 0, self::NAMED_ITEMS) as $item) {
			$named[] = [
				'id' => (string)$item['id'],
				'title' => (string)$item['title'],
				'source' => (string)$item['source'],
				'tier' => (string)($item['tier'] ?? 'normal'),
			];
		}

		return [
			'person' => $userId,
			'day' => $now->format('Y-m-d'),
			'waiting' => count($items),
			'late' => count($late),
			'named' => $named,
			'unavailableSources' => count($queue['unavailable']),
		];
	}//end compose()
}//end class
