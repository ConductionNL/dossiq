<?php

/**
 * Woo Case Type Handling Team Test
 *
 * Since case-access-by-handling-team a case is read and updated only by its
 * assignee and the handling teams of its case type. The Woo request case type
 * shipped without a `handling` block, so an unassigned Woo request was
 * readable by nobody on the staff but an administrator. This reads the seed
 * through the merged register, the case type's own schema, the one reader of
 * the handling teams and the rules the projector writes.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/resident-start-points-have-a-page/specs/case-types/spec.md#requirement-the-shipped-woo-request-case-type-names-a-handling-team-req-rsp-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Repair\ProvisionAssignedGroups;
use OCA\Dossiq\Service\Access\CaseTeamAccessRules;
use OCA\Dossiq\Service\CaseType\CaseTypeHandling;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class WooCaseTypeHandlingTeamTest extends TestCase {
	/**
	 * The shipped Woo case type names a provisioned handling team, the case
	 * schema then grants that team read and update on its cases, and the
	 * block fits the case type schema.
	 *
	 * @return void
	 */
	public function testAnUnassignedWooRequestIsReadByItsHandlingTeam(): void {
		$register = new RealSchemaValidator();
		$caseType = null;
		foreach ($register->objects as $object) {
			if (($object['@self']['schema'] ?? '') === 'caseType' && ($object['@self']['slug'] ?? '') === 'woo-verzoek') {
				$caseType = $object;
			}
		}

		$this->assertNotNull($caseType, 'the merged register must seed the woo-verzoek case type');
		$this->assertSame([], $register->errors(slug: 'caseType', payload: ['handling' => $caseType['handling'] ?? null], creating: false));

		$teams = (new CaseTypeHandling())->teams(caseType: $caseType);
		$this->assertSame(['behandelaars'], $teams);
		// A team the server does not have matches nobody.
		$this->assertContains('behandelaars', ProvisionAssignedGroups::ASSIGNED_GROUPS);

		$block = (new CaseTeamAccessRules())->block(
			shipped: ($register->schemas['case']['authorization'] ?? []),
			caseTypes: [['id' => (string)$caseType['id'], 'teams' => $teams]]
		);
		$entry = ['group' => 'behandelaars', 'match' => ['caseType' => ['$in' => [(string)$caseType['id']]]]];
		$this->assertContains($entry, $block['read']);
		$this->assertContains($entry, $block['update']);
		$this->assertNotContains($entry, $block['delete'] ?? []);
	}//end testAnUnassignedWooRequestIsReadByItsHandlingTeam()
}//end class
