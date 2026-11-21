<?php

/**
 * DoorlooptijdService metrics assembly Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Doorlooptijd\CaseEnricher;
use OCA\Dossiq\Service\Doorlooptijd\CaseTypeThroughputCalculator;
use OCA\Dossiq\Service\Doorlooptijd\DeadlineComplianceCalculator;
use OCA\Dossiq\Service\Doorlooptijd\NormAverage;
use OCA\Dossiq\Service\DoorlooptijdService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * How the throughput dashboard's metrics are loaded, parameterised and assembled.
 *
 * @covers \OCA\Dossiq\Service\DoorlooptijdService
 * @uses \OCA\Dossiq\Service\Support\ConfiguredSchemaRows
 * @uses \OCA\Dossiq\Service\Support\ObjectSearch
 */
class DoorlooptijdServiceMetricsTest extends TestCase {

	/**
	 * The object store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settings;

	/**
	 * The mocked case enricher.
	 *
	 * @var CaseEnricher&MockObject
	 */
	private CaseEnricher $enricher;

	/**
	 * The mocked compliance calculator.
	 *
	 * @var DeadlineComplianceCalculator&MockObject
	 */
	private DeadlineComplianceCalculator $compliance;

	/**
	 * The mocked throughput calculator.
	 *
	 * @var CaseTypeThroughputCalculator&MockObject
	 */
	private CaseTypeThroughputCalculator $throughput;

	/**
	 * The mocked norm average.
	 *
	 * @var NormAverage&MockObject
	 */
	private NormAverage $norms;

	/**
	 * Set up a register with two case types and three cases.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$this->register->seed(schema: 'caseType', uuid: 't1', row: ['title' => 'Permit']);
		$this->register->seed(schema: 'caseType', uuid: 't2', row: ['title' => 'Objection']);
		$this->register->seed(schema: 'case', uuid: 'c1', row: ['caseType' => 't1']);
		$this->register->seed(schema: 'case', uuid: 'c2', row: ['caseType' => 't2']);
		$this->register->seed(schema: 'case', uuid: 'c3', row: ['caseType' => 't1']);

		$this->settings = $this->createMock(originalClassName: SettingsService::class);
		$this->settings->method('getObjectService')->willReturn($this->register);
		$this->settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', 'dossiq'],
				['case_schema', '', 'case'],
				['case_type_schema', '', 'caseType'],
			]
		);

		$this->enricher = $this->createMock(originalClassName: CaseEnricher::class);
		$this->compliance = $this->createMock(originalClassName: DeadlineComplianceCalculator::class);
		$this->throughput = $this->createMock(originalClassName: CaseTypeThroughputCalculator::class);
		$this->norms = $this->createMock(originalClassName: NormAverage::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return DoorlooptijdService The service.
	 */
	private function service(): DoorlooptijdService {
		return new DoorlooptijdService(
			settingsService: $this->settings,
			caseEnricher: $this->enricher,
			complianceCalculator: $this->compliance,
			throughputCalculator: $this->throughput,
			norms: $this->norms,
		);
	}//end service()

	/**
	 * The ids of the given rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<int, string> The ids.
	 */
	private static function ids(array $rows): array {
		return array_column($rows, 'id');
	}//end ids()

	/**
	 * Without parameters the metrics cover every case, twelve months by month, at-risk five days.
	 *
	 * @return void
	 */
	public function testMetricsWithDefaults(): void {
		$enriched = [['id' => 'e1']];
		$this->enricher->expects($this->once())->method('enrichCases')
			->with(
				$this->callback(callback: fn (array $cases): bool => self::ids(rows: $cases) === ['c1', 'c2', 'c3']),
				$this->callback(callback: fn (array $types): bool => self::ids(rows: $types) === ['t1', 't2'])
			)
			->willReturn($enriched);
		$this->compliance->expects($this->once())->method('computeKpi')->with($enriched, 5)->willReturn(['kpi' => 1]);
		$this->compliance->expects($this->once())->method('computeMonthlyCompliance')->with($enriched, '12m')->willReturn(['m' => 1]);
		$this->compliance->expects($this->once())->method('buildCaseList')->with($enriched, 5)->willReturn(['list']);
		$this->compliance->expects($this->once())->method('computeSummary')->with($enriched, '12m', null, 'month')->willReturn(
			['total' => 3, 'normDays' => 99]
		);
		$this->throughput->method('computeCaseTypeBreakdown')->willReturn(['breakdown']);
		$this->throughput->method('computeWithinNormByCaseType')->willReturn(['t1' => 50]);
		$this->norms->expects($this->once())->method('averageOf')->with($enriched)->willReturn(28);

		$this->assertSame(
			expected: [
				'kpi' => ['kpi' => 1],
				'compliance' => ['m' => 1],
				'caseTypeBreakdown' => ['breakdown'],
				'cases' => ['list'],
				'summary' => [
					'total' => 3,
					'normDays' => 28,
					'withinNormByCaseType' => ['t1' => 50],
				],
			],
			actual: $this->service()->getMetrics(params: [])
		);
	}//end testMetricsWithDefaults()

