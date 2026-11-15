<?php

/**
 * Tests for TemplateIdentity: every property reads back what was passed in.
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
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Inspection;

use OCA\Dossiq\Service\Inspection\TemplateIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TemplateIdentity.
 *
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
 */
class TemplateIdentityTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$subject = new TemplateIdentity(
			name: 'name-value',
			caseType: 'caseType-value',
			status: 'status-value',
			legacyRef: 'legacyRef-value'
		);

		self::assertSame(expected: 'name-value', actual: $subject->name);
		self::assertSame(expected: 'caseType-value', actual: $subject->caseType);
		self::assertSame(expected: 'status-value', actual: $subject->status);
		self::assertSame(expected: 'legacyRef-value', actual: $subject->legacyRef);
	}//end testPropertiesReadBack()
}//end class
