<?php

/**
 * Unit test for PersonRoleOnCase.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\People;

use OCA\Dossiq\Service\People\PersonRoleOnCase;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\People\PersonRoleOnCase
 */
class PersonRoleOnCaseTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$caseId = 'caseId-value';
		$personUid = 'personUid-value';
		$roleTypeId = 'roleTypeId-value';

		$value = new PersonRoleOnCase(
			caseId: $caseId,
			personUid: $personUid,
			roleTypeId: $roleTypeId,
		);

		$this->assertSame(expected: $caseId, actual: $value->caseId);
		$this->assertSame(expected: $personUid, actual: $value->personUid);
		$this->assertSame(expected: $roleTypeId, actual: $value->roleTypeId);
	}//end testEveryPropertyReadsBack()
}//end class
