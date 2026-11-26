<?php

/**
 * Example data for DqSociaalDomein: Pieter Jansen is in the group that may
 * read a social domain household.
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
 * @spec openspec/changes/archive/2026-11-26-example-set-pieter-reads-the-household/specs/zuiddrecht-example-set/spec.md#requirement-pieter-works-with-the-social-domains-sensitive-data
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\CitizenLookupGuard;
use PHPUnit\Framework\TestCase;

/**
 * The Zuiddrecht set's sensitive group holds the people the boards show reading a household.
 */
class ZuiddrechtPieterReadsTheHouseholdTest extends TestCase {

	/**
	 * The members of a group in the Zuiddrecht set.
	 *
	 * @param string $group The group id.
	 *
	 * @return array<int, string> The cast handles.
	 */
	private function members(string $group): array {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $set);
		foreach ($set['groups'] as $entry) {
			if ($entry['group'] === $group) {
				return $entry['members'];
			}
		}

		return [];
	}//end members()

	/**
	 * Pieter and Thomas are in the sensitive group the household endpoint asks for.
	 *
	 * @return void
	 */
	public function testPieterIsInTheSensitiveGroup(): void {
		$members = $this->members(group: CitizenLookupGuard::SENSITIVE_GROUP);
		$this->assertContains(needle: 'pieter', haystack: $members);
		$this->assertContains(needle: 'thomas', haystack: $members);
	}//end testPieterIsInTheSensitiveGroup()

	/**
	 * Pieter is in the handling team of the Jeugdwet case type, so he may read the Oosthaven case the household card
	 * sits on: the household endpoint asks for the sensitive group AND case read access, and the case falls to Fatma
	 * (sandbox live pass, 25 Nov: Pieter got 403 household-not-allowed without it).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-26-example-set-pieter-reads-the-household/specs/zuiddrecht-example-set/spec.md
	 */
	public function testPieterIsInTheJeugdwetHandlingTeam(): void {
		$caseType = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/packages/jeugdwet/case-type.json'), true)['caseType'];
		$team     = ($caseType['handling']['defaultGroup'] ?? null);
		$this->assertSame(expected: 'dossiq-sociaal-domein', actual: $team);
		$this->assertContains(needle: 'pieter', haystack: $this->members(group: 'dossiq-sociaal-domein'));
	}//end testPieterIsInTheJeugdwetHandlingTeam()

	/**
	 * Every member of a dossiq group in the set is also dossiq staff.
	 *
	 * The dossiq register lets only its staff group (`dossiq-medewerkers`,
	 * SeedStaffGroup) read, and a schema without its own authorization, such
	 * as caseType, inherits that. Pieter and Fatma were in the coordinator,
	 * sensitive and social-domain groups but not in the staff group, so they
	 * could read their case yet not its case type, and the household card
	 * refused Pieter (`household-not-allowed`, live 25 Nov 2026).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-26-example-set-pieter-reads-the-household/tasks.md
	 */
	public function testEveryDossiqGroupMemberIsDossiqStaff(): void {
		$set   = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$staff = $this->members(group: 'dossiq-medewerkers');
		foreach ($set['groups'] as $entry) {
			$group = (string)$entry['group'];
			if (str_starts_with($group, 'dossiq-') === false || in_array($group, ['dossiq-medewerkers', 'dossiq-background-service'], true) === true) {
				continue;
			}

			foreach ($entry['members'] as $member) {
				$this->assertContains(needle: $member, haystack: $staff, message: $member.' is in '.$group.' but not dossiq staff');
			}
		}
	}//end testEveryDossiqGroupMemberIsDossiqStaff()
}//end class
