<?php

/**
 * A case type moves between instances and arrives whole.
 *
 * 🔴 WHAT THESE TESTS ARE FOR. The endpoints answered and the package was
 * empty. `exportComponent()` returned `fields: []`, `statusTypes: []`,
 * `roles: []`, `documentTypes: []`, `resultTypes: []` and `workflows: []` for
 * every case type on every instance, under a comment saying so, and
 * `importComponent()` read the JSON, logged a line and returned
 * `status: 'success'` having written nothing. An administrator moving a case
 * type from acceptance to production got a green dialog and an empty instance.
 *
 * Two things hid it from every angle except the code: the capability spec
 * already said SHALL, and the archived change ticked "Create
 * CaseDefinitionExportService with exportCaseDefinition() method", which is
 * true of the method and false of the feature. So the assertions below are
 * about CONTENT and about WRITES, never about a status field:
 * `status: 'success'` is exactly what the old code returned.
 *
 * 🔑 THE ROUND TRIP IS THE POINT. The last test exports a seeded case type and
 * imports the same package into an EMPTY store, then compares. An export test
 * and an import test can each pass against a shape the other does not speak;
 * only the round trip can see that.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-definition-export-is-real/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseDefinitionExportService;
use OCA\Dossiq\Service\CaseDefinition\PackageIds;
use OCA\Dossiq\Service\CaseDefinition\PackageValidator;
use OCA\Dossiq\Service\CaseDefinition\PackageWriter;
use OCA\Dossiq\Service\CaseDefinition\WorkflowDeployer;
use OCA\Dossiq\Service\CaseDefinitionImportService;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use ZipArchive;

class CaseDefinitionPortabilityTest extends TestCase {

	/** The case type the fixture instance holds. */
	private const CASE_TYPE_ID = 'ct-1';

	/**
	 * The rows the exporting instance holds, by settings schema key.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * The case type row the exporting instance holds.
	 *
	 * @var array<string, mixed>
	 */
	private array $caseType = [];

	/**
	 * Everything the IMPORT wrote, as [schemaKey => rows].
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $written = [];

	/**
	 * Every id the import rolled back.
	 *
	 * @var array<int, string>
	 */
	private array $deleted = [];

	/**
	 * Seed one case type with two statuses, one role, one document type, one
	 * result type and one workflow template.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->caseType = [
			'id' => self::CASE_TYPE_ID,
			'@self' => ['uuid' => self::CASE_TYPE_ID, 'slug' => 'omgevingsvergunning'],
			'title' => 'Omgevingsvergunning',
			'identifier' => 'OMG-01',
			'decisionTypes' => ['dt-1'],
			'rightsMatrix' => ['behandelaar' => ['read', 'write']],
		];

		$this->rows = [
			'property_definition_schema' => [
				['id' => 'pd-1', 'name' => 'bouwkosten', 'caseType' => self::CASE_TYPE_ID],
			],
			'status_type_schema' => [
				['id' => 'st-1', 'name' => 'Ontvangen', 'order' => 1, 'caseType' => self::CASE_TYPE_ID],
				['id' => 'st-2', 'name' => 'Afgehandeld', 'order' => 2, 'isFinal' => true, 'caseType' => self::CASE_TYPE_ID],
			],
			'role_type_schema' => [
				['id' => 'rt-1', 'name' => 'Behandelaar', 'ncGroupId' => 'vth', 'caseType' => self::CASE_TYPE_ID],
			],
			'document_type_schema' => [
				['id' => 'dt-1', 'name' => 'Bouwtekening', 'caseType' => self::CASE_TYPE_ID],
			],
			'result_type_schema' => [
				['id' => 'res-1', 'name' => 'Verleend', 'caseType' => self::CASE_TYPE_ID],
			],
			'workflow_template_schema' => [
				[
					'id' => 'wf-1',
					'title' => 'Vergunning behandeling',
					'caseType' => self::CASE_TYPE_ID,
					'transitions' => [['from' => 'st-1', 'to' => 'st-2']],
				],
			],
		];
	}//end setUp()

	/**
	 * A store double over the seeded rows.
	 *
	 * `onlyMethods` and not `addMethods`: a double that invented a reader the
	 * real store lacks would pass here and 500 in production.
	 *
	 * @param boolean $caseTypeExists Whether the case type resolves.
	 *
	 * @return CaseTypeStore The double.
	 */
	private function store(bool $caseTypeExists = true): CaseTypeStore {
		$store = $this->getMockBuilder(CaseTypeStore::class)
			->disableOriginalConstructor()
			->onlyMethods(['readCaseType', 'rowsOfType', 'rowId'])
			->getMock();

		$store->method('readCaseType')->willReturn($caseTypeExists === true ? $this->caseType : []);
		$store->method('rowsOfType')->willReturnCallback(
			fn (string $schemaKey, string $caseTypeId): array => ($this->rows[$schemaKey] ?? [])
		);
		$store->method('rowId')->willReturnCallback(
			static fn (array $row): string => trim((string)($row['@self']['uuid'] ?? $row['id'] ?? ''))
		);

		return $store;
	}//end store()

	/**
	 * The export service over the seeded store.
	 *
	 * @param boolean $caseTypeExists Whether the case type resolves.
	 *
	 * @return CaseDefinitionExportService The service.
	 */
	private function exporter(bool $caseTypeExists = true): CaseDefinitionExportService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('1.0');

		return new CaseDefinitionExportService(
			$appConfig,
			new NullLogger(),
			$this->store(caseTypeExists: $caseTypeExists)
		);
	}//end exporter()

	/**
	 * The import service over an EMPTY store that records every write.
	 *
	 * @param boolean $writesFail Whether the store refuses the SECOND status
	 *                             type, so a component is half written when it
	 *                             fails.
	 *
	 * @return CaseDefinitionImportService The service.
	 */
	private function importer(bool $writesFail = false): CaseDefinitionImportService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null, ...$rest) use ($writesFail): ObjectEntityInterface {
				$key = (string)$schema;
				$this->written[$key] ??= [];
				if ($writesFail === true && ($object['name'] ?? '') === 'Afgehandeld') {
					throw new RuntimeException('the store refused the write');
				}

				$id = ($uuid ?? ('new-' . count($this->written[$key])));
				$this->written[$key][] = $object;

				$stored = $this->createMock(ObjectEntityInterface::class);
				$stored->method('getUuid')->willReturn($id);

				return $stored;
			}
		);
		$objects->method('deleteObject')->willReturnCallback(
			function (string $uuid, ...$rest): bool {
				$this->deleted[] = $uuid;

				return true;
			}
		);
		// The target instance is EMPTY: nothing the package names is here yet,
		// so every row is a create and none is a conflict.
		$objects->method('find')->willReturn(null);

		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getObjectService', 'getConfigValue'])
			->getMock();
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($key === 'register' ? 'dossiq' : $key)
		);

		// A REAL writer over the SAME settings double. What this test observes
		// did not move when the writer was split out: only the wiring line did.
		return new CaseDefinitionImportService(
			new NullLogger(),
			new PackageWriter(new NullLogger(), $settings),
			new WorkflowDeployer(new NullLogger(), $settings),
			new PackageValidator(new NullLogger(), new PackageIds()),
		);
	}//end importer()

	/**
	 * Read one entry out of an export package.
	 *
	 * @param string $path The ZIP path.
	 * @param string $entry The entry name.
	 *
	 * @return array<string, mixed> The decoded entry.
	 */
	private function entry(string $path, string $entry): array {
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($path, ZipArchive::RDONLY) === true, "the package at {$path} could not be opened");
		$content = $zip->getFromName($entry);
		$zip->close();

		$this->assertNotFalse($content, "the package carries no {$entry}");

		return (array)json_decode((string)$content, true);
	}//end entry()

	/**
	 * A seeded case type exports its statuses, by id and title, with the
	 * transition between them.
	 *
	 * @return void
	 */
	public function testASeededCaseTypeExportsItsStatuses(): void {
		$package = $this->exporter()->exportCaseDefinition(self::CASE_TYPE_ID);
		$statuses = $this->entry(path: $package['path'], entry: 'statuses.json');

		$this->assertCount(2, $statuses['statusTypes'], 'the statuses component is empty, which is the whole defect');
		$this->assertSame('st-1', $statuses['statusTypes'][0]['id']);
		$this->assertSame('Afgehandeld', $statuses['statusTypes'][1]['name']);

		$this->assertCount(1, $statuses['transitions'], 'the statuses carry no transition, so they connect to nothing');
		$this->assertSame('st-1', $statuses['transitions'][0]['from']);
		$this->assertSame('st-2', $statuses['transitions'][0]['to']);

		unlink($package['path']);
	}//end testASeededCaseTypeExportsItsStatuses()

	/**
	 * Every other component carries real rows too.
	 *
	 * @return void
	 */
	public function testEveryComponentCarriesRealRows(): void {
		$package = $this->exporter()->exportCaseDefinition(self::CASE_TYPE_ID);

		$schema = $this->entry(path: $package['path'], entry: 'schema.json');
		$this->assertSame('Omgevingsvergunning', $schema['caseType']['title']);
		$this->assertCount(1, $schema['propertyDefinitions']);

		$permissions = $this->entry(path: $package['path'], entry: 'permissions.json');
		$this->assertSame('vth', $permissions['roleTypes'][0]['ncGroupId'], 'a role exported without its group binding imports as a role nobody is in');

		$documents = $this->entry(path: $package['path'], entry: 'documents.json');
		$this->assertCount(1, $documents['documentTypes']);

		$metadata = $this->entry(path: $package['path'], entry: 'metadata.json');
		$this->assertCount(1, $metadata['resultTypes']);
		$this->assertSame(['dt-1'], $metadata['decisionTypes']);

		$workflow = $this->entry(path: $package['path'], entry: 'workflows/Vergunning-behandeling.json');
		$this->assertSame('wf-1', $workflow['id']);

		unlink($package['path']);
	}//end testEveryComponentCarriesRealRows()

	/**
	 * The manifest names the package: the object's slug and title, and every
	 * ref the components point at.
	 *
	 * @return void
	 */
	public function testTheManifestNamesWhatThePackageCarries(): void {
		$package = $this->exporter()->exportCaseDefinition(self::CASE_TYPE_ID);
		$manifest = $this->entry(path: $package['path'], entry: 'manifest.json');

		$this->assertSame(
			'omgevingsvergunning',
			$manifest['caseType']['slug'],
			'the slug is the requested id echoed back, so two instances name the same case type differently'
		);
		$this->assertSame('Omgevingsvergunning', $manifest['caseType']['title']);

		$this->assertContains('wf-1', $manifest['dependencies'], 'the workflow template is not listed, so an importer cannot refuse a package it cannot resolve');
		$this->assertContains('st-1', $manifest['dependencies']);
		$this->assertContains('dt-1', $manifest['dependencies']);

		unlink($package['path']);
	}//end testTheManifestNamesWhatThePackageCarries()

	/**
	 * An unknown case type is refused, and no ZIP is written.
	 *
	 * @return void
	 */
	public function testAnUnknownCaseTypeIsRefusedBeforeAnythingIsWritten(): void {
		$before = glob(sys_get_temp_dir() . '/dossiq_export_*');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/nothing to export/');

		try {
			$this->exporter(caseTypeExists: false)->exportCaseDefinition('no-such-case-type');
		} finally {
			$this->assertSame(
				$before,
				glob(sys_get_temp_dir() . '/dossiq_export_*'),
				'a package was written for a case type that does not exist'
			);
		}
	}//end testAnUnknownCaseTypeIsRefusedBeforeAnythingIsWritten()

	/**
	 * A case type survives the round trip: exported from one instance, imported
	 * into an empty one, and the same rows are there afterwards.
	 *
	 * @return void
	 */
	public function testACaseTypeSurvivesARoundTrip(): void {
		$package = $this->exporter()->exportCaseDefinition(self::CASE_TYPE_ID);

		$result = $this->importer()->importCaseDefinition($package['path']);
		unlink($package['path']);

		$this->assertTrue($result['success'], 'the import reported failure: ' . json_encode($result['components']));

		$this->assertCount(1, $this->written['case_type_schema'] ?? [], 'the case type itself was not written');
		$this->assertSame('Omgevingsvergunning', $this->written['case_type_schema'][0]['title']);

		$this->assertCount(2, $this->written['status_type_schema'] ?? [], 'the statuses were not written');
		$this->assertCount(1, $this->written['role_type_schema'] ?? []);
		$this->assertSame('vth', $this->written['role_type_schema'][0]['ncGroupId']);
		$this->assertCount(1, $this->written['document_type_schema'] ?? []);
		$this->assertCount(1, $this->written['result_type_schema'] ?? []);
		$this->assertCount(1, $this->written['workflow_template_schema'] ?? [], 'the workflow template was counted, not deployed');

		// The store's own metadata must NOT travel: writing the exporting
		// instance's register and organisation ids onto a row in a different
		// instance would be wrong in every field.
		$this->assertArrayNotHasKey('@self', $this->written['status_type_schema'][0]);
	}//end testACaseTypeSurvivesARoundTrip()

	/**
	 * A component that writes nothing is not a success.
	 *
	 * @return void
	 */
	public function testAComponentThatWritesNothingIsNotASuccess(): void {
		$path = tempnam(sys_get_temp_dir(), 'dossiq_empty_');
		$zip = new ZipArchive();
		$zip->open((string)$path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		$zip->addFromString('manifest.json', json_encode([
			'version' => '1.0',
			'exportDate' => '2026-09-18T00:00:00+00:00',
			'caseType' => ['id' => 'ct-1', 'slug' => 'x'],
			'components' => ['statuses'],
		]));
		$zip->addFromString('statuses.json', json_encode(['statusTypes' => [], 'transitions' => []]));
		$zip->close();

		$result = $this->importer()->importCaseDefinition((string)$path);
		unlink((string)$path);

		$this->assertFalse($result['success'], 'a package holding no rows reported a successful import');
		$this->assertSame('error', $result['components']['statuses']['status']);
	}//end testAComponentThatWritesNothingIsNotASuccess()

	/**
	 * Counting workflow files is never an import.
	 *
	 * @return void
	 */
	public function testCountingWorkflowFilesIsNotAnImport(): void {
		$path = tempnam(sys_get_temp_dir(), 'dossiq_wf_');
		$zip = new ZipArchive();
		$zip->open((string)$path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		$zip->addFromString('manifest.json', json_encode([
			'version' => '1.0',
			'exportDate' => '2026-09-18T00:00:00+00:00',
			'caseType' => ['id' => 'ct-1', 'slug' => 'x'],
			'components' => ['workflows'],
		]));
		$zip->addFromString('workflows/broken.json', 'not json at all');
		$zip->close();

		$result = $this->importer()->importCaseDefinition((string)$path);
		unlink((string)$path);

		$this->assertFalse($result['success'], 'a workflow that could not be read was counted as imported');
		$this->assertSame('error', $result['components']['workflows']['status']);
		$this->assertStringContainsString('broken.json', $result['components']['workflows']['message']);
	}//end testCountingWorkflowFilesIsNotAnImport()

	/**
	 * A component whose rows cannot all be written leaves none of them written.
	 *
	 * A half-imported case type — statuses without the case type they belong
	 * to, roles pointing at nothing — is a state nobody can read and nobody
	 * asked for, and it is the state an administrator is left holding when a
	 * write fails halfway.
	 *
	 * @return void
	 */
	public function testAHalfWrittenComponentIsRolledBack(): void {
		$package = $this->exporter()->exportCaseDefinition(self::CASE_TYPE_ID);

		$result = $this->importer(writesFail: true)->importCaseDefinition($package['path']);
		unlink($package['path']);

		$this->assertFalse($result['success']);
		$this->assertSame('error', $result['components']['statuses']['status']);
		$this->assertContains(
			'st-1',
			$this->deleted,
			'the first status stayed behind after the second one failed, so the instance holds half a case type'
		);
	}//end testAHalfWrittenComponentIsRolledBack()
}//end class
