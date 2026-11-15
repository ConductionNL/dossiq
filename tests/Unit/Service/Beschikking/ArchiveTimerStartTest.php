<?php

/**
 * Unit test for ArchiveTimerStart.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Beschikking
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

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use OCA\Dossiq\Service\Beschikking\ArchiveTimerStart;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\Beschikking\ArchiveTimerStart
 */
class ArchiveTimerStartTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$trigger = ['trigger' => 0];
		$triggerId = 'triggerId-value';
		$anchor = new \DateTimeImmutable('2026-01-01');
		$slaDays = 103;

		$value = new ArchiveTimerStart(
			trigger: $trigger,
			triggerId: $triggerId,
			anchor: $anchor,
			slaDays: $slaDays,
		);

		$this->assertSame(expected: $trigger, actual: $value->trigger);
		$this->assertSame(expected: $triggerId, actual: $value->triggerId);
		$this->assertSame(expected: $anchor, actual: $value->anchor);
		$this->assertSame(expected: $slaDays, actual: $value->slaDays);
	}//end testEveryPropertyReadsBack()
}//end class
