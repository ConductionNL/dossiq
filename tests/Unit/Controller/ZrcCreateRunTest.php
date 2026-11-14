<?php

/**
 * ZrcCreateRun carries the values it is built with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ZrcCreateRun;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\ZrcCreateRun
 */
class ZrcCreateRunTest extends TestCase {

	/**
	 * Every property reads back the value it was built with.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$resource = 'value-1';
		$mappingConfig = ['key' => 2];
		$originalBody = ['key' => 3];
		$body = ['key' => 4];

		$value = new ZrcCreateRun(
			resource: $resource,
			mappingConfig: $mappingConfig,
			originalBody: $originalBody,
			body: $body,
		);

		self::assertSame(expected: $resource, actual: $value->resource);
		self::assertSame(expected: $mappingConfig, actual: $value->mappingConfig);
		self::assertSame(expected: $originalBody, actual: $value->originalBody);
		self::assertSame(expected: $body, actual: $value->body);
	}//end testEveryPropertyReadsBack()
}//end class
