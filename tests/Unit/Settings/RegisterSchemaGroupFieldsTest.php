<?php

/**
 * The team on a case is a Nextcloud group.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * `case.assignedGroup` is the team a case belongs to, and this file pins the
 * things about it that fail silently.
 *
 * It is a Nextcloud GROUP ID (one-team-model, Ruben 2026-10-09). It used to be
 * a `$ref` to `organisatieRol`, while the handover, the custody chain and the
 * case type's `handling.defaultGroup` all wrote and read group ids: so a case
 * held either kind of value and no reader could trust it. `referenceType:
 * nextcloud-group` is what nextcloud-vue reads to render a group picker. A
 * `$ref` or a `format: uuid` back on the property would make OpenRegister
 * refuse every group id with "should match format uuid", which is how the
 * handover e2e died before.
 *
 * It must stay OPTIONAL and FACETABLE, and the mock register must say the same
 * thing as the live one: `DemoDataService` reads the mock.
 *
 * And the organisation role carries `ncGroupId`, the one link from a role to a
 * team, which the resident's team name and the migration both read.
 *
 * @coversNothing
 */
class RegisterSchemaGroupFieldsTest extends TestCase {

	/**
	 * Repository root.
	 *
	 * @var string
	 */
	private const ROOT = __DIR__ . '/../../..';

	/**
	 * The reference type a Nextcloud group field declares.
	 *
	 * @var string
	 */
	private const GROUP_REFERENCE = 'nextcloud-group';

	/**
	 * The team property of each schema that carries one.
	 *
	 * @var array<string, string>
	 */
	private const TEAM_PROPERTIES = [
		'case' => 'assignedGroup',
	];

	/**
	 * The schemas of one shipped register file.
	 *
	 * @param string $file    File name under lib/Settings, or under register.d.
	 * @param int    $atLeast The fewest schemas the file must yield for a pass to mean something.
	 *
	 * @return array<string, mixed> Schema slug => schema.
	 */
	private function schemas(string $file, int $atLeast = 10): array {
		$data = json_decode((string)file_get_contents(self::ROOT . '/lib/Settings/' . $file), true);
		self::assertIsArray($data, $file . ' did not parse.');

		$schemas = (array)(((array)($data['components'] ?? []))['schemas'] ?? []);
		self::assertGreaterThanOrEqual(
			$atLeast,
			count($schemas),
			$file . ' yielded almost no schemas, so an all-clear below would say nothing.'
		);

		return $schemas;
	}//end schemas()

	/**
	 * Every register file that must declare the team property.
	 *
	 * @return array<string, array{0: string}> Data set name => [file name].
	 */
	public static function registerFileProvider(): array {
		return [
			'live register' => ['dossiq_register.json'],
			'mock register' => ['dossiq_mock_register.json'],
		];
	}//end registerFileProvider()

	/**
	 * The team property is a Nextcloud group, facetable, titled Team.
	 *
	 * @param string $file The register file to read.
	 *
	 * @dataProvider registerFileProvider
	 *
	 * @return void
	 */
	public function testTheTeamIsANextcloudGroup(string $file): void {
		$schemas = $this->schemas($file);

		foreach (self::TEAM_PROPERTIES as $slug => $property) {
			$schema = (array)($schemas[$slug] ?? []);
			self::assertNotSame([], $schema, sprintf('%s declares no "%s" schema.', $file, $slug));

			$properties = (array)($schema['properties'] ?? []);
			self::assertArrayHasKey(
				$property,
				$properties,
				sprintf('%s: schema "%s" has no "%s" property, so no case can name a team.', $file, $slug, $property)
			);

			$declared = (array)$properties[$property];
			self::assertSame('string', ($declared['type'] ?? null), sprintf('%s: "%s.%s" holds one group id.', $file, $slug, $property));
			self::assertSame(
				self::GROUP_REFERENCE,
				($declared['referenceType'] ?? null),
				sprintf(
					'%s: "%s.%s" must declare referenceType "%s", or the form renders a text box instead of a group picker.',
					$file,
					$slug,
					$property,
					self::GROUP_REFERENCE
				)
			);
			self::assertArrayNotHasKey(
				'$ref',
				$declared,
				sprintf('%s: "%s.%s" is a group id, not a reference to a register row.', $file, $slug, $property)
			);
			self::assertArrayNotHasKey(
				'format',
				$declared,
				sprintf(
					'%s: "%s.%s" must declare no format. A uuid format refuses every group id the handover writes.',
					$file,
					$slug,
					$property
				)
			);
			self::assertSame(
				'Team',
				($declared['title'] ?? null),
				sprintf('%s: "%s.%s" is labelled Team on the index and the forms.', $file, $slug, $property)
			);
			self::assertTrue(
				($declared['facetable'] ?? false),
				sprintf(
					'%s: "%s.%s" must be facetable, or the sidebar cannot narrow a list to a team.',
					$file,
					$slug,
					$property
				)
			);
		}//end foreach
	}//end testTheTeamIsANextcloudGroup()

