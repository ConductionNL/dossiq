<?php

/**
 * DrainScope unit tests.
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

use OCA\Dossiq\Service\CasePlanDrain\DrainScope;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for DrainScope.
 *
 * @covers \OCA\Dossiq\Service\CasePlanDrain\DrainScope
 */
class DrainScopeTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$objects = new stdClass();
		$value = new DrainScope(
			objects: $objects,
			register: '5',
			caseSchema: '7',
			typeSchema: '9',
			dryRun: true
		);

		$this->assertSame(expected: $objects, actual: $value->objects);
		$this->assertSame(expected: '5', actual: $value->register);
		$this->assertSame(expected: '7', actual: $value->caseSchema);
		$this->assertSame(expected: '9', actual: $value->typeSchema);
		$this->assertSame(expected: true, actual: $value->dryRun);
	}//end testPropertiesReadBack()
}//end class
