<?php

/**
 * Dossiq DeadlineReportingService.
 *
 * Two report families:
 *   - Quarterly KPI report (per zaaktype): totaal, binnen-termijn %,
 *     gemiddelde doorlooptijd, verlengingen, overschrijdingen,
 *     ingebrekestellingen, dwangsom-total.
 *   - Annual dwangsom audit report: per-uitbetaling row
 *     (zaak-ref, zaaktype, ingebrekestelling-datum, beschikking-datum,
 *     bedrag, betaal-datum, betalings-referentie, status).
 *
 * Plus dashboard KPI snapshot.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\Money\MoneyConfigKeys;
use OCA\Dossiq\Service\Money\PayoutReader;
use OCA\Dossiq\Service\Reporting\PerTypeRows;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Term\DayCount;
use OCA\Dossiq\Service\Termijn\TermCaseTypeResolver;
use OCA\Dossiq\Service\Termijn\TermDashboardFigures;
use OCA\Dossiq\Service\Termijn\TermOutcome;
use RuntimeException;
use OCP\IL10N;

/**
 * Quarterly KPI + annual dwangsom audit + dashboard KPI reports.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */
class DeadlineReportingService {
	use SearchesObjects;

	/**
	 * The bucket for a term whose case type resolves neither through its case
	 * nor through its definition (REQ-WTR-004).
	 *
	 * @var string
	 */
	public const UNRESOLVED = TermCaseTypeResolver::UNRESOLVED;

	/**
	 * Old report key => its generic name. Both are sent for one release.
	 *
	 * @var array<string, string>
	 */
	public const GENERIC_KEYS = [
		'totalZaken' => 'totalCases',
		'withinTermijnPercent' => 'withinTermPercent',
		'dwangsomTotalCents' => 'penaltyTotalCents',
		'totaal' => 'total',
		'binnenTermijnPct' => 'withinTermPct',
		'gemiddeldeDoorlooptijdDagen' => 'averageProcessingDays',
		'verlengingen' => 'extensions',
		'overschrijdingen' => 'overruns',
		'ingebrekestellingen' => 'noticesOfDefault',
		'bedragCents' => 'amountCents',
		'betalingsreferentie' => 'paymentReference',
	];

	/**
	 * Resolves each term's case type for the quarterly report.
	 *
	 * @var TermCaseTypeResolver
	 */
	private readonly TermCaseTypeResolver $caseTypes;

	/**
	 * Classifies each term as met, missed, running or suspended.
	 *
	 * @var TermOutcome
	 */
	private readonly TermOutcome $outcomes;

	/**
	 * Lists per-type figures as table rows.
	 *
	 * @var PerTypeRows
	 */
	private readonly PerTypeRows $perTypeRows;

	/**
	 * The running figures and the share on time the deadline dashboard shows.
	 *
	 * @var TermDashboardFigures
	 */
	private readonly TermDashboardFigures $figures;

	/**
	 * Constructor.
	 *
	 * @param SettingsService           $settingsService Settings.
	 * @param TermCaseTypeResolver|null $caseTypes       The case-type resolver; built from the settings when absent.
	 * @param DayCount                  $days            Counts the days between two moments.
	 * @param PayoutReader|null         $payouts         Reads the payouts from the finance app, beside the rows it does not hold.
	 * @param IL10N|null                $l10n            Words the KPI notes; without it they are empty.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		?TermCaseTypeResolver $caseTypes = null,
		private readonly DayCount $days = new DayCount(),
		private readonly ?PayoutReader $payouts = null,
		?IL10N $l10n = null,
	) {
		$this->caseTypes = ($caseTypes ?? new TermCaseTypeResolver(settingsService: $settingsService));
		$this->outcomes = new TermOutcome();
		$this->perTypeRows = new PerTypeRows();
		$this->figures = new TermDashboardFigures(settingsService: $settingsService, caseTypes: $this->caseTypes, l10n: $l10n);
	}//end __construct()

	/**
	 * The per case type aggregates of the terms that started inside a window.
	 *
	 * @param array{from: string, until: string} $window First and last day (YYYY-MM-DD).
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	public function perTypeBetween(array $window): array {
		$rows = $this->listInstances(from: $window['from'], until: $window['until']);
		$types = $this->caseTypes->resolve(rows: $rows);
		return $this->reducePerType(byType: $this->aggregateByType(rows: $rows, department: null, types: $types['byRow']));
	}//end perTypeBetween()

	/**
	 * Generate a quarterly KPI report.
	 *
	 * @param string $period Period (YYYY-Qn, e.g. "2026-Q2").
	 * @param string|null $department Optional department filter.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
	 */
	public function generateQuarterlyReport(string $period, ?string $department = null): array {
		$bounds = $this->resolveQuarter(period: $period);
		$rows = $this->listInstances(from: $bounds['from'], until: $bounds['until']);
		$types = $this->caseTypes->resolve(rows: $rows);
		$perType = $this->reducePerType(byType: $this->aggregateByType(rows: $rows, department: $department, types: $types['byRow']));

		return [
			// `periode` is the old name of `period`, kept for one release for
			// readers of the old report.
			'period' => $period,
			'periode' => $period,
			'department' => $department,
			'from' => $bounds['from'],
			'until' => $bounds['until'],
			'perType' => $perType,
			'rows' => $this->perTypeRows->listOf(perType: $perType),
			'summaryRows' => $this->quarterSummary(period: $period, perType: $perType),
			'metadata' => [
				'generatedAt' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
				'rowsScanned' => count($rows),
				// A term whose case type could not be resolved is counted under
				// `unresolved` and named here, never hidden under `unknown`.
				'unresolvedInstances' => $types['unresolved'],
			],
		];
	}//end generateQuarterlyReport()

