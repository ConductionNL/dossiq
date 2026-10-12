<?php

/**
 * Drain tests, including the double-run idempotency proof (task 2.3).
 *
 * The fixture set is the one design.md section 3 names: a fully populated
 * blob, a half-written case (some rows already in OpenRegister from a run
 * that crashed before clearing the blob), an empty case and unmappable
 * cases. The migration runs twice over it; the second run must change
 * nothing: same rows, no extra audit, unmappable cases byte-identical.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CasePlanDrain
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
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CasePlanDrain;

use OCA\Dossiq\Service\CasePlanDrain\CasePlanMigrationService;
use OCA\Dossiq\Service\CasePlanProjectionService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * Tests for {@see CasePlanMigrationService}.
 *
 * @covers \OCA\Dossiq\Service\CasePlanDrain\CasePlanMigrationService
 * @uses   \OCA\Dossiq\Service\CasePlanDrain\CasePlanBlob
 */
final class CasePlanMigrationServiceTest extends TestCase {

	/**
	 * In-memory cases, keyed by uuid.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * In-memory OpenRegister case layer.
	 *
	 * @var object
	 */
	private object $plans;

	/**
	 * Build the fixture set.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = self::objectStore(
			cases: [
				'populated' => self::case(
					id: 'populated',
					blob: json_encode(
						[
							'planItemStates' => ['intake' => 'completed', 'controle' => 'completed', 'besluit' => 'completed'],
							'caseFile' => ['decisionNote' => 'akkoord'],
							'eventLog' => [['at' => '2026-09-01T10:00:00+00:00', 'itemId' => 'controle', 'from' => 'active', 'to' => 'completed']],
						]
					)
				),
				'half' => self::case(id: 'half', blob: ['planItemStates' => ['intake' => 'active', 'controle' => 'active']]),
				'empty' => self::case(id: 'empty', blob: ''),
				'broken' => self::case(id: 'broken', blob: '{not json'),
				'orphan' => self::case(id: 'orphan', blob: ['planItemStates' => ['retired-item' => 'active']]),
				'projected' => self::case(id: 'projected', blob: ['planItemStates' => ['intake' => 'completed']]),
				'undeclared' => self::case(id: 'undeclared', blob: ['planItemStates' => ['intake' => 'active'], 'caseFile' => ['nowhere' => 1]]),
			]
		);
		$this->plans = self::caseLayer();
		// The half-written case: a run created `intake` and crashed.
		$this->plans->seed(object: 'half', key: 'intake', type: 'stage', state: 'active');
		// A case whose plan was projected at case start, in another state.
		$this->plans->seed(object: 'projected', key: 'intake', type: 'stage', state: 'active');
	}//end setUp()

	/**
	 * Running twice converges: same rows, no extra audit, unmappable untouched.
	 *
	 * @return void
	 */
	public function testTheDrainRunTwiceChangesNothingTheSecondTime(): void {
		$service = $this->service();
		$before = $this->objects->cases;

		$first = $this->byCase(reports: $service->migrateAll(dryRun: false));
		$rowsAfterFirst = $this->plans->rows;
		$auditAfterFirst = $this->plans->audit;
		$casesAfterFirst = $this->objects->cases;

		$second = $this->byCase(reports: $service->migrateAll(dryRun: false));

		$this->assertSame(CasePlanMigrationService::MIGRATED, $first['populated']['outcome']);
		$this->assertSame(CasePlanMigrationService::MIGRATED, $first['half']['outcome']);
		$this->assertSame(1, $first['half']['existing']);
		$this->assertArrayNotHasKey('empty', $first);
		$this->assertStringStartsWith('blob_undecodable', $first['broken']['reason']);
		$this->assertSame('orphan_item:retired-item', $first['orphan']['reason']);
		$this->assertStringStartsWith('state_mismatch: intake: completed -> active', $first['projected']['reason']);
		$this->assertSame('case_file_undeclared:nowhere', $first['undeclared']['reason']);

		// Second run: the migrated cases have no blob left, so only the
		// unmappable ones report, and they report the same thing.
		$this->assertSame(['broken', 'orphan', 'projected', 'undeclared'], array_keys($second));
		$this->assertSame($rowsAfterFirst, $this->plans->rows);
		$this->assertSame($auditAfterFirst, $this->plans->audit);
		$this->assertSame($casesAfterFirst, $this->objects->cases);

		foreach (['broken', 'orphan', 'projected', 'undeclared', 'empty'] as $untouched) {
			$this->assertSame($before[$untouched], $this->objects->cases[$untouched], $untouched . ' must be byte-identical');
		}
	}//end testTheDrainRunTwiceChangesNothingTheSecondTime()

