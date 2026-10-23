<?php

/**
 * A tenant's subscription: its package and contract reference.
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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/tenant-subscription/specs/tenant-subscription/spec.md#requirement-a-tenant-has-a-subscription-with-a-package-and-a-reference
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * The tenantSubscription schema and Waterland's subscription.
 */
class TenantSubscriptionTest extends TestCase {

	/**
	 * The merged register.
	 *
	 * @return array<string, mixed>
	 */
	private function register(): array {
		$root = dirname(__DIR__, 3);
		$base = json_decode((string) file_get_contents($root.'/lib/Settings/dossiq_register.json'), true);
		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $root.'/lib/Settings/register.d');

		return $merged;
	}//end register()

	/**
	 * The register carries the schema: a reference to the organisation, the package, the contract reference and the start.
	 *
	 * @return void
	 */
	public function testTheRegisterCarriesTheSubscriptionSchema(): void {
		$register = $this->register();
		$schema   = ($register['components']['schemas']['tenantSubscription'] ?? null);

		$this->assertIsArray(actual: $schema);
		$this->assertSame(expected: ['tenantRef', 'packageName'], actual: $schema['required']);
		foreach (['tenantRef', 'packageName', 'reference', 'startDate', 'endDate'] as $property) {
			$this->assertArrayHasKey(key: $property, array: $schema['properties']);
		}

		$this->assertContains(needle: 'tenantSubscription', haystack: $register['components']['registers']['dossiq']['schemas']);
	}//end testTheRegisterCarriesTheSubscriptionSchema()

	/**
	 * Waterland subscribes to the Standaard package under TEN-0014, and every field is one the schema stores.
	 *
	 * @return void
	 */
	public function testWaterlandHasItsSubscription(): void {
		$set   = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/lib/Settings/example-sets/zuiddrecht.json'), true);
		$found = array_values(array_filter($set['objects'], static fn (array $o): bool => $o['schema'] === 'tenantSubscription'));

		$this->assertCount(expectedCount: 1, haystack: $found);
		$data = $found[0]['data'];
		$this->assertSame(expected: '{{organisation:waterland}}', actual: $data['tenantRef']);
		$this->assertSame(expected: 'Standaard', actual: $data['packageName']);
		$this->assertSame(expected: 'TEN-0014', actual: $data['reference']);

		$properties = $this->register()['components']['schemas']['tenantSubscription']['properties'];
		foreach (array_keys($data) as $field) {
			$this->assertArrayHasKey(key: $field, array: $properties, message: $field.' is not declared, so OpenRegister drops it');
		}
	}//end testWaterlandHasItsSubscription()
}//end class
