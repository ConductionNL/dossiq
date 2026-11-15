<?php

/**
 * Tests for the KCC callback retry schedule.
 *
 * Ported from SlaCalculatorTest when Kcc\SlaCalculator retired: the backoff
 * was the only part of it anything called. The doubling and the one-day cap
 * are what keep a failed callback from being retried every few seconds or
 * never again.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Kcc
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Kcc;

use DateTimeImmutable;
use OCA\Dossiq\Service\Kcc\CallbackRetrySchedule;
use PHPUnit\Framework\TestCase;

/**
 * Backoff from a 15-minute base, doubling, capped at a day.
 */
class CallbackRetryScheduleTest extends TestCase {

	/**
	 * Exponential backoff doubles per attempt from a 15-minute base.
	 *
	 * @return void
	 */
	public function testTheBackoffDoubles(): void {
		$schedule = new CallbackRetrySchedule();
		$from     = new DateTimeImmutable('2026-05-21T14:30:00+00:00');

		$this->assertSame(expected: '14:45:00', actual: $schedule->nextRetryAt(from: $from, attemptCount: 0)->format('H:i:s'));
		$this->assertSame(expected: '15:00:00', actual: $schedule->nextRetryAt(from: $from, attemptCount: 1)->format('H:i:s'));
		$this->assertSame(expected: '15:30:00', actual: $schedule->nextRetryAt(from: $from, attemptCount: 2)->format('H:i:s'));
	}//end testTheBackoffDoubles()

	/**
	 * The wait never exceeds a day, and a negative count reads as the first attempt.
	 *
	 * @return void
	 */
	public function testTheBackoffIsCappedAtADay(): void {
		$schedule = new CallbackRetrySchedule();
		$from     = new DateTimeImmutable('2026-05-21T14:30:00+00:00');

		$this->assertSame(expected: '2026-05-22T14:30:00+00:00', actual: $schedule->nextRetryAt(from: $from, attemptCount: 20)->format('c'));
		$this->assertSame(expected: '14:45:00', actual: $schedule->nextRetryAt(from: $from, attemptCount: -3)->format('H:i:s'));
	}//end testTheBackoffIsCappedAtADay()
}//end class
