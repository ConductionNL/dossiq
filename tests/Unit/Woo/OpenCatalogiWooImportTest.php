<?php

/**
 * Every stored opencatalogi Woo request is imported exactly once: its case
 * written, its term carried, and only then the source stamped.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Woo
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-every-stored-opencatalogi-request-is-imported-exactly-once-req-wto-004
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use DateTime;
use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Woo\OpenCatalogiWooCase;
use OCA\Dossiq\Woo\OpenCatalogiWooImport;
use OCA\Dossiq\Woo\OpenCatalogiWooTerm;
use OCA\Dossiq\Woo\WooRequestIntake;
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
 * @covers \OCA\Dossiq\Woo\OpenCatalogiWooImport
 * @uses   \OCA\Dossiq\Woo\OpenCatalogiWooCase
 * @uses   \OCA\Dossiq\Woo\OpenCatalogiWooTerm
 * @uses   \OCA\Dossiq\Woo\WooReceivedAnswers
 * @uses   \OCA\Dossiq\Woo\WooRequestForm
 * @uses   \OCA\Dossiq\Woo\WooRequestRefused
 * @uses   \OCA\Dossiq\Woo\WooWrittenCase
 * @uses   \OCA\Dossiq\Service\CaseDateNormaliser
 */
class OpenCatalogiWooImportTest extends TestCase {

	use MakesCaseDateNormaliser;

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
	 * The fixture rows seeded into the opencatalogi register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryRegister();
		$fixture = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/Fixtures/opencatalogi-woo-requests.json'), true);
		foreach ($fixture['requests'] as $row) {
			$this->store->seed(schema: 'wooRequest', uuid: $row['id'], row: $row);
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
	 * @return OpenCatalogiWooImport
	 */
	private function import(bool $installed = true, ?InMemoryRegister $store = null): OpenCatalogiWooImport {
		$store = ($store ?? $this->store);

		/** @var SettingsService&MockObject $settings */
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'case_schema' => 'case',
				'result_schema' => 'result',
			][$key] ?? $default
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(fn (string $app): bool => $app === 'opencatalogi' && $installed === true);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-20T09:00:00+02:00'));

		$dates = $this->caseDates();

		return new OpenCatalogiWooImport(
			settingsService: $settings,
			appManager: $apps,
			mapper: new OpenCatalogiWooCase(dates: $dates),
			term: new OpenCatalogiWooTerm(terms: $this->terms($store), timers: $this->timers(), dates: $dates),
			time: $time,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end import()

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

		$answer = $this->import()->run();

		$cases = $this->casesFor($source['id']);
		self::assertCount(1, $cases);
		self::assertSame('2026-03-02', $cases[0]['startDate']);
		self::assertSame(OpenCatalogiWooCase::STATUS['received'], $cases[0]['status']);
		self::assertSame(WooRequestIntake::CASE_TYPE_ID, $cases[0]['caseType']);
		self::assertSame('2026-03-30', $this->instances[$cases[0]['id']]['endDateCurrent']);
		self::assertSame([], $this->armed, 'the fresh timer already ends on dueAt');

		$stamped = $this->store->row('wooRequest', $source['id']);
		self::assertSame($cases[0]['id'], $stamped['migratedTo']);
		self::assertSame('2026-10-20T09:00:00+02:00', $stamped['migratedAt']);
		self::assertSame(1, $answer['imported']);
		self::assertSame(0, $answer['unmigrated']);
		self::assertSame(
			[['requestId' => $source['id'], 'reference' => 'WOO-2026-0001', 'caseId' => $cases[0]['id'], 'termTimer' => 'woo-term-0001']],
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

		$this->import()->run();

		$caseId = $this->casesFor($source['id'])[0]['id'];
		$term = $this->instances[$caseId];
		self::assertSame('2026-11-23', $term['endDateCurrent']);
		self::assertSame(1, $term['countExtensions']);
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
		$answer = $this->import()->run();

		self::assertSame(5, $answer['imported']);
		self::assertSame([], $answer['failed']);
		foreach (OpenCatalogiWooCase::STATUS as $status => $stage) {
			$cases = $this->casesFor($this->source($status)['id']);
			self::assertCount(1, $cases, $status);
			self::assertSame($stage, $cases[0]['status'], $status);
		}

		$withdrawn = $this->casesFor($this->source('withdrawn')['id'])[0]['id'];
		self::assertSame([['case' => $withdrawn, 'resultType' => OpenCatalogiWooCase::RESULT_WITHDRAWN, 'id' => 'generated-1']], $this->store->all('result'));
		self::assertSame('completed', $this->instances[$this->casesFor($this->source('decided')['id'])[0]['id']]['status']);
	}//end testEveryStatusMapsToItsStage()

	/**
	 * A second run imports nothing, arms nothing and writes no case.
	 *
	 * @return void
	 */
	public function testASecondRunImportsNothingAndArmsNothing(): void {
		$this->import()->run();
		$armed = $this->armed;
		$cases = count($this->store->all('case'));

		$again = $this->import()->run();

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

		$first = $this->import()->run();

		self::assertSame(0, $first['imported']);
		self::assertSame(1, $first['unmigrated']);
		self::assertSame($source['id'], $first['failed'][0]['requestId']);
		self::assertStringContainsString('not-armed', $first['failed'][0]['reason']);
		self::assertArrayNotHasKey('migratedTo', $this->store->row('wooRequest', $source['id']));

		$this->engine = true;
		$second = $this->import()->run();

		$cases = $this->casesFor($source['id']);
		self::assertCount(1, $cases, 'only one case refers to the source');
		self::assertSame(1, $second['imported']);
		self::assertSame($cases[0]['id'], $this->store->row('wooRequest', $source['id'])['migratedTo']);
		self::assertSame('2026-04-06', $this->instances[$cases[0]['id']]['endDateCurrent']);
		self::assertNotSame('', $this->instances[$cases[0]['id']]['engineTimerId']);
	}//end testAHalfFinishedRequestIsCompletedNotDuplicated()

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

		$answer = $this->import(store: $store)->run();

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
		$answer = $this->import()->run();

		$touched = array_merge($this->armed, $this->cancelled, $this->suspended);
		foreach ($this->store->all('wooRequest') as $source) {
			foreach ($touched as $id) {
				self::assertStringNotContainsString($source['termTimer'], $id);
			}

			self::assertSame($source['termTimer'], $this->store->row('wooRequest', $source['id'])['termTimer']);
		}

		self::assertEqualsCanonicalizing(
			['woo-term-0001', 'woo-term-0002', 'woo-term-0003', 'woo-term-0004', 'woo-term-0005'],
			array_column($answer['migrated'], 'termTimer')
		);
	}//end testOpencatalogisTimerIsNeverTouched()

	/**
	 * Without opencatalogi nothing is read, written or counted.
	 *
	 * @return void
	 */
	public function testWithoutOpencatalogiNothingIsImported(): void {
		$answer = $this->import(installed: false)->run();

		self::assertSame(
			['installed' => false, 'imported' => 0, 'alreadyImported' => 0, 'failed' => [], 'unmigrated' => 0, 'migrated' => []],
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
		$answer = $this->import()->run(dryRun: true);

		self::assertSame(5, $answer['unmigrated']);
		self::assertSame(0, $answer['imported']);
		self::assertSame(0, $this->store->writes);
		self::assertSame([], $this->armed);
	}//end testADryRunCountsAndWritesNothing()
}//end class
