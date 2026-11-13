<?php

/**
 * Lifecycle Case Type Rules Test
 *
 * Pins what the lifecycle reads from a case type row: the role each ending
 * act requires, whether the process owns the status and which process that
 * is, the silence auto-close opt-in and its day counts, and that a missing
 * row, an unconfigured register or a failing read answers the safe default.
 * Also pins when a dossier document store is complete.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Lifecycle;

use OCA\Dossiq\Service\Lifecycle\DossierDocumentStore;
use OCA\Dossiq\Service\Lifecycle\LifecycleCaseTypeRules;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * Tests for LifecycleCaseTypeRules and DossierDocumentStore.
 */
#[CoversClass(LifecycleCaseTypeRules::class)]
#[CoversClass(DossierDocumentStore::class)]
final class LifecycleCaseTypeRulesTest extends TestCase {
	/**
	 * Rules over an object service holding the given case type rows.
	 *
	 * @param array<string,array<string,mixed>> $rows     Case type rows by id.
	 * @param array<string,string>              $config   Config values.
	 * @param bool                              $hasStore Whether OpenRegister answers.
	 * @param object|null                       $service  The object service, when given.
	 *
	 * @return LifecycleCaseTypeRules
	 */
	private function rules(
		array $rows,
		array $config = ['register' => 'dossiq', 'case_type_schema' => 'caseType'],
		bool $hasStore = true,
		?object $service = null
	): LifecycleCaseTypeRules {
		$service ??= new class ($rows) {
			/**
			 * The lookups made, as register/schema/id.
			 *
			 * @var array<int,string>
			 */
			public array $finds = [];

			/**
			 * Build the double.
			 *
			 * @param array<string,array<string,mixed>> $rows Case type rows by id.
			 */
			public function __construct(private readonly array $rows) {
			}

			/**
			 * Find a row.
			 *
			 * @param string     $id       The id.
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 *
			 * @return array<string,mixed>|null
			 */
			public function find(string $id, int|string $register, int|string $schema): ?array {
				$this->finds[] = $register . '/' . $schema . '/' . $id;
				return ($this->rows[$id] ?? null);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(($hasStore === true ? $service : null));
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new LifecycleCaseTypeRules(settingsService: $settings);
	}//end rules()

	/**
	 * Each ending act reads its own role field, trimmed; an unknown act has no role.
	 *
	 * @return void
	 */
	public function testRolePerAct(): void {
		$rules = $this->rules(rows: ['t1' => ['finishingRole' => ' behandelaar ', 'abortingRole' => 'teamleider', 'archivingRole' => 'archivaris']]);

		self::assertSame(expected: 'behandelaar', actual: $rules->roleFor(caseTypeId: 't1', act: 'finish'));
		self::assertSame(expected: 'teamleider', actual: $rules->roleFor(caseTypeId: 't1', act: 'abort'));
		self::assertSame(expected: 'archivaris', actual: $rules->roleFor(caseTypeId: 't1', act: 'archive'));
		self::assertSame(expected: '', actual: $rules->roleFor(caseTypeId: 't1', act: 'suspend'));
	}//end testRolePerAct()

	/**
	 * The process-owned declaration values, and whether they count.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function declarations(): array {
		return [
			'true'          => [true, true],
			'one'           => [1, true],
			'string one'    => ['1', true],
			'string true'   => ['true', true],
			'false'         => [false, false],
			'string false'  => ['false', false],
			'string yes'    => ['yes', false],
			'two'           => [2, false],
		];
	}//end declarations()

	/**
	 * Only true, 1, "1" and "true" declare the status process-owned.
	 *
	 * @param mixed $declared The declared value.
	 * @param bool  $expected Whether the process owns the status.
	 *
	 * @return void
	 */
	#[DataProvider('declarations')]
	public function testProcessOwnsStatus(mixed $declared, bool $expected): void {
		$rules = $this->rules(rows: ['t1' => ['processOwnedStatus' => $declared]]);

		self::assertSame(expected: $expected, actual: $rules->processOwnsStatus(caseTypeId: 't1'));
	}//end testProcessOwnsStatus()

	/**
	 * The process is the workflow definition id, as a string or from an object by id or @self id.
	 *
	 * @return void
	 */
	public function testProcessOf(): void {
		$rules = $this->rules(
			rows: [
				'plain'  => ['workflowDefinition' => ' wf-1 '],
				'object' => ['workflowDefinition' => ['id' => 'wf-2', '@self' => ['id' => 'other']]],
				'self'   => ['workflowDefinition' => ['@self' => ['id' => 'wf-3']]],
				'none'   => [],
			]
		);

		self::assertSame(expected: 'wf-1', actual: $rules->processOf(caseTypeId: 'plain'));
		self::assertSame(expected: 'wf-2', actual: $rules->processOf(caseTypeId: 'object'));
		self::assertSame(expected: 'wf-3', actual: $rules->processOf(caseTypeId: 'self'));
		self::assertSame(expected: '', actual: $rules->processOf(caseTypeId: 'none'));
	}//end testProcessOf()

	/**
	 * Auto-close on silence is opted in only by a literal true, and only then has silence days.
	 *
	 * @return void
	 */
	public function testAutoCloseOptInAndSilenceDays(): void {
		$rules = $this->rules(
			rows: [
				'on'       => ['autoCloseOnSilence' => true, 'autoCloseAfterSilenceDays' => '30'],
				'negative' => ['autoCloseOnSilence' => true, 'autoCloseAfterSilenceDays' => -5],
				'truthy'   => ['autoCloseOnSilence' => 'true', 'autoCloseAfterSilenceDays' => 30],
				'off'      => ['autoCloseOnSilence' => false, 'autoCloseAfterSilenceDays' => 30],
			]
		);

		self::assertTrue(condition: $rules->autoCloseOptedIn(caseTypeId: 'on'));
		self::assertSame(expected: 30, actual: $rules->silenceDays(caseTypeId: 'on'));
		self::assertSame(expected: 0, actual: $rules->silenceDays(caseTypeId: 'negative'));
		self::assertFalse(condition: $rules->autoCloseOptedIn(caseTypeId: 'truthy'));
		self::assertSame(expected: 0, actual: $rules->silenceDays(caseTypeId: 'truthy'));
		self::assertFalse(condition: $rules->autoCloseOptedIn(caseTypeId: 'off'));
		self::assertSame(expected: 0, actual: $rules->silenceDays(caseTypeId: 'off'));
	}//end testAutoCloseOptInAndSilenceDays()

	/**
	 * The warning lead time is the declared positive number of days, else seven.
	 *
	 * @return void
	 */
	public function testWarningDaysDefaultToSeven(): void {
		$rules = $this->rules(rows: ['set' => ['autoCloseWarningDays' => '3'], 'zero' => ['autoCloseWarningDays' => 0], 'none' => []]);

		self::assertSame(expected: 3, actual: $rules->warningDays(caseTypeId: 'set'));
		self::assertSame(expected: LifecycleCaseTypeRules::DEFAULT_WARNING_DAYS, actual: $rules->warningDays(caseTypeId: 'zero'));
		self::assertSame(expected: 7, actual: $rules->warningDays(caseTypeId: 'none'));
	}//end testWarningDaysDefaultToSeven()

	/**
	 * The row is read from the configured register and case type schema by its id.
	 *
	 * @return void
	 */
	public function testRowIsReadFromTheConfiguredRegisterAndSchema(): void {
		$service = new class {
			/**
			 * The lookups made.
			 *
			 * @var array<int,string>
			 */
			public array $finds = [];

			/**
			 * Find a row.
			 *
			 * @param string     $id       The id.
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 *
			 * @return array<string,mixed>
			 */
			public function find(string $id, int|string $register, int|string $schema): array {
				$this->finds[] = $register . '/' . $schema . '/' . $id;
				return ['finishingRole' => 'behandelaar'];
			}
		};
		$rules = $this->rules(rows: [], config: ['register' => 'reg-5', 'case_type_schema' => 'sch-9'], service: $service);

		self::assertSame(expected: 'behandelaar', actual: $rules->roleFor(caseTypeId: 't1', act: 'finish'));
		self::assertSame(expected: ['reg-5/sch-9/t1'], actual: $service->finds);
	}//end testRowIsReadFromTheConfiguredRegisterAndSchema()

	/**
	 * Without a case type id, an unconfigured schema, OpenRegister or a readable row,
	 * every rule answers its safe default.
	 *
	 * @return void
	 */
	public function testMissingRowAnswersDefaults(): void {
		$failing = new class {
			/**
			 * Fail every lookup.
			 *
			 * @param string     $id       The id.
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 *
			 * @return array<string,mixed>
			 */
			public function find(string $id, int|string $register, int|string $schema): array {
				throw new RuntimeException('register unavailable');
			}
		};

		$cases = [
			'no id'             => [$this->rules(rows: ['' => ['processOwnedStatus' => true]]), ''],
			'unknown id'        => [$this->rules(rows: []), 't1'],
			'no schema'         => [$this->rules(rows: ['t1' => ['processOwnedStatus' => true]], config: ['register' => 'dossiq']), 't1'],
			'no OpenRegister'   => [$this->rules(rows: ['t1' => ['processOwnedStatus' => true]], hasStore: false), 't1'],
			'failing read'      => [$this->rules(rows: [], service: $failing), 't1'],
		];
		foreach ($cases as $name => [$rules, $id]) {
			self::assertSame(
				expected: ['', false, '', false, 0, 7],
				actual: [
					$rules->roleFor(caseTypeId: $id, act: 'finish'),
					$rules->processOwnsStatus(caseTypeId: $id),
					$rules->processOf(caseTypeId: $id),
					$rules->autoCloseOptedIn(caseTypeId: $id),
					$rules->silenceDays(caseTypeId: $id),
					$rules->warningDays(caseTypeId: $id),
				],
				message: $name
			);
		}
	}//end testMissingRowAnswersDefaults()

	/**
	 * A dossier document store is complete only with an object service, a register and both schemas.
	 *
	 * @return void
	 */
	public function testDossierDocumentStoreIsCompleteOnlyWithEveryPart(): void {
		$store = new DossierDocumentStore();
		self::assertFalse(condition: $store->isComplete());

		$store->objectService = new stdClass();
		$store->register      = 'dossiq';
		$store->joinSchema    = 'caseDocument';
		self::assertFalse(condition: $store->isComplete());

		$store->infoSchema = 'document';
		self::assertTrue(condition: $store->isComplete());

		$store->objectService = null;
		self::assertFalse(condition: $store->isComplete());
	}//end testDossierDocumentStoreIsCompleteOnlyWithEveryPart()
}//end class
