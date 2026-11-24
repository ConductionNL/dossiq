<?php

/**
 * Unit tests for the Organisation a legacy tenant row becomes.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\TenantMigration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/migrate-tenant-to-or-tenant/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\TenantMigration;

use OCA\Dossiq\Service\TenantMigration\LegacyTenant;
use OCA\Dossiq\Service\TenantMigration\OrganisationFromLegacyTenant;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\TenantMigration\OrganisationFromLegacyTenant
 * @uses   \OCA\Dossiq\Service\TenantMigration\LegacyTenant
 */
class OrganisationFromLegacyTenantTest extends TestCase {

	/**
	 * A legacy tenant with the given row.
	 *
	 * @param array<string, mixed> $row The legacy row.
	 *
	 * @return LegacyTenant The tenant.
	 */
	private function tenant(array $row): LegacyTenant {
		$tenant = new LegacyTenant();
		$tenant->row = $row;
		$tenant->uuid = 'tenant-uuid';
		$tenant->slug = 'zuiddrecht';

		return $tenant;
	}//end tenant()

	/**
	 * A suspended tenant keeps its uuid, slug, name and group, and its quota in bytes.
	 *
	 * @return void
	 */
	public function testASuspendedTenantKeepsItsIdentityGroupAndQuota(): void {
		$builder = new OrganisationFromLegacyTenant(logger: $this->createMock(originalClassName: LoggerInterface::class));

		$organisation = $builder->build(
			tenant: $this->tenant(row: ['status' => 'suspended', 'displayName' => 'Gemeente Zuiddrecht', 'maxStorageMb' => 2])
		);

		self::assertSame(expected: 'tenant-uuid', actual: $organisation->getUuid());
		self::assertSame(expected: 'zuiddrecht', actual: $organisation->getSlug());
		self::assertSame(expected: 'Gemeente Zuiddrecht', actual: $organisation->getName());
		self::assertSame(expected: ['tenant_zuiddrecht'], actual: $organisation->getGroups());
		self::assertSame(expected: 'suspended', actual: $organisation->getStatus());
		self::assertFalse(condition: $organisation->getActive());
		self::assertSame(expected: 2 * 1024 * 1024, actual: $organisation->getStorageQuota());
	}//end testASuspendedTenantKeepsItsIdentityGroupAndQuota()

	/**
	 * A terminated tenant is retained from its own termination date.
	 *
	 * @return void
	 */
	public function testATerminatedTenantIsRetainedFromItsTerminationDate(): void {
		$builder = new OrganisationFromLegacyTenant(logger: $this->createMock(originalClassName: LoggerInterface::class));

		$organisation = $builder->build(tenant: $this->tenant(row: ['status' => 'terminated', 'terminatedAt' => '2026-03-01T00:00:00+00:00']));

		self::assertSame(expected: 'retained', actual: $organisation->getStatus());
		self::assertSame(expected: '2026-03-01', actual: $organisation->getRetainedAt()?->format('Y-m-d'));
	}//end testATerminatedTenantIsRetainedFromItsTerminationDate()
}//end class
