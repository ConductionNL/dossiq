<?php

/**
 * PlannedSeriesLedger tests: the fired counter, the cases a series created,
 * the planned flows, and retiring the spent ones.
 *
 * The counter only moves when the engine's last-run stamp moves, so a sweep
 * that runs twice between fires does not count a fire twice. The cases a
 * series created are read by its handoff source and shaped to id and title,
 * whatever row type the store hands back. A follow-up is retired once it has
 * done its work: a single one after its one fire, a counted series on its
 * last; one not yet run, or already switched off, is left alone.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use DateTimeImmutable;
use OCA\Dossiq\Service\Flow\PlannedFollowUpDocument;
use OCA\Dossiq\Service\Flow\PlannedSeriesLedger;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for PlannedSeriesLedger.
 *
 * @covers \OCA\Dossiq\Service\Flow\PlannedSeriesLedger
 * @uses \OCA\Dossiq\Service\Flow\PlannedFollowUpDocument
 * @uses \OCA\Dossiq\Service\Flow\PlannedFollowUp
 * @uses \OCA\Dossiq\Service\Flow\PlannedSeriesCalendar
 * @uses \OCA\Dossiq\Service\Flow\RetirementSweep
 */
class PlannedSeriesLedgerTest extends TestCase {

	/**
	 * App config values, as the mock stores them.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The saves the flow engine received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saves = [];

	/**
	 * An app config that keeps what is written.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		return $appConfig;
	}//end appConfig()

	/**
	 * A stored planned flow.
	 *
	 * @param string                 $uuid      The flow uuid.
	 * @param string                 $recurrence The recurrence.
	 * @param int                    $count     The series length, 0 for none.
	 * @param DateTimeImmutable|null $lastRunAt When the engine last ran it.
	 * @param bool                   $enabled   Whether it is switched on.
	 *
	 * @return object The flow.
	 */
	private static function flow(string $uuid, string $recurrence, int $count, ?DateTimeImmutable $lastRunAt, bool $enabled = true): object {
		$nodes = (new PlannedFollowUpDocument())->build(
			caseId: 'case-1',
			caseTypeId: 'type-controle',
			due: new DateTimeImmutable('2026-10-15'),
			title: 'Controle',
			uid: 'behandelaar',
			recurrence: $recurrence,
			count: $count
		)['nodes'];

		return new class ($uuid, (array)$nodes, $lastRunAt, $enabled) {
			/**
			 * Constructor.
			 *
			 * @param string                 $uuid      The uuid.
			 * @param array<int, mixed>      $nodes     The graph.
			 * @param DateTimeImmutable|null $lastRunAt The last run.
			 * @param bool                   $enabled   Switched on.
			 */
			public function __construct(
				private string $uuid,
				private array $nodes,
				private ?DateTimeImmutable $lastRunAt,
				private bool $enabled,
			) {
			}//end __construct()

			/** @return string */
			public function getUuid(): string {
				return $this->uuid;
			}//end getUuid()

			/** @return array<int, mixed> */
			public function getNodes(): array {
				return $this->nodes;
			}//end getNodes()

			/** @return DateTimeImmutable|null */
			public function getLastRunAt(): ?DateTimeImmutable {
				return $this->lastRunAt;
			}//end getLastRunAt()

			/** @return bool */
			public function getEnabled(): bool {
				return $this->enabled;
			}//end getEnabled()
		};
	}//end flow()

