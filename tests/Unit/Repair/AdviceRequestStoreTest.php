<?php

/**
 * AdviceRequestStore unit tests.
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

use OCA\Dossiq\Repair\AdviceRequestStore;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for AdviceRequestStore.
 *
 * @covers \OCA\Dossiq\Repair\AdviceRequestStore
 */
class AdviceRequestStoreTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$objectService = new stdClass();
		$value = new AdviceRequestStore(
			objectService: $objectService,
			register: '5',
			schema: '14'
		);

		$this->assertSame(expected: $objectService, actual: $value->objectService);
		$this->assertSame(expected: '5', actual: $value->register);
		$this->assertSame(expected: '14', actual: $value->schema);
	}//end testPropertiesReadBack()
}//end class
