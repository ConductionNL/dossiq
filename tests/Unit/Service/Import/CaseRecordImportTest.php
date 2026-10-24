<?php

/**
 * Every record a case type declares an import for is imported exactly once:
 * its case written, its term carried, and only then the source stamped. The
 * declaration under test is the one the seeded Woo case type carries
 * (register.d/81-woo-verzoek.json), over opencatalogi's own request shape.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Import
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Import;

use DateTime;
use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Service\Import\CaseRecordImport;
use OCA\Dossiq\Service\Import\RecordCaseMapping;
use OCA\Dossiq\Service\Import\RecordImportStore;
use OCA\Dossiq\Service\Term\TermCarryOver;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Runs the import over opencatalogi's own request shape (tests/Fixtures/opencatalogi-woo-requests.json)
 * in an in-memory register, with the term engine played by doubles.
 *
 * @covers \OCA\Dossiq\Service\Import\CaseRecordImport
 * @covers \OCA\Dossiq\Service\Import\RecordCaseMapping
 * @covers \OCA\Dossiq\Service\Import\RecordImportStore
 * @covers \OCA\Dossiq\Service\Import\RecordValues
 * @covers \OCA\Dossiq\Service\Import\RecordImportReferences
 * @uses   \OCA\Dossiq\Service\Term\TermCarryOver
 * @uses   \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 * @uses \OCA\Dossiq\Service\Term\DayCount
 */
class CaseRecordImportTest extends TestCase {

	use MakesCaseDateNormaliser;

	/**
	 * The seeded Woo case type's version in use (version 2), which carries the import.
	 */
	private const CASE_TYPE = '3c0f5a00-0000-4000-a000-00000000a002';

	/**
	 * The first version, superseded, which shares the identifier.
	 */
	private const FIRST_VERSION = '3c0f5a00-0000-4000-a000-00000000a001';

	/**
	 * Its status uuids, as its declaration maps them.
	 */
	private const STAGE = [
		'received' => '3c0f5a00-0000-4000-a000-00000000b011',
		'in_progress' => '3c0f5a00-0000-4000-a000-00000000b012',
		'awaiting_clarification' => '3c0f5a00-0000-4000-a000-00000000b012',
		'decided' => '3c0f5a00-0000-4000-a000-00000000b015',
		'withdrawn' => '3c0f5a00-0000-4000-a000-00000000b015',
	];

	/**
	 * The result a withdrawn request ends with.
	 */
	private const RESULT_WITHDRAWN = '3c0f5a00-0000-4000-a000-00000000c014';

	/**
	 * The register both apps' rows live in.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The statutory instances per case, as the case-created listener binds them.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $instances = [];

	/**
	 * Every timer the engine armed, by instance.
	 *
	 * @var array<int, string>
	 */
	private array $armed = [];

	/**
	 * Every instance whose timers were cancelled.
	 *
	 * @var array<int, string>
	 */
	private array $cancelled = [];

	/**
	 * Every timer suspended.
	 *
	 * @var array<int, string>
	 */
	private array $suspended = [];

	/**
	 * Whether the engine arms.
	 *
	 * @var bool
	 */
	private bool $engine = true;

	/**
	 * The days each recorded extension was said to add.
	 *
	 * @var array<int, int|null>
	 */
	private array $extensionDays = [];

	/**
	 * The fixture rows seeded into the opencatalogi register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryRegister();
		$fixture = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/Fixtures/opencatalogi-woo-requests.json'), true);
		foreach ($fixture['requests'] as $row) {
			$this->store->seed(schema: 'wooRequest', uuid: $row['id'], row: $row);
		}

		foreach (['81-woo-verzoek.json', '81-woo-verzoek-version-2.json'] as $file) {
			$fragment = json_decode((string)file_get_contents(dirname(__DIR__, 4) . '/lib/Settings/register.d/' . $file), true);
			foreach ($fragment['components']['objects'] as $object) {
				$id = (string)($object['id'] ?? '');
				if ($id === self::CASE_TYPE || $id === self::FIRST_VERSION) {
					unset($object['@self']);
					$this->store->seed(schema: 'caseType', uuid: $id, row: $object);
				}
			}
		}
	}//end setUp()

	/**
	 * The fixture row of one status.
	 *
	 * @param string $status The status.
	 *
	 * @return array<string, mixed>
	 */
	private function source(string $status): array {
		foreach ($this->store->all('wooRequest') as $row) {
			if ($row['status'] === $status) {
				return $row;
			}
		}

		return [];
	}//end source()

