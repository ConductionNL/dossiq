<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Repair\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair\Support;

use ArrayObject;
use JsonSerializable;
use OCA\Dossiq\Repair\Support\SerialisedRows;
use PHPUnit\Framework\TestCase;

/**
 * Arrays are kept, serialisable objects serialised, the rest dropped, in order.
 *
 * @covers \OCA\Dossiq\Repair\Support\SerialisedRows
 */
class SerialisedRowsTest extends TestCase {

	public function testRowsBecomeArraysInOrder(): void {
		$entity = new class implements JsonSerializable {
			public function jsonSerialize(): array {
				return ['id' => 'b'];
			}
		};
		$scalarEntity = new class implements JsonSerializable {
			public function jsonSerialize(): string {
				return 'not a row';
			}
		};

		$rows = (new SerialisedRows())->arrays(
			rows: [['id' => 'a'], $entity, 'text', $scalarEntity, new ArrayObject(['id' => 'x']), ['id' => 'c']]
		);

		$this->assertSame([['id' => 'a'], ['id' => 'b'], ['id' => 'c']], $rows);
	}

	public function testNoRowsIsEmpty(): void {
		$this->assertSame([], (new SerialisedRows())->arrays(rows: []));
	}
}
