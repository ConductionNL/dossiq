<?php

/**
 * Unit test for PriorityFloorRaise.
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

use OCA\Dossiq\Service\PriorityFloorRaise;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\PriorityFloorRaise
 */
class PriorityFloorRaiseTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$caseId = 'caseId-value';
		$floor = 'floor-value';
		$rule = 'rule-value';
		$fallback = 'fallback-value';

		$value = new PriorityFloorRaise(
			caseId: $caseId,
			floor: $floor,
			rule: $rule,
			fallback: $fallback,
		);

		$this->assertSame(expected: $caseId, actual: $value->caseId);
		$this->assertSame(expected: $floor, actual: $value->floor);
		$this->assertSame(expected: $rule, actual: $value->rule);
		$this->assertSame(expected: $fallback, actual: $value->fallback);
	}//end testEveryPropertyReadsBack()
}//end class
