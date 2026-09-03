<?php

/**
 * Dossiq KPI Aggregation Service Test
 *
 * 🔴 THE TEST THIS REPLACED COULD NOT FAIL. It mocked the database, asserted
 * that the returned array HAD the expected keys and that they were integers, and
 * had a case named "returns zero defaults on db error" that pinned the
 * zero-on-exception behaviour as correct. So it stayed green for the entire life
 * of a service that answered 0 for every metric on every PostgreSQL instance:
 * the SQL used MySQL's JSON_EXTRACT, every query threw, every catch returned 0.
 * A shape assertion cannot tell a working aggregation from a broken one.
 *
 * These tests assert NUMBERS, computed from a fake that behaves the way
 * OpenRegister's ObjectService actually behaves, including the part that bit:
 * `count()` reads the register/schema CONTEXT rather than the ids in its own
 * filters, and `findAll()` overwrites that context. The fake reproduces that,
 * so a service that forgets to set the context before counting fails here
 * instead of shipping zeros.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\KpiAggregationService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Dashboard KPI aggregation.
 */
final class KpiAggregationServiceTest extends TestCase {
	/**
	 * The register and schema ids the fake instance uses.
	 *
	 * @var array<string, string>
	 */
	private const IDS = ['register' => '23', 'case' => '172', 'task' => '173'];

	/**
	 * Today, as the service formats it.
	 *
	 * @var string
	 */
	private string $today;

	/**
	 * Set the clock the fixtures are written against.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->today = date('Y-m-d');
	}

	/**
	 * Every headline number is the one the register actually holds.
	 *
	 * @return void
	 */
	public function testComputesTheRealCounts(): void {
		$kpis = $this->service()->computeKpis('admin');

		$this->assertSame(16, $kpis['openCount'], 'openCount must count the non-final cases.');
		$this->assertSame(6, $kpis['overdueCount'], 'overdueCount must count open cases past their deadline.');
		$this->assertSame(23, $kpis['taskCount'], "taskCount must count the user's own open tasks.");
		$this->assertSame(2, $kpis['tasksDueToday'], 'tasksDueToday must count those due today.');
		$this->assertSame(1, $kpis['newToday'], 'newToday must count open cases started today.');
	}

	/**
	 * 🔴 THE REGRESSION. A count issued after a read of ANOTHER schema must still
	 * count in its own schema.
	 *
	 * The service reads the closed cases and the open cases before it counts the
	 * tasks. `findAll()` leaves its register/schema on the shared ObjectService,
	 * and `count()` honours that context rather than its own filters, so a
	 * service that does not reset the context counts tasks inside the CASE schema
	 * and answers 0. Measured on the dev instance: 23 on its own, 0 straight
	 * after a findAll over cases.
	 *
	 * @return void
	 */
	public function testCountsAreNotPoisonedByAnEarlierReadOfAnotherSchema(): void {
		$objectService = $this->objectService();
		$kpis = $this->service(objectService: $objectService)->computeKpis('admin');

		$this->assertSame(
			23,
			$kpis['taskCount'],
			'taskCount is counted after the case reads; without resetting the schema context it reads 0.'
		);
		$this->assertGreaterThan(
			0,
			$objectService->findAllCalls,
			'the fixture only proves anything if a findAll really did run first',
		);
	}

	/**
	 * The month's closed cases produce a mean duration and an SLA percentage.
	 *
	 * @return void
	 */
	public function testFoldsTheClosedCasesIntoDurationAndSla(): void {
		$kpis = $this->service()->computeKpis('admin');

		$this->assertSame(3, $kpis['completedCount'], 'completedCount counts the cases closed this month.');
		// 10, 20 and 6 days -> 12.0
		$this->assertSame(12.0, $kpis['avgProcessingDays'], 'avgProcessingDays is the mean of the closed durations.');
		// two of three met their deadline -> 67%
		$this->assertSame(67.0, $kpis['slaCompliance'], 'slaCompliance is the share that met its deadline.');
	}

	/**
	 * A case with no deadline is left out of the SLA figure rather than counted
	 * as a pass or a breach.
	 *
	 * @return void
	 */
	public function testCasesWithoutADeadlineAreExcludedFromSla(): void {
		$closed = [
			['startDate' => '2026-09-01', 'endDate' => '2026-09-05', 'deadline' => '2026-09-10'],
			['startDate' => '2026-09-01', 'endDate' => '2026-09-05'],
		];

		$kpis = $this->service(closedCases: $closed)->computeKpis('admin');

		$this->assertSame(
			100.0,
			$kpis['slaCompliance'],
			'the one case with a deadline met it, so the figure is 100 and not 50.'
		);
	}

	/**
	 * Nothing closed means no average and no percentage, not zero.
	 *
	 * Zero would read as "every case took no time" and "nothing met its
	 * deadline", which are claims the data does not support.
	 *
	 * @return void
	 */
	public function testNoClosedCasesReportsNullRatherThanZero(): void {
		$kpis = $this->service(closedCases: [])->computeKpis('admin');

		$this->assertNull($kpis['avgProcessingDays']);
		$this->assertNull($kpis['slaCompliance']);
		$this->assertSame(0, $kpis['completedCount']);
	}

