<?php

/**
 * Unit tests for DeadlineReportingService.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\DeadlineReportingService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Money\PayoutReader;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\DeadlineReportingService
 * @uses \OCA\Dossiq\Service\Termijn\TermCaseTypeResolver
 * @uses \OCA\Dossiq\Service\Termijn\TermOutcome
 * @uses \OCA\Dossiq\Service\Termijn\TermDashboardFigures
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses \OCA\Dossiq\Service\Term\DayCount
 */
class DeadlineReportingServiceTest extends TestCase {
	private FakeTermijnStore $objects;
	private DeadlineReportingService $service;

	/**
	 * The settings the service reads.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settings;

	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'termijn_instance_schema' => 'deadlineInstance',
					'termijn_definitie_schema' => 'deadlineDefinition',
					'case_schema' => 'case',
					'case_type_schema' => 'caseType',
					'payout_schema' => 'dwangsomUitbetaling',
					default => '',
				};
			},
		);

		$this->settings = $settings;
		$this->service = new DeadlineReportingService($settings);

		$this->objects->seed('caseType', ['id' => 'ct-omv', 'identifier' => 'omgevingsvergunning-regulier', 'title' => 'Omgevingsvergunning (regulier)']);
		$this->objects->seed('caseType', ['id' => 'ct-woo', 'identifier' => 'woo-verzoek', 'title' => 'Woo-verzoek']);

		// Seed 5 instances spread across Q2-2026 for one zaaktype. A term
		// instance has no caseType of its own: it is read through its case.
		for ($i = 1; $i <= 5; $i++) {
			$this->objects->seed('case', ['id' => 'case-q2-' . $i, 'caseType' => 'ct-omv']);
			$this->objects->seed('deadlineInstance', [
				'id' => 'ti-q2-' . $i,
				'case' => 'case-q2-' . $i,
				'startDate' => '2026-05-0' . $i . 'T10:00:00+00:00',
				'endDateCurrent' => '2026-07-0' . $i,
				'status' => ($i <= 3 ? 'completed' : ($i === 4 ? 'exceeded' : 'lopend')),
				'countExtensions' => ($i === 5 ? 1 : 0),
			]);
		}
	}

	/**
	 * @return void
	 */
	public function testQuarterlyReportAggregatesPerType(): void {
		$report = $this->service->generateQuarterlyReport('2026-Q2');
		self::assertSame('2026-04-01', $report['from']);
		self::assertSame('2026-06-30', $report['until']);

		$row = $report['perType']['omgevingsvergunning-regulier'];
		self::assertSame(5, $row['totaal']);
		self::assertSame(75.0, $row['binnenTermijnPct'], 'Three met of four decided: the running term is not in the share.');
		self::assertSame(1, $row['overschrijdingen']);
		self::assertSame(1, $row['verlengingen']);
		// Each seeded term runs from the Nth of May to the Nth of July: 61 days.
		self::assertSame(61.0, $row['gemiddeldeDoorlooptijdDagen']);
	}

	/**
	 * The quarter's decided terms in one line a footnote reads (DqTermijnen
	 * "Kwartaal 2 van 2026: 4 zaken beslist, 3 binnen de termijn (75 %)").
	 *
	 * @return void
	 */
	public function testQuarterlyReportSummarisesTheDecidedTerms(): void {
		$report = $this->service->generateQuarterlyReport('2026-Q2');

		self::assertSame(
			[['period' => '2026-Q2', 'quarter' => 2, 'year' => 2026, 'decided' => 4, 'withinTerm' => 3, 'share' => 75]],
			$report['summaryRows']
		);
	}//end testQuarterlyReportSummarisesTheDecidedTerms()

