<?php

/**
 * InformatieobjectTarget unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
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

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\InformatieobjectTarget;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for InformatieobjectTarget.
 *
 * @covers \OCA\Dossiq\Repair\InformatieobjectTarget
 */
class InformatieobjectTargetTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$objectService = new stdClass();
		$value = new InformatieobjectTarget(
			objectService: $objectService,
			register: 'dossiq',
			schema: 'informatieobject'
		);

		$this->assertSame(expected: $objectService, actual: $value->objectService);
		$this->assertSame(expected: 'dossiq', actual: $value->register);
		$this->assertSame(expected: 'informatieobject', actual: $value->schema);
	}//end testPropertiesReadBack()
}//end class