	/**
	 * A migrated case carries its states, its case file and an empty blob.
	 *
	 * @return void
	 */
	public function testAMigratedCaseHasRowsInTheRecordedStatesAndNoBlob(): void {
		$this->service()->migrateCaseById(caseId: 'populated', dryRun: false);

		$this->assertSame(
			['intake' => 'completed', 'controle' => 'completed', 'besluit' => 'completed'],
			$this->plans->statesOf(object: 'populated')
		);
		$this->assertSame('', $this->objects->cases['populated']['casePlanState']);
		$this->assertSame('akkoord', $this->objects->cases['populated']['decisionNote']);
		$this->assertSame('dossiq', $this->plans->apps[0]);
		// One history entry imported, for a row this run created.
		$this->assertSame(1, $this->plans->audit['populated']['imported']);
	}//end testAMigratedCaseHasRowsInTheRecordedStatesAndNoBlob()

	/**
	 * A dry run reports and writes nothing at all.
	 *
	 * @return void
	 */
	public function testADryRunWritesNothing(): void {
		$before = $this->objects->cases;
		$rows = $this->plans->rows;

		$report = $this->service()->migrateCaseById(caseId: 'populated', dryRun: true);

		$this->assertSame(CasePlanMigrationService::WOULD_MIGRATE, $report['outcome']);
		$this->assertSame($before, $this->objects->cases);
		$this->assertSame($rows, $this->plans->rows);
	}//end testADryRunWritesNothing()

	/**
	 * An OpenRegister refusal keeps the blob and reports the reason.
	 *
	 * @return void
	 */
	public function testARefusalFromOpenRegisterKeepsTheBlob(): void {
		$this->plans->refuseWith = 'unreachable state';
		$before = $this->objects->cases['populated'];

		$report = $this->service()->migrateCaseById(caseId: 'populated', dryRun: false);

		$this->assertSame('refused: unreachable state', $report['reason']);
		$this->assertSame($before, $this->objects->cases['populated']);
	}//end testARefusalFromOpenRegisterKeepsTheBlob()

	/**
	 * An OpenRegister without ensureItems is named, and nothing is written.
	 *
	 * @return void
	 */
	public function testACaseLayerWithoutEnsureItemsIsReported(): void {
		$service = new CasePlanMigrationService($this->settings(layer: new \stdClass()), $this->projection(), $this->createMock(LoggerInterface::class));

		$report = $service->migrateCaseById(caseId: 'populated', dryRun: false);

		$this->assertSame('case_layer_lacks_ensure_items', $report['reason']);
		$this->assertNotSame('', $this->objects->cases['populated']['casePlanState']);
	}//end testACaseLayerWithoutEnsureItemsIsReported()

	/**
	 * A case type with no published model leaves its cases intact.
	 *
	 * @return void
	 */
	public function testACaseTypeWithoutAModelIsUnmappable(): void {
		$projection = $this->createMock(CasePlanProjectionService::class);
		$projection->method('definitionForCaseType')->willThrowException(new UnexpectedValueException('no_published_case_model'));
		$service = new CasePlanMigrationService($this->settings(layer: $this->plans), $projection, $this->createMock(LoggerInterface::class));

		$this->assertSame('no_published_case_model', $service->migrateCaseById(caseId: 'populated', dryRun: false)['reason']);
	}//end testACaseTypeWithoutAModelIsUnmappable()