	/**
	 * The ledger over the given collaborators.
	 *
	 * @param array<string, object|\Throwable> $services Container entries by class fragment.
	 * @param SettingsService|null             $settings The settings.
	 * @param LoggerInterface|null             $logger   The logger.
	 *
	 * @return PlannedSeriesLedger
	 */
	private function ledger(array $services = [], ?SettingsService $settings = null, ?LoggerInterface $logger = null): PlannedSeriesLedger {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $name) use ($services): object {
				foreach ($services as $fragment => $service) {
					if (str_contains($name, $fragment) === false) {
						continue;
					}

					if ($service instanceof \Throwable) {
						throw $service;
					}

					return $service;
				}

				throw new RuntimeException('not registered: ' . $name);
			}
		);

		return new PlannedSeriesLedger(
			$container,
			($settings ?? $this->createMock(SettingsService::class)),
			new PlannedFollowUpDocument(),
			$this->appConfig(),
			($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end ledger()

	/**
	 * A flow mapper answering the given flows, or throwing.
	 *
	 * @param array<int, object>|\Throwable $flows The flows.
	 *
	 * @return object
	 */
	private static function mapper(array|\Throwable $flows): object {
		return new class ($flows) {
			/**
			 * Constructor.
			 *
			 * @param array<int, object>|\Throwable $flows The flows.
			 */
			public function __construct(private array|\Throwable $flows) {
			}//end __construct()

			/**
			 * @param string|null $applicationSlug The marker.
			 * @param int         $limit           The page.
			 *
			 * @return array<int, object> The flows.
			 */
			public function findAllFlows(?string $applicationSlug = null, int $limit = 100): array {
				if ($this->flows instanceof \Throwable) {
					throw $this->flows;
				}

				return $this->flows;
			}//end findAllFlows()
		};
	}//end mapper()

	/**
	 * A flow engine recording its saves, or failing them.
	 *
	 * @param bool $fails Whether save() throws.
	 *
	 * @return object
	 */
	private function engine(bool $fails = false): object {
		return new class ($this, $fails) {
			/**
			 * Constructor.
			 *
			 * @param PlannedSeriesLedgerTest $test  Where the saves go.
			 * @param bool                    $fails Whether save() throws.
			 */
			public function __construct(private PlannedSeriesLedgerTest $test, private bool $fails) {
			}//end __construct()

			/**
			 * @param array<string, mixed> $data The fields.
			 * @param string|null          $uuid The flow.
			 *
			 * @return object
			 */
			public function save(array $data, ?string $uuid = null): object {
				if ($this->fails === true) {
					throw new RuntimeException('engine down');
				}

				$this->test->saves[] = ['data' => $data, 'uuid' => $uuid];
				return new \stdClass();
			}//end save()
		};
	}//end engine()

	/**
	 * Settings with an object service and the case register and schema.
	 *
	 * @param object|null $objectService The object service.
	 * @param string      $register      The register id.
	 *
	 * @return SettingsService
	 */
	private function settings(?object $objectService, string $register = 'reg-1'): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', $register],
				['case_schema', '', 'schema-case'],
			]
		);

		return $settings;
	}//end settings()

	/**
	 * The counter moves once per new last-run stamp, and holds while the stamp holds.
	 *
	 * @return void
	 */
	public function testTheFiredCountMovesOnlyWithTheLastRun(): void {
		$ledger = $this->ledger();
		$first  = new DateTimeImmutable('2026-10-15 07:00:00');

		self::assertSame(1, $ledger->firedCount('flow-1', $first));
		self::assertSame(1, $ledger->firedCount('flow-1', $first));
		self::assertSame(2, $ledger->firedCount('flow-1', new DateTimeImmutable('2026-11-15 07:00:00')));
		self::assertSame('2', $this->config['planned_series_fired_flow-1']);
		self::assertSame('2026-11-15 07:00:00', $this->config['planned_series_seen_flow-1']);

		// A flow that has never run reads as the empty stamp: nothing has fired.
		self::assertSame(0, $ledger->firedCount('flow-2', null));
		self::assertSame(0, $ledger->firedCount('flow-2', 'not a date'));
		self::assertArrayNotHasKey('planned_series_fired_flow-2', $this->config);
	}//end testTheFiredCountMovesOnlyWithTheLastRun()

	/**
	 * A single follow-up asks the store nothing.
	 *
	 * @return void
	 */
	public function testASingleFollowUpHasNoOccurrencesToRead(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->never())->method('getObjectService');

		self::assertSame([], $this->ledger(settings: $settings)->occurrencesOf('flow-1', true));
	}//end testASingleFollowUpHasNoOccurrencesToRead()

	/**
	 * A series' cases are read by its handoff source and shaped to id and title.
	 *
	 * @return void
	 */
	public function testASeriesListsTheCasesItCreated(): void {
		$serialisable = new class () implements \JsonSerializable {
			/** @return array<string, string> */
			public function jsonSerialize(): array {
				return ['id' => 'case-b', 'title' => 'Second'];
			}//end jsonSerialize()
		};
		$plain = new \stdClass();
		$plain->id = 'case-c';
		$objects = new class ([['id' => 'case-a', 'title' => 'First'], $serialisable, $plain, ['id' => '  ', 'title' => 'No id']]) {
			/**
			 * The query asked.
			 *
			 * @var array<string, mixed>
			 */
			public array $asked = [];

			/**
			 * Constructor.
			 *
			 * @param array<int, mixed> $rows The rows.
			 */
			public function __construct(private array $rows) {
			}//end __construct()

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<string, mixed>
			 */
			public function findAll(array $config): array {
				$this->asked = $config;
				return ['results' => $this->rows];
			}//end findAll()
		};

		$occurrences = $this->ledger(settings: $this->settings($objects))->occurrencesOf('flow-9', false);

		self::assertSame(
			[
				['id' => 'case-a', 'title' => 'First'],
				['id' => 'case-b', 'title' => 'Second'],
				['id' => 'case-c', 'title' => ''],
			],
			$occurrences
		);
		self::assertSame(
			[
				'filters' => ['register' => 'reg-1', 'schema' => 'schema-case', 'handoffSource' => 'planned-series:flow-9'],
				'limit' => 200,
			],
			$objects->asked
		);
	}//end testASeriesListsTheCasesItCreated()

	/**
	 * A bare list from the store is read as the rows.
	 *
	 * @return void
	 */
	public function testABareListIsReadAsTheRows(): void {
		$objects = new class () {
			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, array<string, string>>
			 */
			public function findAll(array $config): array {
				return [['id' => 'case-a', 'title' => 'First']];
			}//end findAll()
		};

		self::assertSame([['id' => 'case-a', 'title' => 'First']], $this->ledger(settings: $this->settings($objects))->occurrencesOf('f', false));
	}//end testABareListIsReadAsTheRows()

	/**
	 * Without a store, or without the register, nothing is read; a failed read is logged.
	 *
	 * @return void
	 */
	public function testAStoreThatCannotAnswerListsNothing(): void {
		self::assertSame([], $this->ledger(settings: $this->settings(null))->occurrencesOf('f', false));

		$objects = new class () {
			/** @var int */
			public int $calls = 0;

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config): array {
				$this->calls++;
				throw new RuntimeException('store down');
			}//end findAll()
		};
		self::assertSame([], $this->ledger(settings: $this->settings($objects, ''))->occurrencesOf('f', false));
		self::assertSame(0, $objects->calls);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'PlannedSeriesLedger: could not read the cases a series created',
				['flow' => 'f', 'exception' => 'store down']
			);
		self::assertSame([], $this->ledger(settings: $this->settings($objects), logger: $logger)->occurrencesOf('f', false));
	}//end testAStoreThatCannotAnswerListsNothing()

	/**
	 * The planned flows are read from the mapper; a missing or failing mapper lists none.
	 *
	 * @return void
	 */
	public function testThePlannedFlowsComeFromTheMapper(): void {
		$flow = self::flow('flow-1', 'none', 0, null);
		self::assertSame([$flow], $this->ledger(['FlowMapper' => self::mapper([$flow])])->plannedFlows());
		self::assertSame([], $this->ledger()->plannedFlows());

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('PlannedSeriesLedger: could not read the planned follow-ups', ['exception' => 'db down']);
		self::assertSame([], $this->ledger(['FlowMapper' => self::mapper(new RuntimeException('db down'))], logger: $logger)->plannedFlows());
	}//end testThePlannedFlowsComeFromTheMapper()

	/**
	 * Spent follow-ups are switched off with their reason; the rest are left alone.
	 *
	 * @return void
	 */
	public function testSpentFollowUpsAreRetired(): void {
		$ran   = new DateTimeImmutable('2026-10-15 07:00:00');
		$flows = [
			self::flow('single-fired', 'none', 0, $ran),
			self::flow('never-ran', 'none', 0, null),
			self::flow('switched-off', 'none', 0, $ran, false),
			self::flow('series-open', 'yearly', 3, $ran),
		];
		$ledger = $this->ledger(['FlowMapper' => self::mapper($flows), 'FlowService' => $this->engine()]);

		self::assertSame(1, $ledger->retireSpent(new DateTimeImmutable('2026-10-16')));
		self::assertSame([['data' => ['enabled' => false, 'notes' => 'follow-up created'], 'uuid' => 'single-fired']], $this->saves);
	}//end testSpentFollowUpsAreRetired()

	/**
	 * A counted series is retired as complete on its last fire.
	 *
	 * @return void
	 */
	public function testACountedSeriesIsRetiredAsComplete(): void {
		$this->config['planned_series_fired_series-done'] = '2';
		$this->config['planned_series_seen_series-done']  = '2027-10-15 07:00:00';
		$flow   = self::flow('series-done', 'yearly', 3, new DateTimeImmutable('2028-10-15 07:00:00'));
		$ledger = $this->ledger(['FlowMapper' => self::mapper([$flow]), 'FlowService' => $this->engine()]);

		self::assertSame(1, $ledger->retireSpent(new DateTimeImmutable('2028-10-16')));
		self::assertSame([['data' => ['enabled' => false, 'notes' => 'series complete'], 'uuid' => 'series-done']], $this->saves);
	}//end testACountedSeriesIsRetiredAsComplete()

	/**
	 * Without a flow engine nothing is retired; a failed save is logged and not counted.
	 *
	 * @return void
	 */
	public function testARetirementThatCannotBeSavedIsNotCounted(): void {
		$flows = [self::flow('single-fired', 'none', 0, new DateTimeImmutable('2026-10-15 07:00:00'))];
		self::assertSame(0, $this->ledger(['FlowMapper' => self::mapper($flows)])->retireSpent());

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('PlannedSeriesLedger: could not retire a spent follow-up', ['flow' => 'single-fired', 'exception' => 'engine down']);
		$ledger = $this->ledger(['FlowMapper' => self::mapper($flows), 'FlowService' => $this->engine(true)], logger: $logger);

		self::assertSame(0, $ledger->retireSpent(new DateTimeImmutable('2026-10-16')));
	}//end testARetirementThatCannotBeSavedIsNotCounted()
}//end class
