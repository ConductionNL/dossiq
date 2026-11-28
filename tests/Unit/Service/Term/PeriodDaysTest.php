<?php

/**
 * Unit tests for PeriodDays.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Term
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Term;

use OCA\Dossiq\Service\Term\PeriodDays;
use PHPUnit\Framework\TestCase;

/**
 * A period is its days counted from one fixed day; nothing readable is no days.
 *
 * @covers \OCA\Dossiq\Service\Term\PeriodDays
 * @uses   \OCA\Dossiq\Service\Term\DayCount
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class PeriodDaysTest extends TestCase {

	/**
	 * Days, weeks and a month (January 2000, so 31) read as days; empty and unreadable as 0.
	 *
	 * @return void
	 */
	public function testAPeriodReadsAsItsDays(): void {
		$days = new PeriodDays();

		self::assertSame(expected: 14, actual: $days->days(period: 'P14D'));
		self::assertSame(expected: 14, actual: $days->days(period: 'P2W'));
		self::assertSame(expected: 31, actual: $days->days(period: 'P1M'));
		self::assertSame(expected: 0, actual: $days->days(period: ''));
		self::assertSame(expected: 0, actual: $days->days(period: 'two weeks'));
	}//end testAPeriodReadsAsItsDays()
}//end class
