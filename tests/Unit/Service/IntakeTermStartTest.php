<?php

/**
 * When a request arrived, when its clock starts, and what the citizen is told.
 *
 * 🔴 THE ASSERTION THAT CARRIES THE ROW is that a Sunday filing and a Tuesday
 * filing are stamped DIFFERENTLY. A test that only checked the Sunday case
 * would pass against a service that flagged every case as out of hours, which
 * is the same as flagging none: a confirmation that always explains itself is
 * one people stop reading, and the gap register measured exactly that
 * behaviour as the losing one.
 *
 * 🔴 AND THAT AN UNANSWERED CALENDAR STAMPS NOTHING. dossiq keeps no calendar
 * of its own (`NoLocalCalendarTest` refuses one), so when the engine does not
 * answer the honest result is an absent `termStartsAt`, not a weekday guess. A
 * guessed start is quoted back by a citizen months later and there is no
 * defence for it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\IntakeConfirmation;
use OCA\Dossiq\Service\IntakeTermStart;
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The two moments, the flag, and the sentence between them.
 *
 * @covers \OCA\Dossiq\Service\IntakeTermStart
 * @covers \OCA\Dossiq\Service\IntakeConfirmation
 */
class IntakeTermStartTest extends TestCase {

	/**
	 * A calendar that answers a fixed first working moment.
	 *
	 * Doubled with `onlyMethods`, so the double cannot grow a method the real
	 * class does not have.
	 *
	 * @param DateTimeImmutable|null $answer What the calendar says, or null.
	 *
	 * @return WorkingDayRoll The double.
	 */
	private function calendar(?DateTimeImmutable $answer): WorkingDayRoll {
		$calendar = $this->getMockBuilder(WorkingDayRoll::class)
			->disableOriginalConstructor()
			->onlyMethods(['firstWorkingMomentAtOrAfter'])
			->getMock();
		$calendar->method('firstWorkingMomentAtOrAfter')->willReturn($answer);

		return $calendar;
	}//end calendar()

	/**
	 * A translator that hands back the source string.
	 *
	 * @return IL10N The double.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return $l10n;
	}//end l10n()

	/**
	 * A Sunday filing is stamped as starting on Monday, and says so.
	 *
	 * @return void
	 */
	public function testASundayFilingStartsOnMonday(): void {
		$received = new DateTimeImmutable('2026-09-13T20:41:00+02:00');
		$monday = new DateTimeImmutable('2026-09-14T09:00:00+02:00');

		$stamp = (new IntakeTermStart($this->calendar($monday)))->stampFor(received: $received);

		$this->assertSame($received->format(DateTimeImmutable::ATOM), $stamp['receivedAt']);
		$this->assertSame($monday->format(DateTimeImmutable::ATOM), $stamp['termStartsAt']);
		$this->assertTrue($stamp['receivedOutsideWorkingHours']);
	}//end testASundayFilingStartsOnMonday()

	/**
	 * A filing inside the window starts at once, and does not explain itself.
	 *
	 * The control for the test above: without it, "true" could mean the flag
	 * is true for everything, which explains every confirmation and therefore
	 * none.
	 *
	 * @return void
	 */
	public function testAFilingInsideTheWindowStartsAtOnce(): void {
		$received = new DateTimeImmutable('2026-09-15T10:00:00+02:00');

		$stamp = (new IntakeTermStart($this->calendar($received)))->stampFor(received: $received);

		$this->assertSame($received->format(DateTimeImmutable::ATOM), $stamp['termStartsAt']);
		$this->assertFalse($stamp['receivedOutsideWorkingHours']);
	}//end testAFilingInsideTheWindowStartsAtOnce()

	/**
	 * The same instant in another timezone is not "outside working hours".
	 *
	 * @return void
	 */
	public function testTheSameInstantInAnotherZoneIsNotOutsideHours(): void {
		$received = new DateTimeImmutable('2026-09-15T10:00:00+02:00');
		$sameMomentElsewhere = new DateTimeImmutable('2026-09-15T08:00:00+00:00');

		$stamp = (new IntakeTermStart($this->calendar($sameMomentElsewhere)))
			->stampFor(received: $received);

		// A string comparison would call these different and flag a Tuesday
		// morning filing as out of hours.
		$this->assertFalse($stamp['receivedOutsideWorkingHours']);
	}//end testTheSameInstantInAnotherZoneIsNotOutsideHours()

	/**
	 * An unanswered calendar stamps the arrival and nothing else.
	 *
	 * @return void
	 */
	public function testAnUnansweredCalendarStampsNoStart(): void {
		$received = new DateTimeImmutable('2026-09-13T20:41:00+02:00');

		$stamp = (new IntakeTermStart($this->calendar(null)))->stampFor(received: $received);

		$this->assertSame($received->format(DateTimeImmutable::ATOM), $stamp['receivedAt']);
		$this->assertArrayNotHasKey(
			'termStartsAt',
			$stamp,
			'dossiq keeps no calendar of its own, so an unanswered start is left unsaid'
		);
		$this->assertArrayNotHasKey('receivedOutsideWorkingHours', $stamp);
	}//end testAnUnansweredCalendarStampsNoStart()