	/**
	 * The quarter's decided terms as one row a footnote reads: how many were
	 * decided, how many within the statutory or extended term, and the share.
	 * Empty when nothing was decided, so no line says "0 decided".
	 *
	 * @param string $period The quarter, YYYY-Qn.
	 * @param array<string, array<string, mixed>> $perType The per-case-type figures.
	 *
	 * @return array<int, array<string, int|string>> The summary row, or none.
	 *
	 * @spec openspec/changes/termijnen-week-and-footnote/specs/termijnen-week-and-footnote/spec.md#requirement-the-quarterly-report-summarises-the-decided-terms
	 */
	private function quarterSummary(string $period, array $perType): array {
		$met = 0;
		$decided = 0;
		foreach ($perType as $figures) {
			$met += (int)($figures['met'] ?? 0);
			$decided += ((int)($figures['met'] ?? 0) + (int)($figures['missed'] ?? 0));
		}

		if ($decided === 0) {
			return [];
		}

		[$year, $quarter] = explode('-Q', $period);
		return [
			[
				'period' => $period,
				'quarter' => (int)$quarter,
				'year' => (int)$year,
				'decided' => $decided,
				'withinTerm' => $met,
				'share' => (int)round(($met / $decided) * 100),
			],
		];
	}//end quarterSummary()

