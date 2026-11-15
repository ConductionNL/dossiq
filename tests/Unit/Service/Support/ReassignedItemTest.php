<?php

/**
 * Unit test for ReassignedItem.
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

use OCA\Dossiq\Service\Support\ReassignedItem;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\Support\ReassignedItem
 */
class ReassignedItemTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$objectService = new \stdClass();
		$schema = 'schema-value';
		$id = 'id-value';
		$item = ['item' => 4];

		$value = new ReassignedItem(
			objectService: $objectService,
			schema: $schema,
			id: $id,
			item: $item,
		);

		self::assertSame($objectService, $value->objectService);
		self::assertSame($schema, $value->schema);
		self::assertSame($id, $value->id);
		self::assertSame($item, $value->item);
	}//end testEveryPropertyReadsBack()
}//end class
