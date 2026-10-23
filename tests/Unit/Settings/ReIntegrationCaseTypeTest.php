<?php

/**
 * A re-integration trajectory is a case of the Re-integratietraject case type.
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
 * @spec openspec/changes/re-integration-is-a-case-type/specs/dossiq-sociaal-domein-participatiewet/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Import\RecordCaseMapping;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * The package, its record import over the trajectory shape, and the Cases view.
 *
 * @covers \OCA\Dossiq\Service\Import\RecordCaseMapping
 * @covers \OCA\Dossiq\Service\Import\RecordValues
 * @uses   \OCA\Dossiq\Service\CaseDateNormaliser
 *
 * @spec openspec/changes/re-integration-is-a-case-type/specs/dossiq-sociaal-domein-participatiewet/spec.md
 */
class ReIntegrationCaseTypeTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The package directory.
	 */
	private const PACKAGE = __DIR__ . '/../../../lib/Settings/packages/re-integratietraject';

	/**
	 * A JSON file of the app, decoded.
	 *
	 * @param string $path The path.
	 *
	 * @return array<string, mixed>
	 */
	private function json(string $path): array {
		self::assertFileExists($path);
		return (array)json_decode((string)file_get_contents($path), true);
	}//end json()

	/**
	 * The package's case-type file.
	 *
	 * @return array<string, mixed>
	 */
	private function caseTypeFile(): array {
		return $this->json(self::PACKAGE . '/case-type.json');
	}//end caseTypeFile()

	/**
	 * The status id of a status name.
	 *
	 * @param string $name The status name.
	 *
	 * @return string The id.
	 */
	private function statusId(string $name): string {
		foreach ($this->caseTypeFile()['statusTypes'] as $row) {
			if ($row['name'] === $name) {
				return $row['id'];
			}
		}

		self::fail('No status ' . $name);
	}//end statusId()

	/**
	 * The package installs everywhere, confidential, handled by the social-domain group.
	 *
	 * @return void
	 */
	public function testThePackageShipsTheCaseTypeEverywhere(): void {
		self::assertSame('auto', $this->json(self::PACKAGE . '/package.json')['install']);

		$type = $this->caseTypeFile()['caseType'];
		self::assertSame('re-integratietraject', $type['identifier']);
		self::assertSame('vertrouwelijk', $type['confidentiality']);
		self::assertSame('dossiq-sociaal-domein', $type['handling']['defaultGroup']);
		self::assertSame($this->statusId('Aangemeld'), $type['initialStatus']);
		self::assertSame(
			['Aangemeld', 'Plan van aanpak', 'In uitvoering', 'Evaluatie', 'Afgerond', 'Beëindigd'],
			array_column($this->caseTypeFile()['statusTypes'], 'name')
		);
	}//end testThePackageShipsTheCaseTypeEverywhere()

	/**
	 * Every field the import answers is a field of the case type, under its own name.
	 *
	 * @return void
	 */
	public function testEveryImportedFieldIsAFieldOfTheCaseType(): void {
		$definitions = array_column($this->caseTypeFile()['propertyDefinitions'], 'name', 'id');
		$import = $this->caseTypeFile()['caseType']['recordImports'][0];

		self::assertSame('reIntegratieTraject', $import['sourceSchema']);
		self::assertTrue($import['runOnUpgrade']);
		self::assertFalse($import['carriesTerm']);
		foreach ($import['fields'] as $field) {
			if (isset($field['property']) === false) {
				continue;
			}

			self::assertSame($field['property']['name'], $definitions[$field['property']['definition']] ?? null);
		}

		$hidden = array_column(array_filter($this->caseTypeFile()['propertyDefinitions'], static fn (array $row): bool => ($row['hideOnCreate'] ?? false) === true), 'name');
		self::assertSame(['vrijstellingArbeidsplicht', 'vrijstellingReden', 'herbeoordelingsdatum'], $hidden);
	}//end testEveryImportedFieldIsAFieldOfTheCaseType()

	/**
	 * A trajectory of the shipped shape maps onto one case with every value.
	 *
	 * @return void
	 */
	public function testAStoredTrajectoryMapsOntoACase(): void {
		$type = $this->caseTypeFile()['caseType'];
		$trajectory = [
			'caseId' => 'zaak-2026-pw-01278',
			'caseManagerId' => 'klantmanager-477',
			'startDate' => '2026-03-01',
			'trajectKind' => 'werkfit-maken',
			'distanceToLabourMarket' => 'groot',
			'instrumenten' => [['kind' => 'jobcoaching', 'budget' => 900]],
			'collaboratingParties' => ['UWV'],
			'evaluationMoments' => ['2026-06-01'],
			'considerationMandatory' => true,
			'exemptionWorkObligation' => ['actief' => false, 'reason' => 'Medisch', 'reassessmentDate' => '2026-09-01'],
		];

		$mapped = (new RecordCaseMapping(dates: $this->caseDates()))->map(
			import: $type['recordImports'][0],
			record: $trajectory,
			recordId: 'traj-1',
			caseTypeId: $type['id']
		);

		$case = $mapped['case'];
		self::assertSame($this->statusId('In uitvoering'), $case['status']);
		self::assertSame('werkfit-maken', $case['title']);
		self::assertSame('klantmanager-477', $case['assignee']);
		self::assertSame('2026-03-01', $case['startDate']);
		self::assertSame('traj-1', $case['externalReference']);
		self::assertSame(
			[
				'bijstandszaak' => 'zaak-2026-pw-01278',
				'trajectSoort' => 'werkfit-maken',
				'afstandTotArbeidsmarkt' => 'groot',
				'instrumenten' => '[{"kind":"jobcoaching","budget":900}]',
				'samenwerkendePartijen' => '["UWV"]',
				'evaluatiemomenten' => '["2026-06-01"]',
				'tegenprestatieVerplicht' => 'true',
				'vrijstellingArbeidsplicht' => 'false',
				'vrijstellingReden' => 'Medisch',
				'herbeoordelingsdatum' => '2026-09-01',
			],
			array_column($case['properties'], 'value', 'name')
		);
	}//end testAStoredTrajectoryMapsOntoACase()

	/**
	 * The trajectory schema declares the stamp the import writes.
	 *
	 * @return void
	 */
	public function testTheTrajectoryKeepsTheStamp(): void {
		$schema = $this->json(__DIR__ . '/../../../lib/Settings/register.d/50-sociaal-domein.json')['components']['schemas']['reIntegratieTraject'];

		self::assertArrayHasKey('migratedTo', $schema['properties']);
		self::assertArrayHasKey('migratedAt', $schema['properties']);
		self::assertSame('1.2.0', $schema['version']);
	}//end testTheTrajectoryKeepsTheStamp()

	/**
	 * The trajectories are a view of the Cases list.
	 *
	 * @return void
	 */
	public function testTheTrajectoriesAreAViewOfTheCaseList(): void {
		$manifest = $this->json(__DIR__ . '/../../../src/manifest.json');
		$cases = array_values(array_filter($manifest['pages'], static fn (array $page): bool => $page['id'] === 'Cases'))[0];
		$view = array_values(array_filter($cases['config']['defaultSavedViews'], static fn (array $row): bool => $row['slug'] === 're-integration-pathways'));

		self::assertCount(1, $view);
		self::assertSame('re-integratietraject', $view[0]['filters']['caseTypeIdentifier']);
	}//end testTheTrajectoriesAreAViewOfTheCaseList()

	/**
	 * The example trajectory is a case of the type, answering only declared
	 * fields, tied to a shipped Participatiewet case; the demo data no longer
	 * carries placeholder trajectories that would only move onto placeholder cases.
	 *
	 * @return void
	 */
	public function testTheExampleIsACaseOfTheType(): void {
		self::assertContains('examples', $this->json(self::PACKAGE . '/package.json')['parts']);
		$type = $this->caseTypeFile();
		$case = $this->json(self::PACKAGE . '/examples/20-case.json')['rows'][0];

		self::assertSame($type['caseType']['id'], $case['caseType']);
		self::assertSame($this->statusId('In uitvoering'), $case['status']);
		$definitions = array_column($type['propertyDefinitions'], 'name', 'id');
		foreach ($case['properties'] as $answer) {
			self::assertSame($answer['name'], $definitions[$answer['propertyDefinition']] ?? null);
		}

		$benefitCase = array_column($case['properties'], 'value', 'name')['bijstandszaak'];
		$shipped = array_map(
			static fn (array $object): string => (string)($object['@self']['slug'] ?? ''),
			$this->json(__DIR__ . '/../../../lib/Settings/register.d/50-sociaal-domein.json')['components']['objects']
		);
		self::assertContains($benefitCase, $shipped);

		$roleTypes = array_column($type['roleTypes'], 'id');
		foreach ($this->json(self::PACKAGE . '/examples/40-people.json')['rows'] as $role) {
			self::assertSame($case['id'], $role['case']);
			self::assertContains($role['roleType'], $roleTypes);
		}

		$mock = $this->json(__DIR__ . '/../../../lib/Settings/dossiq_mock_register.json')['components']['objects'];
		self::assertSame([], array_filter($mock, static fn (array $object): bool => ($object['@self']['schema'] ?? '') === 'reIntegratieTraject'));
	}//end testTheExampleIsACaseOfTheType()
}//end class