	/**
	 * An unconfigured register yields the empty shape without touching
	 * OpenRegister.
	 *
	 * @return void
	 */
	public function testUnconfiguredRegisterReturnsTheEmptyShape(): void {
		$kpis = $this->service(configured: false)->computeKpis('admin');

		$this->assertSame(0, $kpis['openCount']);
		$this->assertNull($kpis['avgProcessingDays']);
		$this->assertSame([], $kpis['statusBreakdown']);
	}

	/**
	 * The breakdowns group the open cases and lead with the largest.
	 *
	 * @return void
	 */
	public function testBreakdownsGroupAndSortTheOpenCases(): void {
		$kpis = $this->service()->computeKpis('admin');

		$this->assertSame(
			[['status' => 'in-behandeling', 'count' => 2], ['status' => 'ontvangen', 'count' => 1]],
			$kpis['statusBreakdown'],
			'the breakdown groups by status, most frequent first.'
		);
	}

	/**
	 * Build the service against a fake ObjectService.
	 *
	 * @param object|null $objectService A prepared fake, or null to build one.
	 * @param array|null $closedCases Override the cases closed this month.
	 * @param boolean $configured Whether the register ids are set.
	 *
	 * @return KpiAggregationService The service under test.
	 */
	private function service(
		?object $objectService = null,
		?array $closedCases = null,
		bool $configured = true,
	): KpiAggregationService {
		$objectService = ($objectService ?? $this->objectService(closedCases: $closedCases));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '', bool $lazy = false) use ($configured): string {
				if ($configured === false) {
					return $default;
				}

				return match ($key) {
					'register' => self::IDS['register'],
					'case_schema' => self::IDS['case'],
					'task_schema' => self::IDS['task'],
					default => $default,
				};
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new KpiAggregationService($appConfig, $container, $appManager, new NullLogger());
	}

	/**
	 * A fake ObjectService that behaves like the real one, including the context
	 * rule that `count()` honours setRegister()/setSchema() over its own filters
	 * and that `findAll()` overwrites that context.
	 *
	 * @param array|null $closedCases Override the cases closed this month.
	 *
	 * @return object The fake.
	 */
	private function objectService(?array $closedCases = null): object {
		$closed = ($closedCases ?? [
			['startDate' => '2026-09-01', 'endDate' => '2026-09-11', 'deadline' => '2026-09-12'],
			['startDate' => '2026-09-01', 'endDate' => '2026-09-21', 'deadline' => '2026-09-30'],
			['startDate' => '2026-09-01', 'endDate' => '2026-09-07', 'deadline' => '2026-09-05'],
		]);

		$open = [
			['status' => 'ontvangen', 'caseType' => 'omgevingsvergunning'],
			['status' => 'in-behandeling', 'caseType' => 'omgevingsvergunning'],
			['status' => 'in-behandeling', 'caseType' => 'subsidieaanvraag'],
		];

		return new class($closed, $open, $this->today) {
			/**
			 * How many times findAll ran, so a test can prove the ordering it claims.
			 *
			 * @var int
			 */
			public int $findAllCalls = 0;

			/**
			 * The register the context currently points at.
			 *
			 * @var string
			 */
			private string $register = '';

			/**
			 * The schema the context currently points at.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array $closed The closed cases.
			 * @param array $open The open cases.
			 * @param string $today Today, as Y-m-d.
			 */
			public function __construct(private array $closed, private array $open, private string $today) {
			}

			/**
			 * @param string $register The register id.
			 *
			 * @return static This instance.
			 */
			public function setRegister(string $register): static {
				$this->register = $register;
				return $this;
			}

			/**
			 * @param string $schema The schema id.
			 *
			 * @return static This instance.
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * Count within the CONTEXT, exactly as OpenRegister does. The schema in
			 * the filters is deliberately ignored: that is the behaviour that made
			 * a real service answer 0.
			 *
			 * @param array $config The find configuration.
			 *
			 * @return int The count.
			 */
			public function count(array $config = []): int {
				$f = ($config['filters'] ?? []);

				if ($this->schema === '172') {
					if (isset($f['deadline']) === true) {
						return 6;
					}

					if (($f['startDate'] ?? null) === $this->today) {
						return 1;
					}

					return 16;
				}

				if ($this->schema === '173') {
					if (isset($f['dueDate']) === true) {
						return 2;
					}

					return 23;
				}

				return 0;
			}

			/**
			 * Read rows, and set the context as a side effect the way the real
			 * service does.
			 *
			 * @param array $config The find configuration.
			 *
			 * @return array The results envelope.
			 */
			public function findAll(array $config = []): array {
				$this->findAllCalls++;
				$f = ($config['filters'] ?? []);
				$this->register = (string)($f['register'] ?? '');
				$this->schema = (string)($f['schema'] ?? '');

				if (isset($f['endDate']) === true) {
					return ['results' => $this->closed];
				}

				return ['results' => $this->open];
			}
		};
	}
}
