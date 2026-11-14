<?php

/**
 * MilestoneStallSpan unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Milestone
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

namespace OCA\Dossiq\Tests\Unit\Service\Milestone;

use DateTimeImmutable;
use OCA\Dossiq\Service\Milestone\MilestoneStallSpan;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MilestoneStallSpan.
 *
 * @covers \OCA\Dossiq\Service\Milestone\MilestoneStallSpan
 */
class MilestoneStallSpanTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$anchor = new DateTimeImmutable('2026-11-14 00:00:00');
		$value = new MilestoneStallSpan(
			caseId: 'case-1',
			waiting: ['milestoneLabel' => 'Advies binnen'],
			anchor: $anchor,
			slaDays: 8
		);

		$this->assertSame(expected: 'case-1', actual: $value->caseId);
		$this->assertSame(expected: ['milestoneLabel' => 'Advies binnen'], actual: $value->waiting);
		$this->assertSame(expected: $anchor, actual: $value->anchor);
		$this->assertSame(expected: 8, actual: $value->slaDays);
	}//end testPropertiesReadBack()
}//end class
