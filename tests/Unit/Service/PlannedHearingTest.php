<?php

/**
 * Unit test for PlannedHearing.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\PlannedHearing;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\PlannedHearing
 */
class PlannedHearingTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$data = ['data' => 0];
		$start = new \DateTimeImmutable('2026-01-01T10:00:00');
		$participants = ['participants' => 2];

		$value = new PlannedHearing(
			data: $data,
			start: $start,
			participants: $participants,
		);

		$this->assertSame(expected: $data, actual: $value->data);
		$this->assertSame(expected: $start, actual: $value->start);
		$this->assertSame(expected: $participants, actual: $value->participants);
	}//end testEveryPropertyReadsBack()
}//end class
