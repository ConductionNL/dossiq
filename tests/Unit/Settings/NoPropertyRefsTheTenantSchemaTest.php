<?php

/**
 * The tenant schema is the read-only audit anchor, and nothing else points at it.
 *
 * Every tenant uuid lives on its OpenRegister Organisation since the migration,
 * so a property that names a tenant references `nc-organisation`. The `tenant`
 * schema stays declared because every tenant audit entry is anchored on a
 * tenant object (decision Q4), and an anchor needs only a slug and a name
 * (decision Q6).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
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
 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class NoPropertyRefsTheTenantSchemaTest extends TestCase {
	/**
	 * The two register descriptors.
	 */
	private const DESCRIPTORS = ['lib/Settings/dossiq_register.json', 'lib/Settings/dossiq_mock_register.json'];

	/**
	 * Read one descriptor's schemas.
	 *
	 * @param string $descriptor The path, relative to the app root.
	 *
	 * @return array<string, array<string, mixed>> The schemas by slug.
	 */
	private function schemas(string $descriptor): array {
		$json = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/'.$descriptor), true);

		return (array) ($json['components']['schemas'] ?? []);
	}//end schemas()

	/**
	 * No property in either descriptor carries `$ref: tenant` (REQ-TOO-002).
	 *
	 * @return void
	 */
	public function testNoPropertyInEitherDescriptorRefsTheTenantSchema(): void {
		$hits = [];
		foreach (self::DESCRIPTORS as $descriptor) {
			foreach ($this->schemas(descriptor: $descriptor) as $slug => $schema) {
				foreach ((array) ($schema['properties'] ?? []) as $name => $property) {
					$ref = (is_array($property) === true) ? ($property['$ref'] ?? ($property['items']['$ref'] ?? null)) : null;
					if ($ref === 'tenant') {
						$hits[] = $descriptor.' '.$slug.'.'.$name;
					}
				}
			}
		}

		$this->assertSame([], $hits, 'These properties still reference the tenant schema instead of nc-organisation');
	}//end testNoPropertyInEitherDescriptorRefsTheTenantSchema()

	/**
	 * The two re-pointed properties now reference nc-organisation (REQ-TOO-002).
	 *
	 * @return void
	 */
	public function testTheTwoTenantPropertiesReferenceTheOrganisation(): void {
		foreach (self::DESCRIPTORS as $descriptor) {
			$schemas = $this->schemas(descriptor: $descriptor);
			$this->assertSame('nc-organisation', $schemas['automaticAction']['properties']['tenantId']['$ref'] ?? null, $descriptor);
			$this->assertSame('nc-organisation', $schemas['tenantOnboardingTask']['properties']['tenantRef']['$ref'] ?? null, $descriptor);
		}
	}//end testTheTwoTenantPropertiesReferenceTheOrganisation()

	/**
	 * The tenant schema is declared and described as the read-only audit anchor (REQ-TOO-002, REQ-TOO-006).
	 *
	 * @return void
	 */
	public function testTheTenantSchemaIsDescribedAsTheReadOnlyAuditAnchor(): void {
		foreach (self::DESCRIPTORS as $descriptor) {
			$tenant = ($this->schemas(descriptor: $descriptor)['tenant'] ?? null);
			$this->assertIsArray($tenant, $descriptor.' must keep the tenant schema');

			$description = (string) ($tenant['description'] ?? '');
			$this->assertStringContainsString('read-only anchor of the tenant audit trail', $description, $descriptor);
			$this->assertSame(['slug', 'displayName'], $tenant['required'] ?? null, $descriptor.': an anchor needs only a slug and a name');
		}
	}//end testTheTenantSchemaIsDescribedAsTheReadOnlyAuditAnchor()
}//end class
