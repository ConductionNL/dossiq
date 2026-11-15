<?php

/**
 * Unit test for ConnectedSystemWrite.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
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

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Service\Cases\ConnectedSystemWrite;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\Cases\ConnectedSystemWrite
 */
class ConnectedSystemWriteTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$operation = ['operation' => 1];
		$connection = ['connection' => 1];
		$system = 'system-value';

		$value = new ConnectedSystemWrite(
			operation: $operation,
			connection: $connection,
			system: $system,
		);

		$this->assertSame(expected: $operation, actual: $value->operation);
		$this->assertSame(expected: $connection, actual: $value->connection);
		$this->assertSame(expected: $system, actual: $value->system);
	}//end testEveryPropertyReadsBack()
}//end class
