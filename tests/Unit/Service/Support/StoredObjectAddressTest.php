<?php

/**
 * Unit test for StoredObjectAddress.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Support
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

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\StoredObjectAddress;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\Support\StoredObjectAddress
 */
class StoredObjectAddressTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$objectService = new \stdClass();
		$register = 'register-value';
		$schema = 'schema-value';
		$id = 'id-value';

		$value = new StoredObjectAddress(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $id,
		);

		self::assertSame($objectService, $value->objectService);
		self::assertSame($register, $value->register);
		self::assertSame($schema, $value->schema);
		self::assertSame($id, $value->id);
	}//end testEveryPropertyReadsBack()
}//end class
