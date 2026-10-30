<?php

/**
 * The package installer writes a whole package or nothing, and records it.
 *
 * Every test runs against the neutral fixture package in
 * tests/fixtures/packages/fixture-case-type, never a real procedure (hydra
 * ADR-118, testing split rule 2). The register is an in-memory store with
 * OpenRegister's real deleteObject signature, so a roll-back that calls it
 * wrongly fails here instead of being swallowed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseDefinition;

use OCA\Dossiq\Service\CaseDefinition\ExistingRowMatcher;
use OCA\Dossiq\Service\CaseDefinition\PackageCapabilities;
use OCA\Dossiq\Service\CaseDefinition\PackageDirectory;
use OCA\Dossiq\Service\CaseDefinition\PackageIds;
use OCA\Dossiq\Service\CaseDefinition\PackageInstaller;
use OCA\Dossiq\Service\CaseDefinition\PackageSchemaReferences;
use OCA\Dossiq\Service\CaseDefinition\PackageValidator;
use OCA\Dossiq\Service\CaseDefinition\PackageWriter;
use OCA\Dossiq\Service\DemoDataService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Starter\ShippedConfigurationService;
use OCA\Dossiq\Service\Starter\ShippedFingerprint;
use OCA\Dossiq\Service\Starter\ShippedSets;
use OCA\Dossiq\Service\Starter\StarterStore;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * An in-memory register that speaks OpenRegister's deleteObject signature
 * (uuid first) and can be told to refuse a row by name.
 */
final class PackageTestRegister extends InMemoryRegister {

	/**
	 * Row names or titles the store refuses to save.
	 *
	 * @var array<int, string>
	 */
	public array $refuse = [];

	/**
	 * The register each saved row was written into, by row id.
	 *
	 * @var array<string, string>
	 */
	public array $savedIn = [];

	/**
	 * The register each delete named, by row id.
	 *
	 * @var array<string, string>
	 */
	public array $deletedFrom = [];

	/**
	 * Properties the store fills in on save when a row leaves them out, by
	 * schema, as OpenRegister fills every property a schema declares.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $fills = [];

	/**
	 * Save, unless the row is one the test told the store to refuse.
	 *
	 * @param array<string, mixed> $object        The row.
	 * @param int|string           $register      The register.
	 * @param int|string           $schema        The schema.
	 * @param string|null          $uuid          The id.
	 * @param boolean              $_rbac         Unused.
	 * @param boolean              $_multitenancy Unused.
	 *
	 * @return array<string, mixed> The saved row.
	 */
	public function saveObject(
		array $object,
		int|string $register = '',
		int|string $schema = '',
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		if (in_array(($object['name'] ?? $object['title'] ?? ''), $this->refuse, true) === true) {
			throw new RuntimeException('the store refused the write');
		}

		$object += ($this->fills[(string)$schema] ?? []);
		$stored = parent::saveObject($object, $register, $schema, $uuid, $_rbac, $_multitenancy);
		$this->savedIn[(string)$stored['id']] = (string)$register;

		return $stored;
	}//end saveObject()

	/**
	 * The numeric search: register and schema ids sit in `@self`, as OpenRegister reads them.
	 *
	 * @param array<string, mixed> $query         The query.
	 * @param boolean              $_rbac         Unused.
	 * @param boolean              $_multitenancy Unused.
	 *
	 * @return array<int, array<string, mixed>> The matching rows.
	 */
	public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
		$schema = (string)($query['@self']['schema'] ?? '');

		return $this->searchObjectsBySlug((string)($query['@self']['register'] ?? ''), $schema, $query);
	}//end searchObjects()

	/**
	 * Delete by uuid, as OpenRegister's ObjectServiceInterface does.
	 *
	 * @param int|string $uuid     The id.
	 * @param int|string $register The register.
	 * @param int|string $schema   The schema; '' for any.
	 *
	 * @return boolean True when a row went.
	 */
	public function deleteObject(int|string $uuid = '', int|string $register = '', int|string $schema = ''): bool {
		$this->deletedFrom[(string)$uuid] = (string)$register;
		foreach (array_keys($this->rows) as $slug) {
			if (($schema === '' || $schema === $slug) && isset($this->rows[$slug][(string)$uuid]) === true) {
				unset($this->rows[$slug][(string)$uuid]);
				return true;
			}
		}

		return false;
	}//end deleteObject()
}//end class

/**
 * A container answering OpenRegister's register and schema mappers over a
 * fixed map, as OpenRegister's own mappers would: find() throws for an
 * unknown register, findBySlugInIds() answers null for an unknown slug.
 */
final class PackageTestSchemas implements ContainerInterface {

	/**
	 * Constructor.
	 *
	 * @param array<string, array<int, int>> $registers Register slug to the schema ids it holds.
	 * @param array<int, string>             $schemas   Schema id to slug.
	 */
	public function __construct(
		public array $registers = [],
		public array $schemas = [],
	) {
	}//end __construct()

	/**
	 * Whether the container answers an id.
	 *
	 * @param string $id The service id.
	 *
	 * @return boolean True for the two mappers.
	 */
	public function has(string $id): bool {
		return in_array($id, ['OCA\OpenRegister\Db\RegisterMapper', 'OCA\OpenRegister\Db\SchemaMapper'], true);
	}//end has()

	/**
	 * The mapper for an id.
	 *
	 * @param string $id The service id.
	 *
	 * @return object The mapper.
	 */
	public function get(string $id): object {
		if ($id === 'OCA\OpenRegister\Db\RegisterMapper') {
			return new PackageTestRegisterMapper($this->registers);
		}

		if ($id === 'OCA\OpenRegister\Db\SchemaMapper') {
			return new PackageTestSchemaMapper($this->schemas);
		}

		throw new RuntimeException("No service {$id}");
	}//end get()
}//end class

/**
 * OpenRegister's RegisterMapper over a fixed map.
 */
final class PackageTestRegisterMapper {

	/**
	 * Constructor.
	 *
	 * @param array<string, array<int, int>> $registers Register slug to schema ids.
	 */
	public function __construct(private readonly array $registers) {
	}//end __construct()

	/**
	 * The register with this slug.
	 *
	 * @param string|integer $id            The slug.
	 * @param boolean        $_rbac         Unused.
	 * @param boolean        $_multitenancy Unused.
	 *
	 * @return PackageTestRegisterEntity The register.
	 */
	public function find(string|int $id, bool $_rbac = true, bool $_multitenancy = true): PackageTestRegisterEntity {
		if (isset($this->registers[(string)$id]) === false) {
			throw new RuntimeException('Register not found');
		}

		return new PackageTestRegisterEntity(
			$this->registers[(string)$id],
			900 + (int)array_search((string)$id, array_keys($this->registers), true)
		);
	}//end find()
}//end class

/**
 * A register entity: only the schema ids are read.
 */
final class PackageTestRegisterEntity {

	/**
	 * Constructor.
	 *
	 * @param array<int, int> $schemaIds The schema ids.
	 * @param int             $id        The register id.
	 */
	public function __construct(private readonly array $schemaIds, private readonly int $id = 900) {
	}//end __construct()

	/**
	 * The register id.
	 *
	 * @return int The id.
	 */
	public function getId(): int {
		return $this->id;
	}//end getId()

	/**
	 * The schema ids.
	 *
	 * @return array<int, int> The ids.
	 */
	public function getSchemas(): array {
		return $this->schemaIds;
	}//end getSchemas()
}//end class

/**
 * OpenRegister's SchemaMapper over a fixed map.
 */
final class PackageTestSchemaMapper {

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $schemas Schema id to slug.
	 */
	public function __construct(private readonly array $schemas) {
	}//end __construct()

	/**
	 * The schema with this slug among these ids.
	 *
	 * @param string          $slug      The slug.
	 * @param array<int, int> $schemaIds The ids to look in.
	 *
	 * @return PackageTestSchemaEntity|null The schema.
	 */
	public function findBySlugInIds(string $slug, array $schemaIds): ?PackageTestSchemaEntity {
		foreach ($schemaIds as $id) {
			if (($this->schemas[(int)$id] ?? null) === $slug) {
				return new PackageTestSchemaEntity((int)$id);
			}
		}

		return null;
	}//end findBySlugInIds()
}//end class

/**
 * A schema entity: only the id is read.
 */
final class PackageTestSchemaEntity {

	/**
	 * Constructor.
	 *
	 * @param integer $id The id.
	 */
	public function __construct(private readonly int $id) {
	}//end __construct()

	/**
	 * The id.
	 *
	 * @return integer The id.
	 */
	public function getId(): int {
		return $this->id;
	}//end getId()
}//end class

/**
 * Unit tests for the package installer, validator rules and writer.
 *
 * @covers \OCA\Dossiq\Service\CaseDefinition\PackageInstaller
 * @covers \OCA\Dossiq\Service\CaseDefinition\ShippedPackageRules
 * @covers \OCA\Dossiq\Service\CaseDefinition\PackageDirectory
 * @covers \OCA\Dossiq\Service\CaseDefinition\PackageCapabilities
 * @covers \OCA\Dossiq\Service\CaseDefinition\PackagePartRules
 * @covers \OCA\Dossiq\Service\CaseDefinition\ExistingRowMatcher
 * @covers \OCA\Dossiq\Service\CaseDefinition\PackageSchemaReferences
 * @covers \OCA\Dossiq\Service\CaseDefinition\PackageSettingRules
 * @uses \OCA\Dossiq\Support\FleetAppId
 * @uses \OCA\Dossiq\Service\CaseDefinition\PackageValidator
 * @uses \OCA\Dossiq\Service\CaseDefinition\PackageWriter
 * @uses \OCA\Dossiq\Service\CaseDefinition\PackageSeedRules
 * @uses \OCA\Dossiq\Service\CaseDefinition\WritesPackageRows
 * @uses \OCA\Dossiq\Service\CaseDefinition\PackageIds
 * @uses \OCA\Dossiq\Service\Starter\ShippedConfigurationService
 * @uses \OCA\Dossiq\Service\Starter\ShippedFingerprint
 * @uses \OCA\Dossiq\Service\Starter\ShippedSets
 * @uses \OCA\Dossiq\Service\Starter\StarterStore
 */
