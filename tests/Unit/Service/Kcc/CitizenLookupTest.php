<?php

/**
 * Unit test for CitizenLookup.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Kcc
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

namespace OCA\Dossiq\Tests\Unit\Service\Kcc;

use OCA\Dossiq\Service\Kcc\CitizenLookup;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\Kcc\CitizenLookup
 */
class CitizenLookupTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$employeeId = 'employeeId-value';
		$subjectId = 'subjectId-value';
		$allowed = true;
		$fields = ['fields' => 3];
		$ground = 'ground-value';

		$value = new CitizenLookup(
			employeeId: $employeeId,
			subjectId: $subjectId,
			allowed: $allowed,
			fields: $fields,
			ground: $ground,
		);

		$this->assertSame(expected: $employeeId, actual: $value->employeeId);
		$this->assertSame(expected: $subjectId, actual: $value->subjectId);
		$this->assertSame(expected: $allowed, actual: $value->allowed);
		$this->assertSame(expected: $fields, actual: $value->fields);
		$this->assertSame(expected: $ground, actual: $value->ground);
	}//end testEveryPropertyReadsBack()
}//end class
