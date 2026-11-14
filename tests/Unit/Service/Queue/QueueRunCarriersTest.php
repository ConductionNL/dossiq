<?php

/**
 * The per-run carriers of the queue fold and scoring keep what they are given.
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
use OCA\Dossiq\Service\Queue\CaseQueueSource;
use OCA\Dossiq\Service\Queue\QueueFoldRun;
use OCA\Dossiq\Service\Queue\QueueScoringRun;
use OCA\Dossiq\Service\Queue\UrgencyProfile;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Queue\QueueFoldRun
 * @covers \OCA\Dossiq\Service\Queue\QueueScoringRun
 * @uses   \OCA\Dossiq\Service\Queue\CaseQueueSource
 * @uses   \OCA\Dossiq\Service\Queue\UrgencyProfile
 */
class QueueRunCarriersTest extends TestCase {
	/**
	 * A fold run derives its day from the moment and starts with empty figures.
	 *
	 * @return void
	 */
	public function testAFoldRunStartsEmptyOnItsDay(): void {
		$run = new QueueFoldRun(today: new DateTimeImmutable('2026-03-02T23:30:00+01:00'), typeTitles: ['ct-1' => 'Permit']);

		$this->assertSame('2026-03-02', $run->todayText);
		$this->assertSame(['ct-1' => 'Permit'], $run->typeTitles);
		$this->assertSame(
			['queueCount' => 0, 'queueNewToday' => 0, 'queueDueSoon' => 0, 'queueOldestDays' => 0, 'queueOldestType' => '', 'queueOldestDueDays' => null],
			$run->figures
		);
		$this->assertNull($run->oldestStart);
	}//end testAFoldRunStartsEmptyOnItsDay()

	/**
	 * Swapping the profile keeps the source and the moment and leaves the original run as it was.
	 *
	 * @return void
	 */
	public function testWithProfileSwapsOnlyTheProfile(): void {
		$source = new CaseQueueSource(objectService: new \stdClass(), register: '5', caseSchema: 'case');
		$now = new DateTimeImmutable('2026-03-02T07:00:00Z');
		$first = new UrgencyProfile();
		$second = new UrgencyProfile(criticalDays: 1, warningDays: 2);
		$run = new QueueScoringRun(source: $source, now: $now, profile: $first);

		$swapped = $run->withProfile(profile: $second);

		$this->assertNotSame($run, $swapped);
		$this->assertSame($source, $swapped->source);
		$this->assertSame($now, $swapped->now);
		$this->assertSame($second, $swapped->profile);
		$this->assertSame($first, $run->profile);
	}//end testWithProfileSwapsOnlyTheProfile()
}//end class
