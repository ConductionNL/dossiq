<?php

/**
 * EioDocument carries the values it is built with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\EioDocument;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\EioDocument
 */
class EioDocumentTest extends TestCase {

	/**
	 * Every property reads back the value it was built with.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$objectService = new \stdClass();
		$mappingConfig = ['key' => 2];
		$uuid = 'value-3';

		$value = new EioDocument(
			objectService: $objectService,
			mappingConfig: $mappingConfig,
			uuid: $uuid,
		);

		self::assertSame(expected: $objectService, actual: $value->objectService);
		self::assertSame(expected: $mappingConfig, actual: $value->mappingConfig);
		self::assertSame(expected: $uuid, actual: $value->uuid);
	}//end testEveryPropertyReadsBack()
}//end class
