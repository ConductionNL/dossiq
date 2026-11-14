<?php

/**
 * Tests for ReaderDay: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/end-of-day-manifest/spec.md#requirement-the-day-lists-are-worked-out-on-the-server
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use DateTimeZone;
use OCA\Dossiq\Service\Queue\ReaderDay;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ReaderDay.
 *
 * @spec openspec/specs/end-of-day-manifest/spec.md#requirement-the-day-lists-are-worked-out-on-the-server
 */
class ReaderDayTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$zone = new DateTimeZone('Europe/Amsterdam');
		$subject = new ReaderDay(
			userId: 'userId-value',
			today: 'today-value',
			zone: $zone
		);

		self::assertSame(expected: 'userId-value', actual: $subject->userId);
		self::assertSame(expected: 'today-value', actual: $subject->today);
		self::assertSame(expected: $zone, actual: $subject->zone);
	}//end testPropertiesReadBack()
}//end class
