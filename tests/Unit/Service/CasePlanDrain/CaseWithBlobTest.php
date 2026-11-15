<?php

/**
 * CaseWithBlob unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CasePlanDrain
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

namespace OCA\Dossiq\Tests\Unit\Service\CasePlanDrain;

use OCA\Dossiq\Service\CasePlanDrain\CaseWithBlob;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CaseWithBlob.
 *
 * @covers \OCA\Dossiq\Service\CasePlanDrain\CaseWithBlob
 */
class CaseWithBlobTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$value = new CaseWithBlob(
			caseId: 'case-1',
			blob: ['caseFile' => ['a' => 1], 'planItemStates' => []]
		);

		$this->assertSame(expected: 'case-1', actual: $value->caseId);
		$this->assertSame(expected: ['caseFile' => ['a' => 1], 'planItemStates' => []], actual: $value->blob);
	}//end testPropertiesReadBack()
}//end class
