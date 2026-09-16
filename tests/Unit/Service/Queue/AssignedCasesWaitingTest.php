<?php

/**
 * The queue says who a case waits on, since when, and what has been tried.
 *
 * The day count is computed on the server, beside every other number the queue
 * renders, so a fixed moment is what the test pins it against.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use DateTimeImmutable;
use OCA\Dossiq\Service\Queue\Source\AssignedCasesSource;
use OCA\Dossiq\Service\SettingsService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * REQ-TERM-011: the queue reads the waiting sentence off the case.
 *
 * @covers \OCA\Dossiq\Service\Queue\Source\AssignedCasesSource
 */
class AssignedCasesWaitingTest extends TestCase {
	/**
	 * The source under test.
	 *
	 * @var AssignedCasesSource
	 */
	private AssignedCasesSource $source;

	/**
	 * Build the source. Only the pure reading is exercised here, so the
	 * register seam is a double that is never asked anything.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->source = new AssignedCasesSource(
			settings: $this->createMock(SettingsService::class),
			l10n: $l10n,
		);
	}//end setUp()

	/**
	 * A case waiting on the applicant reads the party, the days and the count.
	 *
	 * @return void
	 */
	public function testACaseWaitingOnTheApplicantReadsItsFacts(): void {
		$facts = $this->source->waitingFactsOf(
			row: [
				'pauseWaitingOn' => 'applicant',
				'waitingSince' => '2026-09-01',
				'chasesSent' => 2,
			],
			now: new DateTimeImmutable('2026-09-10')
		);

		$this->assertSame('applicant', $facts['on']);
		$this->assertSame(9, $facts['days']);
		$this->assertSame(2, $facts['chases']);
	}//end testACaseWaitingOnTheApplicantReadsItsFacts()

	/**
	 * A case paused before pause reasons existed still reads a number, from
	 * the date the aanvullingsverzoek wrote.
	 *
	 * @return void
	 */
	public function testAnOlderCaseFallsBackToTheRequestDate(): void {
		$facts = $this->source->waitingFactsOf(
			row: [
				'pauseWaitingOn' => 'applicant',
				'waitingOnApplicantSince' => '2026-09-03T08:00:00+02:00',
			],
			now: new DateTimeImmutable('2026-09-10')
		);

		$this->assertSame(7, $facts['days']);
		$this->assertSame(0, $facts['chases']);
	}//end testAnOlderCaseFallsBackToTheRequestDate()

	/**
	 * A case nobody is waiting on carries no sentence at all, rather than a
	 * row of zeroes under every case in the queue.
	 *
	 * @return void
	 */
	public function testACaseNobodyWaitsOnCarriesNoSentence(): void {
		$this->assertSame([], $this->source->waitingFactsOf(row: []));
		$this->assertSame([], $this->source->waitingFactsOf(row: ['pauseWaitingOn' => 'us']));
	}//end testACaseNobodyWaitsOnCarriesNoSentence()

	/**
	 * A date that cannot be read counts as zero days rather than throwing a
	 * queue nobody can open.
	 *
	 * @return void
	 */
	public function testAnUnreadableDateCountsAsZeroDays(): void {
		$facts = $this->source->waitingFactsOf(
			row: ['pauseWaitingOn' => 'thirdParty', 'waitingSince' => 'niet ingevuld'],
			now: new DateTimeImmutable('2026-09-10')
		);

		$this->assertSame('thirdParty', $facts['on']);
		$this->assertSame(0, $facts['days']);
	}//end testAnUnreadableDateCountsAsZeroDays()
}//end class