	/**
	 * The service under test, wired to the fixtures.
	 *
	 * @return CasePlanMigrationService The service.
	 */
	private function service(): CasePlanMigrationService {
		return new CasePlanMigrationService($this->settings(layer: $this->plans), $this->projection(), $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * A projection that answers the fixture definition.
	 *
	 * @return CasePlanProjectionService The stub.
	 */
	private function projection(): CasePlanProjectionService {
		$projection = $this->createMock(CasePlanProjectionService::class);
		$projection->method('definitionForCaseType')->willReturn(
			[
				'settings' => [],
				'items' => [
					['key' => 'intake', 'type' => 'stage', 'children' => [['key' => 'controle', 'type' => 'humanTask']]],
					['key' => 'besluit', 'type' => 'milestone'],
				],
			]
		);

		return $projection;
	}//end projection()

	/**
	 * Settings that resolve the fixture stores.
	 *
	 * @param object $layer The case layer.
	 *
	 * @return SettingsService The stub.
	 */
	private function settings(object $layer): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getOpenRegisterClass')->willReturn($layer);
		$settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', '7'],
				['case_schema', '', '11'],
				['case_type_schema', '', '12'],
			]
		);

		return $settings;
	}//end settings()

	/**
	 * Reports keyed by case.
	 *
	 * @param array<int, array<string, mixed>> $reports The reports.
	 *
	 * @return array<string, array<string, mixed>> Keyed by case uuid.
	 */
	private function byCase(array $reports): array {
		$out = [];
		foreach ($reports as $report) {
			$out[$report['caseId']] = $report;
		}

		ksort($out);

		return $out;
	}//end byCase()

	/**
	 * One CMMN case.
	 *
	 * @param string $id   The uuid.
	 * @param mixed  $blob The stored blob.
	 *
	 * @return array<string, mixed> The case.
	 */
	private static function case(string $id, mixed $blob): array {
		return ['id' => $id, 'caseType' => 'ct-cmmn', 'decisionNote' => null, 'casePlanState' => $blob];
	}//end case()

	/**
	 * An in-memory ObjectService holding one CMMN case type and the cases.
	 *
	 * @param array<string, array<string, mixed>> $cases The cases.
	 *
	 * @return object The store.
	 */
	private static function objectStore(array $cases): object {
		return new class($cases) {
			/**
			 * Hold the cases.
			 *
			 * @param array<string, array<string, mixed>> $cases The cases.
			 */
			public function __construct(public array $cases) {
			}//end __construct()

			/**
			 * Run the operation; the store has no identities.
			 *
			 * @param callable $operation The work.
			 *
			 * @return mixed Its result.
			 */
			public function runAsSystem(callable $operation): mixed {
				return $operation();
			}

			/**
			 * Answer the case type search (schema 12) and the case search (schema 11).
			 *
			 * @param array<string, mixed> $query        The query.
			 * @param bool                 $_rbac         Ignored: the store has no access rules.
			 * @param bool                 $_multitenancy Ignored: the store has one tenant.
			 *
			 * @return array<int, array<string, mixed>> The hits.
			 *
			 * @SuppressWarnings(PHPMD.UnusedFormalParameter) OpenRegister's signature.
			 */
			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($query['@self']['schema'] === 12) {
					return [['id' => 'ct-cmmn', 'handlingModel' => 'cmmn']];
				}

				if (($query['_offset'] ?? 0) > 0) {
					return [];
				}

				return array_values(array_filter($this->cases, static fn (array $c): bool => $c['caseType'] === $query['caseType']));
			}

			/**
			 * One case.
			 *
			 * @param string     $id       The uuid.
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 *
			 * @return array<string, mixed>|null The case.
			 */
			public function find(string $id, int|string $register, int|string $schema): ?array {
				unset($register, $schema);

				return ($this->cases[$id] ?? null);
			}

			/**
			 * Merge a partial change.
			 *
			 * @param string               $objectId The uuid.
			 * @param array<string, mixed> $data     The change.
			 * @param int|string           $register The register.
			 * @param int|string           $schema   The schema.
			 *
			 * @return array<string, mixed> The case after the write.
			 */
			public function patchObject(string $objectId, array $data, int|string $register, int|string $schema): array {
				unset($register, $schema);
				$this->cases[$objectId] = array_merge($this->cases[$objectId], $data);

				return $this->cases[$objectId];
			}
		};
	}//end objectStore()

	/**
	 * An in-memory case layer whose ensureItems() is convergent the way
	 * OpenRegister's is: existing rows are never touched, audit only for
	 * rows created in the call.
	 *
	 * @return object The layer.
	 */
	private static function caseLayer(): object {
		return new class {
			/**
			 * Rows per object, key => [type, state].
			 *
			 * @var array<string, array<string, array{type: string, state: string}>>
			 */
			public array $rows = [];

			/**
			 * Audit counts per object.
			 *
			 * @var array<string, array{created: int, imported: int}>
			 */
			public array $audit = [];

			/**
			 * The app ids it was called as.
			 *
			 * @var array<int, string>
			 */
			public array $apps = [];

			/**
			 * When set, every call is refused with this message.
			 *
			 * @var string|null
			 */
			public ?string $refuseWith = null;

			/**
			 * Pre-create a row.
			 *
			 * @param string $object The object.
			 * @param string $key    The item key.
			 * @param string $type   The type.
			 * @param string $state  The state.
			 *
			 * @return void
			 */
			public function seed(string $object, string $key, string $type, string $state): void {
				$this->rows[$object][$key] = ['type' => $type, 'state' => $state];
			}

			/**
			 * The states held for one object.
			 *
			 * @param string $object The object.
			 *
			 * @return array<string, string> key => state.
			 */
			public function statesOf(string $object): array {
				return array_map(static fn (array $row): string => $row['state'], ($this->rows[$object] ?? []));
			}

			/**
			 * Create the missing rows, carrying states; audit only those.
			 *
			 * @param string               $objectUuid The object.
			 * @param int|null             $registerId The register.
			 * @param int|null             $schemaId   The schema.
			 * @param array<string, mixed> $definition The definition.
			 * @param array<int, mixed>    $history    The history.
			 * @param string               $app        The app.
			 *
			 * @return array<string, mixed> created, existing, items.
			 */
			public function ensureItems(string $objectUuid, ?int $registerId, ?int $schemaId, array $definition, array $history, string $app): array {
				unset($registerId, $schemaId);
				if ($this->refuseWith !== null) {
					throw new \RuntimeException($this->refuseWith);
				}

				$this->apps[] = $app;
				$created = [];
				$existing = [];
				$walk = function (array $nodes) use (&$walk, $objectUuid, &$created, &$existing): void {
					foreach ($nodes as $node) {
						$key = $node['key'];
						if (isset($this->rows[$objectUuid][$key]) === true) {
							$existing[] = $key;
						} else {
							$this->rows[$objectUuid][$key] = ['type' => $node['type'], 'state' => ($node['state'] ?? 'available')];
							$created[] = $key;
						}

						$walk($node['children'] ?? []);
					}
				};
				$walk($definition['items']);

				$imported = count(array_filter($history, static fn (array $entry): bool => in_array($entry['item'], $created, true)));
				if ($created !== []) {
					$this->audit[$objectUuid] = [
						'created' => (($this->audit[$objectUuid]['created'] ?? 0) + count($created)),
						'imported' => (($this->audit[$objectUuid]['imported'] ?? 0) + $imported),
					];
				}

				$items = [];
				foreach (($this->rows[$objectUuid] ?? []) as $key => $row) {
					$items[] = ['key' => $key, 'type' => $row['type'], 'state' => $row['state']];
				}

				return ['created' => $created, 'existing' => $existing, 'items' => $items];
			}
		};
	}//end caseLayer()
}//end class
