<?php

/**
 * Tests for InspectionRunStart: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Inspection
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
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-panel-on-case-dashboard
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Inspection;

use OCA\Dossiq\Service\Inspection\InspectionRunStart;
use PHPUnit\Framework\TestCase;

/**
 * Tests for InspectionRunStart.
 *
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-panel-on-case-dashboard
 */
class InspectionRunStartTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$subject = new InspectionRunStart(
			caseId: 'caseId-value',
			template: ['template' => 1],
			payload: ['payload' => 1],
			actor: 'actor-value'
		);

		self::assertSame(expected: 'caseId-value', actual: $subject->caseId);
		self::assertSame(expected: ['template' => 1], actual: $subject->template);
		self::assertSame(expected: ['payload' => 1], actual: $subject->payload);
		self::assertSame(expected: 'actor-value', actual: $subject->actor);
	}//end testPropertiesReadBack()
}//end class
