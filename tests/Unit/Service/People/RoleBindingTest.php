<?php

/**
 * Tests for RoleBinding: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\People
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
 * @spec openspec/specs/case-access-purpose/spec.md#requirement-handling-the-case-makes-you-its-handler
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\People;

use OCA\Dossiq\Service\People\RoleBinding;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RoleBinding.
 *
 * @spec openspec/specs/case-access-purpose/spec.md#requirement-handling-the-case-makes-you-its-handler
 */
class RoleBindingTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$subject = new RoleBinding(
			caseRow: ['caseRow' => 1],
			caseId: 'caseId-value',
			uid: 'uid-value',
			genericRole: 'genericRole-value',
			displayName: 'displayName-value'
		);

		self::assertSame(expected: ['caseRow' => 1], actual: $subject->caseRow);
		self::assertSame(expected: 'caseId-value', actual: $subject->caseId);
		self::assertSame(expected: 'uid-value', actual: $subject->uid);
		self::assertSame(expected: 'genericRole-value', actual: $subject->genericRole);
		self::assertSame(expected: 'displayName-value', actual: $subject->displayName);
	}//end testPropertiesReadBack()
}//end class
