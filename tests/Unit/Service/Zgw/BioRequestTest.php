<?php

/**
 * BioRequest unit tests.
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

use OCA\Dossiq\Service\Zgw\BioRequest;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for BioRequest.
 *
 * @covers \OCA\Dossiq\Service\Zgw\BioRequest
 */
class BioRequestTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$request = $this->createMock(originalClassName: IRequest::class);
		$objectService = new stdClass();
		$value = new BioRequest(
			request: $request,
			objectService: $objectService,
			mappingConfig: ['sourceRegister' => '1', 'sourceSchema' => '2']
		);

		$this->assertSame(expected: $request, actual: $value->request);
		$this->assertSame(expected: $objectService, actual: $value->objectService);
		$this->assertSame(expected: ['sourceRegister' => '1', 'sourceSchema' => '2'], actual: $value->mappingConfig);
	}//end testPropertiesReadBack()
}//end class