	/**
	 * The quarterly report counts the notices of default and the penalty
	 * amount of each term from the accruing amounts that point at it
	 * (DqTermijnen Kwartaalrapport: Parkstraat's € 1.442 under its case type).
	 *
	 * @return void
	 */
	public function testQuarterlyReportCountsNoticesAndPenaltiesFromAccruals(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'termijn_instance_schema' => 'deadlineInstance',
				'case_type_schema' => 'caseType',
				'case_schema' => 'case',
				'accruing_amount_schema' => 'penaltyPaymentCalculation',
				default => '',
			},
		);
		$this->objects->seed(
			'penaltyPaymentCalculation',
			['id' => 'pp-1', 'deadlineInstance' => 'ti-q2-4', 'noticeOfDefault' => 'nd-1', 'definitiveAmount' => 144200, 'cumulativeAmount' => 144200]
		);
		$this->objects->seed(
			'penaltyPaymentCalculation',
			['id' => 'pp-2', 'deadlineInstance' => 'ti-q2-5', 'noticeOfDefault' => 'nd-2', 'cumulativeAmount' => 20700]
		);

		$report = (new DeadlineReportingService(settingsService: $settings))->generateQuarterlyReport(period: '2026-Q2');
		$row    = $report['perType']['omgevingsvergunning-regulier'];

		self::assertSame(expected: 2, actual: $row['ingebrekestellingen']);
		self::assertSame(expected: 164900, actual: $row['dwangsomTotalCents']);
	}//end testQuarterlyReportCountsNoticesAndPenaltiesFromAccruals()

	/**
	 * Amounts that cannot be read stop the report rather than read as
	 * "no notices, no penalties".
	 *
	 * @return void
	 */
	public function testQuarterlyReportStopsWhenTheAccrualsCannotBeRead(): void {
		$objects = new class ($this->objects) {
			/**
			 * @param object $inner The store every other read goes to.
			 */
			public function __construct(private object $inner) {
			}

			/**
			 * @param string       $name      The method.
			 * @param array<mixed> $arguments The arguments.
			 *
			 * @return mixed
			 */
			public function __call(string $name, array $arguments): mixed {
				$schema = (string)($arguments['schema'] ?? ($arguments[1] ?? ''));
				if ($schema === 'penaltyPaymentCalculation') {
					throw new \RuntimeException('register unreachable');
				}

				return $this->inner->$name(...$arguments);
			}
		};
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'termijn_instance_schema' => 'deadlineInstance',
				'case_type_schema' => 'caseType',
				'case_schema' => 'case',
				'accruing_amount_schema' => 'penaltyPaymentCalculation',
				default => '',
			},
		);

		$this->expectException(\RuntimeException::class);
		(new DeadlineReportingService(settingsService: $settings))->generateQuarterlyReport(period: '2026-Q2');
	}//end testQuarterlyReportStopsWhenTheAccrualsCannotBeRead()

	/**
	 * A quarter without a decided term has no summary line.
	 *
	 * @return void
	 */
	public function testQuarterWithoutDecidedTermsHasNoSummary(): void {
		self::assertSame([], $this->service->generateQuarterlyReport('2027-Q1')['summaryRows']);
	}//end testQuarterWithoutDecidedTermsHasNoSummary()

	/**
	 * Seed a term in 2026 Q4 on a new case of the given case type.
	 *
	 * @param string               $id         The instance id.
	 * @param string|null          $caseType   The case's case type id, or null for no case.
	 * @param array<string, mixed> $overrides  Instance fields.
	 *
	 * @return void
	 */
	private function q4Term(string $id, ?string $caseType, array $overrides = []): void {
		if ($caseType !== null) {
			$this->objects->seed('case', ['id' => 'case-' . $id, 'caseType' => $caseType]);
		}

		$this->objects->seed('deadlineInstance', array_merge([
			'id' => $id,
			'case' => 'case-' . $id,
			'startDate' => '2026-10-05T09:00:00+02:00',
			'endDateCurrent' => '2026-11-02',
			'status' => 'lopend',
		], $overrides));
	}//end q4Term()

	/**
	 * REQ-WTR-004: Woo terms are reported under the Woo case type.
	 *
	 * @return void
	 */
	public function testWooTermsAreGroupedUnderTheWooCaseType(): void {
		foreach (['w1', 'w2', 'w3'] as $id) {
			$this->q4Term($id, 'ct-woo');
		}

		foreach (['o1', 'o2'] as $id) {
			$this->q4Term($id, 'ct-omv');
		}

		$report = $this->service->generateQuarterlyReport('2026-Q4');

		self::assertSame(3, $report['perType']['woo-verzoek']['totaal']);
		self::assertSame('Woo-verzoek', $report['perType']['woo-verzoek']['title']);
		self::assertSame(2, $report['perType']['omgevingsvergunning-regulier']['totaal']);
	}//end testWooTermsAreGroupedUnderTheWooCaseType()

	/**
	 * REQ-WTR-004: nothing lands under `unknown`; a term with neither a case
	 * nor a definition case type is `unresolved` and named; a deleted case
	 * falls back to the definition.
	 *
	 * @return void
	 */
	public function testNoTermIsReportedUnderUnknown(): void {
		$this->objects->seed('deadlineDefinition', ['id' => 'td-woo', 'caseType' => 'woo-verzoek']);
		$this->q4Term('deleted-case-with-definition', null, ['deadlineDefinition' => 'td-woo']);
		$this->q4Term('orphan', null);

		$report = $this->service->generateQuarterlyReport('2026-Q4');

		self::assertArrayNotHasKey('unknown', $report['perType']);
		self::assertSame(1, $report['perType']['woo-verzoek']['totaal']);
		self::assertSame(1, $report['perType']['unresolved']['totaal']);
		self::assertSame(['orphan'], $report['metadata']['unresolvedInstances']);
	}//end testNoTermIsReportedUnderUnknown()

	/**
	 * The quarterly figures also come as a list a table draws: each row named
	 * by the case type's title, or by its key without one, with the penalty
	 * total in euros beside the cents.
	 *
	 * @return void
	 */
	public function testQuarterlyRowsNameEachCaseType(): void {
		$this->q4Term('w1', 'ct-woo');
		$this->q4Term('orphan', null);

		$rows = array_column($this->service->generateQuarterlyReport('2026-Q4')['rows'], null, 'caseType');

		self::assertSame('Woo-verzoek', $rows['woo-verzoek']['caseTypeTitle']);
		self::assertSame(1, $rows['woo-verzoek']['totaal']);
		self::assertSame('unresolved', $rows['unresolved']['caseTypeTitle']);
		self::assertEqualsWithDelta(
			$rows['woo-verzoek']['dwangsomTotalCents'] / 100,
			$rows['woo-verzoek']['penaltyTotalEuros'],
			0.001
		);
	}//end testQuarterlyRowsNameEachCaseType()

	/**
	 * REQ-WTR-005: one met, one two days late, one running, one suspended.
	 *
	 * @return void
	 */
	public function testMetMissedRunningAndSuspendedAreCounted(): void {
		$this->q4Term('on-time', 'ct-woo', ['status' => 'completed', 'voltooiDatum' => '2026-10-30']);
		$this->q4Term('late', 'ct-woo', ['status' => 'completed', 'voltooiDatum' => '2026-11-04']);
		$this->q4Term('running', 'ct-woo');
		$this->q4Term('suspended', 'ct-woo', ['status' => 'paused']);

		$woo = $this->service->generateQuarterlyReport('2026-Q4')['perType']['woo-verzoek'];

		self::assertSame(4, $woo['received']);
		self::assertSame(1, $woo['met']);
		self::assertSame(1, $woo['missed']);
		self::assertSame(1, $woo['running']);
		self::assertSame(1, $woo['suspended']);
		self::assertSame(50.0, $woo['metShare']);
		self::assertSame(4, $woo['totaal'], 'the existing keys keep their meaning');
	}//end testMetMissedRunningAndSuspendedAreCounted()

	/**
	 * @return void
	 */
	public function testCsvExportShape(): void {
		$report = $this->service->generateQuarterlyReport('2026-Q2');
		$csv = $this->service->quarterlyReportAsCsv($report);
		$lines = explode("\n", $csv);
		self::assertStringStartsWith('caseType,totaal,binnenTermijnPct', $lines[0]);
		self::assertStringStartsWith('omgevingsvergunning-regulier,5,75', $lines[1]);
	}

	/**
	 * @return void
	 */
	public function testKpiSummary(): void {
		$k = $this->service->getTermijnKpi();
		self::assertSame(5, $k['totalZaken']);
		self::assertSame(75.0, $k['withinTermijnPercent']);
		self::assertSame(1, $k['overrunCount']);
		self::assertSame(61.0, $k['avgDurationDays']);
	}

	/**
	 * The running figures count only terms that still run, read against the
	 * given day, and the share per case type comes from that day's quarter.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	public function testKpiCarriesTheRunningFiguresOfTheDay(): void {
		$this->objects->seed('case', ['id' => 'case-due', 'caseType' => 'ct-woo']);
		$this->objects->seed('deadlineInstance', [
			'id' => 'ti-due',
			'case' => 'case-due',
			'startDate' => '2026-06-20T10:00:00+00:00',
			'endDateCurrent' => '2026-06-30',
			'status' => 'verlengd',
		]);

		$k = $this->service->getTermijnKpi([], new \DateTimeImmutable('2026-07-02'));

		self::assertSame(2, $k['runningCount']);
		self::assertSame(2, $k['runningCaseTypeCount']);
		self::assertSame(1, $k['dueThisWeekCount']);
		self::assertSame(1, $k['overdueCount']);
		self::assertSame(0.0, $k['penaltyRunningEuros']);
		self::assertSame([], $k['onTimeByCaseType']['labels']);
	}

	/**
	 * The KPI tiles say which case type most of the week's ending terms belong
	 * to, and how far the longest running penalty is (DqTermijnen "3 bij
	 * Woo-verzoeken", "1 zaak, dag 9 van 42").
	 *
	 * @return void
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	public function testKpiNamesTheWeeksLeadCaseTypeAndThePenaltyDay(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'termijn_instance_schema' => 'deadlineInstance',
				'case_type_schema' => 'caseType',
				'case_schema' => 'case',
				'accruing_amount_schema' => 'penaltyPaymentCalculation',
				default => '',
			},
		);
		foreach (['a', 'b'] as $id) {
			$this->objects->seed('case', ['id' => 'case-woo-' . $id, 'caseType' => 'ct-woo']);
			$this->objects->seed(
				'deadlineInstance',
				['id' => 'ti-woo-' . $id, 'case' => 'case-woo-' . $id, 'startDate' => '2026-06-20', 'endDateCurrent' => '2026-07-05', 'status' => 'lopend']
			);
		}

		$this->objects->seed('case', ['id' => 'case-omv-week', 'caseType' => 'ct-omv']);
		$this->objects->seed(
			'deadlineInstance',
			['id' => 'ti-omv-later', 'case' => 'case-omv-week', 'startDate' => '2026-06-20', 'endDateCurrent' => '2026-07-20', 'status' => 'lopend']
		);
		$bands = [['upTo' => 14, 'rate' => 2300], ['upTo' => 28, 'rate' => 3500], ['upTo' => 42, 'rate' => 4500]];
		$this->objects->seed('penaltyPaymentCalculation', ['id' => 'pp-run', 'status' => 'lopend', 'currentDag' => 9, 'rateBands' => $bands]);
		$this->objects->seed('penaltyPaymentCalculation', ['id' => 'pp-done', 'status' => 'gestopt', 'currentDag' => 30]);

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('n')->willReturnCallback(
			static function (string $one, string $many, int $count, array $params = []): string {
				$text = $many;
				if ($count === 1) {
					$text = $one;
				}

				return vsprintf(format: str_replace(search: '%n', replace: (string)$count, subject: $text), values: $params);
			}
		);

		$k = (new DeadlineReportingService(settingsService: $settings, l10n: $l10n))->getTermijnKpi([], new \DateTimeImmutable('2026-07-02'));

		self::assertSame(expected: 3, actual: $k['dueThisWeekCount']);
		self::assertSame(expected: '2 at Woo-verzoek', actual: $k['dueThisWeekNote']);
		self::assertSame(expected: '1 case, day 9 of 42', actual: $k['penaltyRunningNote']);
	}//end testKpiNamesTheWeeksLeadCaseTypeAndThePenaltyDay()

	/**
	 * Without terms ending this week or a running penalty there is no note.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	public function testKpiHasNoNotesWithoutTheirFigures(): void {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$k = (new DeadlineReportingService(settingsService: $this->settings, l10n: $l10n))->getTermijnKpi([], new \DateTimeImmutable('2027-07-02'));

		self::assertSame(expected: '', actual: $k['dueThisWeekNote']);
		self::assertSame(expected: '', actual: $k['penaltyRunningNote']);
	}//end testKpiHasNoNotesWithoutTheirFigures()

	/**
	 * A window given by the page decides which terms the share per case type covers.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	public function testShareOnTimeFollowsTheGivenWindow(): void {
		$inWindow = $this->service->getTermijnKpi([], new \DateTimeImmutable('2026-10-16'), '2026-04-01', '2026-06-30');
		self::assertSame(['Omgevingsvergunning (regulier)'], $inWindow['onTimeByCaseType']['labels']);
		self::assertSame([75.0], $inWindow['onTimeByCaseType']['shares']);

		$currentQuarter = $this->service->getTermijnKpi([], new \DateTimeImmutable('2026-10-16'));
		self::assertSame([], $currentQuarter['onTimeByCaseType']['labels']);
	}

	/**
	 * With nothing decided the dashboard has no share to show, and the CSV
	 * leaves the cell empty rather than writing 0.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-the-term-behaves-as-opencatalogis-did-proven-on-its-fixtures-req-wto-003
	 */
	public function testAShareOfNothingIsNoFigure(): void {
		$k = $this->service->getTermijnKpi(['caseType' => 'nothing-decided']);
		self::assertSame(0, $k['totalZaken']);
		self::assertNull($k['withinTermijnPercent']);

		$csv = $this->service->quarterlyReportAsCsv(['perType' => ['t' => ['totaal' => 2, 'binnenTermijnPct' => null]]]);
		self::assertStringStartsWith('t,2,,', explode("\n", $csv)[1]);
	}

	/**
	 * @return void
	 */
	public function testInvalidPeriodeRaises(): void {
		$this->expectException(\RuntimeException::class);
		$this->service->generateQuarterlyReport('not-a-quarter');
	}

	/**
	 * @return void
	 */
	public function testDwangsomAuditReportFiltersByYear(): void {
		// Seed two uitbetalingen, one in 2026 one in 2025.
		$this->objects->seed('dwangsomUitbetaling', [
			'id' => 'u-1',
			'reference' => 'REF-1',
			'amount' => 35700,
			'actualPaymentDate' => '2026-04-20',
			'betalingsreferentie' => 'ERP-1',
			'status' => 'paid',
			'wettelijkeGrondslag' => 'AWB 4:17',
			'iban' => 'NL91ABNA0417164300',
		]);
		$this->objects->seed('dwangsomUitbetaling', [
			'id' => 'u-2',
			'reference' => 'REF-2',
			'amount' => 50000,
			'actualPaymentDate' => '2025-12-31',
			'betalingsreferentie' => 'ERP-2',
			'status' => 'paid',
		]);

		$report = $this->service->generateDwangsomAuditReport(2026);
		self::assertSame(1, $report['summary']['count']);
		self::assertSame(35700, $report['summary']['totalCents']);
		self::assertSame(357.0, $report['summary']['totalEuros']);
		self::assertSame(count($report['warnings']), $report['summary']['warningCount']);
	}

	/**
	 * With a finance app the annual statement counts the payouts finance paid
	 * in the year (REQ-DQM-004).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
	 */
	public function testTheAnnualStatementReadsFinancesPayouts(): void {
		$asked = null;
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use (&$asked): void {
				$asked = $event;
				$event->answer([['payableId' => 'payable-1', 'reference' => 'DWS-1', 'correlationId' => 'calc-1', 'amount' => 357.0, 'state' => 'paid', 'paidOn' => '2026-04-20', 'bankReference' => 'ERP-1', 'payee' => ['name' => 'J', 'iban' => 'NL91ABNA0417164300']]]);
			}
		);
		$service = new DeadlineReportingService(settingsService: $this->settings, payouts: new PayoutReader(dispatcher: $dispatcher));

		$report = $service->generateDwangsomAuditReport(2026);

		self::assertSame(2026, $asked->getPaidInYear());
		self::assertSame(1, $report['summary']['count']);
		self::assertSame(35700, $report['summary']['totalCents']);
		self::assertSame('ERP-1', $report['rows'][0]['betalingsreferentie']);
		self::assertSame('NL91ABNA0417164300', $report['rows'][0]['iban']);
	}

	/**
	 * Every report carries the generic key beside the old one, with the same
	 * value, so a reader of either name gets the figure for one release.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md
	 */
	public function testReportsCarryTheGenericKeysBesideTheOldOnes(): void {
		$this->objects->seed('dwangsomUitbetaling', [
			'id' => 'u-1',
			'reference' => 'REF-1',
			'amount' => 35700,
			'actualPaymentDate' => '2026-04-20',
			'betalingsreferentie' => 'ERP-1',
			'status' => 'paid',
		]);

		$quarterly = $this->service->generateQuarterlyReport('2026-Q2');
		self::assertSame('2026-Q2', $quarterly['period']);
		self::assertSame($quarterly['periode'], $quarterly['period']);
		$row = $quarterly['perType']['omgevingsvergunning-regulier'];
		$pairs = [
			'totaal' => 'total',
			'binnenTermijnPct' => 'withinTermPct',
			'gemiddeldeDoorlooptijdDagen' => 'averageProcessingDays',
			'verlengingen' => 'extensions',
			'overschrijdingen' => 'overruns',
			'ingebrekestellingen' => 'noticesOfDefault',
			'dwangsomTotalCents' => 'penaltyTotalCents',
		];
		foreach ($pairs as $old => $new) {
			self::assertArrayHasKey($new, $row, $new . ' missing beside ' . $old);
			self::assertSame($row[$old], $row[$new]);
		}

		$listed = array_column($quarterly['rows'], 'withinTermPct', 'caseType');
		self::assertSame(75.0, $listed['omgevingsvergunning-regulier']);

		$kpi = $this->service->getTermijnKpi();
		self::assertSame($kpi['totalZaken'], $kpi['totalCases']);
		self::assertSame($kpi['withinTermijnPercent'], $kpi['withinTermPercent']);
		self::assertSame($kpi['dwangsomTotalCents'], $kpi['penaltyTotalCents']);

		$annual = $this->service->generateDwangsomAuditReport(2026);
		self::assertSame(2026, $annual['year']);
		self::assertSame(35700, $annual['rows'][0]['amountCents']);
		self::assertSame('ERP-1', $annual['rows'][0]['paymentReference']);
		self::assertSame($annual['rows'][0]['bedragCents'], $annual['rows'][0]['amountCents']);
	}

	/**
	 * A row without an old key gains no generic key.
	 *
	 * @return void
	 */
	public function testGenericKeysAddNothingARowDoesNotHave(): void {
		self::assertSame([], DeadlineReportingService::genericKeys(row: ['received' => 3]));
		self::assertSame(['total' => 3], DeadlineReportingService::genericKeys(row: ['totaal' => 3]));
	}
}