	/**
	 * Bucket instance rows per case type, skipping rows outside the department filter.
	 *
	 * @param array<int, array<string, mixed>> $rows Instance rows.
	 * @param string|null $department Optional department filter.
	 * @param array<int, array{key: string, title: string}> $types The case type of each row, by row index.
	 *
	 * @return array<string, array<string, mixed>> Raw per-case-type tallies.
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	private function aggregateByType(array $rows, ?string $department, array $types): array {
		$byType   = [];
		$accruals = $this->accrualsByInstance();
		foreach ($rows as $index => $row) {
			$type = ($types[$index] ?? ['key' => self::UNRESOLVED, 'title' => '']);
			// $row['department'] is a SCHEMA PROPERTY — OpenRegister materialises
			// it as a real column. It moves with a data migration, not here.
			if ($department !== null && (string)($row['department'] ?? '') !== $department) {
				continue;
			}

			$byType[$type['key']] ??= [
				'title' => $type['title'],
				'totaal' => 0,
				'withinTerm' => 0,
				'doorlooptijdenDagen' => [],
				'verlengingen' => 0,
				'overschrijdingen' => 0,
				'ingebrekestellingen' => 0,
				'dwangsomTotalCents' => 0,
				'met' => 0,
				'missed' => 0,
				'running' => 0,
				'suspended' => 0,
			];

			$this->accumulateRow(row: $row, bucket: $byType[$type['key']]);
			$this->accumulateAccruals(row: $row, bucket: $byType[$type['key']], accruals: $accruals);
		}

		return $byType;
	}//end aggregateByType()

	/**
	 * The notices of default and the penalty amount on each term instance,
	 * read from the accruing amounts that point at it (`deadlineInstance`):
	 * the notice each one started from, and its definitive amount, else what
	 * it has accrued so far, in cents.
	 *
	 * @return array<string, array{notices: array<string, true>, cents: int}> Per instance id.
	 *
	 * @throws \Throwable When the amounts cannot be read; a report without them would read "no notices, no penalties".
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	private function accrualsByInstance(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue(MoneyConfigKeys::ACCRUING_AMOUNT_SCHEMA);
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		$rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: []);

		$byInstance = [];
		foreach ($rows as $row) {
			$instance = (string)($row['deadlineInstance'] ?? '');
			if ($instance === '') {
				continue;
			}

			$byInstance[$instance] ??= ['notices' => [], 'cents' => 0];
			$notice = (string)($row['noticeOfDefault'] ?? '');
			if ($notice !== '') {
				$byInstance[$instance]['notices'][$notice] = true;
			}

			$byInstance[$instance]['cents'] += (int)($row['definitiveAmount'] ?? $row['cumulativeAmount'] ?? 0);
		}

		return $byInstance;
	}//end accrualsByInstance()

	/**
	 * Add a term instance's notices of default and penalty amount to its bucket:
	 * the notice the instance names itself (`relevantIngbrekes`) and those its
	 * accruing amounts started from, each counted once.
	 *
	 * @param array<string, mixed>                                      $row      Instance row.
	 * @param array<string, mixed>                                      $bucket   Bucket for the row's case type (by reference).
	 * @param array<string, array{notices: array<string, true>, cents: int}> $accruals Per instance id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	private function accumulateAccruals(array $row, array &$bucket, array $accruals): void {
		$accrual = ($accruals[(string)($row['id'] ?? '')] ?? ['notices' => [], 'cents' => 0]);
		$notices = $accrual['notices'];
		$own = (string)($row['relevantIngbrekes'] ?? '');
		if ($own !== '') {
			$notices[$own] = true;
		}

		$bucket['ingebrekestellingen'] += count($notices);
		$bucket['dwangsomTotalCents'] += $accrual['cents'];
	}//end accumulateAccruals()

	/**
	 * Fold a single instance row into its zaaktype bucket.
	 *
	 * @param array<string, mixed> $row Instance row.
	 * @param array<string, mixed> $bucket Bucket for the row's zaaktype (by reference).
	 *
	 * @return void
	 */
	private function accumulateRow(array $row, array &$bucket): void {
		$bucket['totaal']++;
		$outcome = $this->outcomes->classify(row: $row);
		if ($outcome === TermOutcome::MET) {
			$bucket['withinTerm']++;
		}

		$status = (string)($row['status'] ?? '');
		if ($status === 'exceeded') {
			$bucket['overschrijdingen']++;
		}

		if ((int)($row['countExtensions'] ?? 0) > 0) {
			$bucket['verlengingen']++;
		}

		if ($outcome !== '') {
			$bucket[$outcome]++;
		}

		$start = (string)($row['startDate'] ?? '');
		$end = (string)($row['endDateCurrent'] ?? '');
		if ($start !== '' && $end !== '') {
			$startD = new DateTimeImmutable(substr($start, 0, 10));
			$endD = new DateTimeImmutable($end);
			$bucket['doorlooptijdenDagen'][] = $this->days->between(from: $startD, to: $endD);
		}
	}//end accumulateRow()

	/**
	 * Reduce the raw per-zaaktype tallies into the reported percentages and averages.
	 *
	 * @param array<string, array<string, mixed>> $byType Raw per-zaaktype tallies.
	 *
	 * @return array<string, array<string, mixed>> Reported per-zaaktype aggregates.
	 */
	private function reducePerType(array $byType): array {
		$perType = [];
		foreach ($byType as $type => $b) {
			// $byType entries are only created when a row is counted, so
			// 'totaal' is always >= 1 here.
			$total = $b['totaal'];
			// The share of DECIDED terms that were met: a running term is not
			// in the denominator, and a share of nothing is null, no figure.
			$binnenPct = $this->outcomes->shareMet(met: $b['met'], missed: $b['missed']);

			$avgDur = 0.0;

			$aantalDoorlooptijden = count($b['doorlooptijdenDagen']);
			if ($aantalDoorlooptijden > 0) {
				$avgDur = round(array_sum($b['doorlooptijdenDagen']) / $aantalDoorlooptijden, 1);
			}

			$perType[$type] = [
				'title' => $b['title'],
				'totaal' => $total,
				'binnenTermijnPct' => $binnenPct,
				'gemiddeldeDoorlooptijdDagen' => $avgDur,
				'verlengingen' => $b['verlengingen'],
				'overschrijdingen' => $b['overschrijdingen'],
				'ingebrekestellingen' => $b['ingebrekestellingen'],
				'dwangsomTotalCents' => $b['dwangsomTotalCents'],
				// The listing selects terms by start date in the quarter, so every
				// counted term arrived in it.
				'received' => $total,
				'met' => $b['met'],
				'missed' => $b['missed'],
				'running' => $b['running'],
				'suspended' => $b['suspended'],
				'metShare' => $binnenPct,
			];
			$perType[$type] += self::genericKeys(row: $perType[$type]);
		}//end foreach

		return $perType;
	}//end reducePerType()

