<?php

/**
 * Unit test for FederatedShareStore.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Sharing
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

namespace OCA\Dossiq\Tests\Unit\Service\Sharing;

use OCA\Dossiq\Service\Sharing\FederatedShareStore;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\Sharing\FederatedShareStore
 */
class FederatedShareStoreTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$objectService = new \stdClass();
		$register = 'register-value';
		$shareSchema = 'shareSchema-value';

		$value = new FederatedShareStore(
			objectService: $objectService,
			register: $register,
			shareSchema: $shareSchema,
		);

		$this->assertSame(expected: $objectService, actual: $value->objectService);
		$this->assertSame(expected: $register, actual: $value->register);
		$this->assertSame(expected: $shareSchema, actual: $value->shareSchema);
	}//end testEveryPropertyReadsBack()
}//end class
