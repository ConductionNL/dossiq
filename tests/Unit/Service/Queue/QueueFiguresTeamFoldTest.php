<?php

/**
 * The team queue's figures, narrowed to the reader's teams.
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
use OCA\Dossiq\Service\Queue\QueueFoldRun;
use OCA\Dossiq\Service\Team\ReaderTeamCases;
use OCA\Dossiq\Service\Term\DayCount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * foldFor() folds only the reader's team cases, or every case without a team filter.
 */
#[CoversClass(QueueFigures::class)]
#[UsesClass(QueueFoldRun::class)]
#[UsesClass(DayCount::class)]
class QueueFiguresTeamFoldTest extends TestCase {
	/**
	 * With a team filter, only the cases it hands back are counted.
	 *
	 * @return void
	 */
	public function testFoldForCountsOnlyTheReadersTeamCases(): void {
		$all = [
			['startDate' => '2026-10-10', 'caseType' => 'woo'],
			['startDate' => '2026-10-12', 'caseType' => 'bezwaar'],
		];
		$teamCases = $this->createMock(originalClassName: ReaderTeamCases::class);
		$teamCases->expects($this->once())
			->method('casesOf')
			->with('pieter', $all)
			->willReturn([$all[1]]);

		$figures = (new QueueFigures(dayCount: new DayCount(), teamCases: $teamCases))->foldFor(
			userId: 'pieter',
			openCases: $all,
			today: new DateTimeImmutable('2026-10-16'),
			typeTitles: ['bezwaar' => 'Bezwaar']
		);

		$this->assertSame(1, $figures['queueCount']);
		$this->assertSame(4, $figures['queueOldestDays']);
		$this->assertSame('Bezwaar', $figures['queueOldestType']);
		$this->assertSame([['caseType' => 'bezwaar', 'title' => 'Bezwaar', 'open' => 1, 'waiting' => 1]], $figures['queueByType']);
	}//end testFoldForCountsOnlyTheReadersTeamCases()

	/**
	 * Without a team filter, foldFor() folds every open case.
	 *
	 * @return void
	 */
	public function testFoldForWithoutTeamsFoldsEveryCase(): void {
		$figures = (new QueueFigures(dayCount: new DayCount()))->foldFor(
			userId: 'pieter',
			openCases: [
				['startDate' => '2026-10-10', 'caseType' => 'woo'],
				['startDate' => '2026-10-16', 'caseType' => 'woo'],
			],
			today: new DateTimeImmutable('2026-10-16'),
			typeTitles: []
		);

		$this->assertSame(2, $figures['queueCount']);
		$this->assertSame(1, $figures['queueNewToday']);
		$this->assertSame(6, $figures['queueOldestDays']);
	}//end testFoldForWithoutTeamsFoldsEveryCase()

	/**
	 * A waiting case with no start date at all is counted but has waited no days.
	 *
	 * @return void
	 */
	public function testAWaitingCaseWithoutAStartDateHasWaitedNoDays(): void {
		$figures = (new QueueFigures(dayCount: new DayCount()))->fold(
			openCases: [['caseType' => 'woo', 'deadline' => '2026-10-18']],
			today: new DateTimeImmutable('2026-10-16'),
			typeTitles: []
		);

		$this->assertSame(1, $figures['queueCount']);
		$this->assertSame(0, $figures['queueOldestDays']);
		$this->assertSame(1, $figures['queueDueSoon']);
	}//end testAWaitingCaseWithoutAStartDateHasWaitedNoDays()
}//end class
