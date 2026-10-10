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

use OCA\Dossiq\Service\CaseDefinition\PackageCapabilities;
use OCA\Dossiq\Service\CaseDefinition\PackageDirectory;
use OCA\Dossiq\Service\CaseDefinition\PackageIds;
use OCA\Dossiq\Service\CaseDefinition\PackageInstaller;
use OCA\Dossiq\Service\CaseDefinition\PackageValidator;
use OCA\Dossiq\Service\CaseDefinition\PackageWriter;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Starter\ShippedConfigurationService;
use OCA\Dossiq\Service\Starter\ShippedFingerprint;
use OCA\Dossiq\Service\Starter\ShippedSets;
use OCA\Dossiq\Service\Starter\StarterStore;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
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

		return parent::saveObject($object, $register, $schema, $uuid, $_rbac, $_multitenancy);
	}//end saveObject()

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
 * Unit tests for the package installer, validator rules and writer.
 *
 * @covers \OCA\Dossiq\Service\CaseDefinition\PackageInstaller
 * @covers \OCA\Dossiq\Service\CaseDefinition\ShippedPackageRules
 * @covers \OCA\Dossiq\Service\CaseDefinition\PackageDirectory
 * @covers \OCA\Dossiq\Service\CaseDefinition\PackageCapabilities
 */
class PackageInstallerTest extends TestCase {

	/** The fixture package's case type id. */
	private const CASE_TYPE = '0f1c0000-0000-4000-8000-000000000001';

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
	 * A fresh register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->register = new PackageTestRegister();
		$this->appConfigValues = [];
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
			new PackageWriter(new NullLogger(), $settings),
			$ledger,
			$appConfig,
			new NullLogger(),
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

		$ledger = $this->register->all('shippedOrigin');
		$this->assertCount(6, $ledger, 'every written row has a ledger row');
		foreach ($ledger as $row) {
			$this->assertSame('fixture-case-type', $row['set']);
			$this->assertSame('1.0.0', $row['setVersion']);
		}
	}//end testAPackageWritesEveryRowAndRecordsEachInTheLedger()

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
}//end class
