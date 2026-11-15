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
 * @spec openspec/changes/example-set-pieter-reads-the-household/specs/zuiddrecht-example-set/spec.md#requirement-pieter-works-with-the-social-domains-sensitive-data
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
}//end class
