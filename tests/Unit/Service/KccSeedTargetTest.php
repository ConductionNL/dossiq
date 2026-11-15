<?php

/**
 * KccSeedTarget unit tests.
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

use OCA\Dossiq\Service\KccSeedTarget;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for KccSeedTarget.
 *
 * @covers \OCA\Dossiq\Service\KccSeedTarget
 */
class KccSeedTargetTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$objectService = new stdClass();
		$value = new KccSeedTarget(
			objectService: $objectService,
			register: '5',
			schema: '8',
			counterKey: 'belplannen'
		);

		$this->assertSame(expected: $objectService, actual: $value->objectService);
		$this->assertSame(expected: '5', actual: $value->register);
		$this->assertSame(expected: '8', actual: $value->schema);
		$this->assertSame(expected: 'belplannen', actual: $value->counterKey);
	}//end testPropertiesReadBack()
}//end class
