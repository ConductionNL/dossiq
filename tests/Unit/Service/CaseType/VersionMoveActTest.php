<?php

/**
 * VersionMoveAct unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
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

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\VersionMoveAct;
use PHPUnit\Framework\TestCase;

/**
 * Tests for VersionMoveAct.
 *
 * @covers \OCA\Dossiq\Service\CaseType\VersionMoveAct
 */
class VersionMoveActTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$value = new VersionMoveAct(
			caseId: 'case-1',
			targetCaseTypeId: 'type-v2',
			preview: ['canMove' => true],
			reason: 'Nieuwe versie',
			actorUid: 'alice'
		);

		$this->assertSame(expected: 'case-1', actual: $value->caseId);
		$this->assertSame(expected: 'type-v2', actual: $value->targetCaseTypeId);
		$this->assertSame(expected: ['canMove' => true], actual: $value->preview);
		$this->assertSame(expected: 'Nieuwe versie', actual: $value->reason);
		$this->assertSame(expected: 'alice', actual: $value->actorUid);
	}//end testPropertiesReadBack()
}//end class
