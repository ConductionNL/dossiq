<?php

/**
 * Unit tests for a mandate's conditions on a case.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Mandate
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
 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-02-authorization-engine/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Mandate;

use OCA\Dossiq\Service\MandaatCheckService;
use OCA\Dossiq\Service\Mandate\MandateConditions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Mandate\MandateConditions
 */
class MandateConditionsTest extends TestCase {

	/**
	 * The ceiling and the subdelegation each fail; the ceiling's reason wins; within both the mandate passes.
	 *
	 * @return void
	 */
	public function testTheCeilingAndSubdelegation(): void {
		$conditions = new MandateConditions();
		$mandate = ['terms' => ['plafondCents' => 1000, 'subdelegatie' => false]];

		$both = $conditions->evaluate(mandate: $mandate, caseProperties: ['bedragCents' => 1001, 'subdelegatieRequested' => true]);
		$within = $conditions->evaluate(mandate: $mandate, caseProperties: ['bedragCents' => 1000]);

		self::assertSame(
			expected: ['passed' => false, 'reason' => MandaatCheckService::REDEN_PLAFOND_OVERSCHREDEN, 'failedConditions' => ['plafond', 'subdelegatie']],
			actual: $both
		);
		self::assertSame(expected: ['passed' => true, 'reason' => '', 'failedConditions' => []], actual: $within);
	}//end testTheCeilingAndSubdelegation()
}//end class
