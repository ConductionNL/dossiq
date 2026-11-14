<?php

/**
 * Where a case's receipt is read from: the stamp, the receipt-date answer, and the fallbacks.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Intake
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use DateTimeImmutable;
use OCA\Dossiq\Service\Intake\IntakeTermStart;
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Intake\IntakeTermStart
 */
class IntakeTermStartReceiptTest extends TestCase {

	/**
	 * The service, on a calendar that answers one moment for every question.
	 *
	 * @param DateTimeImmutable|null $answering The first working moment, or null for an unreachable calendar.
	 * @param array<int, string>     $asked     Filled with every moment the calendar is asked about.
	 *
	 * @return IntakeTermStart
	 */
	private function intake(?DateTimeImmutable $answering, array &$asked = []): IntakeTermStart {
		$calendar = $this->createMock(WorkingDayRoll::class);
		$calendar->method('firstWorkingMomentAtOrAfter')->willReturnCallback(
			static function (DateTimeImmutable $moment) use ($answering, &$asked): ?DateTimeImmutable {
				$asked[] = $moment->format('c');
				return $answering;
			}
		);

		return new IntakeTermStart(calendar: $calendar);
	}//end intake()

	/**
	 * A receipt with a time of day is judged by the instant, not by the day, so an early filing is outside.
	 *
	 * @return void
	 */
	public function testAReceiptWithATimeIsJudgedByTheInstant(): void {
		$asked = [];
		$intake = $this->intake(answering: new DateTimeImmutable('2026-03-09T09:00:00+01:00'), asked: $asked);

		$stamp = $intake->stampForCase(case: ['receivedAt' => '2026-03-09T07:30:00+01:00']);

		self::assertSame(
			[
				'receivedAt' => '2026-03-09T07:30:00+01:00',
				'termStartsAt' => '2026-03-09T09:00:00+01:00',
				// The same day, but not the same instant: a day-only reading would say inside.
				'receivedOutsideWorkingHours' => true,
			],
			$stamp
		);
		self::assertSame(['2026-03-09T07:30:00+01:00'], $asked);
	}//end testAReceiptWithATimeIsJudgedByTheInstant()

	/**
	 * An unreachable calendar stamps a case nothing, whatever its receipt.
	 *
	 * @return void
	 */
	public function testAnUnreachableCalendarStampsACaseNothing(): void {
		self::assertSame([], $this->intake(answering: null)->stampForCase(case: ['receivedAt' => '2026-03-09']));
	}//end testAnUnreachableCalendarStampsACaseNothing()

	/**
	 * The receipt-date answer is the first non-blank `receiptDate` entry; other entries are passed over.
	 *
	 * @return void
	 */
	public function testTheReceiptAnswerIsTheFirstFilledReceiptDateEntry(): void {
		$intake = $this->intake(answering: null);

		self::assertSame(
			'2026-02-03',
			$intake->receiptAnswerOf(
				case: [
					'properties' => [
						'not an entry',
						['name' => 'otherDate', 'value' => '2026-01-01'],
						['name' => 'receiptDate', 'value' => '   '],
						['name' => 'receiptDate', 'value' => ['2026-01-02']],
						['name' => 'receiptDate', 'value' => ' 2026-02-03 '],
					],
				]
			)
		);
		self::assertSame(
			'2026-04-05',
			$intake->receiptAnswerOf(case: ['properties' => '[{"name":"receiptDate","value":"2026-04-05"}]'])
		);
	}//end testTheReceiptAnswerIsTheFirstFilledReceiptDateEntry()

	/**
	 * Properties that do not read as a list have no receipt answer.
	 *
	 * @return void
	 */
	public function testUnreadablePropertiesHaveNoReceiptAnswer(): void {
		$intake = $this->intake(answering: null);

		self::assertSame('', $intake->receiptAnswerOf(case: ['properties' => 'not json']));
		self::assertSame('', $intake->receiptAnswerOf(case: ['properties' => 7]));
		self::assertSame('', $intake->receiptAnswerOf(case: []));
	}//end testUnreadablePropertiesHaveNoReceiptAnswer()

	/**
	 * An unreadable candidate is passed over for the next, and with none the arrival is now.
	 *
	 * @return void
	 */
	public function testTheArrivalSkipsUnreadableCandidatesAndFallsBackToNow(): void {
		$intake = $this->intake(answering: null);

		self::assertSame(
			'2026-01-05',
			$intake->arrivalOf(case: ['receivedAt' => 'not a date', 'registrationDate' => '2026-01-05'])->format('Y-m-d')
		);

		$before = time();
		$now = $intake->arrivalOf(case: ['receivedAt' => 'not a date', '@self' => ['created' => '']]);
		$after = time();

		self::assertGreaterThanOrEqual($before, $now->getTimestamp());
		self::assertLessThanOrEqual($after, $now->getTimestamp());
	}//end testTheArrivalSkipsUnreadableCandidatesAndFallsBackToNow()
}//end class