	/**
	 * The team property is not required.
	 *
	 * @param string $file The register file to read.
	 *
	 * @dataProvider registerFileProvider
	 *
	 * @return void
	 */
	public function testNeitherTeamPropertyIsRequired(string $file): void {
		$schemas = $this->schemas($file);

		foreach (self::TEAM_PROPERTIES as $slug => $property) {
			$required = (array)(((array)($schemas[$slug] ?? []))['required'] ?? []);
			self::assertNotContains(
				$property,
				$required,
				sprintf(
					'%s: "%s.%s" is optional. A team is additive, a case keeps its personal assignee and a case '
					. 'without a team is still a valid case, and requiring it would 400 every create the flows make.',
					$file,
					$slug,
					$property
				)
			);
		}
	}//end testNeitherTeamPropertyIsRequired()

	/**
	 * An organisation role names its Nextcloud group, in the live and the mock register.
	 *
	 * @return void
	 */
	public function testTheOrganisationRoleNamesItsGroup(): void {
		$sources = [
			'register.d/61-mandaat-matrix.json' => $this->schemas('register.d/61-mandaat-matrix.json', 1),
			'dossiq_mock_register.json' => $this->schemas('dossiq_mock_register.json'),
		];

		foreach ($sources as $file => $schemas) {
			$properties = (array)(((array)($schemas['organisatieRol'] ?? []))['properties'] ?? []);
			self::assertArrayHasKey(
				'ncGroupId',
				$properties,
				sprintf('%s: organisatieRol has no ncGroupId, so no role can be moved to a team.', $file)
			);
			self::assertSame(
				self::GROUP_REFERENCE,
				(((array)$properties['ncGroupId'])['referenceType'] ?? null),
				sprintf('%s: organisatieRol.ncGroupId must be picked from the Nextcloud groups.', $file)
			);
		}
	}//end testTheOrganisationRoleNamesItsGroup()

	/**
	 * The resident's team name is read through the role bound to the case's group.
	 *
	 * `@ref.assignedGroup` named a reference nothing declared once the field
	 * stopped being a `$ref`, and a calculation that reads an undeclared
	 * reference answers null on every case. The lookup must be on `ncGroupId`,
	 * and the expression must refuse an empty team: a lookup on an empty value
	 * may match a role that is bound to nothing and hand its name to the case.
	 *
	 * @return void
	 */
	public function testThePublicNameIsReadThroughTheRoleBoundToTheGroup(): void {
		$case = (array)($this->schemas('dossiq_register.json')['case'] ?? []);
		$configuration = (array)($case['configuration'] ?? []);

		$team = (array)(((array)($configuration['x-openregister-references'] ?? []))['team'] ?? []);
		self::assertSame('organisatieRol', ($team['schema'] ?? null), 'The team lookup reads organisation roles.');
		self::assertSame('lookup', ($team['mode'] ?? null), 'The team is found by a lookup, not followed as a reference.');
		self::assertSame(
			['ncGroupId' => '@self.assignedGroup'],
			($team['filters'] ?? null),
			'The role is the one bound to the case\'s group.'
		);

		$calculation = (array)(((array)($configuration['x-openregister-calculations'] ?? []))['assignedGroupPublicName'] ?? []);
		$expression = (string)json_encode($calculation['expression'] ?? null);
		self::assertStringNotContainsString('@ref.assignedGroup', $expression, 'No reference named assignedGroup is declared.');
		self::assertStringContainsString('@ref.team.publicName', $expression);
		self::assertStringContainsString(
			'{"eq":[{"coalesce":[{"prop":"assignedGroup"},""]},""]}',
			$expression,
			'The name must be empty for a case without a team, whatever the lookup returned.'
		);
		self::assertStringContainsString(
			'{"prop":"@ref.team.ncGroupId"}',
			$expression,
			'The name is only trusted from a role whose group is the case\'s group.'
		);
	}//end testThePublicNameIsReadThroughTheRoleBoundToTheGroup()

	/**
	 * The personal assignee survives beside the team.
	 *
	 * The spec is explicit that assigning a team does not clear the personal
	 * assignee. The way that breaks in a config change is not a line of logic
	 * but a REPLACED property, and a rename leaves no error behind.
	 *
	 * @param string $file The register file to read.
	 *
	 * @dataProvider registerFileProvider
	 *
	 * @return void
	 */
	public function testThePersonalAssigneeSurvivesBesideTheTeam(string $file): void {
		$schemas = $this->schemas($file);

		foreach (array_keys(self::TEAM_PROPERTIES) as $slug) {
			$properties = (array)(((array)($schemas[$slug] ?? []))['properties'] ?? []);
			self::assertArrayHasKey(
				'assignee',
				$properties,
				sprintf('%s: schema "%s" lost its "assignee" property; the team is additive, not a replacement.', $file, $slug)
			);
		}
	}//end testThePersonalAssigneeSurvivesBesideTheTeam()
}//end class