	/**
	 * The case type, period, granularity and at-risk window are passed on; a negative window is zero.
	 *
	 * @return void
	 */
	public function testMetricsHonourTheirParameters(): void {
		$this->enricher->expects($this->once())->method('enrichCases')
			->with($this->callback(callback: fn (array $cases): bool => self::ids(rows: $cases) === ['c1', 'c3']), $this->anything())
			->willReturn([]);
		$this->compliance->expects($this->once())->method('computeKpi')->with([], 0)->willReturn([]);
		$this->compliance->expects($this->once())->method('computeMonthlyCompliance')->with([], '6m')->willReturn([]);
		$this->compliance->expects($this->once())->method('computeSummary')->with([], '6m', null, 'week')->willReturn([]);
		$this->compliance->method('buildCaseList')->willReturn([]);
		$this->throughput->method('computeCaseTypeBreakdown')->willReturn([]);
		$this->throughput->method('computeWithinNormByCaseType')->willReturn([]);

		$this->service()->getMetrics(
			params: ['caseType' => 't1', 'period' => '6m', 'granularity' => 'week', 'atRiskDays' => '-3']
		);
	}//end testMetricsHonourTheirParameters()

	/**
	 * Non-string parameters fall back to their defaults.
	 *
	 * @return void
	 */
	public function testMetricsIgnoreNonStringParameters(): void {
		$this->enricher->expects($this->once())->method('enrichCases')
			->with($this->callback(callback: fn (array $cases): bool => count($cases) === 3), $this->anything())
			->willReturn([]);
		$this->compliance->expects($this->once())->method('computeKpi')->with([], 10)->willReturn([]);
		$this->compliance->expects($this->once())->method('computeSummary')->with([], '12m', null, 'month')->willReturn([]);
		$this->compliance->method('computeMonthlyCompliance')->willReturn([]);
		$this->compliance->method('buildCaseList')->willReturn([]);
		$this->throughput->method('computeCaseTypeBreakdown')->willReturn([]);
		$this->throughput->method('computeWithinNormByCaseType')->willReturn([]);

		$this->service()->getMetrics(params: ['caseType' => ['t1'], 'period' => 6, 'granularity' => null, 'atRiskDays' => 10]);
	}//end testMetricsIgnoreNonStringParameters()

	/**
	 * Without a configured register nothing is loaded and the calculators see no cases.
	 *
	 * @return void
	 */
	public function testUnconfiguredRegisterLoadsNothing(): void {
		$this->settings = $this->createMock(originalClassName: SettingsService::class);
		$this->settings->method('getObjectService')->willReturn(null);
		$this->enricher->expects($this->once())->method('enrichCases')->with([], [])->willReturn([]);
		$this->throughput->expects($this->once())->method('computeMonthlyByCaseType')->with([], [], 12)->willReturn([]);

		$this->assertSame(expected: [], actual: $this->service()->getMonthlyRows(params: []));
	}//end testUnconfiguredRegisterLoadsNothing()

	/**
	 * Monthly rows filter by case type and read the month count from the period, clamped to 1..36.
	 *
	 * @return void
	 */
	public function testMonthlyRowsReadTheirPeriod(): void {
		$this->enricher->method('enrichCases')->willReturnCallback(fn (array $cases): array => $cases);
		$filtered = [];
		$this->throughput->method('computeMonthlyByCaseType')->willReturnCallback(
			function (array $cases, array $caseTypes, int $months) use (&$filtered, &$monthsSeen): array {
				$filtered[] = self::ids(rows: $cases);
				$monthsSeen[] = $months;
				return ['rows'];
			}
		);
		$monthsSeen = [];
		$service = $this->service();

		$this->assertSame(expected: ['rows'], actual: $service->getMonthlyRows(params: ['caseType' => 't2', 'period' => '6m']));
		$service->getMonthlyRows(params: ['period' => '0m']);
		$service->getMonthlyRows(params: ['period' => '120m']);
		$service->getMonthlyRows(params: ['period' => '1y', 'caseType' => '']);

		$this->assertSame(expected: [6, 1, 36, 12], actual: $monthsSeen);
		$this->assertSame(expected: [['c2'], ['c1', 'c2', 'c3'], ['c1', 'c2', 'c3'], ['c1', 'c2', 'c3']], actual: $filtered);
	}//end testMonthlyRowsReadTheirPeriod()

	/**
	 * The calculator pass-throughs answer what their calculator answers.
	 *
	 * @return void
	 */
	public function testPassThroughsDelegate(): void {
		$cases = [['id' => 'c1']];
		$types = [['id' => 't1']];
		$this->compliance->expects($this->once())->method('computeKpi')->with($cases, 3)->willReturn(['kpi']);
		$this->compliance->expects($this->once())->method('computeMonthlyCompliance')->with($cases, '3m')->willReturn(['monthly']);
		$this->compliance->expects($this->once())->method('buildCaseList')->with($cases, 4)->willReturn(['list']);
		$this->throughput->expects($this->once())->method('computeCaseTypeBreakdown')->with($cases, $types)->willReturn(['breakdown']);
		$this->enricher->expects($this->once())->method('enrichCases')->with($cases, $types)->willReturn(['enriched']);

		$service = $this->service();

		$this->assertSame(expected: ['kpi'], actual: $service->computeKpi(cases: $cases, atRiskDays: 3));
		$this->assertSame(expected: ['monthly'], actual: $service->computeMonthlyCompliance(cases: $cases, period: '3m'));
		$this->assertSame(expected: ['list'], actual: $service->buildCaseList(cases: $cases, atRiskDays: 4));
		$this->assertSame(expected: ['breakdown'], actual: $service->computeCaseTypeBreakdown(cases: $cases, caseTypes: $types));
		$this->assertSame(expected: ['enriched'], actual: $service->enrichCases(cases: $cases, caseTypes: $types));
	}//end testPassThroughsDelegate()
}//end class
