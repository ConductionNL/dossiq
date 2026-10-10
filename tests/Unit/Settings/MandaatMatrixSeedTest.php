<?php

/**
 * The two teams the mandate-matrix fragment seeds.
 *
 * @category Tests
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Repair\ProvisionAssignedGroups;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * The mandate matrix seeds two example organisation roles, and the demo cases
 * carry a team.
 *
 * Since one-team-model a case's team is a Nextcloud group id, not a reference
 * to an `organisatieRol` row. The demo cases used to reach the two seeded rows
 * through `@ref:` tokens; they now name `behandelaars`, the group
 * `ProvisionAssignedGroups` creates on every install, so the Team column shows
 * a group that exists rather than a uuid nothing answers to. A demo case that
 * still carried an `@ref:` would store a role uuid in a group field, which is
 * exactly what `occ dossiq:teams:migrate` reports as unmapped.
 *
 * @covers \OCA\Dossiq\Service\SettingsService
 *
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class MandaatMatrixSeedTest extends TestCase {

	/**
	 * The merged shipped configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $merged;

	/**
	 * The two team rows this fragment must seed, by slug.
	 *
	 * @var array<string, string>
	 */
	private const TEAMS = [
		'org-rol-team-vergunningen' => 'Team Vergunningen',
		'org-rol-team-handhaving' => 'Team Handhaving',
	];

	/**
	 * Load the monolith and merge the real register.d fragments.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$base = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);

		[$merged] = (new RegisterFragmentMerger())->merge(
			base: $base,
			fragmentDir: __DIR__ . '/../../../lib/Settings/register.d'
		);

		$this->merged = $merged;
	}//end setUp()

	/**
	 * Every seeded object of one schema, keyed by slug.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, array<string, mixed>> Object slug => object.
	 */
	private function seedObjects(string $schema): array {
		$objects = (array)(((array)($this->merged['components'] ?? []))['objects'] ?? []);
		$this->assertGreaterThan(
			10,
			count($objects),
			'The merge produced almost no seed objects, so an all-clear below would say nothing.'
		);

		$found = [];
		foreach ($objects as $object) {
			$self = (array)(((array)$object)['@self'] ?? []);
			if (($self['schema'] ?? null) === $schema && empty($self['slug']) === false) {
				$found[(string)$self['slug']] = (array)$object;
			}
		}

		return $found;
	}//end seedObjects()

	/**
	 * Both teams are seeded with a name and a department.
	 *
	 * @return void
	 */
	public function testBothTeamsAreSeeded(): void {
		$teams = $this->seedObjects('organisatieRol');

		foreach (self::TEAMS as $slug => $name) {
			$this->assertArrayHasKey(
				$slug,
				$teams,
				sprintf('No organisatieRol row "%s" is seeded, so the mandate matrix ships without an example role.', $slug)
			);
			$this->assertSame($name, ($teams[$slug]['roleName'] ?? null), $slug . ' must carry its role name.');
			$this->assertSame(
				'Ruimte',
				($teams[$slug]['department'] ?? null),
				$slug . ' must carry its department: the facet groups on it.'
			);
		}
	}//end testBothTeamsAreSeeded()

	/**
	 * Two demo cases carry a team, and it is a group every install provisions.
	 *
	 * @return void
	 */
	public function testTwoDemoCasesCarryATeam(): void {
		$cases = $this->seedObjects('case');

		$withTeam = [];
		foreach ($cases as $slug => $case) {
			if (isset($case['assignedGroup']) === false) {
				continue;
			}

			$withTeam[$slug] = (string)$case['assignedGroup'];
		}

		$this->assertCount(
			2,
			$withTeam,
			'Exactly two demo cases should carry a team, so the Team column and facet have something to show: '
			. implode(', ', array_keys($withTeam))
		);

		foreach ($withTeam as $slug => $group) {
			$this->assertStringStartsNotWith(
				'@ref:',
				$group,
				sprintf('Case "%s" points its team at a register row; a team is a Nextcloud group id.', $slug)
			);
			$this->assertContains(
				$group,
				ProvisionAssignedGroups::ASSIGNED_GROUPS,
				sprintf('Case "%s" names the group "%s", which no install provisions.', $slug, $group)
			);
		}
	}//end testTwoDemoCasesCarryATeam()

	/**
	 * The demo cases keep their personal assignee beside the team.
	 *
	 * @return void
	 */
	public function testTheDemoCasesKeepTheirAssignee(): void {
		foreach ($this->seedObjects('case') as $slug => $case) {
			if (isset($case['assignedGroup']) === false) {
				continue;
			}

			$this->assertNotEmpty(
				($case['assignee'] ?? null),
				sprintf('Demo case "%s" lost its assignee when it gained a team; a team is additive.', $slug)
			);
		}
	}//end testTheDemoCasesKeepTheirAssignee()

	/**
	 * The seeded teams declare no property `organisatieRol` does not have.
	 *
	 * A seed key the schema never declares is dropped on import without a
	 * word, so a row can look complete in the file and arrive half empty.
	 *
	 * @return void
	 */
	public function testTheSeededTeamsUseOnlyDeclaredProperties(): void {
		$schema = (array)((array)(((array)($this->merged['components'] ?? []))['schemas'] ?? []))['organisatieRol'];
		$properties = array_keys((array)($schema['properties'] ?? []));
		$this->assertNotEmpty($properties, 'The organisatieRol schema declared no properties.');

		foreach ($this->seedObjects('organisatieRol') as $slug => $team) {
			foreach (array_keys($team) as $key) {
				if ($key === '@self') {
					continue;
				}

				$this->assertContains(
					$key,
					$properties,
					sprintf('Seeded team "%s" sets "%s", which organisatieRol does not declare.', $slug, $key)
				);
			}
		}
	}//end testTheSeededTeamsUseOnlyDeclaredProperties()
}//end class
