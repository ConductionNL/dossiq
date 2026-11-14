<?php

/**
 * ZaakBesluitWrite unit tests.
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

use OCA\Dossiq\Service\Zgw\ZaakBesluitWrite;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for ZaakBesluitWrite.
 *
 * @covers \OCA\Dossiq\Service\Zgw\ZaakBesluitWrite
 */
class ZaakBesluitWriteTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$objectService = new stdClass();
		$value = new ZaakBesluitWrite(
			objectService: $objectService,
			register: '5',
			zbSchema: '12',
			zaakUuid: '0f0e2f4c-0000-4000-8000-000000000001',
			decisionUrl: 'https://example.test/besluiten/1'
		);

		$this->assertSame(expected: $objectService, actual: $value->objectService);
		$this->assertSame(expected: '5', actual: $value->register);
		$this->assertSame(expected: '12', actual: $value->zbSchema);
		$this->assertSame(expected: '0f0e2f4c-0000-4000-8000-000000000001', actual: $value->zaakUuid);
		$this->assertSame(expected: 'https://example.test/besluiten/1', actual: $value->decisionUrl);
	}//end testPropertiesReadBack()
}//end class
