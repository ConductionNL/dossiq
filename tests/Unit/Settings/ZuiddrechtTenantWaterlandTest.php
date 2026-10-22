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
 * @spec openspec/changes/example-set-tenant-waterland/specs/example-set-tenant-waterland/spec.md#requirement-the-tenant-page-has-a-tenant-with-an-administrator-mandates-and-billing
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Waterland's tenant records.
 *
 * @spec openspec/changes/example-set-tenant-waterland/specs/example-set-tenant-waterland/spec.md#requirement-the-tenant-page-has-a-tenant-with-an-administrator-mandates-and-billing
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
				['plafond' => null, 'decisionTypes' => ['vergunning', 'bezwaar'], 'enforcement' => 'full'],
			],
			actual: array_column($records['tenantMandate'], 'terms')
		);
		$this->assertSame(expected: ['subscription_fee', 'case_created', 'case_refund'], actual: array_column($records['tenantBillingEvent'], 'eventType'));
	}//end testWaterlandHasItsAdministratorMandatesAndBilling()
}//end class
