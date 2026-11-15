<?php

/**
 * Tests for NewCaseIdentity: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
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
 * @spec openspec/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\NewCaseIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NewCaseIdentity.
 *
 * @spec openspec/specs/termijn-binding/spec.md
 */
class NewCaseIdentityTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$subject = new NewCaseIdentity(
			caseId: 'caseId-value',
			caseTypeRef: 'caseTypeRef-value',
			caseType: 'caseType-value'
		);

		self::assertSame(expected: 'caseId-value', actual: $subject->caseId);
		self::assertSame(expected: 'caseTypeRef-value', actual: $subject->caseTypeRef);
		self::assertSame(expected: 'caseType-value', actual: $subject->caseType);
	}//end testPropertiesReadBack()
}//end class
