<?php

/**
 * Unit tests for QueueFigures.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use DateTimeImmutable;
use OCA\Dossiq\Service\Queue\QueueFigures;
use OCA\Dossiq\Service\Term\DayCount;
use PHPUnit\Framework\TestCase;

/**
 * The team queue's figures fold over the open cases.
 */
class QueueFiguresTest extends TestCase {
	/**
	 * A case without a readable start date counts as waiting no days, and one without a type is not grouped.
	 *
	 * @return void
	 */
	public function testUnreadableDatesAndMissingTypesFoldQuietly(): void {
		$today = new DateTimeImmutable('2026-10-16');
		$figures = (new QueueFigures(new DayCount()))->fold(
			openCases: [
				['startDate' => 'not a date', 'deadline' => ''],
				['startDate' => '2026-10-20', 'caseType' => 'woo'],
				['startDate' => '2026-10-10', 'caseType' => 'woo', 'assignee' => 'pieter'],
			],
			today: $today,
			typeTitles: []
		);

		$this->assertSame(2, $figures['queueCount']);
		$this->assertSame(0, $figures['queueOldestDays'], 'a future or unreadable start is no wait at all.');
		$this->assertSame(0, $figures['queueDueSoon']);
		$this->assertSame([['caseType' => 'woo', 'title' => 'woo', 'open' => 2, 'waiting' => 1]], $figures['queueByType']);
	}//end testUnreadableDatesAndMissingTypesFoldQuietly()

	/**
	 * Nothing open folds to zeroes and an empty breakdown.
	 *
	 * @return void
	 */
	public function testNothingOpenFoldsToZero(): void {
		$figures = (new QueueFigures(new DayCount()))->fold(openCases: [], today: new DateTimeImmutable('2026-10-16'), typeTitles: []);

		$this->assertSame(
			['queueCount' => 0, 'queueNewToday' => 0, 'queueDueSoon' => 0, 'queueOldestDays' => 0, 'queueByType' => []],
			$figures
		);
	}//end testNothingOpenFoldsToZero()
}//end class
