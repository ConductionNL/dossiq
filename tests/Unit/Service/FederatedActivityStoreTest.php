<?php

/**
 * Unit test for FederatedActivityStore.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\FederatedActivityStore;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\FederatedActivityStore
 */
class FederatedActivityStoreTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$objectService = new \stdClass();
		$register = 'register-value';
		$shareSchema = 'shareSchema-value';
		$activitySchema = 'activitySchema-value';

		$value = new FederatedActivityStore(
			objectService: $objectService,
			register: $register,
			shareSchema: $shareSchema,
			activitySchema: $activitySchema,
		);

		$this->assertSame(expected: $objectService, actual: $value->objectService);
		$this->assertSame(expected: $register, actual: $value->register);
		$this->assertSame(expected: $shareSchema, actual: $value->shareSchema);
		$this->assertSame(expected: $activitySchema, actual: $value->activitySchema);
	}//end testEveryPropertyReadsBack()
}//end class
