<?php

/**
 * Example data for DqTenant: the second tenant, Gemeente Waterland, with its
 * administrator, the mandates she set, and its billing events.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/example-set-tenant-waterland/spec.md#requirement-the-tenant-page-has-a-tenant-with-an-administrator-mandates-and-billing
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Waterland's tenant records.
 *
 * @spec openspec/specs/example-set-tenant-waterland/spec.md#requirement-the-tenant-page-has-a-tenant-with-an-administrator-mandates-and-billing
 */
class ZuiddrechtTenantWaterlandTest extends TestCase {

	/**
	 * Every tenant record points at Waterland; Marieke is its administrator and signed its three mandates; billing has the package fee, a case and its refund.
	 *
	 * @return void
	 */
	public function testWaterlandHasItsAdministratorMandatesAndBilling(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$records = [];
		foreach ($set['objects'] as $object) {
			if (in_array($object['schema'], ['tenantUser', 'tenantMandate', 'tenantBillingEvent'], true) === true) {
				$this->assertSame(expected: '{{organisation:waterland}}', actual: $object['data']['tenantRef'], message: $object['key']);
				$records[$object['schema']][] = $object['data'];
			}
		}

		$this->assertSame(expected: '{{cast:marieke.uid}}', actual: $records['tenantUser'][0]['userRef']);
		$this->assertSame(expected: 'admin', actual: $records['tenantUser'][0]['role']);
		$this->assertSame(expected: ['Behandelaar', 'Teamleider', 'Afdelingshoofd'], actual: array_column($records['tenantMandate'], 'mandateMatrixRef'));
		$this->assertSame(expected: ['{{cast:marieke.uid}}'], actual: array_values(array_unique(array_column($records['tenantMandate'], 'signedBy'))));
		$this->assertSame(
			expected: [
				['plafond' => 5000, 'decisionTypes' => ['vergunning'], 'enforcement' => 'none'],
				['plafond' => 50000, 'decisionTypes' => ['vergunning', 'bezwaar'], 'enforcement' => 'warning'],
				['decisionTypes' => ['vergunning', 'bezwaar'], 'enforcement' => 'full'],
			],
			actual: array_column($records['tenantMandate'], 'terms')
		);
		$this->assertSame(expected: ['subscription_fee', 'case_created', 'case_refund'], actual: array_column($records['tenantBillingEvent'], 'eventType'));

		$steps = array_filter($set['tasks'] ?? [], static fn (array $t): bool => ($t['data']['kind'] ?? '') === 'dossiq.onboarding');
		$this->assertSame(
			expected: ['contract', 'mandate_import', 'sso_setup', 'branding', 'zaaktype_selection', 'first_user', 'go_live'],
			actual: array_values(array_map(static fn (array $t): string => $t['data']['taskKey'], $steps))
		);
		$done = array_filter($steps, static fn (array $t): bool => $t['data']['state'] === 'completed');
		$this->assertCount(expectedCount: 5, haystack: $done);
		foreach ($steps as $step) {
			$this->assertSame(expected: '{{organisation:waterland}}', actual: $step['data']['objectUuid']);
			$this->assertSame(expected: '{{organisation:waterland}}', actual: $step['data']['organisation']);
		}
	}//end testWaterlandHasItsAdministratorMandatesAndBilling()

	/**
	 * What OpenRegister stores: every field of every tenant record is declared on its schema, and every enum value is one the schema allows, nested ones included.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-set-tenant-waterland/spec.md#requirement-the-tenant-page-has-a-tenant-with-an-administrator-mandates-and-billing
	 */
	public function testWaterlandsRecordsAreWhatTheSchemasStore(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$schemas = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/dossiq_register.json'), true)['components']['schemas'];

		$checked = 0;
		foreach ($set['objects'] as $object) {
			if (in_array($object['schema'], ['tenantUser', 'tenantMandate', 'tenantBillingEvent'], true) === false) {
				continue;
			}

			$this->assertStoredAsDeclared(data: $object['data'], properties: $schemas[$object['schema']]['properties'], where: $object['key']);
			$checked++;
		}

		$this->assertSame(expected: 7, actual: $checked);
	}//end testWaterlandsRecordsAreWhatTheSchemasStore()

	/**
	 * Assert each field is a declared property and each enum value is allowed, walking nested objects.
	 *
	 * @param array<string, mixed> $data       The record's fields.
	 * @param array<string, mixed> $properties The schema's properties.
	 * @param string               $where      The record key and path, for messages.
	 *
	 * @return void
	 */
	private function assertStoredAsDeclared(array $data, array $properties, string $where): void {
		foreach ($data as $field => $value) {
			$this->assertArrayHasKey(key: $field, array: $properties, message: $where.': "'.$field.'" is not declared, so OpenRegister drops it');
			$property = $properties[$field];
			if (isset($property['enum']) === true && $value !== null) {
				$this->assertContains(needle: $value, haystack: $property['enum'], message: $where.'.'.$field);
			}

			if (is_array($value) === true && isset($property['properties']) === true && array_is_list($value) === false) {
				$this->assertStoredAsDeclared(data: $value, properties: $property['properties'], where: $where.'.'.$field);
			}
		}
	}//end assertStoredAsDeclared()
}//end class
