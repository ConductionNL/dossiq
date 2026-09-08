<?php

/**
 * The team fields on a case and on a task.
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
 * `case.assignedGroup` and `caseTask.assigneeGroup` are the team a case or a
 * task belongs to, and this file pins the four things about them that fail
 * silently.
 *
 * They must reference `organisatieRol`. A Nextcloud group id was considered
 * and rejected: `roleType.ncGroupId` already binds a role to a group for
 * AUTHORIZATION, and a second group field on the case would blur assignment
 * with permission. A `$ref` pointing anywhere else, or missing, turns the
 * picker into a free-text box and the facet into a list of raw strings.
 *
 * They must stay OPTIONAL. `role`'s `required` list is what makes the Add
 * party form's props matter; adding a team to a case's required list would
 * 400 every case create in the app, including the ones the flows write.
 *
 * They must be FACETABLE. The Team chip on the indexes is blocked on the
 * platform resolving the signed-in handler's `organisatieRol` rows, so the
 * sidebar facet is the only way to narrow a list to a team. A facet is opt-in
 * per property: drop the flag and the sidebar shows nothing, with no error.
 *
 * And the mock register must carry the same two. `DemoDataService` reads it,
 * so a property present in the live register and absent from the mock makes
 * the demo instance disagree with the real one about what a case has.
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
	 * The schema the two team properties reference.
	 *
	 * @var string
	 */
	private const TEAM_SCHEMA = 'organisatieRol';

	/**
	 * The team property of each schema that carries one.
	 *
	 * @var array<string, string>
	 */
	private const TEAM_PROPERTIES = [
		'case' => 'assignedGroup',
		'caseTask' => 'assigneeGroup',
	];

	/**
	 * The schemas of one shipped register file.
	 *
	 * @param string $file File name under lib/Settings.
	 *
	 * @return array<string, mixed> Schema slug => schema.
	 */
	private function schemas(string $file): array {
		$data = json_decode((string)file_get_contents(self::ROOT . '/lib/Settings/' . $file), true);
		self::assertIsArray($data, $file . ' did not parse.');

		$schemas = (array)(((array)($data['components'] ?? []))['schemas'] ?? []);
		self::assertGreaterThan(
			10,
			count($schemas),
			$file . ' yielded almost no schemas, so an all-clear below would say nothing.'
		);

		return $schemas;
	}//end schemas()

	/**
	 * Every register file that must declare the two team properties.
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
	 * Both team properties exist, reference organisatieRol and are facetable.
	 *
	 * @param string $file The register file to read.
	 *
	 * @dataProvider registerFileProvider
	 *
	 * @return void
	 */
	public function testBothTeamPropertiesReferenceTheOrganisationRole(string $file): void {
		$schemas = $this->schemas($file);

		foreach (self::TEAM_PROPERTIES as $slug => $property) {
			$schema = (array)($schemas[$slug] ?? []);
			self::assertNotSame([], $schema, sprintf('%s declares no "%s" schema.', $file, $slug));

			$properties = (array)($schema['properties'] ?? []);
			self::assertArrayHasKey(
				$property,
				$properties,
				sprintf('%s: schema "%s" has no "%s" property, so no case or task can name a team.', $file, $slug, $property)
			);

			$declared = (array)$properties[$property];
			self::assertSame(
				self::TEAM_SCHEMA,
				($declared['$ref'] ?? null),
				sprintf(
					'%s: "%s.%s" must reference "%s". Without the reference the field is free text: the form renders a '
					. 'box instead of a picker and the facet lists whatever was typed.',
					$file,
					$slug,
					$property,
					self::TEAM_SCHEMA
				)
			);
			self::assertSame(
				'Team',
				($declared['title'] ?? null),
				sprintf('%s: "%s.%s" is labelled Team on both indexes and both forms.', $file, $slug, $property)
			);
			self::assertTrue(
				($declared['facetable'] ?? false),
				sprintf(
					'%s: "%s.%s" must be facetable. The Team quick-filter chip is blocked on the platform, so the '
					. 'sidebar facet is the only way to narrow a list to a team.',
					$file,
					$slug,
					$property
				)
			);
		}
	}//end testBothTeamPropertiesReferenceTheOrganisationRole()

	/**
	 * Neither team property is required.
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
					'%s: "%s.%s" is optional. A team is additive — a case keeps its personal assignee and a case '
					. 'without a team is still a valid case — and requiring it would 400 every create the flows make.',
					$file,
					$slug,
					$property
				)
			);
		}
	}//end testNeitherTeamPropertyIsRequired()

	/**
	 * The team schema the two properties point at is actually shipped.
	 *
	 * A `$ref` to a slug nothing declares resolves to nothing at import time
	 * and leaves the picker empty, which reads exactly like an instance that
	 * has no teams yet.
	 *
	 * @return void
	 */
	public function testTheReferencedTeamSchemaIsShipped(): void {
		$declared = [];
		foreach ((array)glob(self::ROOT . '/lib/Settings/register.d/*.json') as $file) {
			$data = json_decode((string)file_get_contents((string)$file), true);
			if (is_array($data) === false) {
				continue;
			}

			$declared = array_merge($declared, array_keys((array)(((array)($data['components'] ?? []))['schemas'] ?? [])));
		}

		$declared = array_merge($declared, array_keys($this->schemas('dossiq_register.json')));

		self::assertContains(
			self::TEAM_SCHEMA,
			$declared,
			sprintf('No shipped fragment declares "%s", so both team pickers would come up empty.', self::TEAM_SCHEMA)
		);
	}//end testTheReferencedTeamSchemaIsShipped()

	/**
	 * The personal assignee survives beside the team on both schemas.
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