	/**
	 * Generate an annual dwangsom audit report.
	 *
	 * @param int $year Year.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
	 */
	public function generateDwangsomAuditReport(int $year): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$uSchema = (string)$this->settingsService->getConfigValue(MoneyConfigKeys::PAYOUT_SCHEMA);
		if ($objectService === null || $register === '' || $uSchema === '') {
			return ['rows' => [], 'summary' => ['count' => 0, 'totalCents' => 0, 'totalEuros' => 0.0, 'warningCount' => 0]];
		}

		try {
			$rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $uSchema, filters: []);
		} catch (\Throwable $e) {
			return ['rows' => [], 'summary' => ['count' => 0, 'totalCents' => 0, 'totalEuros' => 0.0, 'warningCount' => 0]];
		}

		$rows = ($this->payouts?->mergedRows(storedRows: $rows, paidInYear: $year) ?? $rows);

		$yearPrefix = (string)$year;
		$outRows = [];
		$total = 0;
		$warnings = [];

		foreach ($rows as $row) {
			$payment = (string)($row['actualPaymentDate'] ?? '');
			if (str_starts_with($payment, $yearPrefix) === false) {
				continue;
			}

			$amount = (int)($row['amount'] ?? 0);
			$total += $amount;

			if (($row['betalingsreferentie'] ?? '') === '') {
				$warnings[] = 'Missing betalingsreferentie for ' . ((string)($row['reference'] ?? ''));
			}

			$outRow = [
				'reference' => (string)($row['reference'] ?? ''),
				'bedragCents' => $amount,
				'actualPaymentDate' => $payment,
				'betalingsreferentie' => (string)($row['betalingsreferentie'] ?? ''),
				'status' => (string)($row['status'] ?? ''),
				'legalBasis' => (string)($row['legalBasis'] ?? ''),
				'iban' => (string)($row['iban'] ?? ''),
			];
			$outRows[] = $outRow + self::genericKeys(row: $outRow);
		}//end foreach

		return [
			'year' => $year,
			'jaar' => $year,
			'rows' => $outRows,
			'summary' => [
				'count' => count($outRows),
				'totalCents' => $total,
				'totalEuros' => round($total / 100, 2),
				'warningCount' => count($warnings),
			],
			'warnings' => $warnings,
		];
	}//end generateDwangsomAuditReport()

	/**
	 * Compute a snapshot KPI summary for the dashboard widget.
	 *
	 * @param array<string, mixed>   $filters Optional filters (afdeling, zaaktype).
	 * @param DateTimeImmutable|null $today   The day the running figures are read on; now when absent.
	 * @param string|null            $from    First day (YYYY-MM-DD) of the window the share per case type covers; the current quarter when absent.
	 * @param string|null            $until   Last day (YYYY-MM-DD) of that window.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
	 */
	public function getTermijnKpi(array $filters = [], ?DateTimeImmutable $today = null, ?string $from = null, ?string $until = null): array {
		$rows = $this->listInstances(from: '1970-01-01', until: '2999-12-31');

		$dwTotal = 0;
		$totals = $this->collectKpiTotals(rows: $rows, filters: $filters);

		$total = $totals['total'];
		$within = $totals['within'];
		$overrun = $totals['overrun'];
		$durations = $totals['durations'];

		$withinTermPercent = $this->outcomes->shareMet(met: $within, missed: $totals['missed']);

		$aantalDuraties = count($durations);
		$avgDurationDays = 0.0;
		if ($aantalDuraties > 0) {
			$avgDurationDays = round(array_sum($durations) / $aantalDuraties, 1);
		}

		$kpi = [
			'totalZaken' => $total,
			'withinTermijnPercent' => $withinTermPercent,
			'avgDurationDays' => $avgDurationDays,
			'overrunCount' => $overrun,
			'dwangsomTotalCents' => $dwTotal,
		];

		return $kpi + self::genericKeys(row: $kpi) + [
			'lastUpdated' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
			...$this->figures->forDashboard(
				rows: $rows,
				today: $today,
				perType: $this->perTypeBetween(window: $this->figures->window(today: $today, from: $from, until: $until))
			),
		];
	}//end getTermijnKpi()

	/**
	 * Tally the dashboard KPI counters over the instance rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Instance rows.
	 * @param array<string, mixed> $filters Optional filters (afdeling, zaaktype).
	 *
	 * @return array{total:int,within:int,missed:int,overrun:int,durations:array<int,int>} Counters plus the collected doorlooptijden.
	 */
	private function collectKpiTotals(array $rows, array $filters): array {
		$total = 0;
		$within = 0;
		$missed = 0;
		$overrun = 0;
		$durations = [];
		foreach ($rows as $row) {
			if (isset($filters['caseType']) === true && (string)($row['caseType'] ?? '') !== $filters['caseType']) {
				continue;
			}

			$total++;
			$outcome = $this->outcomes->classify(row: $row);
			if ($outcome === TermOutcome::MET) {
				$within++;
			}

			if ($outcome === TermOutcome::MISSED) {
				$missed++;
			}

			if ((string)($row['status'] ?? '') === 'exceeded') {
				$overrun++;
			}

			$start = (string)($row['startDate'] ?? '');
			$end = (string)($row['endDateCurrent'] ?? '');
			if ($start !== '' && $end !== '') {
				$durations[] = $this->days->between(from: new DateTimeImmutable(substr($start, 0, 10)), to: new DateTimeImmutable($end));
			}
		}//end foreach

		return [
			'total' => $total,
			'within' => $within,
			'missed' => $missed,
			'overrun' => $overrun,
			'durations' => $durations,
		];
	}//end collectKpiTotals()

	/**
	 * The generic names of a row's procedure-named keys, with their values.
	 *
	 * A report carries both names for one release, so a cached client or an
	 * external report reader that still reads the old key keeps working.
	 *
	 * @param array<string, mixed> $row A report row or summary.
	 *
	 * @return array<string, mixed> The generic keys present in the row's old keys.
	 *
	 * @spec openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md
	 */
	public static function genericKeys(array $row): array {
		$generic = [];
		foreach (self::GENERIC_KEYS as $old => $new) {
			if (array_key_exists($old, $row) === true) {
				$generic[$new] = $row[$old];
			}
		}

		return $generic;
	}//end genericKeys()

	/**
	 * Generate a CSV for a quarterly report.
	 *
	 * @param array<string, mixed> $report Report.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
	 */
	public function quarterlyReportAsCsv(array $report): string {
		$header = [
			'caseType',
			'totaal',
			'binnenTermijnPct',
			'gemiddeldeDoorlooptijdDagen',
			'verlengingen',
			'overschrijdingen',
			'ingebrekestellingen',
			'dwangsomTotalCents',
		];
		$lines = [implode(',', $header)];
		foreach ((array)($report['perType'] ?? []) as $type => $row) {
			$line = [
				$type,
				(string)($row['totaal'] ?? 0),
				(string)($row['binnenTermijnPct'] ?? ''),
				(string)($row['gemiddeldeDoorlooptijdDagen'] ?? 0),
				(string)($row['verlengingen'] ?? 0),
				(string)($row['overschrijdingen'] ?? 0),
				(string)($row['ingebrekestellingen'] ?? 0),
				(string)($row['dwangsomTotalCents'] ?? 0),
			];
			$lines[] = implode(',', $line);
		}

		return implode("\n", $lines);
	}//end quarterlyReportAsCsv()

	/**
	 * Resolve a quarter spec (YYYY-Qn) to its from/until date bounds.
	 *
	 * @param string $period Period (YYYY-Qn).
	 *
	 * @return array{from:string,until:string}
	 */
	private function resolveQuarter(string $period): array {
		if (preg_match('/^(\d{4})-Q([1-4])$/', $period, $matches) !== 1) {
			throw new RuntimeException('Invalid periode (expected YYYY-Qn): ' . $period);
		}

		$year = (int)$matches[1];
		$quarter = (int)$matches[2];
		$startM = (($quarter - 1) * 3) + 1;
		$endM = $startM + 2;
		$from = sprintf('%04d-%02d-01', $year, $startM);
		$lastDay = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $endM)))->format('t');
		$until = sprintf('%04d-%02d-%02d', $year, $endM, $lastDay);
		return ['from' => $from, 'until' => $until];
	}//end resolveQuarter()

	/**
	 * List termijn instances whose start date falls within the given bounds.
	 *
	 * @param string $from YYYY-MM-DD.
	 * @param string $until YYYY-MM-DD.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function listInstances(string $from, string $until): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_instance_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: []);
		} catch (\Throwable $e) {
			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			$start = substr((string)($row['startDate'] ?? ''), 0, 10);
			if ($start === '' || ($start >= $from && $start <= $until) === false) {
				continue;
			}

			$out[] = $row;
		}

		return $out;
	}//end listInstances()
}//end class
