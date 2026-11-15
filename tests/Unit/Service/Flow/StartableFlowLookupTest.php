<?php

/**
 * StartableFlowLookup unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Flow
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

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use OCA\Dossiq\Service\Flow\StartableFlowLookup;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for StartableFlowLookup.
 *
 * @covers \OCA\Dossiq\Service\Flow\StartableFlowLookup
 */
class StartableFlowLookupTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$objectService = new stdClass();
		$value = new StartableFlowLookup(
			objectService: $objectService,
			register: 'dossiq',
			typeSchema: 'caseType'
		);

		$this->assertSame(expected: $objectService, actual: $value->objectService);
		$this->assertSame(expected: 'dossiq', actual: $value->register);
		$this->assertSame(expected: 'caseType', actual: $value->typeSchema);
	}//end testPropertiesReadBack()
}//end class
