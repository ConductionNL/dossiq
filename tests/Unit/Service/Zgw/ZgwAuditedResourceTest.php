<?php

/**
 * The audited resource: what every ZGW audit trail entry of an object says
 * about that object.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\ZgwAuditedResource;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Zgw\ZgwAuditedResource
 */
class ZgwAuditedResourceTest extends TestCase {

	/**
	 * The object's URL is both the main object and the resource URL, and its
	 * resource name is both the resource and its display.
	 *
	 * @return void
	 */
	public function testTheZgwFieldsNameTheObjectByUrlAndResource(): void {
		$url     = 'https://nc.example/apps/dossiq/api/zgw/zaken/v1/zaken/c0000000-0000-0000-0000-000000000001';
		$audited = new ZgwAuditedResource(resource: 'zaak', uuid: 'c0000000-0000-0000-0000-000000000001', url: $url);

		self::assertSame(
			[
				'hoofdObject' => $url,
				'resource' => 'zaak',
				'resourceUrl' => $url,
				'resourceWeergave' => 'zaak',
			],
			$audited->zgwFields()
		);
		self::assertSame(['zaak', 'c0000000-0000-0000-0000-000000000001', $url], [$audited->resource, $audited->uuid, $audited->url]);
	}//end testTheZgwFieldsNameTheObjectByUrlAndResource()

	/**
	 * The fields follow the resource they were built for.
	 *
	 * @return void
	 */
	public function testTheFieldsFollowTheResource(): void {
		$fields = (new ZgwAuditedResource(resource: 'status', uuid: 's-1', url: 'https://x/statussen/s-1'))->zgwFields();

		self::assertSame(['https://x/statussen/s-1', 'status'], [$fields['hoofdObject'], $fields['resourceWeergave']]);
	}//end testTheFieldsFollowTheResource()
}//end class
