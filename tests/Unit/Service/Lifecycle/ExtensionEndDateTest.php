<?php

/**
 * Unit tests for ExtensionEndDate.
 *
 * The end an extension lands on: a named date that must lie after the current
 * end, else the current end plus the case type's period, rolled by the
 * administered calendar when one is wired.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 *
 * @covers \OCA\Dossiq\Service\Lifecycle\ExtensionEndDate
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Lifecycle;

use DateTimeImmutable;
use OCA\Dossiq\Service\Lifecycle\ExtensionEndDate;
use OCA\Dossiq\Service\TermijnTimerService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ExtensionEndDateTest extends TestCase {

	/**
	 * A named date is taken as named once it lies after the current end, and refused otherwise.
	 *
	 * @return void
	 */
	public function testANamedDateMustLieAfterTheCurrentEnd(): void {
		$endDates = new ExtensionEndDate();

		$this->assertSame(
			'2027-04-01',
			$endDates->resolve(current: '2027-03-01', asked: ['named' => '2027-04-01T10:00:00', 'period' => 'P14D'])
		);
		$this->assertSame(
			'2027-04-01',
			$endDates->resolve(current: '', asked: ['named' => '2027-04-01', 'period' => ''])
		);

		foreach ([['2027-03-01', 'new_end_date_not_later'], ['not a date', 'new_end_date_unreadable']] as [$named, $code]) {
			try {
				$endDates->resolve(current: '2027-03-01', asked: ['named' => $named, 'period' => 'P14D']);
				$this->fail('Expected '.$code);
			} catch (RuntimeException $e) {
				$this->assertSame($code, $e->getMessage());
			}
		}
	}//end testANamedDateMustLieAfterTheCurrentEnd()

	/**
	 * Without a named date the period is added, and a missing or unreadable period is refused.
	 *
	 * @return void
	 */
	public function testThePeriodIsAddedWhenNoDateIsNamed(): void {
		$endDates = new ExtensionEndDate();

		$this->assertSame(
			'2027-03-15',
			$endDates->resolve(current: '2027-03-01', asked: ['named' => '', 'period' => 'P14D'])
		);

		foreach ([['', 'extension_period_not_configured'], ['fortnight', 'extension_period_unreadable']] as [$period, $code]) {
			try {
				$endDates->resolve(current: '2027-03-01', asked: ['named' => '', 'period' => $period]);
				$this->fail('Expected '.$code);
			} catch (RuntimeException $e) {
				$this->assertSame($code, $e->getMessage());
			}
		}
	}//end testThePeriodIsAddedWhenNoDateIsNamed()

	/**
	 * The calendar decides the day a period lands on; a named date is not rolled.
	 *
	 * @return void
	 */
	public function testTheCalendarRollsTheEndOfAPeriodOnly(): void {
		$timer = $this->createMock(originalClassName: TermijnTimerService::class);
		$timer->expects($this->once())
			->method('rollTermEndFor')
			->with($this->callback(static fn (DateTimeImmutable $date): bool => $date->format('Y-m-d') === '2027-03-13'))
			->willReturn(new DateTimeImmutable('2027-03-15'));

		$endDates = new ExtensionEndDate(timer: $timer);

		$this->assertSame(
			'2027-03-15',
			$endDates->resolve(current: '2027-03-01', asked: ['named' => '', 'period' => 'P12D'])
		);
		$this->assertSame(
			'2027-03-13',
			$endDates->resolve(current: '2027-03-01', asked: ['named' => '2027-03-13', 'period' => 'P12D'])
		);
	}//end testTheCalendarRollsTheEndOfAPeriodOnly()
}//end class
