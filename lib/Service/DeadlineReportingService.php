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
use OCA\Dossiq\Service\Support\SearchesObjects;
use RuntimeException;

/**
 * Quarterly KPI + annual dwangsom audit + dashboard KPI reports.
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
	public const UNRESOLVED = 'unresolved';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

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

		$types = $this->caseTypesOf(rows: $rows);
		$byType = $this->aggregateByType(rows: $rows, department: $department, types: $types['byRow']);

		// Reduce per-type aggregates.
		$perType = $this->reducePerType(byType: $byType);

		return [
			// 'periode' and 'department' are RESPONSE KEYS, not identifiers. They
			// are the published shape of /api/termijn/reports/kwartaal and are
			// read by the dashboard; they move only with a coordinated frontend
			// change, not with this rename.
			'periode' => $period,
			'department' => $department,
			'from' => $bounds['from'],
			'until' => $bounds['until'],
			'perType' => $perType,
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
		$byType = [];
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
		}

		return $byType;
	}//end aggregateByType()

	/**
	 * The case type each term belongs to, resolved in batched reads.
	 *
	 * Through the case the term names (`deadlineInstance.case` to
	 * `case.caseType`), and otherwise through its definition's case type. A
	 * term neither resolves is `unresolved`, and its id is listed. Three
	 * searches at most, each restricted by `_ids`, never one per row.
	 *
	 * @param array<int, array<string, mixed>> $rows Instance rows.
	 *
	 * @return array{byRow: array<int, array{key: string, title: string}>, unresolved: array<int, string>}
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	private function caseTypesOf(array $rows): array {
		$cases = $this->batch(configKey: 'case_schema', ids: array_map(fn (array $row): string => $this->ref(value: ($row['case'] ?? null)), $rows));
		$caseTypes = $this->batch(
			configKey: 'case_type_schema',
			ids: array_map(fn (array $case): string => $this->ref(value: ($case['caseType'] ?? null)), array_values($cases))
		);
		$definitions = $this->batch(
			configKey: 'termijn_definitie_schema',
			ids: array_map(fn (array $row): string => $this->ref(value: ($row['deadlineDefinition'] ?? null)), $rows)
		);

		$titlesBySlug = [];
		foreach ($caseTypes as $caseType) {
			$titlesBySlug[$this->caseTypeKey(caseType: $caseType)] = (string)($caseType['title'] ?? '');
		}

		$byRow = [];
		$unresolved = [];
		foreach ($rows as $index => $row) {
			$case = ($cases[$this->ref(value: ($row['case'] ?? null))] ?? null);
			$caseTypeId = $this->ref(value: ($case['caseType'] ?? null));
			if ($caseTypeId !== '' && isset($caseTypes[$caseTypeId]) === true) {
				$key = $this->caseTypeKey(caseType: $caseTypes[$caseTypeId]);
				$byRow[$index] = ['key' => $key, 'title' => (string)($caseTypes[$caseTypeId]['title'] ?? '')];
				continue;
			}

			$slug = trim((string)($definitions[$this->ref(value: ($row['deadlineDefinition'] ?? null))]['caseType'] ?? ''));
			if ($slug !== '') {
				$byRow[$index] = ['key' => $slug, 'title' => ($titlesBySlug[$slug] ?? '')];
				continue;
			}

			$byRow[$index] = ['key' => self::UNRESOLVED, 'title' => ''];
			$unresolved[] = (string)($row['id'] ?? '');
		}//end foreach

		return ['byRow' => $byRow, 'unresolved' => $unresolved];
	}//end caseTypesOf()

	/**
	 * The key a case type is reported under: its identifier, then its slug, then its id.
	 *
	 * The identifier is what a term definition names as its `caseType`, so a
	 * term resolved through its case and one resolved through its definition
	 * land in the same bucket.
	 *
	 * @param array<string, mixed> $caseType The case type row.
	 *
	 * @return string The key.
	 */
	private function caseTypeKey(array $caseType): string {
		$candidates = [
			($caseType['identifier'] ?? null),
			($caseType['@self']['slug'] ?? null),
			($caseType['slug'] ?? null),
			($caseType['id'] ?? null),
		];
		foreach ($candidates as $candidate) {
			if (is_string($candidate) === true && trim($candidate) !== '') {
				return trim($candidate);
			}
		}

		return self::UNRESOLVED;
	}//end caseTypeKey()

	/**
	 * Read the rows with these ids from one schema, in one search.
	 *
	 * @param string             $configKey The schema's config key.
	 * @param array<int, string> $ids       The ids; empties and repeats are dropped.
	 *
	 * @return array<string, array<string, mixed>> The rows, keyed by id.
	 */
	private function batch(string $configKey, array $ids): array {
		$ids = array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue($configKey);
		if ($ids === [] || $objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_ids' => $ids, '_limit' => count($ids)]
			);
		} catch (\Throwable $e) {
			return [];
		}

		$keyed = [];
		foreach ($rows as $row) {
			$id = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
			if ($id !== '') {
				$keyed[$id] = $row;
			}
		}

		return $keyed;
	}//end batch()

	/**
	 * The id a reference carries, whether it arrived as a uuid or as a row.
	 *
	 * @param mixed $value A uuid string, or an array carrying `id`/`uuid`.
	 *
	 * @return string The id, or the empty string.
	 */
	private function ref(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? ''));
		}

		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end ref()

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
		$status = (string)($row['status'] ?? '');
		if ($status === 'completed') {
			$bucket['withinTerm']++;
		}

		if ($status === 'exceeded') {
			$bucket['overschrijdingen']++;
		}

		if ((int)($row['countExtensions'] ?? 0) > 0) {
			$bucket['verlengingen']++;
		}

		$outcome = $this->outcomeOf(row: $row);
		if ($outcome !== '') {
			$bucket[$outcome]++;
		}

		$start = (string)($row['startDate'] ?? '');
		$end = (string)($row['endDateCurrent'] ?? '');
		if ($start !== '' && $end !== '') {
			$startD = new DateTimeImmutable(substr($start, 0, 10));
			$endD = new DateTimeImmutable($end);
			$bucket['doorlooptijdenDagen'][] = (int)$startD->diff($endD)->days;
		}
	}//end accumulateRow()

	/**
	 * Whether a term was met, missed, is running or is suspended (REQ-WTR-005).
	 *
	 * Met: completed on or before `endDateCurrent` (a completion without a
	 * recorded date counts as met, as `withinTerm` always did). Missed: status
	 * `exceeded`, or completed after `endDateCurrent`. A withdrawn term is none
	 * of the four.
	 *
	 * @param array<string, mixed> $row Instance row.
	 *
	 * @return string One of met, missed, running, suspended, or the empty string.
	 */
	private function outcomeOf(array $row): string {
		$status = (string)($row['status'] ?? '');
		$completedOn = substr((string)($row['voltooiDatum'] ?? ''), 0, 10);
		$end = substr((string)($row['endDateCurrent'] ?? ''), 0, 10);

		return match (true) {
			$status === 'completed' && $completedOn !== '' && $end !== '' && $completedOn > $end => 'missed',
			$status === 'completed' => 'met',
			$status === 'exceeded' => 'missed',
			$status === 'lopend', $status === 'verlengd' => 'running',
			$status === 'paused' => 'suspended',
			default => '',
		};
	}//end outcomeOf()

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
			$binnenPct = round(($b['withinTerm'] / $total) * 100, 1);

			$avgDur = 0.0;

			$aantalDoorlooptijden = count($b['doorlooptijdenDagen']);
			if ($aantalDoorlooptijden > 0) {
				$avgDur = round(array_sum($b['doorlooptijdenDagen']) / $aantalDoorlooptijden, 1);
			}

			$metShare = 0.0;
			if (($b['met'] + $b['missed']) > 0) {
				$metShare = round(($b['met'] / ($b['met'] + $b['missed'])) * 100, 1);
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
				'metShare' => $metShare,
			];
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
		$uSchema = (string)$this->settingsService->getConfigValue('dwangsom_uitbetaling_schema');
		if ($objectService === null || $register === '' || $uSchema === '') {
			return ['rows' => [], 'summary' => ['count' => 0, 'totalCents' => 0]];
		}

		try {
			$rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $uSchema, filters: []);
		} catch (\Throwable $e) {
			return ['rows' => [], 'summary' => ['count' => 0, 'totalCents' => 0]];
		}

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

			$outRows[] = [
				'reference' => (string)($row['reference'] ?? ''),
				'bedragCents' => $amount,
				'actualPaymentDate' => $payment,
				'betalingsreferentie' => (string)($row['betalingsreferentie'] ?? ''),
				'status' => (string)($row['status'] ?? ''),
				'legalBasis' => (string)($row['legalBasis'] ?? ''),
				'iban' => (string)($row['iban'] ?? ''),
			];
		}//end foreach

		return [
			'jaar' => $year,
			'rows' => $outRows,
			'summary' => ['count' => count($outRows), 'totalCents' => $total],
			'warnings' => $warnings,
		];
	}//end generateDwangsomAuditReport()

	/**
	 * Compute a snapshot KPI summary for the dashboard widget.
	 *
	 * @param array<string, mixed> $filters Optional filters (afdeling, zaaktype).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
	 */
	public function getTermijnKpi(array $filters = []): array {
		$rows = $this->listInstances(from: '1970-01-01', until: '2999-12-31');

		$dwTotal = 0;
		$totals = $this->collectKpiTotals(rows: $rows, filters: $filters);

		$total = $totals['total'];
		$within = $totals['within'];
		$overrun = $totals['overrun'];
		$durations = $totals['durations'];

		$withinTermPercent = 0.0;
		if ($total > 0) {
			$withinTermPercent = round(($within / $total) * 100, 1);
		}

		$aantalDuraties = count($durations);
		$avgDurationDays = 0.0;
		if ($aantalDuraties > 0) {
			$avgDurationDays = round(array_sum($durations) / $aantalDuraties, 1);
		}

		return [
			'totalZaken' => $total,
			'withinTermijnPercent' => $withinTermPercent,
			'avgDurationDays' => $avgDurationDays,
			'overrunCount' => $overrun,
			'dwangsomTotalCents' => $dwTotal,
			'lastUpdated' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
		];
	}//end getTermijnKpi()

	/**
	 * Tally the dashboard KPI counters over the instance rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Instance rows.
	 * @param array<string, mixed> $filters Optional filters (afdeling, zaaktype).
	 *
	 * @return array{total:int,within:int,overrun:int,durations:array<int,int>} Counters plus the collected doorlooptijden.
	 */
	private function collectKpiTotals(array $rows, array $filters): array {
		$total = 0;
		$within = 0;
		$overrun = 0;
		$durations = [];
		foreach ($rows as $row) {
			if (isset($filters['caseType']) === true && (string)($row['caseType'] ?? '') !== $filters['caseType']) {
				continue;
			}

			$total++;
			$status = (string)($row['status'] ?? '');
			if ($status === 'completed') {
				$within++;
			}

			if ($status === 'exceeded') {
				$overrun++;
			}

			$start = (string)($row['startDate'] ?? '');
			$end = (string)($row['endDateCurrent'] ?? '');
			if ($start !== '' && $end !== '') {
				$durations[] = (int)(new DateTimeImmutable(substr($start, 0, 10)))->diff(new DateTimeImmutable($end))->days;
			}
		}//end foreach

		return [
			'total' => $total,
			'within' => $within,
			'overrun' => $overrun,
			'durations' => $durations,
		];
	}//end collectKpiTotals()

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
				(string)($row['binnenTermijnPct'] ?? 0),
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