	/**
	 * A case that is already stamped is not stamped again.
	 *
	 * @return void
	 */
	public function testAnAlreadyStampedCaseIsLeftAlone(): void {
		$intake = new IntakeTermStart($this->calendar(null));

		$this->assertTrue($intake->isStamped(case: ['receivedAt' => '2026-01-02T09:00:00+01:00']));
		$this->assertFalse($intake->isStamped(case: ['receivedAt' => '   ']));
		$this->assertFalse($intake->isStamped(case: []));
	}//end testAnAlreadyStampedCaseIsLeftAlone()

	/**
	 * The stored flag is read, never recomputed.
	 *
	 * @return void
	 */
	public function testTheStoredFlagIsWhatIsRead(): void {
		$intake = new IntakeTermStart($this->calendar(null));

		$this->assertTrue($intake->wasOutsideWorkingHours(case: ['receivedOutsideWorkingHours' => true]));
		$this->assertTrue($intake->wasOutsideWorkingHours(case: ['receivedOutsideWorkingHours' => '1']));
		$this->assertFalse($intake->wasOutsideWorkingHours(case: ['receivedOutsideWorkingHours' => false]));
		$this->assertFalse($intake->wasOutsideWorkingHours(case: []));
	}//end testTheStoredFlagIsWhatIsRead()

	/**
	 * The confirmation names the four, and explains only when it must.
	 *
	 * @return void
	 */
	public function testTheConfirmationNamesTheFour(): void {
		$confirmation = new IntakeConfirmation(
			new IntakeTermStart($this->calendar(null)),
			$this->l10n()
		);

		$outOfHours = $confirmation->forCase(
			case: [
				'identifier' => '2026-0042',
				'receivedAt' => '2026-09-13T20:41:00+02:00',
				'termStartsAt' => '2026-09-14T09:00:00+02:00',
				'deadline' => '2026-11-09',
				'receivedOutsideWorkingHours' => true,
			]
		);

		$this->assertSame('2026-0042', $outOfHours['reference']);
		$this->assertSame('2026-09-13T20:41:00+02:00', $outOfHours['receivedAt']);
		$this->assertSame('2026-09-14T09:00:00+02:00', $outOfHours['termStartsAt']);
		$this->assertSame('2026-11-09', $outOfHours['deadline']);
		$this->assertStringContainsString('first working day', $outOfHours['explanation']);

		$inHours = $confirmation->forCase(
			case: [
				'identifier' => '2026-0043',
				'receivedAt' => '2026-09-15T10:00:00+02:00',
				'termStartsAt' => '2026-09-15T10:00:00+02:00',
				'deadline' => '2026-11-10',
				'receivedOutsideWorkingHours' => false,
			]
		);

		// 🔴 THE SENTENCE IS ABSENT, not merely different. An explanation on
		// every confirmation is one nobody reads.
		$this->assertSame('', $inHours['explanation']);
		$this->assertSame('2026-11-10', $inHours['deadline']);
	}//end testTheConfirmationNamesTheFour()

	/**
	 * No sentence promises a start the confirmation cannot name.
	 *
	 * @return void
	 */
	public function testNoSentenceWithoutAStartToPointAt(): void {
		$confirmation = new IntakeConfirmation(
			new IntakeTermStart($this->calendar(null)),
			$this->l10n()
		);

		$answer = $confirmation->forCase(
			case: ['receivedOutsideWorkingHours' => true, 'termStartsAt' => '']
		);

		$this->assertSame('', $answer['explanation']);
	}//end testNoSentenceWithoutAStartToPointAt()

	/**
	 * The mail placeholders come from the same producer as the screen.
	 *
	 * @return void
	 */
	public function testThePlaceholdersMatchTheScreen(): void {
		$confirmation = new IntakeConfirmation(
			new IntakeTermStart($this->calendar(null)),
			$this->l10n()
		);
		$case = [
			'identifier' => '2026-0042',
			'receivedAt' => '2026-09-13T20:41:00+02:00',
			'termStartsAt' => '2026-09-14T09:00:00+02:00',
			'deadline' => '2026-11-09',
			'receivedOutsideWorkingHours' => true,
		];

		$screen = $confirmation->forCase(case: $case);
		$mail = $confirmation->placeholdersFor(case: $case);

		$this->assertSame($screen['receivedAt'], $mail['ontvangenOp']);
		$this->assertSame($screen['termStartsAt'], $mail['termijnStartOp']);
		$this->assertSame($screen['explanation'], $mail['buitenKantoortijden']);
	}//end testThePlaceholdersMatchTheScreen()
}//end class
