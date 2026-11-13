<?php

/**
 * The daily digest counts what waits for a person and names the first items.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use DateTimeImmutable;
use OCA\Dossiq\Service\Queue\DailyDigestComposer;
use OCA\Dossiq\Service\Queue\PersonalQueueService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Queue\DailyDigestComposer
 */
class DailyDigestComposerTest extends TestCase {
	/**
	 * A composer over a queue that answers the given items.
	 *
	 * @param list<array<string, mixed>> $items       The queue items.
	 * @param list<string>               $unavailable The unavailable sources.
	 * @param DateTimeImmutable          $now         The moment the queue is asked for.
	 *
	 * @return DailyDigestComposer
	 */
	private function composer(array $items, array $unavailable, DateTimeImmutable $now): DailyDigestComposer {
		$queue = $this->createMock(PersonalQueueService::class);
		$queue->expects($this->once())
			->method('forPerson')
			->with('alice', $now)
			->willReturn(['items' => $items, 'closings' => [], 'unavailable' => $unavailable]);

		return new DailyDigestComposer(queue: $queue);
	}//end composer()

	/**
	 * An empty queue gives no digest.
	 *
	 * @return void
	 */
	public function testAnEmptyQueueGivesNoDigest(): void {
		$now = new DateTimeImmutable('2026-03-02T07:00:00Z');

		$this->assertNull($this->composer([], ['mentions'], $now)->compose(userId: 'alice', now: $now));
	}//end testAnEmptyQueueGivesNoDigest()

	/**
	 * The digest counts every item and every late one, names the first five
	 * in queue order, and counts the sources that could not be read.
	 *
	 * @return void
	 */
	public function testTheDigestCountsAndNamesTheFirstFive(): void {
		$now = new DateTimeImmutable('2026-03-02T07:00:00Z');
		$items = [];
		foreach (range(1, 7) as $i) {
			$items[] = ['id' => $i, 'title' => 'Case ' . $i, 'source' => 'cases', 'deadlineTier' => ($i % 3 === 0 ? 'overdue' : 'warning')];
		}

		$items[1] = ['id' => 2, 'title' => 'Case 2', 'source' => 'mentions'];

		$digest = $this->composer($items, ['advice'], $now)->compose(userId: 'alice', now: $now);

		$this->assertSame('alice', $digest['person']);
		$this->assertSame('2026-03-02', $digest['day']);
		$this->assertSame(7, $digest['waiting']);
		$this->assertSame(2, $digest['late']);
		$this->assertSame(1, $digest['unavailableSources']);
		$this->assertCount(DailyDigestComposer::NAMED_ITEMS, $digest['named']);
		$this->assertSame(['id' => '1', 'title' => 'Case 1', 'source' => 'cases', 'deadlineTier' => 'warning'], $digest['named'][0]);
		$this->assertSame(['id' => '2', 'title' => 'Case 2', 'source' => 'mentions', 'deadlineTier' => 'normal'], $digest['named'][1]);
		$this->assertSame('5', $digest['named'][4]['id']);
	}//end testTheDigestCountsAndNamesTheFirstFive()
}//end class
