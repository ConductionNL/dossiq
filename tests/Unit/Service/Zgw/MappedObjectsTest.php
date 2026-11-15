<?php

/**
 * Unit test for MappedObjects.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
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

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\MappedObjects;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\Zgw\MappedObjects
 */
class MappedObjectsTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$objectService = new \stdClass();
		$mapping = ['mapping' => 1];

		$value = new MappedObjects(
			objectService: $objectService,
			mapping: $mapping,
		);

		$this->assertSame(expected: $objectService, actual: $value->objectService);
		$this->assertSame(expected: $mapping, actual: $value->mapping);
	}//end testEveryPropertyReadsBack()
}//end class