class PackageInstallerTest extends TestCase {

	/** The fixture package's case type id. */
	private const CASE_TYPE = '0f1c0000-0000-4000-8000-000000000001';

	/**
	 * The case the example fixture ships.
	 */
	private const EXAMPLE_CASE = '0f1c0000-0000-4000-8000-0000000000e1';

	/** The fixture packages. */
	private const FIXTURES = __DIR__ . '/../../../fixtures/packages';

	/**
	 * The register every write lands in.
	 *
	 * @var PackageTestRegister
	 */
	private PackageTestRegister $register;

	/**
	 * Temporary package roots to remove.
	 *
	 * @var array<int, string>
	 */
	private array $temporary = [];

	/**
	 * Settings keys the instance has.
	 *
	 * @var array<string, string>
	 */
	private array $appConfigValues = [];

	/**
	 * The registers and schemas OpenRegister answers for.
	 *
	 * @var PackageTestSchemas
	 */
	private PackageTestSchemas $schemas;

	/**
	 * A fresh register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->register = new PackageTestRegister();
		$this->appConfigValues = [];
		$this->schemas = new PackageTestSchemas(
			registers: ['fixture-register' => [12, 60]],
			schemas: [12 => 'unrelated', 60 => 'fixture_source'],
		);
	}//end setUp()

	/**
	 * Remove temporary package roots.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->temporary as $dir) {
			exec('rm -rf ' . escapeshellarg($dir));
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * The installer over the given package root, the shared register and a real ledger.
	 *
	 * @param string             $root          The package root.
	 * @param array<int, string> $unconfigured  Settings keys that resolve to nothing.
	 * @param array<int, string> $installedApps Apps IAppManager reports installed.
	 *
	 * @return PackageInstaller The installer.
	 */
	private function installer(string $root = self::FIXTURES, array $unconfigured = [], array $installedApps = []): PackageInstaller {
		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getObjectService', 'getConfigValue'])
			->getMock();
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = '') use ($unconfigured): string {
				if (in_array($key, $unconfigured, true) === true) {
					return '';
				}

				if ($key === 'register') {
					return 'dossiq';
				}

				$slug = (string)preg_replace('/_schema$/', '', $key);

				return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $slug))));
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $id): bool => in_array($id, $installedApps, true));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willReturnCallback(fn (): array => array_keys($this->appConfigValues));
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->appConfigValues[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->appConfigValues[$key] = $value;
				return true;
			}
		);

		$packages = new PackageDirectory(root: $root);
		$ledger = new ShippedConfigurationService(
			new StarterStore($settings, new NullLogger()),
			new ShippedSets($packages),
			new ShippedFingerprint(),
			new NullLogger(),
		);

		return new PackageInstaller(
			$packages,
			new PackageValidator(new NullLogger(), new PackageIds()),
			new PackageCapabilities($apps),
			new PackageWriter(new NullLogger(), $settings, new ExistingRowMatcher(new NullLogger())),
			$ledger,
			$appConfig,
			new NullLogger(),
			new PackageSchemaReferences($apps, $this->schemas, new NullLogger()),
		);
	}//end installer()

	/**
	 * A copy of the fixture package in a temporary root, changed by a callback.
	 *
	 * @param callable $change Receives the package directory and edits it.
	 *
	 * @return string The temporary root.
	 */
	private function variant(callable $change): string {
		$root = sys_get_temp_dir() . '/dossiq-packages-' . bin2hex(random_bytes(6));
		$this->temporary[] = $root;
		mkdir($root);
		exec('cp -r ' . escapeshellarg(self::FIXTURES . '/fixture-case-type') . ' ' . escapeshellarg($root . '/fixture-case-type'));
		$change($root . '/fixture-case-type');

		return $root;
	}//end variant()

	/**
	 * Edit a json file in a package directory.
	 *
	 * @param string   $path   The file.
	 * @param callable $change Receives the decoded file and returns it changed.
	 *
	 * @return void
	 */
	private static function editJson(string $path, callable $change): void {
		$data = json_decode((string)file_get_contents($path), true);
		file_put_contents($path, json_encode($change($data), JSON_PRETTY_PRINT));
	}//end editJson()

	/**
	 * The ledger rows that record a written or adopted row, without the
	 * package version records.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rowLedger(): array {
		return array_values(
			array_filter(
				$this->register->all('shippedOrigin'),
				static fn (array $row): bool => $row['targetSchema'] !== ShippedConfigurationService::PACKAGE_RECORD
			)
		);
	}//end rowLedger()

	/**
	 * Every row of the fixture lands in its schema, and the ledger records each
	 * one under the package id and version.
	 *
	 * @return void
	 */
	public function testAPackageWritesEveryRowAndRecordsEachInTheLedger(): void {
		$outcome = $this->installer()->install(id: 'fixture-case-type');

		$this->assertSame(PackageInstaller::INSTALLED, $outcome['status'], $outcome['message']);
		$this->assertSame(6, $outcome['written']);
		$this->assertSame('Fixture request', $this->register->row('caseType', self::CASE_TYPE)['title']);
		$this->assertCount(2, $this->register->all('statusType'));
		$this->assertCount(1, $this->register->all('roleType'));
		$this->assertSame(self::CASE_TYPE, $this->register->all('workflowTemplate')[0]['caseType']);
		$this->assertSame('reference', $this->register->all('propertyDefinition')[0]['name']);

		$ledger = $this->rowLedger();
		$this->assertCount(6, $ledger, 'every written row has a ledger row');
		foreach ($ledger as $row) {
			$this->assertSame('fixture-case-type', $row['set']);
			$this->assertSame('1.0.0', $row['setVersion']);
		}
	}//end testAPackageWritesEveryRowAndRecordsEachInTheLedger()

	/**
	 * Seed the fixture case type as a seeder would have written it: the same
	 * identifier and names, random ids, statuses pointing at the random case
	 * type id.
	 *
	 * @param boolean $complete      False to seed only the case type and its statuses.
	 * @param boolean $hasIdentifier False to seed the case type without an identifier,
	 *                               as the VTH seeder wrote them.
	 *
	 * @return void
	 */
	private function seedAsASeederWould(bool $complete = true, bool $hasIdentifier = true): void {
		$caseType = ['title' => 'Fixture request', 'identifier' => 'fixture-request'];
		if ($hasIdentifier === false) {
			unset($caseType['identifier']);
		}

		$this->register->seed('caseType', 'seeded-ct', $caseType);
		$this->register->seed('statusType', 'seeded-received', ['name' => 'Received', 'order' => 1, 'caseType' => 'seeded-ct']);
		$this->register->seed('statusType', 'seeded-done', ['name' => 'Done', 'order' => 2, 'caseType' => 'seeded-ct']);
		if ($complete === false) {
			return;
		}

		$this->register->seed('roleType', 'seeded-handler', ['name' => 'Handler', 'caseType' => 'seeded-ct']);
		$this->register->seed('workflowTemplate', 'seeded-flow', ['title' => 'Fixture request handling', 'caseType' => 'seeded-ct']);
		$this->register->seed('propertyDefinition', 'seeded-reference', ['name' => 'reference', 'caseType' => 'seeded-ct']);
	}//end seedAsASeederWould()

	/**
	 * A case type a seeder already wrote under a random id is adopted by its
	 * identifier: every row is recorded in the ledger under the id it has
	 * here, and nothing new is written (decision 190).
	 *
	 * @return void
	 */
	public function testAnExistingCaseTypeIsAdoptedByIdentifierAndNothingIsWritten(): void {
		$this->seedAsASeederWould();
		$before = $this->register->rows;

		$outcome = $this->installer()->install(id: 'fixture-case-type');

		$this->assertSame(PackageInstaller::INSTALLED, $outcome['status'], $outcome['message']);
		$this->assertSame(0, $outcome['written']);
		$this->assertSame(6, $outcome['adopted']);
		$ledger = $this->rowLedger();
		unset($this->register->rows['shippedOrigin']);
		$this->assertSame($before, $this->register->rows, 'no row was written, changed or removed');

		$recorded = array_column($ledger, 'targetObject');
		sort($recorded);
		$this->assertSame(['seeded-ct', 'seeded-done', 'seeded-flow', 'seeded-handler', 'seeded-received', 'seeded-reference'], $recorded);
		foreach ($ledger as $row) {
			$this->assertSame('fixture-case-type', $row['set']);
			$this->assertSame('1.0.0', $row['setVersion']);
		}
	}//end testAnExistingCaseTypeIsAdoptedByIdentifierAndNothingIsWritten()

	/**
	 * A case type a seeder wrote without an identifier is adopted by its title:
	 * the VTH seeder keyed its case types by slug and never set one.
	 *
	 * @return void
	 */
	public function testACaseTypeWithoutAnIdentifierIsAdoptedByTitle(): void {
		$this->seedAsASeederWould(hasIdentifier: false);
		$before = $this->register->rows;

		$outcome = $this->installer()->install(id: 'fixture-case-type');

		$this->assertSame(PackageInstaller::INSTALLED, $outcome['status'], $outcome['message']);
		$this->assertSame(0, $outcome['written']);
		$this->assertSame(6, $outcome['adopted']);
		unset($this->register->rows['shippedOrigin']);
		$this->assertSame($before, $this->register->rows, 'no row was written, changed or removed');
	}//end testACaseTypeWithoutAnIdentifierIsAdoptedByTitle()

	/**
	 * A case type with another title and no identifier is not adopted.
	 *
	 * @return void
	 */
	public function testACaseTypeWithoutAnIdentifierAndAnotherTitleIsNotAdopted(): void {
		$this->register->seed('caseType', 'seeded-ct', ['title' => 'Something else']);

		$outcome = $this->installer()->install(id: 'fixture-case-type');

		$this->assertSame(PackageInstaller::INSTALLED, $outcome['status'], $outcome['message']);
		$this->assertSame(0, $outcome['adopted']);
		$this->assertCount(2, $this->register->all('caseType'));
	}//end testACaseTypeWithoutAnIdentifierAndAnotherTitleIsNotAdopted()

	/**
	 * A case type with another identifier is not adopted, even with the same title.
	 *
	 * @return void
	 */
	public function testACaseTypeWithAnotherIdentifierIsNotAdopted(): void {
		$this->register->seed('caseType', 'seeded-ct', ['title' => 'Fixture request', 'identifier' => 'something-else']);

		$outcome = $this->installer()->install(id: 'fixture-case-type');

		$this->assertSame(PackageInstaller::INSTALLED, $outcome['status'], $outcome['message']);
		$this->assertSame(6, $outcome['written']);
		$this->assertSame(0, $outcome['adopted']);
		$this->assertCount(2, $this->register->all('caseType'));
	}//end testACaseTypeWithAnotherIdentifierIsNotAdopted()

	/**
	 * An adopted case type gets only the rows it lacks, and those point at the
	 * ids it has here: the flow's case type and the statuses inside its
	 * transitions are the seeded ones, not the package's.
	 *
	 * @return void
	 */
	public function testAnAdoptedCaseTypeGetsOnlyTheRowsItLacksPointingAtItsOwnIds(): void {
		$this->seedAsASeederWould(complete: false);

		$outcome = $this->installer()->install(id: 'fixture-case-type');

		$this->assertSame(PackageInstaller::INSTALLED, $outcome['status'], $outcome['message']);
		$this->assertSame(3, $outcome['adopted']);
		$this->assertSame(3, $outcome['written']);
		$this->assertCount(1, $this->register->all('caseType'));
		$this->assertCount(2, $this->register->all('statusType'));
		$this->assertSame('seeded-ct', $this->register->all('roleType')[0]['caseType']);
		$this->assertSame('seeded-ct', $this->register->all('propertyDefinition')[0]['caseType']);

		$flow = $this->register->all('workflowTemplate')[0];
		$this->assertSame('seeded-ct', $flow['caseType']);
		$this->assertStringContainsString('"fromStatus":"seeded-received"', $flow['transitions']);
		$this->assertStringContainsString('"toStatus":"seeded-done"', $flow['transitions']);
		$this->assertStringContainsString('"status":"seeded-received"', $flow['steps']);
		$this->assertCount(6, $this->rowLedger());
	}//end testAnAdoptedCaseTypeGetsOnlyTheRowsItLacksPointingAtItsOwnIds()

	/**
	 * A failed install takes back what it wrote and never what it adopted.
	 *
	 * @return void
	 */
	public function testAFailedInstallNeverRemovesAnAdoptedRow(): void {
		$this->seedAsASeederWould(complete: false);
		$this->register->refuse = ['Fixture request handling'];

		$outcome = $this->installer()->install(id: 'fixture-case-type');

		$this->assertSame(PackageInstaller::FAILED, $outcome['status']);
		$this->assertSame(['seeded-ct'], array_column($this->register->all('caseType'), 'id'));
		$this->assertCount(2, $this->register->all('statusType'));
		$this->assertSame([], $this->register->all('roleType'));
		$this->assertSame([], $this->register->all('shippedOrigin'));
	}//end testAFailedInstallNeverRemovesAnAdoptedRow()

	/**
	 * A second install of the same version writes nothing.
	 *
	 * @return void
	 */
	public function testASecondInstallOfTheSameVersionWritesNothing(): void {
		$this->installer()->install(id: 'fixture-case-type');
		$writes = $this->register->writes;

		$outcome = $this->installer()->install(id: 'fixture-case-type');

		$this->assertSame(PackageInstaller::CURRENT, $outcome['status']);
		$this->assertSame($writes, $this->register->writes);
	}//end testASecondInstallOfTheSameVersionWritesNothing()

	/**
	 * A newer version adds its new rows and leaves the rows already here alone.
	 *
	 * @return void
	 */
	public function testANewerVersionAddsNewRowsAndLeavesExistingOnesAlone(): void {
		$this->installer()->install(id: 'fixture-case-type');
		$this->register->rows['caseType'][self::CASE_TYPE]['title'] = 'Edited here';

		$root = $this->variant(
			static function (string $dir): void {
				self::editJson($dir . '/package.json', static fn (array $m): array => ['version' => '1.1.0'] + $m);
				self::editJson(
					$dir . '/case-type.json',
					static function (array $c): array {
						$c['roleTypes'][] = ['id' => '0f1c0000-0000-4000-8000-000000000022', 'name' => 'Reviewer', 'caseType' => self::CASE_TYPE];
						return $c;
					}
				);
			}
		);

		$outcome = $this->installer(root: $root)->install(id: 'fixture-case-type');

		$this->assertSame(PackageInstaller::INSTALLED, $outcome['status'], $outcome['message']);
		$this->assertSame(1, $outcome['written']);
		$this->assertSame(6, $outcome['skipped']);
		$this->assertSame('Edited here', $this->register->row('caseType', self::CASE_TYPE)['title'], 'a local edit survives');
	}//end testANewerVersionAddsNewRowsAndLeavesExistingOnesAlone()

	/**
	 * Version 1.1.0 of the fixture with a new case type description and a renamed first status.
	 *
	 * @return string The package root.
	 */
	private function raisedVersion(): string {
		return $this->variant(
			static function (string $dir): void {
				self::editJson($dir . '/package.json', static fn (array $m): array => ['version' => '1.1.0'] + $m);
				self::editJson(
					$dir . '/case-type.json',
					static function (array $c): array {
						$c['caseType']['description'] = 'The description 1.1.0 ships.';
						$c['statusTypes'][0]['name'] = 'Registered';
						return $c;
					}
				);
			}
		);
	}//end raisedVersion()

	/**
	 * The ledger row of one target object.
	 *
	 * @param string $targetObject The row id.
	 *
	 * @return array<string, mixed> The ledger row.
	 */
	private function ledgerRowOf(string $targetObject): array {
		foreach ($this->rowLedger() as $row) {
			if ($row['targetObject'] === $targetObject) {
				return $row;
			}
		}

		$this->fail("No ledger row for {$targetObject}");
	}//end ledgerRowOf()

	/**
	 * A newer version refreshes the rows nobody edited here, and stamps them with that version.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/package-upgrade-refreshes-untouched-rows/specs/case-types/spec.md
	 */
	public function testANewerVersionRefreshesRowsNobodyEdited(): void {
		$this->installer()->install(id: 'fixture-case-type');

		$outcome = $this->installer(root: $this->raisedVersion())->install(id: 'fixture-case-type');

		$this->assertSame(PackageInstaller::INSTALLED, $outcome['status'], $outcome['message']);
		$this->assertSame('The description 1.1.0 ships.', $this->register->row('caseType', self::CASE_TYPE)['description']);
		$this->assertSame('Registered', $this->register->row('statusType', '0f1c0000-0000-4000-8000-000000000011')['name']);
		$this->assertSame(2, $outcome['refreshed']);
		$this->assertSame([], $outcome['kept']);
		$this->assertSame('1.1.0', $this->ledgerRowOf(targetObject: self::CASE_TYPE)['setVersion']);
		$this->assertSame('1.0.0', $this->ledgerRowOf(targetObject: '0f1c0000-0000-4000-8000-000000000012')['setVersion'], 'a row the version did not change is not rewritten');
	}//end testANewerVersionRefreshesRowsNobodyEdited()

	/**
	 * A row the package ships by its own id but the ledger does not know (written before the ledger existed) is
	 * taken over by an install over local changes, and recorded from then on; a plain install keeps it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/package-upgrade-refreshes-untouched-rows/specs/case-types/spec.md
	 */
	public function testAnInstallOverLocalChangesAdoptsARowTheLedgerDoesNotKnow(): void {
		$this->installer()->install(id: 'fixture-case-type');
		$status = '0f1c0000-0000-4000-8000-000000000011';
		foreach ($this->register->rows['shippedOrigin'] as $id => $row) {
			if ($row['targetObject'] === $status) {
				unset($this->register->rows['shippedOrigin'][$id]);
			}
		}

		$kept = $this->installer(root: $this->raisedVersion())->install(id: 'fixture-case-type');
		$this->assertSame('Received', $this->register->row('statusType', $status)['name'], 'a plain install keeps a row it cannot place');
		$this->assertContains($status, $kept['kept']);

		$outcome = $this->installer(root: $this->raisedVersion())->installOverLocalChanges(id: 'fixture-case-type');

		$this->assertSame(PackageInstaller::INSTALLED, $outcome['status'], $outcome['message']);
		$this->assertSame('Registered', $this->register->row('statusType', $status)['name']);
		$this->assertSame('1.1.0', $this->ledgerRowOf(targetObject: $status)['setVersion']);
	}//end testAnInstallOverLocalChangesAdoptsARowTheLedgerDoesNotKnow()

	/**
	 * A row edited here survives a newer version and is named; its untouched siblings are refreshed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/package-upgrade-refreshes-untouched-rows/specs/case-types/spec.md
	 */
	public function testANewerVersionKeepsARowEditedHereAndNamesIt(): void {
		$this->installer()->install(id: 'fixture-case-type');
		$this->register->rows['caseType'][self::CASE_TYPE]['title'] = 'Edited here';

		$outcome = $this->installer(root: $this->raisedVersion())->install(id: 'fixture-case-type');

		$caseType = $this->register->row('caseType', self::CASE_TYPE);
		$this->assertSame('Edited here', $caseType['title'], 'a local edit survives');
		$this->assertSame('A neutral case type used to test the package installer.', $caseType['description']);
		$this->assertSame([self::CASE_TYPE], $outcome['kept']);
		$this->assertSame(1, $outcome['refreshed']);
		$this->assertSame('Registered', $this->register->row('statusType', '0f1c0000-0000-4000-8000-000000000011')['name']);
		$this->assertSame('1.0.0', $this->ledgerRowOf(targetObject: self::CASE_TYPE)['setVersion']);
	}//end testANewerVersionKeepsARowEditedHereAndNamesIt()

	/**
	 * 🔴 Zuiddrecht, 14 Oct: an upgraded instance holding the Woo case type at version 1 and no flow.
	 *
	 * The shipped `woo-verzoek` package installs on the upgrade and writes the
	 * flow the shipped case type names, so the register import that follows
	 * (InstallShippedPackages imports again) can write the case type at 1.4.7.
	 * A second run on the same version writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function testTheShippedWooPackageWritesItsFlowOnAnUpgradedInstance(): void {
		$caseType = '3c0f5a00-0000-4000-a000-00000000a001';
		$flow = 'cb967581-3962-5b61-8106-9f75248ffd82';
		$this->register->rows['caseType'][$caseType] = ['id' => $caseType, 'title' => 'Woo-verzoek', 'handling' => null, 'version' => '1'];
		$root = __DIR__ . '/../../../../lib/Settings/packages';

		$outcome = $this->installer(root: $root)->install(id: 'woo-verzoek');

		$this->assertSame(PackageInstaller::INSTALLED, $outcome['status'], $outcome['message']);
		$this->assertSame($caseType, $this->register->row('workflowTemplate', $flow)['caseType']);
		$writes = $this->register->writes;

		$again = $this->installer(root: $root)->install(id: 'woo-verzoek');

		$this->assertSame(PackageInstaller::CURRENT, $again['status']);
		$this->assertSame($writes, $this->register->writes);
	}//end testTheShippedWooPackageWritesItsFlowOnAnUpgradedInstance()

	/**
	 * A package may carry a family of case types: `caseType` as a list writes
	 * each one, and a list holding something other than objects is refused.
	 *
	 * @return void
	 */
	public function testAPackageMayCarrySeveralCaseTypes(): void {
		$second = '0f1c0000-0000-4000-8000-000000000002';
		$root = $this->variant(
			static function (string $dir) use ($second): void {
				self::editJson(
					$dir . '/case-type.json',
					static function (array $c) use ($second): array {
						$c['caseType'] = [$c['caseType'], ['id' => $second, 'title' => 'Fixture appeal', 'identifier' => 'fixture-appeal']];
						$c['statusTypes'][] = ['id' => '0f1c0000-0000-4000-8000-000000000013', 'name' => 'Lodged', 'order' => 1, 'caseType' => $second];
						return $c;
					}
				);
			}
		);

		$outcome = $this->installer(root: $root)->install(id: 'fixture-case-type');

		$this->assertSame(PackageInstaller::INSTALLED, $outcome['status'], $outcome['message']);
		$this->assertSame(8, $outcome['written']);
		$this->assertSame('Fixture appeal', $this->register->row('caseType', $second)['title']);

		$broken = $this->variant(
			static function (string $dir): void {
				self::editJson($dir . '/case-type.json', static fn (array $c): array => ['caseType' => [$c['caseType'], 'not an object']] + $c);
			}
		);
		$refused = $this->installer(root: $broken)->install(id: 'fixture-case-type');

		$this->assertSame(PackageInstaller::REFUSED, $refused['status']);
		$this->assertStringContainsString('caseType must be one object or a non-empty list of objects', implode("\n", $refused['errors']));
	}//end testAPackageMayCarrySeveralCaseTypes()

	/**
	 * A row the store refuses takes every row of the package back out, across
	 * parts, and nothing is recorded.
	 *
	 * @return void
	 */
	public function testARefusedRowTakesTheWholePackageBack(): void {
		// The flow is written after the case type and its statuses.
		$this->register->refuse = ['Fixture request handling'];

		$outcome = $this->installer()->install(id: 'fixture-case-type');

		$this->assertSame(PackageInstaller::FAILED, $outcome['status']);
		$this->assertStringContainsString('the store refused the write', $outcome['message']);
		foreach (['caseType', 'statusType', 'roleType', 'workflowTemplate', 'propertyDefinition', 'shippedOrigin'] as $schema) {
			$this->assertSame([], $this->register->all($schema), "{$schema} kept a row from a failed install");
		}
	}//end testARefusedRowTakesTheWholePackageBack()

	/**
	 * A schema key that resolves to nothing fails the install and leaves nothing.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaLeavesNothingBehind(): void {
		$outcome = $this->installer(unconfigured: ['property_definition_schema'])->install(id: 'fixture-case-type');

		$this->assertSame(PackageInstaller::FAILED, $outcome['status']);
		$this->assertStringContainsString('property_definition_schema', $outcome['message']);
		$this->assertSame([], $this->register->all('caseType'));
	}//end testAnUnconfiguredSchemaLeavesNothingBehind()

	/**
	 * Without a ledger nothing is written: a row nobody can account for is not shipped.
	 *
	 * @return void
	 */
	public function testWithoutALedgerNothingIsWritten(): void {
		$outcome = $this->installer(unconfigured: ['shipped_origin_schema'])->install(id: 'fixture-case-type');

		$this->assertSame(PackageInstaller::FAILED, $outcome['status']);
		$this->assertStringContainsString('ledger', $outcome['message']);
		$this->assertSame(0, $this->register->writes);
	}//end testWithoutALedgerNothingIsWritten()

	/**
	 * A package that requires a capability this instance lacks is refused by
	 * name, before any write.
	 *
	 * @return void
	 */
	public function testAMissingCapabilityIsRefusedByName(): void {
		$root = $this->variant(
			static function (string $dir): void {
				self::editJson($dir . '/package.json', static fn (array $m): array => ['requires' => ['term', 'app:decidiq']] + $m);
			}
		);

		$outcome = $this->installer(root: $root)->install(id: 'fixture-case-type');

		$this->assertSame(PackageInstaller::REFUSED, $outcome['status']);
		$this->assertSame(['term', 'app:decidiq'], $outcome['missing']);
		$this->assertStringContainsString('term, app:decidiq', $outcome['message']);
		$this->assertSame(0, $this->register->writes);
	}//end testAMissingCapabilityIsRefusedByName()

	/**
	 * An app requirement is met when that app is installed.
	 *
	 * @return void
	 */
	public function testAnAppRequirementIsMetWhenTheAppIsInstalled(): void {
		$root = $this->variant(
			static function (string $dir): void {
				self::editJson($dir . '/package.json', static fn (array $m): array => ['requires' => ['app:decidiq']] + $m);
			}
		);

		$outcome = $this->installer(root: $root, installedApps: ['decidiq'])->install(id: 'fixture-case-type');

		$this->assertSame(PackageInstaller::INSTALLED, $outcome['status'], $outcome['message']);
	}//end testAnAppRequirementIsMetWhenTheAppIsInstalled()

	/**
	 * The validator names what is wrong, and an invalid package writes nothing.
	 *
	 * @return void
	 */
	public function testAnInvalidPackageIsRefusedWithItsErrors(): void {
		$root = $this->variant(
			static function (string $dir): void {
				self::editJson($dir . '/package.json', static fn (array $m): array => ['id' => 'other', 'version' => '1', 'parts' => ['case-type', 'flows', 'seed', 'terms']] + $m);
				self::editJson(
					$dir . '/case-type.json',
					static function (array $c): array {
						unset($c['statusTypes'][1]['id']);
						$c['roleTypes'][0]['id'] = $c['statusTypes'][0]['id'];
						return $c;
					}
				);
				file_put_contents($dir . '/seed/broken.json', '{"schema": "nope", "rows": []}');
				file_put_contents($dir . '/notes.txt', 'stray');
			}
		);

		$installer = $this->installer(root: $root);
		$outcome = $installer->install(id: 'fixture-case-type');
		$errors = implode("\n", $outcome['errors']);

		$this->assertSame(PackageInstaller::REFUSED, $outcome['status']);
		$this->assertStringContainsString("id must be 'fixture-case-type'", $errors);
		$this->assertStringContainsString('version must read major.minor.patch', $errors);
		$this->assertStringContainsString("part 'terms' is not one this installer can write", $errors);
		$this->assertStringContainsString('statusTypes row 1: id must be a fixed UUID', $errors);
		$this->assertStringContainsString('is also used in', $errors);
		$this->assertStringContainsString('seed/broken.json: schema must name a settings key', $errors);
		$this->assertStringContainsString('seed/broken.json: rows must be a non-empty list', $errors);
		$this->assertSame(0, $this->register->writes);
		$this->assertFalse($installer->catalogue()[0]['valid']);
	}//end testAnInvalidPackageIsRefusedWithItsErrors()

	/**
	 * An unknown id is refused, and an id that would leave the root is never read.
	 *
	 * @return void
	 */
	public function testAnUnknownOrUnsafeIdIsRefused(): void {
		$this->assertSame(PackageInstaller::REFUSED, $this->installer()->install(id: 'not-shipped')['status']);
		$this->assertSame(PackageInstaller::REFUSED, $this->installer()->install(id: '../fixtures')['status']);
	}//end testAnUnknownOrUnsafeIdIsRefused()

	/**
	 * Only packages marked auto install automatically; on-request ones wait.
	 *
	 * @return void
	 */
	public function testOnlyAutomaticPackagesInstallAutomatically(): void {
		$root = $this->variant(
			static function (string $dir): void {
				self::editJson($dir . '/package.json', static fn (array $m): array => ['install' => 'on-request'] + $m);
			}
		);

		$installer = $this->installer(root: $root);

		$this->assertSame([], $installer->installAutomatic());
		$this->assertSame(0, $this->register->writes);
		$this->assertSame(PackageInstaller::INSTALLED, $installer->install(id: 'fixture-case-type')['status']);
	}//end testOnlyAutomaticPackagesInstallAutomatically()

	/**
	 * A reload installs what is installed here or automatic, and nothing else.
	 *
	 * @spec openspec/specs/case-types/spec.md#requirement-an-administrator-reloads-the-shipped-templates
	 *
	 * @return void
	 */
	public function testAReloadInstallsWhatIsInstalledOrAutomaticAndNothingElse(): void {
		$root = $this->variant(
			change: static function (string $dir): void {
				self::editJson(path: $dir . '/package.json', change: static fn (array $m): array => ['install' => 'on-request'] + $m);
			}
		);

		$reloaded = $this->installer(root: $root)->reloadInstalled();
		$this->assertSame(expected: [], actual: $reloaded, message: 'an on-request package never installed is left alone');
		$this->assertSame(expected: 0, actual: $this->register->writes);

		$this->installer(root: $root)->install(id: 'fixture-case-type');
		$writes = $this->register->writes;

		$outcomes = $this->installer(root: $root)->reloadInstalled();

		$this->assertCount(expectedCount: 1, haystack: $outcomes);
		$this->assertSame(expected: PackageInstaller::CURRENT, actual: $outcomes[0]['status']);
		$this->assertSame(expected: $writes, actual: $this->register->writes, message: 'a reload at the shipped version writes nothing');
	}//end testAReloadInstallsWhatIsInstalledOrAutomaticAndNothingElse()

	/**
	 * An installed on-request package takes a newer version on reload, and an edit here survives.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/repair-updates-installed-packages/specs/case-types/spec.md
	 */
	public function testAReloadUpdatesAnInstalledOnRequestPackageAndKeepsAnEdit(): void {
		$onRequest = static function (string $dir): void {
			self::editJson(path: $dir . '/package.json', change: static fn (array $m): array => ['install' => 'on-request'] + $m);
		};
		$this->installer(root: $this->variant(change: $onRequest))->install(id: 'fixture-case-type');
		$this->register->rows['caseType'][self::CASE_TYPE]['title'] = 'Edited here';
		$raised = $this->raisedVersion();
		$onRequest($raised . '/fixture-case-type');

		$outcomes = $this->installer(root: $raised)->reloadInstalled();

		$this->assertCount(expectedCount: 1, haystack: $outcomes);
		$this->assertSame(expected: PackageInstaller::INSTALLED, actual: $outcomes[0]['status'], message: $outcomes[0]['message']);
		$this->assertSame(expected: 'Registered', actual: $this->register->row('statusType', '0f1c0000-0000-4000-8000-000000000011')['name']);
		$this->assertSame(expected: 'Edited here', actual: $this->register->row('caseType', self::CASE_TYPE)['title']);
		$this->assertSame(expected: [self::CASE_TYPE], actual: $outcomes[0]['kept']);
	}//end testAReloadUpdatesAnInstalledOnRequestPackageAndKeepsAnEdit()

	/**
	 * Settings fill keys this instance never set and keep an administrator's value.
	 *
	 * @return void
	 */
	public function testSettingsNeverOverruleAnAdministratorsValue(): void {
		$root = $this->variant(
			static function (string $dir): void {
				self::editJson($dir . '/package.json', static fn (array $m): array => ['parts' => ['case-type', 'flows', 'seed', 'settings']] + $m);
				file_put_contents($dir . '/settings.json', json_encode(['fixture_enabled' => true, 'fixture_label' => 'shipped']));
			}
		);
		$this->appConfigValues = ['fixture_label' => ''];

		$outcome = $this->installer(root: $root)->install(id: 'fixture-case-type');

		$this->assertSame(PackageInstaller::INSTALLED, $outcome['status'], $outcome['message']);
		$this->assertSame('true', $this->appConfigValues['fixture_enabled']);
		$this->assertSame('', $this->appConfigValues['fixture_label']);
	}//end testSettingsNeverOverruleAnAdministratorsValue()

	/**
	 * A setting that names another app's schema by register and slug is
	 * stored as that schema's id on this instance, resolved at install.
	 *
	 * @return void
	 */
	public function testASettingNamingAnotherAppsSchemaIsStoredAsItsIdHere(): void {
		$outcome = $this->installer(installedApps: ['fixture-app'])->install(id: 'fixture-settings');

		$this->assertSame(PackageInstaller::INSTALLED, $outcome['status'], $outcome['message']);
		$this->assertSame('60', $this->appConfigValues['fixture_source_schema']);
		$this->assertSame('shipped', $this->appConfigValues['fixture_label']);
	}//end testASettingNamingAnotherAppsSchemaIsStoredAsItsIdHere()

	/**
	 * A register the app used to call by another slug is still found.
	 *
	 * @return void
	 */
	public function testAReferenceFindsTheSchemaUnderAnOlderRegisterSlug(): void {
		$this->schemas->registers = ['fixture-register-old' => [77]];
		$this->schemas->schemas = [77 => 'fixture_source'];

		$outcome = $this->installer(installedApps: ['fixture-app'])->install(id: 'fixture-settings');

		$this->assertSame(PackageInstaller::INSTALLED, $outcome['status'], $outcome['message']);
		$this->assertSame('77', $this->appConfigValues['fixture_source_schema']);
	}//end testAReferenceFindsTheSchemaUnderAnOlderRegisterSlug()

	/**
	 * An app installed under its former id still answers for its schema, as
	 * every other fleet lookup does (FleetAppId: integriq was openconnector).
	 *
	 * @return void
	 */
	public function testAReferenceFindsAnAppInstalledUnderItsFormerId(): void {
		$root = sys_get_temp_dir() . '/dossiq-packages-' . bin2hex(random_bytes(6));
		$this->temporary[] = $root;
		mkdir($root);
		exec('cp -r ' . escapeshellarg(self::FIXTURES . '/fixture-settings') . ' ' . escapeshellarg($root . '/fixture-settings'));
		file_put_contents(
			$root . '/fixture-settings/settings.json',
			json_encode(['fixture_source_schema' => ['schema' => ['app' => 'integriq', 'register' => 'fixture-register', 'slug' => 'fixture_source']]])
		);

		$outcome = $this->installer(root: $root, installedApps: ['openconnector'])->install(id: 'fixture-settings');

		$this->assertSame(PackageInstaller::INSTALLED, $outcome['status'], $outcome['message']);
		$this->assertSame('60', $this->appConfigValues['fixture_source_schema']);
	}//end testAReferenceFindsAnAppInstalledUnderItsFormerId()

	/**
	 * When the named schema is missing the package is refused, the app is
	 * named, and no setting is written.
	 *
	 * @return void
	 */
	public function testAMissingReferencedSchemaRefusesThePackageAndNamesTheApp(): void {
		$this->schemas->schemas = [12 => 'unrelated'];

		$outcome = $this->installer(installedApps: ['fixture-app'])->install(id: 'fixture-settings');

		$this->assertSame(PackageInstaller::REFUSED, $outcome['status']);
		$this->assertStringContainsString('fixture-app', $outcome['message']);
		$this->assertStringContainsString('fixture_source', $outcome['message']);
		$this->assertSame(['fixture-app:fixture_source'], $outcome['missing']);
		$this->assertSame([], $this->appConfigValues, 'no setting is written for a refused package');
		$this->assertSame([], $this->register->all('shippedOrigin'), 'a refused package is not recorded');
	}//end testAMissingReferencedSchemaRefusesThePackageAndNamesTheApp()

	/**
	 * When the app that owns the schema is not installed the package is
	 * refused and the message names the app.
	 *
	 * @return void
	 */
	public function testAReferenceToAnAppThatIsNotInstalledRefusesThePackage(): void {
		$outcome = $this->installer()->install(id: 'fixture-settings');

		$this->assertSame(PackageInstaller::REFUSED, $outcome['status']);
		$this->assertStringContainsString('fixture-app', $outcome['message']);
		$this->assertSame([], $this->appConfigValues);
	}//end testAReferenceToAnAppThatIsNotInstalledRefusesThePackage()

	/**
	 * A reference rejected as malformed makes the package invalid.
	 *
	 * @return void
	 */
	public function testAMalformedReferenceMakesThePackageInvalid(): void {
		$root = sys_get_temp_dir() . '/dossiq-packages-' . bin2hex(random_bytes(6));
		$this->temporary[] = $root;
		mkdir($root);
		exec('cp -r ' . escapeshellarg(self::FIXTURES . '/fixture-settings') . ' ' . escapeshellarg($root . '/fixture-settings'));
		file_put_contents($root . '/fixture-settings/settings.json', json_encode(['fixture_source_schema' => ['schema' => ['app' => 'fixture-app']]]));

		$outcome = $this->installer(root: $root, installedApps: ['fixture-app'])->install(id: 'fixture-settings');

		$this->assertSame(PackageInstaller::REFUSED, $outcome['status']);
		$this->assertStringContainsString("settings.json: 'fixture_source_schema' names a schema without", implode("\n", $outcome['errors']));
	}//end testAMalformedReferenceMakesThePackageInvalid()

	/**
	 * A copy of one fixture package, changed for one test.
	 *
	 * @param string   $package The fixture package to copy.
	 * @param callable $change  Receives the copy's directory.
	 *
	 * @return string The root holding the copy.
	 */
	private function copyOf(string $package, callable $change): string {
		$root = sys_get_temp_dir() . '/dossiq-packages-' . bin2hex(random_bytes(6));
		$this->temporary[] = $root;
		mkdir($root);
		exec('cp -r ' . escapeshellarg(self::FIXTURES . '/' . $package) . ' ' . escapeshellarg($root . '/' . $package));
		$change($root . '/' . $package);

		return $root;
	}//end copyOf()

	/**
	 * REQ-CT-55: a seed file that names another app's schema by app, register
	 * and slug has its rows written into that register, and recorded here.
	 *
	 * @return void
	 */
	public function testASeedFileNamingAnotherAppsSchemaWritesItsRowsIntoThatRegister(): void {
		$outcome = $this->installer(installedApps: ['fixture-app'])->install(id: 'fixture-shared-seed');

		$this->assertSame(PackageInstaller::INSTALLED, $outcome['status'], $outcome['message']);
		$this->assertSame(2, $outcome['written']);

		// Schema 60 is fixture_source, held by register fixture-register (id 900).
		$rows = $this->register->all('60');
		$this->assertSame(['fixture.first', 'fixture.second'], array_column($rows, 'key'));
		$this->assertSame('900', $this->register->savedIn['0f1c0000-0000-4000-8000-000000000071']);
		$this->assertSame('900', $this->register->savedIn['0f1c0000-0000-4000-8000-000000000072']);

		$ledger = $this->rowLedger();
		$this->assertCount(2, $ledger);
		foreach ($ledger as $row) {
			$this->assertSame('fixture-shared-seed', $row['set']);
			$this->assertSame('60', (string)$row['targetSchema']);
		}

		$this->assertSame(PackageInstaller::CURRENT, $this->installer(installedApps: ['fixture-app'])->install(id: 'fixture-shared-seed')['status']);
	}//end testASeedFileNamingAnotherAppsSchemaWritesItsRowsIntoThatRegister()

	/**
	 * REQ-CT-55: the other app is not installed, so the package is refused by name and nothing is written.
	 *
	 * @return void
	 */
	public function testASeedForAnAppThatIsNotInstalledRefusesThePackage(): void {
		$outcome = $this->installer()->install(id: 'fixture-shared-seed');

		$this->assertSame(PackageInstaller::REFUSED, $outcome['status']);
		$this->assertStringContainsString('fixture-app', $outcome['message']);
		$this->assertSame(['fixture-app:fixture_source'], $outcome['missing']);
		$this->assertSame(0, $this->register->writes);
	}//end testASeedForAnAppThatIsNotInstalledRefusesThePackage()

	/**
	 * REQ-CT-55: the app is installed but no named register holds the schema, so the package is refused.
	 *
	 * @return void
	 */
	public function testASeedForASchemaTheRegisterDoesNotHoldRefusesThePackage(): void {
		$this->schemas->registers = ['fixture-register' => [12]];

		$outcome = $this->installer(installedApps: ['fixture-app'])->install(id: 'fixture-shared-seed');

		$this->assertSame(PackageInstaller::REFUSED, $outcome['status']);
		$this->assertStringContainsString('fixture_source', $outcome['message']);
		$this->assertSame(['fixture-app:fixture_source'], $outcome['missing']);
		$this->assertSame(0, $this->register->writes);
	}//end testASeedForASchemaTheRegisterDoesNotHoldRefusesThePackage()

	/**
	 * REQ-CT-55: a row this instance already holds under another id, with the
	 * same value for the property the seed file matches on, is adopted.
	 *
	 * @return void
	 */
	public function testASeedRowIsAdoptedByThePropertyTheSeedFileMatchesOn(): void {
		$this->register->seed('60', 'written-by-hand', ['key' => 'fixture.first', 'name' => 'Edited here']);

		$outcome = $this->installer(installedApps: ['fixture-app'])->install(id: 'fixture-shared-seed');

		$this->assertSame(PackageInstaller::INSTALLED, $outcome['status'], $outcome['message']);
		$this->assertSame(1, $outcome['written']);
		$this->assertSame(1, $outcome['adopted']);
		$this->assertCount(2, $this->register->all('60'));
		$this->assertSame('Edited here', $this->register->row('60', 'written-by-hand')['name']);
	}//end testASeedRowIsAdoptedByThePropertyTheSeedFileMatchesOn()

	/**
	 * The shared seed fixture, matching on something else and carrying other rows.
	 *
	 * @param mixed                            $match The `match` value.
	 * @param array<int, array<string, mixed>> $rows  The rows.
	 *
	 * @return string The root holding the variant.
	 */
	private function sharedSeedMatching(mixed $match, array $rows): string {
		return $this->copyOf(
			'fixture-shared-seed',
			static function (string $dir) use ($match, $rows): void {
				self::editJson(
					$dir . '/seed/tables.json',
					static function (array $seed) use ($match, $rows): array {
						$seed['match'] = $match;
						$seed['rows'] = $rows;
						return $seed;
					}
				);
			}
		);
	}//end sharedSeedMatching()

	/**
	 * REQ-CT-56: a seed file may match on a flag. A row this instance holds
	 * with the flag set is adopted whatever it is called, so an instance that
	 * replaced the shipped default under another name gets no second one.
	 * The flag counts however the store answers it.
	 *
	 * @param mixed $held How the existing row holds the flag.
	 *
	 * @return void
	 *
	 * @dataProvider waysAStoreAnswersASetFlag
	 */
	public function testASeedRowIsAdoptedByAFlagWhateverTheExistingRowIsCalled(mixed $held = true): void {
		$this->register->seed('60', 'made-here', ['key' => 'made.here', 'name' => 'Made here', 'active' => $held]);
		$root = $this->sharedSeedMatching(
			match: 'active',
			rows: [['id' => '0f1c0000-0000-4000-8000-000000000081', 'key' => 'fixture.default', 'name' => 'Fixture default', 'active' => true]],
		);

		$outcome = $this->installer(root: $root, installedApps: ['fixture-app'])->install(id: 'fixture-shared-seed');

		$this->assertSame(PackageInstaller::INSTALLED, $outcome['status'], $outcome['message']);
		$this->assertSame(0, $outcome['written']);
		$this->assertSame(1, $outcome['adopted']);
		$this->assertSame(['made.here'], array_column($this->register->all('60'), 'key'));
	}//end testASeedRowIsAdoptedByAFlagWhateverTheExistingRowIsCalled()

	/**
	 * The ways a store answers a flag that is set.
	 *
	 * @return array<string, array{0: mixed}> The cases.
	 */
	public static function waysAStoreAnswersASetFlag(): array {
		return ['a boolean' => [true], 'the number one' => [1], 'the text true' => ['true']];
	}//end waysAStoreAnswersASetFlag()

	/**
	 * REQ-CT-56: a row with the flag cleared is not the one, so the package's row is written beside it.
	 *
	 * @return void
	 */
	public function testASeedRowIsNotAdoptedByARowWithTheFlagCleared(): void {
		$this->register->seed('60', 'retired-here', ['key' => 'retired.here', 'name' => 'Retired here', 'active' => false]);
		$root = $this->sharedSeedMatching(
			match: 'active',
			rows: [['id' => '0f1c0000-0000-4000-8000-000000000081', 'key' => 'fixture.default', 'name' => 'Fixture default', 'active' => true]],
		);

		$outcome = $this->installer(root: $root, installedApps: ['fixture-app'])->install(id: 'fixture-shared-seed');

		$this->assertSame(PackageInstaller::INSTALLED, $outcome['status'], $outcome['message']);
		$this->assertSame(1, $outcome['written']);
		$this->assertSame(0, $outcome['adopted']);
		$this->assertSame(['retired.here', 'fixture.default'], array_column($this->register->all('60'), 'key'));
	}//end testASeedRowIsNotAdoptedByARowWithTheFlagCleared()

	/**
	 * REQ-CT-56: a seed file may match on several properties together. Only a
	 * row equal on every one of them is adopted.
	 *
	 * @return void
	 */
	public function testASeedRowIsAdoptedOnlyWhenEveryMatchedPropertyIsEqual(): void {
		$this->register->seed('60', 'cell-a1', ['row' => 'A', 'column' => '1', 'name' => 'Edited here']);
		$this->register->seed('60', 'cell-b2', ['row' => 'B', 'column' => '2', 'name' => 'Other cell']);
		$root = $this->sharedSeedMatching(
			match: ['row', 'column'],
			rows: [
				['id' => '0f1c0000-0000-4000-8000-000000000091', 'row' => 'A', 'column' => '1', 'name' => 'Shipped A1'],
				['id' => '0f1c0000-0000-4000-8000-000000000092', 'row' => 'A', 'column' => '2', 'name' => 'Shipped A2'],
			],
		);

		$outcome = $this->installer(root: $root, installedApps: ['fixture-app'])->install(id: 'fixture-shared-seed');

		$this->assertSame(PackageInstaller::INSTALLED, $outcome['status'], $outcome['message']);
		$this->assertSame(1, $outcome['written']);
		$this->assertSame(1, $outcome['adopted']);
		$this->assertSame(['Edited here', 'Other cell', 'Shipped A2'], array_column($this->register->all('60'), 'name'));
	}//end testASeedRowIsAdoptedOnlyWhenEveryMatchedPropertyIsEqual()

	/**
	 * REQ-CT-56: a row that lacks one of several matched properties makes the package invalid.
	 *
	 * @return void
	 */
	public function testASeedRowWithoutOneOfSeveralMatchedPropertiesIsInvalid(): void {
		$root = $this->sharedSeedMatching(
			match: ['row', 'column'],
			rows: [['id' => '0f1c0000-0000-4000-8000-000000000091', 'row' => 'A', 'name' => 'Shipped A1']],
		);

		$outcome = $this->installer(root: $root, installedApps: ['fixture-app'])->install(id: 'fixture-shared-seed');

		$this->assertSame(PackageInstaller::REFUSED, $outcome['status']);
		$this->assertStringContainsString("row 0: has no 'column'", implode(' ', (array)($outcome['errors'] ?? [])));
		$this->assertSame(0, $this->register->writes);
	}//end testASeedRowWithoutOneOfSeveralMatchedPropertiesIsInvalid()

	/**
	 * REQ-CT-56: a match that names nothing, or something that is not a property name, makes the package invalid.
	 *
	 * @param mixed $match The `match` value.
	 *
	 * @return void
	 *
	 * @dataProvider matchesThatNameNoProperty
	 */
	public function testASeedMatchThatNamesNoPropertyIsInvalid(mixed $match): void {
		$root = $this->sharedSeedMatching(
			match: $match,
			rows: [['id' => '0f1c0000-0000-4000-8000-000000000091', 'key' => 'fixture.first', 'name' => 'Fixture first']],
		);

		$outcome = $this->installer(root: $root, installedApps: ['fixture-app'])->install(id: 'fixture-shared-seed');

		$this->assertSame(PackageInstaller::REFUSED, $outcome['status']);
		$this->assertStringContainsString('match must name', implode(' ', (array)($outcome['errors'] ?? [])));
	}//end testASeedMatchThatNamesNoPropertyIsInvalid()

	/**
	 * The `match` values that name no property.
	 *
	 * @return array<string, array{0: mixed}> The cases.
	 */
	public static function matchesThatNameNoProperty(): array {
		return [
			'an empty list' => [[]],
			'a list holding a number' => [['key', 7]],
			'a flag' => [true],
			'a name with a space' => [['key', 'the name']],
		];
	}//end matchesThatNameNoProperty()

	/**
	 * REQ-CT-55: when a later row fails, the row already written into the other register is taken back from that register.
	 *
	 * @return void
	 */
	public function testAFailedInstallTakesARowBackFromTheOtherRegister(): void {
		$this->register->refuse = ['Fixture second'];

		$outcome = $this->installer(installedApps: ['fixture-app'])->install(id: 'fixture-shared-seed');

		$this->assertSame(PackageInstaller::FAILED, $outcome['status']);
		$this->assertSame([], $this->register->all('60'));
		$this->assertSame('900', $this->register->deletedFrom['0f1c0000-0000-4000-8000-000000000071']);
		$this->assertSame([], $this->rowLedger());
	}//end testAFailedInstallTakesARowBackFromTheOtherRegister()

	/**
	 * REQ-CT-55: a seed file that names a schema without all three of app, register and slug is invalid.
	 *
	 * @return void
	 */
	public function testASeedNamingAnotherAppsSchemaIncompletelyIsInvalid(): void {
		$root = $this->copyOf(
			'fixture-shared-seed',
			static function (string $dir): void {
				self::editJson(
					$dir . '/seed/tables.json',
					static function (array $seed): array {
						unset($seed['schema']['register']);
						return $seed;
					}
				);
			}
		);

		$outcome = $this->installer(root: $root, installedApps: ['fixture-app'])->install(id: 'fixture-shared-seed');

		$this->assertSame(PackageInstaller::REFUSED, $outcome['status']);
		$this->assertStringContainsString('an app, a register and a slug', implode(' ', $outcome['errors']));
		$this->assertSame(0, $this->register->writes);
	}//end testASeedNamingAnotherAppsSchemaIncompletelyIsInvalid()

	/**
	 * REQ-CT-55: a row that lacks the property the seed file matches on makes the package invalid.
	 *
	 * @return void
	 */
	public function testASeedRowWithoutTheMatchPropertyIsInvalid(): void {
		$root = $this->copyOf(
			'fixture-shared-seed',
			static function (string $dir): void {
				self::editJson(
					$dir . '/seed/tables.json',
					static function (array $seed): array {
						unset($seed['rows'][1]['key']);
						return $seed;
					}
				);
			}
		);

		$outcome = $this->installer(root: $root, installedApps: ['fixture-app'])->install(id: 'fixture-shared-seed');

		$this->assertSame(PackageInstaller::REFUSED, $outcome['status']);
		$this->assertStringContainsString("row 1: has no 'key'", implode(' ', $outcome['errors']));
	}//end testASeedRowWithoutTheMatchPropertyIsInvalid()

	/**
	 * A package that writes no rows is still recorded at its version, so a
	 * second install reports it current and the catalogue shows it installed.
	 *
	 * @return void
	 */
	public function testASettingsOnlyPackageIsCurrentOnTheSecondInstall(): void {
		$installer = $this->installer(installedApps: ['fixture-app']);

		$first = $installer->install(id: 'fixture-settings');
		$this->appConfigValues = [];
		$second = $installer->install(id: 'fixture-settings');

		$this->assertSame(PackageInstaller::INSTALLED, $first['status'], $first['message']);
		$this->assertSame(0, $first['written']);
		$this->assertSame(PackageInstaller::CURRENT, $second['status'], $second['message']);
		$this->assertSame([], $this->appConfigValues, 'a current package applies nothing again');
		$catalogue = array_column($installer->catalogue(), 'installed', 'id');
		$this->assertSame('1.0.0', $catalogue['fixture-settings']);
	}//end testASettingsOnlyPackageIsCurrentOnTheSecondInstall()

	/**
	 * The version record is one ledger row per package, kept apart from the
	 * rows the package wrote, and updated in place on a newer version.
	 *
	 * @return void
	 */
	public function testThePackageVersionRecordIsOneRowUpdatedInPlace(): void {
		$this->installer()->install(id: 'fixture-case-type');
		$root = $this->variant(
			static function (string $dir): void {
				self::editJson($dir . '/package.json', static fn (array $m): array => ['version' => '1.1.0'] + $m);
			}
		);
		$this->installer(root: $root)->install(id: 'fixture-case-type');

		$records = array_values(
			array_filter(
				$this->register->all('shippedOrigin'),
				static fn (array $row): bool => $row['targetSchema'] === ShippedConfigurationService::PACKAGE_RECORD
			)
		);
		$this->assertCount(1, $records);
		$this->assertSame('fixture-case-type', $records[0]['targetObject']);
		$this->assertSame('1.1.0', $records[0]['setVersion']);
	}//end testThePackageVersionRecordIsOneRowUpdatedInPlace()

	/**
	 * The ledger knows a package's version from its own manifest.
	 *
	 * @return void
	 */
	public function testShippedSetsReadsAPackagesVersionFromItsManifest(): void {
		$sets = new ShippedSets(new PackageDirectory(root: self::FIXTURES));

		$this->assertSame('1.0.0', $sets->versionOf(set: 'fixture-case-type'));
		$this->assertSame('', $sets->versionOf(set: 'not-shipped'));
		$this->assertSame('1.0.0', $sets->versionOf(set: ShippedSets::VTH));
	}//end testShippedSetsReadsAPackagesVersionFromItsManifest()

	/**
	 * The fixture package with an `examples` part holding one case.
	 *
	 * @return string The root holding the variant.
	 */
	private function withAnExample(): string {
		return $this->variant(
			change: static function (string $dir): void {
				self::editJson(
					path: $dir . '/package.json',
					change: static function (array $manifest): array {
						$manifest['parts'][] = 'examples';
						return $manifest;
					}
				);
				mkdir($dir . '/examples');
				file_put_contents(
					$dir . '/examples/20-case.json',
					json_encode(
						[
							'schema' => 'case_schema',
							'rows' => [
								[
									'id' => self::EXAMPLE_CASE,
									'title' => 'Example request',
									'caseType' => self::CASE_TYPE,
									'startDate' => '{{date:-3}}',
								],
							],
						]
					)
				);
			}
		);
	}//end withAnExample()

	/**
	 * On an instance that opted into example data, example rows install with
	 * the package, each date token filled relative to the install day.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/package-examples/specs/case-types/spec.md
	 */
	public function testExampleRowsInstallWhenTheInstanceOptedIntoExampleData(): void {
		$this->appConfigValues[DemoDataService::DECIDED_KEY] = DemoDataService::DECIDED_INSTALLED;

		$outcome = $this->installer(root: $this->withAnExample())->install(id: 'fixture-case-type');

		$this->assertSame(expected: PackageInstaller::INSTALLED, actual: $outcome['status'], message: $outcome['message']);
		$this->assertSame(expected: 7, actual: $outcome['written']);
		$case = $this->register->row('case', self::EXAMPLE_CASE);
		$this->assertSame(expected: self::CASE_TYPE, actual: $case['caseType']);
		$this->assertSame(expected: (new \DateTimeImmutable('today'))->modify('-3 days')->format('Y-m-d'), actual: $case['startDate']);
	}//end testExampleRowsInstallWhenTheInstanceOptedIntoExampleData()

	/**
	 * Without the opt-in (never asked, or "None" chosen in the setup wizard),
	 * the package installs and its example rows do not: a production install
	 * never gets fake cases.
	 *
	 * @param string|null $decided The wizard's example data decision; null when never asked.
	 *
	 * @return void
	 *
	 * @dataProvider decisionsThatAreNotAnOptIn
	 *
	 * @spec openspec/changes/package-examples/specs/case-types/spec.md
	 */
	public function testExampleRowsAreSkippedWithoutTheOptIn(?string $decided): void {
		if ($decided !== null) {
			$this->appConfigValues[DemoDataService::DECIDED_KEY] = $decided;
		}

		$outcome = $this->installer(root: $this->withAnExample())->install(id: 'fixture-case-type');

		$this->assertSame(expected: PackageInstaller::INSTALLED, actual: $outcome['status'], message: $outcome['message']);
		$this->assertSame(expected: 6, actual: $outcome['written']);
		$this->assertSame(expected: [], actual: $this->register->all('case'));
	}//end testExampleRowsAreSkippedWithoutTheOptIn()

	/**
	 * Example data decisions that are not an opt-in.
	 *
	 * @return array<string, array{0: string|null}> The cases.
	 */
	public static function decisionsThatAreNotAnOptIn(): array {
		return ['never asked' => [null], 'none chosen' => ['skipped']];
	}//end decisionsThatAreNotAnOptIn()

	/**
	 * The shipped bezwaar-beroep package installs with its example case on an
	 * instance that opted into example data, and the case carries its status
	 * history as the text the schema declares, not as an array.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function testTheShippedBezwaarPackageInstallsWithItsExampleCase(): void {
		$this->appConfigValues[DemoDataService::DECIDED_KEY] = DemoDataService::DECIDED_INSTALLED;

		$outcome = $this->installer(root: __DIR__ . '/../../../../lib/Settings/packages')->install(id: 'bezwaar-beroep');

		$this->assertSame(expected: PackageInstaller::INSTALLED, actual: $outcome['status'], message: $outcome['message']);
		$cases = $this->register->all('case');
		$this->assertCount(expectedCount: 1, haystack: $cases);
		$this->assertIsString(actual: $cases[0]['statusHistory']);
		$this->assertCount(expectedCount: 4, haystack: (array)json_decode(json: $cases[0]['statusHistory'], associative: true));
	}//end testTheShippedBezwaarPackageInstallsWithItsExampleCase()

	/**
	 * A row the store filled in on save is still untouched: a newer version
	 * refreshes it. The ledger stamps the row as stored, not as shipped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/package-upgrade-refreshes-untouched-rows/specs/case-types/spec.md
	 */
	public function testANewerVersionRefreshesARowTheStoreFilledIn(): void {
		$this->register->fills['caseType'] = ['handlingModel' => null, 'tags' => []];
		$this->installer()->install(id: 'fixture-case-type');

		$outcome = $this->installer(root: $this->raisedVersion())->install(id: 'fixture-case-type');

		$this->assertSame(expected: [], actual: $outcome['kept']);
		$this->assertSame(expected: 'The description 1.1.0 ships.', actual: $this->register->row('caseType', self::CASE_TYPE)['description']);
		$this->assertSame(expected: '1.1.0', actual: $this->ledgerRowOf(targetObject: self::CASE_TYPE)['setVersion']);
	}//end testANewerVersionRefreshesARowTheStoreFilledIn()

	/**
	 * A ledger stamped with the shipped row (before rows were stamped as
	 * stored) does not hold an untouched row back: over the keys the package
	 * ships, the stored row still hashes to what shipped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/package-upgrade-refreshes-untouched-rows/specs/case-types/spec.md
	 */
	public function testALedgerStampedWithTheShippedRowStillRefreshesAnUntouchedRow(): void {
		$this->register->fills['caseType'] = ['handlingModel' => null, 'tags' => []];
		$this->installer()->install(id: 'fixture-case-type');
		$shipped = json_decode(json: (string)file_get_contents(self::FIXTURES . '/fixture-case-type/case-type.json'), associative: true)['caseType'];
		$this->stampLedger(targetObject: self::CASE_TYPE, fingerprint: (new ShippedFingerprint())->hashOf(object: $shipped));

		$outcome = $this->installer(root: $this->raisedVersion())->install(id: 'fixture-case-type');

		$this->assertSame(expected: [], actual: $outcome['kept']);
		$this->assertSame(expected: 'The description 1.1.0 ships.', actual: $this->register->row('caseType', self::CASE_TYPE)['description']);
	}//end testALedgerStampedWithTheShippedRowStillRefreshesAnUntouchedRow()

	/**
	 * A field filled in here that the package does not ship survives the
	 * refresh, and an edited shipped field still keeps the row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/package-upgrade-refreshes-untouched-rows/specs/case-types/spec.md
	 */
	public function testARefreshKeepsWhatThisInstanceAddedAndNeverAnEdit(): void {
		$this->register->fills['caseType'] = ['handlingModel' => null];
		$this->installer()->install(id: 'fixture-case-type');
		$this->register->rows['caseType'][self::CASE_TYPE]['handlingModel'] = 'cmmn';

		$outcome = $this->installer(root: $this->raisedVersion())->install(id: 'fixture-case-type');

		$caseType = $this->register->row('caseType', self::CASE_TYPE);
		$this->assertSame(expected: [], actual: $outcome['kept']);
		$this->assertSame(expected: 'The description 1.1.0 ships.', actual: $caseType['description']);
		$this->assertSame(expected: 'cmmn', actual: $caseType['handlingModel'], message: 'a field the package does not ship keeps the value set here');
	}//end testARefreshKeepsWhatThisInstanceAddedAndNeverAnEdit()

	/**
	 * The fixture at its own version, with a changed case type description.
	 *
	 * @return string The package root.
	 */
	private function changedContentSameVersion(): string {
		return $this->variant(
			change: static function (string $dir): void {
				self::editJson(
					path: $dir . '/case-type.json',
					change: static function (array $c): array {
						$c['caseType']['description'] = 'The description the same version now ships.';
						return $c;
					}
				);
			}
		);
	}//end changedContentSameVersion()

	/**
	 * The same version with changed files is applied, and is current after that.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/package-reinstall-applies-changed-content/specs/case-types/spec.md
	 */
	public function testTheSameVersionWithChangedContentRefreshesUntouchedRows(): void {
		$this->installer()->install(id: 'fixture-case-type');
		$root = $this->changedContentSameVersion();

		$outcome = $this->installer(root: $root)->install(id: 'fixture-case-type');

		$this->assertSame(expected: PackageInstaller::INSTALLED, actual: $outcome['status'], message: $outcome['message']);
		$this->assertSame(
			expected: 'The description the same version now ships.',
			actual: $this->register->row('caseType', self::CASE_TYPE)['description']
		);
		$this->assertSame(expected: 1, actual: $outcome['refreshed']);

		$writes = $this->register->writes;
		$again = $this->installer(root: $root)->install(id: 'fixture-case-type');
		$this->assertSame(expected: PackageInstaller::CURRENT, actual: $again['status'], message: $again['message']);
		$this->assertSame(expected: $writes, actual: $this->register->writes);
	}//end testTheSameVersionWithChangedContentRefreshesUntouchedRows()

	/**
	 * A package record from before the digest installs once more, writing no row, and is current after that.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/package-reinstall-applies-changed-content/specs/case-types/spec.md
	 */
	public function testARecordWithoutADigestInstallsOnceMore(): void {
		$this->installer()->install(id: 'fixture-case-type');
		$this->stampLedger(
			targetObject: 'fixture-case-type',
			fingerprint: (new ShippedFingerprint())->hashOf(object: ['package' => 'fixture-case-type', 'version' => '1.0.0'])
		);

		$outcome = $this->installer()->install(id: 'fixture-case-type');
		$again = $this->installer()->install(id: 'fixture-case-type');

		$this->assertSame(expected: PackageInstaller::INSTALLED, actual: $outcome['status'], message: $outcome['message']);
		$this->assertSame(expected: 0, actual: $outcome['written']);
		$this->assertSame(expected: PackageInstaller::CURRENT, actual: $again['status'], message: $again['message']);
	}//end testARecordWithoutADigestInstallsOnceMore()

	/**
	 * `--over-local-changes` takes every key the package ships over an edit here, and keeps a key it does not ship.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/package-reinstall-applies-changed-content/specs/case-types/spec.md
	 */
	public function testOverLocalChangesTakesThePackagesKeysAndKeepsTheRest(): void {
		$this->installer()->install(id: 'fixture-case-type');
		$shippedTitle = $this->register->row('caseType', self::CASE_TYPE)['title'];
		$this->register->rows['caseType'][self::CASE_TYPE]['title'] = 'Edited here';
		$this->register->rows['caseType'][self::CASE_TYPE]['handlingModel'] = 'cmmn';

		$kept = $this->installer()->install(id: 'fixture-case-type');
		$this->assertSame(
			expected: 'Edited here',
			actual: $this->register->row('caseType', self::CASE_TYPE)['title'],
			message: 'without the option an edit survives'
		);

		$outcome = $this->installer()->installOverLocalChanges(id: 'fixture-case-type');

		$caseType = $this->register->row('caseType', self::CASE_TYPE);
		$this->assertSame(expected: PackageInstaller::CURRENT, actual: $kept['status'], message: $kept['message']);
		$this->assertSame(expected: PackageInstaller::INSTALLED, actual: $outcome['status'], message: $outcome['message']);
		$this->assertSame(expected: $shippedTitle, actual: $caseType['title']);
		$this->assertSame(expected: 'cmmn', actual: $caseType['handlingModel'], message: 'a key the package does not ship keeps its value');
		$this->assertSame(expected: [], actual: $outcome['kept']);
		$this->assertSame(expected: '1.0.0', actual: $this->ledgerRowOf(targetObject: self::CASE_TYPE)['setVersion']);
	}//end testOverLocalChangesTakesThePackagesKeysAndKeepsTheRest()

	/**
	 * Overwrite the fingerprint the ledger holds for one row.
	 *
	 * @param string $targetObject The row id.
	 * @param string $fingerprint  The fingerprint.
	 *
	 * @return void
	 */
	private function stampLedger(string $targetObject, string $fingerprint): void {
		foreach ($this->register->rows['shippedOrigin'] as $id => $row) {
			if (($row['targetObject'] ?? '') === $targetObject) {
				$this->register->rows['shippedOrigin'][$id]['fingerprint'] = $fingerprint;
				return;
			}
		}

		$this->fail(message: "No ledger row for {$targetObject}");
	}//end stampLedger()
}//end class