	/**
	 * Keep only the source rows of these statuses.
	 *
	 * @param array<int, string> $statuses The statuses kept.
	 *
	 * @return void
	 */
	private function only(array $statuses): void {
		foreach ($this->store->all('wooRequest') as $row) {
			if (in_array($row['status'], $statuses, true) === false) {
				unset($this->store->rows['wooRequest'][$row['id']]);
			}
		}
	}//end only()

	/**
	 * The import as the container builds it.
	 *
	 * @param bool                  $installed Whether opencatalogi is installed.
	 * @param InMemoryRegister|null $store     Another store, when the test needs one.
	 *
	 * @return CaseRecordImport
	 */
	private function import(bool $installed = true, ?InMemoryRegister $store = null): CaseRecordImport {
		$store = ($store ?? $this->store);

		/** @var SettingsService&MockObject $settings */
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'case_schema' => 'case',
				'result_schema' => 'result',
				'case_type_schema' => 'caseType',
			][$key] ?? $default
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(fn (string $app): bool => ($app === 'opencatalogi' && $installed === true) || $app === 'dossiq');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-20T09:00:00+02:00'));

		$dates = $this->caseDates();

		return new CaseRecordImport(
			settingsService: $settings,
			appManager: $apps,
			mapping: new RecordCaseMapping(dates: $dates),
			term: new TermCarryOver(terms: $this->terms($store), timers: $this->timers(), dates: $dates),
			store: new RecordImportStore(settingsService: $settings, mapping: new RecordCaseMapping(dates: $dates), time: $time, logger: $this->createMock(LoggerInterface::class)),
		);
	}//end import()

	/**
	 * The Woo case type's declared import, run once.
	 *
	 * @param bool                  $installed Whether opencatalogi is installed.
	 * @param InMemoryRegister|null $store     Another store.
	 * @param bool                  $dryRun    Count only.
	 *
	 * @return array<string, mixed> The one import's answer.
	 */
	private function runImport(bool $installed = true, ?InMemoryRegister $store = null, bool $dryRun = false): array {
		$method = ($dryRun === true) ? 'dryRun' : 'run';
		$answer = $this->import(installed: $installed, store: $store)->{$method}(caseType: self::CASE_TYPE);
		self::assertSame(self::CASE_TYPE, $answer['caseType']);
		self::assertCount(1, $answer['imports']);

		return $answer['imports'][0];
	}//end runImport()

	/**
	 * The term service: binds a fresh P28D instance per written case, as
	 * DeadlineCaseCreatedListener does, and keeps every patch.
	 *
	 * @param InMemoryRegister $store The store the cases are in.
	 *
	 * @return TermijnService
	 */
	private function terms(InMemoryRegister $store): TermijnService {
		$terms = $this->createMock(TermijnService::class);
		$terms->method('instancesForCase')->willReturnCallback(
			function (string $caseId) use ($store): array {
				$case = $store->row('case', $caseId);
				if ($case === []) {
					return [];
				}

				$this->instances[$caseId] ??= [
					'id' => 'term-' . $caseId,
					'case' => $caseId,
					'kind' => 'statutory',
					'status' => 'lopend',
					'startDate' => $case['startDate'],
					'endDateCurrent' => (new DateTimeImmutable($case['startDate']))->modify('+28 days')->format('Y-m-d'),
					'engineTimerId' => 'fresh-' . $caseId,
				];

				return [$this->instances[$caseId]];
			}
		);
		$terms->method('updateTermijnInstance')->willReturnCallback(
			function (string $termInstanceId, array $patch): array {
				$caseId = substr($termInstanceId, strlen('term-'));
				$this->instances[$caseId] = array_merge($this->instances[$caseId], $patch);
				return $this->instances[$caseId];
			}
		);
		$terms->method('recordEvent')->willReturnCallback(
			function (string $termInstanceId, string $type, string $basis = '', string $rationale = '', ?int $daysImpact = null): array {
				$this->extensionDays[] = $daysImpact;
				return [];
			}
		);
		$terms->method('markTermijnCompleted')->willReturnCallback(
			function (string $termInstanceId): array {
				$caseId = substr($termInstanceId, strlen('term-'));
				$this->instances[$caseId]['status'] = 'completed';
				return $this->instances[$caseId];
			}
		);

		return $terms;
	}//end terms()

	/**
	 * The timer service, recording what it was asked.
	 *
	 * @return TermijnTimerService
	 */
	private function timers(): TermijnTimerService {
		$timers = $this->createMock(TermijnTimerService::class);
		$timers->method('armBeslistermijn')->willReturnCallback(
			function (array $instance): ?string {
				if ($this->engine === false) {
					return null;
				}

				$this->armed[] = $instance['id'] . '@' . $instance['endDateCurrent'];
				return 'timer-' . count($this->armed);
			}
		);
		$timers->method('cancelForInstance')->willReturnCallback(
			function (string $instanceId): int {
				$this->cancelled[] = $instanceId;
				return 1;
			}
		);
		$timers->method('suspendBeslistermijn')->willReturnCallback(
			function (array $instance): bool {
				$this->suspended[] = $instance['engineTimerId'];
				return true;
			}
		);

		return $timers;
	}//end timers()

	/**
	 * The one case written for a source.
	 *
	 * @param string $sourceId The source uuid.
	 *
	 * @return array<int, array<string, mixed>> Every case that refers to it.
	 */
	private function casesFor(string $sourceId): array {
		return array_values(
			array_filter(
				$this->store->all('case'),
				static fn (array $case): bool => ($case['wooRequest']['originReference'] ?? '') === $sourceId
			)
		);
	}//end casesFor()

	/**
	 * A running request moves with its own date: the fresh term already ends
	 * on dueAt, so its timer is kept, and the source is stamped with the case.
	 *
	 * @return void
	 */
	public function testARunningRequestMovesWithItsRemainingTime(): void {
		$this->only(['received']);
		$source = $this->source('received');

		$answer = $this->runImport();

		$cases = $this->casesFor($source['id']);
		self::assertCount(1, $cases);
		self::assertSame('2026-03-02', $cases[0]['startDate']);
		self::assertSame(self::STAGE['received'], $cases[0]['status']);
		self::assertSame(self::CASE_TYPE, $cases[0]['caseType']);
		self::assertSame('2026-03-30', $this->instances[$cases[0]['id']]['endDateCurrent']);
		self::assertSame([], $this->armed, 'the fresh timer already ends on dueAt');

		$stamped = $this->store->row('wooRequest', $source['id']);
		self::assertSame($cases[0]['id'], $stamped['migratedTo']);
		self::assertSame('2026-10-20T09:00:00+02:00', $stamped['migratedAt']);
		self::assertSame(1, $answer['imported']);
		self::assertSame(0, $answer['unmigrated']);
		self::assertSame(
			[['requestId' => $source['id'], 'reference' => 'WOO-2026-0001', 'caseId' => $cases[0]['id'], 'sourceTimer' => 'woo-term-0001']],
			$answer['migrated']
		);
	}//end testARunningRequestMovesWithItsRemainingTime()

	/**
	 * An extended, suspended request keeps both: the source's later end, one
	 * extension, paused, and its new timer suspended at once.
	 *
	 * @return void
	 */
	public function testAnExtendedSuspendedRequestKeepsBoth(): void {
		$this->only(['awaiting_clarification']);
		$source = $this->source('awaiting_clarification');

		$this->runImport();

		$caseId = $this->casesFor($source['id'])[0]['id'];
		$term = $this->instances[$caseId];
		self::assertSame('2026-11-23', $term['endDateCurrent']);
		self::assertSame(1, $term['countExtensions']);
		// The extension is recorded with what the case type says one is worth:
		// its `extensionPeriod` of P14D, counted as 14 days.
		self::assertSame([14], $this->extensionDays);
		self::assertSame('paused', $term['status']);
		self::assertSame(['term-' . $caseId . '@2026-11-23'], $this->armed);
		self::assertSame(['term-' . $caseId], $this->cancelled, 'the fresh P28D timer is cancelled');
		self::assertSame([$term['engineTimerId']], $this->suspended);
		self::assertTrue($term['timerBreachesAfterLastDay']);
	}//end testAnExtendedSuspendedRequestKeepsBoth()

	/**
	 * All five statuses become a case on their stage; withdrawn gets its result.
	 *
	 * @return void
	 */
	public function testEveryStatusMapsToItsStage(): void {
		$answer = $this->runImport();

		self::assertSame(5, $answer['imported']);
		self::assertSame([], $answer['failed']);
		foreach (self::STAGE as $status => $stage) {
			$cases = $this->casesFor($this->source($status)['id']);
			self::assertCount(1, $cases, $status);
			self::assertSame($stage, $cases[0]['status'], $status);
		}

		$withdrawn = $this->casesFor($this->source('withdrawn')['id'])[0]['id'];
		self::assertSame([['case' => $withdrawn, 'resultType' => self::RESULT_WITHDRAWN, 'id' => 'generated-1']], $this->store->all('result'));
		self::assertSame('completed', $this->instances[$this->casesFor($this->source('decided')['id'])[0]['id']]['status']);
	}//end testEveryStatusMapsToItsStage()

	/**
	 * A second run imports nothing, arms nothing and writes no case.
	 *
	 * @return void
	 */
	public function testASecondRunImportsNothingAndArmsNothing(): void {
		$this->runImport();
		$armed = $this->armed;
		$cases = count($this->store->all('case'));

		$again = $this->runImport();

		self::assertSame(0, $again['imported']);
		self::assertSame(5, $again['alreadyImported']);
		self::assertSame(0, $again['unmigrated']);
		self::assertSame($armed, $this->armed);
		self::assertCount($cases, $this->store->all('case'));
	}//end testASecondRunImportsNothingAndArmsNothing()

	/**
	 * A request whose timer failed to arm is not stamped and is listed as
	 * failed; the next run with the engine back completes the same case.
	 *
	 * @return void
	 */
	public function testAHalfFinishedRequestIsCompletedNotDuplicated(): void {
		$this->only(['in_progress']);
		$source = $this->source('in_progress');
		$this->engine = false;

		$first = $this->runImport();

		self::assertSame(0, $first['imported']);
		self::assertSame(1, $first['unmigrated']);
		self::assertSame($source['id'], $first['failed'][0]['requestId']);
		self::assertStringContainsString('not-armed', $first['failed'][0]['reason']);
		self::assertArrayNotHasKey('migratedTo', $this->store->row('wooRequest', $source['id']));

		$this->engine = true;
		$second = $this->runImport();

		$cases = $this->casesFor($source['id']);
		self::assertCount(1, $cases, 'only one case refers to the source');
		self::assertSame(1, $second['imported']);
		self::assertSame($cases[0]['id'], $this->store->row('wooRequest', $source['id'])['migratedTo']);
		self::assertSame('2026-04-06', $this->instances[$cases[0]['id']]['endDateCurrent']);
		self::assertNotSame('', $this->instances[$cases[0]['id']]['engineTimerId']);
	}//end testAHalfFinishedRequestIsCompletedNotDuplicated()

	/**
	 * Existing cases that cannot be read stop the run: an unread list is not an
	 * empty one, and importing over it would write a half-finished case twice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
	 */
	public function testUnreadableCasesStopTheRunInsteadOfDuplicating(): void {
		$this->only(['in_progress']);
		$store = new class extends InMemoryRegister {
			/**
			 * Fails every read of the case schema.
			 *
			 * @param string               $register      Ignored.
			 * @param string               $schema        The schema slug.
			 * @param array<string, mixed> $filters       Equality filters.
			 * @param bool                 $_rbac         Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(
				string $register,
				string $schema,
				array $filters = [],
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				if ($schema === 'case') {
					throw new RuntimeException('the case table is locked');
				}

				return parent::searchObjectsBySlug($register, $schema, $filters, $_rbac, $_multitenancy);
			}
		};
		$store->rows = $this->store->rows;
		$this->store = $store;
		$casesBefore = count($store->all('case'));

		$answer = $this->runImport(store: $store);

		self::assertSame(0, $answer['imported']);
		self::assertStringContainsString('existing cases could not be read', $answer['failed'][0]['reason']);
		self::assertCount($casesBefore, $store->all('case'), 'no case is written over an unread list');
	}//end testUnreadableCasesStopTheRunInsteadOfDuplicating()

	/**
	 * A stamp opencatalogi refuses, or drops, is a failure, never a migration.
	 *
	 * @return void
	 */
	public function testARefusedStampIsAFailureNotAMigration(): void {
		$this->only(['received', 'in_progress']);
		$store = new class extends InMemoryRegister {
			/**
			 * Refuses the first stamp outright and silently drops the second.
			 *
			 * @param array<string, mixed> $object        The row.
			 * @param int|string           $register      Ignored.
			 * @param int|string           $schema        The schema slug.
			 * @param string|null          $uuid          The uuid.
			 * @param bool                 $_rbac         Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(
				array $object,
				int|string $register = '',
				int|string $schema = '',
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				if ($schema === 'wooRequest' && ($object['status'] ?? '') === 'received') {
					throw new RuntimeException('migratedTo is not a property of wooRequest');
				}

				if ($schema === 'wooRequest') {
					unset($object['migratedTo'], $object['migratedAt']);
				}

				return parent::saveObject(object: $object, register: $register, schema: $schema, uuid: $uuid);
			}
		};
		$store->rows = $this->store->rows;
		$this->store = $store;

		$answer = $this->runImport(store: $store);

		self::assertSame(0, $answer['imported']);
		self::assertSame([], $answer['migrated']);
		self::assertCount(2, $answer['failed']);
		self::assertSame(2, $answer['unmigrated']);
		self::assertStringContainsString('stamp', $answer['failed'][0]['reason']);
	}//end testARefusedStampIsAFailureNotAMigration()

	/**
	 * opencatalogi's own term timer is never cancelled, armed or suspended; it is answered.
	 *
	 * @return void
	 */
	public function testOpencatalogisTimerIsNeverTouched(): void {
		$answer = $this->runImport();

		$touched = array_merge($this->armed, $this->cancelled, $this->suspended);
		foreach ($this->store->all('wooRequest') as $source) {
			foreach ($touched as $id) {
				self::assertStringNotContainsString($source['termTimer'], $id);
			}

			self::assertSame($source['termTimer'], $this->store->row('wooRequest', $source['id'])['termTimer']);
		}

		self::assertEqualsCanonicalizing(
			['woo-term-0001', 'woo-term-0002', 'woo-term-0003', 'woo-term-0004', 'woo-term-0005'],
			array_column($answer['migrated'], 'sourceTimer')
		);
	}//end testOpencatalogisTimerIsNeverTouched()

	/**
	 * Without opencatalogi nothing is read, written or counted.
	 *
	 * @return void
	 */
	public function testWithoutOpencatalogiNothingIsImported(): void {
		$answer = $this->runImport(installed: false);

		self::assertSame(
			['key' => 'opencatalogi-woo-requests', 'sourceApp' => 'opencatalogi', 'installed' => false, 'imported' => 0, 'alreadyImported' => 0, 'failed' => [], 'unmigrated' => 0, 'migrated' => [], 'duplicates' => [], 'repointed' => 0],
			$answer
		);
		self::assertSame(0, $this->store->writes);
	}//end testWithoutOpencatalogiNothingIsImported()

	/**
	 * A dry run counts what has not moved and writes nothing.
	 *
	 * @return void
	 */
	public function testADryRunCountsAndWritesNothing(): void {
		$answer = $this->runImport(dryRun: true);

		self::assertSame(5, $answer['unmigrated']);
		self::assertSame(0, $answer['imported']);
		self::assertSame(0, $this->store->writes);
		self::assertSame([], $this->armed);
	}//end testADryRunCountsAndWritesNothing()
	/**
	 * Every case the declaration writes fits the merged case schema.
	 *
	 * @return void
	 */
	public function testEveryWrittenCaseFitsTheCaseSchema(): void {
		$this->runImport();

		$real = new RealSchemaValidator();
		self::assertCount(5, $this->store->all('case'));
		foreach ($this->store->all('case') as $case) {
			unset($case['id']);
			self::assertSame([], $real->errors(slug: 'case', payload: $case), json_encode($case));
		}

		$extended = $this->casesFor($this->source('in_progress')['id'])[0];
		self::assertSame([['application' => 'opencatalogi', 'reference' => 'WOO-2026-0002']], $extended['formerReferences']);
		self::assertSame('email', $extended['intakeChannel']);
		self::assertSame('2026-02-09T11:30:00+00:00', (new DateTimeImmutable($extended['receivedAt']))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM));
	}//end testEveryWrittenCaseFitsTheCaseSchema()

	/**
	 * A record the declaration cannot read (undeclared status, no question,
	 * a channel outside the list) is listed as failed, and no case is written.
	 *
	 * @return void
	 */
	public function testARecordTheDeclarationCannotReadFailsAndWritesNothing(): void {
		$this->only(['received', 'in_progress', 'decided']);
		$this->store->rows['wooRequest'][$this->source('received')['id']]['status'] = 'archived';
		$this->store->rows['wooRequest'][$this->source('in_progress')['id']]['requestedInformation'] = '  ';
		$this->store->rows['wooRequest'][$this->source('decided')['id']]['channel'] = 'pigeon';

		$answer = $this->runImport();

		self::assertSame(0, $answer['imported']);
		self::assertSame(3, $answer['unmigrated']);
		$reasons = implode(' | ', array_column($answer['failed'], 'reason'));
		self::assertStringContainsString('does not declare: archived', $reasons);
		self::assertStringContainsString('no readable requestedInformation', $reasons);
		self::assertStringContainsString('channel must be one of', $reasons);
		self::assertSame([], $this->store->all('case'));
	}//end testARecordTheDeclarationCannotReadFailsAndWritesNothing()

	/**
	 * A case type kept in dossiq's own register, without a statutory term,
	 * whose import moves `trajectory` records and may run on upgrade.
	 *
	 * @param bool $onUpgrade Whether the declaration runs on upgrade.
	 * @param bool $superseded Whether a later version replaced this one.
	 *
	 * @return void
	 */
	private function seedTrajectoryType(bool $onUpgrade, bool $superseded = false): void {
		$supersededBy = '';
		if ($superseded === true) {
			$supersededBy = 'type-trajectory-2';
		}

		$this->store->seed(
			schema: 'caseType',
			uuid: 'type-trajectory',
			row: [
				'id' => 'type-trajectory',
				'identifier' => 'trajectory',
				'supersededBy' => $supersededBy,
				'recordImports' => [[
					'key' => 'trajectories',
					'sourceApp' => 'dossiq',
					'sourceRegister' => 'dossiq',
					'sourceSchema' => 'trajectory',
					'statuses' => ['*' => ['status' => 'status-running']],
					'fields' => [
						['to' => 'title', 'value' => 'Trajectory'],
						['to' => 'startDate', 'from' => 'start', 'as' => 'date'],
						['to' => 'externalReference', 'from' => '@id'],
						['to' => 'properties', 'property' => ['definition' => 'def-kind', 'name' => 'kind'], 'from' => 'kind'],
					],
					'matchField' => 'externalReference',
					'stamp' => ['caseId' => 'migratedTo', 'at' => 'migratedAt'],
					'carriesTerm' => false,
					'runOnUpgrade' => $onUpgrade,
				]],
			]
		);
		$this->store->seed(schema: 'trajectory', uuid: 'traj-1', row: ['id' => 'traj-1', 'start' => '2026-03-01', 'kind' => 'werkfit']);
	}//end seedTrajectoryType()

	/**
	 * A record moves onto a case type without a term: the term engine is not
	 * asked, and the record counts as moved.
	 *
	 * @return void
	 */
	public function testACaseTypeWithoutATermImportsWithoutOne(): void {
		$this->seedTrajectoryType(onUpgrade: false);

		$answer = $this->import()->run(caseType: 'type-trajectory')['imports'][0];

		self::assertSame(expected: 1, actual: $answer['imported']);
		self::assertSame(expected: [], actual: $answer['failed']);
		self::assertSame(expected: [], actual: $this->instances, message: 'the term engine is not asked');
		self::assertSame(expected: [], actual: $this->armed);
		$case = $this->store->row('case', $answer['migrated'][0]['caseId']);
		self::assertSame(expected: 'status-running', actual: $case['status']);
		self::assertSame(expected: [['propertyDefinition' => 'def-kind', 'name' => 'kind', 'value' => 'werkfit']], actual: $case['properties']);
		self::assertSame(expected: $case['id'], actual: $this->store->row('trajectory', 'traj-1')['migratedTo']);
	}//end testACaseTypeWithoutATermImportsWithoutOne()

	/**
	 * An upgrade runs only the flagged declarations of a version in use, and a
	 * second upgrade imports nothing.
	 *
	 * @return void
	 */
	public function testAnUpgradeRunsOnlyTheFlaggedImportsOnce(): void {
		$this->seedTrajectoryType(onUpgrade: true);

		$first = $this->import()->runOnUpgrade();
		$second = $this->import()->runOnUpgrade();

		self::assertCount(expectedCount: 1, haystack: $first);
		self::assertSame(expected: 'type-trajectory', actual: $first[0]['caseType']);
		self::assertSame(expected: 'trajectories', actual: $first[0]['key']);
		self::assertSame(expected: 1, actual: $first[0]['imported']);
		self::assertCount(expectedCount: 1, haystack: $this->store->all('case'), message: 'the Woo import is not flagged, so no Woo request moved');
		self::assertSame(expected: 0, actual: $second[0]['imported']);
		self::assertSame(expected: 1, actual: $second[0]['alreadyImported']);
	}//end testAnUpgradeRunsOnlyTheFlaggedImportsOnce()

	/**
	 * A superseded version's flagged import does not run on upgrade.
	 *
	 * @return void
	 */
	public function testAnUpgradeSkipsASupersededVersion(): void {
		$this->seedTrajectoryType(onUpgrade: true, superseded: true);

		self::assertSame(expected: [], actual: $this->import()->runOnUpgrade());
		self::assertSame(expected: [], actual: $this->store->all('case'));
	}//end testAnUpgradeSkipsASupersededVersion()

	/**
	 * Two source records carrying one number become one case; the second is
	 * reported as a duplicate, stamped with that case, and a second run moves nothing.
	 *
	 * @return void
	 */
	public function testADuplicateNumberBecomesOneCaseAndIsReported(): void {
		$this->seedTrajectoryType(onUpgrade: false);
		$type = $this->store->row('caseType', 'type-trajectory');
		$type['recordImports'][0]['dedupeBy'] = 'number';
		$type['recordImports'][0]['formerReference'] = ['application' => 'dossiq', 'from' => 'number'];
		$this->store->seed(schema: 'caseType', uuid: 'type-trajectory', row: $type);
		$this->store->rows['trajectory']['traj-1']['number'] = 'PW-1';
		$this->store->seed(schema: 'trajectory', uuid: 'traj-2', row: ['id' => 'traj-2', 'start' => '2026-03-01', 'kind' => 'werkfit', 'number' => 'PW-1']);

		$first = $this->import()->run(caseType: 'type-trajectory')['imports'][0];
		$second = $this->import()->run(caseType: 'type-trajectory')['imports'][0];

		self::assertSame(expected: 1, actual: $first['imported']);
		self::assertCount(expectedCount: 1, haystack: $first['duplicates']);
		self::assertCount(expectedCount: 1, haystack: $this->store->all('case'));
		$caseId = $first['migrated'][0]['caseId'];
		self::assertSame(expected: $caseId, actual: $first['duplicates'][0]['caseId']);
		self::assertSame(expected: $caseId, actual: $this->store->row('trajectory', 'traj-2')['migratedTo']);
		self::assertSame(expected: 0, actual: $second['imported']);
		self::assertSame(expected: 2, actual: $second['alreadyImported']);
	}//end testADuplicateNumberBecomesOneCaseAndIsReported()

	/**
	 * Records that point at a moved record by its id or its slug point at its
	 * case afterwards; anything else is left alone.
	 *
	 * @return void
	 */
	public function testReferencesToAMovedRecordPointAtItsCase(): void {
		$this->seedTrajectoryType(onUpgrade: false);
		$type = $this->store->row('caseType', 'type-trajectory');
		$type['recordImports'][0]['references'] = [['schema' => 'consent', 'field' => 'caseId']];
		$this->store->seed(schema: 'caseType', uuid: 'type-trajectory', row: $type);
		$this->store->rows['trajectory']['traj-1']['@self'] = ['slug' => 'traj-one'];
		$this->store->seed(schema: 'consent', uuid: 'c-1', row: ['id' => 'c-1', 'caseId' => 'traj-1']);
		$this->store->seed(schema: 'consent', uuid: 'c-2', row: ['id' => 'c-2', 'caseId' => 'traj-one']);
		$this->store->seed(schema: 'consent', uuid: 'c-3', row: ['id' => 'c-3', 'caseId' => 'another-case']);

		$answer = $this->import()->run(caseType: 'type-trajectory')['imports'][0];

		$caseId = $answer['migrated'][0]['caseId'];
		self::assertSame(expected: 2, actual: $answer['repointed']);
		self::assertSame(expected: $caseId, actual: $this->store->row('consent', 'c-1')['caseId']);
		self::assertSame(expected: $caseId, actual: $this->store->row('consent', 'c-2')['caseId']);
		self::assertSame(expected: 'another-case', actual: $this->store->row('consent', 'c-3')['caseId']);
	}//end testReferencesToAMovedRecordPointAtItsCase()

	/**
	 * A list of references (`list: true`) has the moved record's entry
	 * replaced by its case, the other entries kept in place.
	 *
	 * @return void
	 */
	public function testAListOfReferencesPointsAtTheCase(): void {
		$this->seedTrajectoryType(onUpgrade: false);
		$type = $this->store->row('caseType', 'type-trajectory');
		$type['recordImports'][0]['references'] = [['schema' => 'meeting', 'field' => 'caseIds', 'list' => true]];
		$this->store->seed(schema: 'caseType', uuid: 'type-trajectory', row: $type);
		$this->store->seed(schema: 'meeting', uuid: 'm-1', row: ['id' => 'm-1', 'caseIds' => ['other-1', 'traj-1', 'other-2']]);
		$this->store->seed(schema: 'meeting', uuid: 'm-2', row: ['id' => 'm-2', 'caseIds' => ['other-3']]);

		$answer = $this->import()->run(caseType: 'type-trajectory')['imports'][0];

		$caseId = $answer['migrated'][0]['caseId'];
		self::assertSame(expected: 1, actual: $answer['repointed']);
		self::assertSame(expected: ['other-1', $caseId, 'other-2'], actual: $this->store->row('meeting', 'm-1')['caseIds']);
		self::assertSame(expected: ['other-3'], actual: $this->store->row('meeting', 'm-2')['caseIds']);
	}//end testAListOfReferencesPointsAtTheCase()

	/**
	 * An unknown case type, or another import key, runs nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownCaseTypeOrImportRunsNothing(): void {
		self::assertSame(['caseType' => '', 'imports' => []], $this->import()->run(caseType: 'no-such-type'));

		$byIdentifier = $this->import()->run(caseType: 'woo-verzoek', importKey: 'another-import');
		self::assertSame(['caseType' => self::CASE_TYPE, 'imports' => []], $byIdentifier);
		self::assertSame(0, $this->store->writes);
	}//end testAnUnknownCaseTypeOrImportRunsNothing()
}//end class
