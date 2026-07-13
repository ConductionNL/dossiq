<?php

/**
 * Procest Iv3ReportService
 *
 * Quarterly IV3 (Informatie voor Derden) cost-reporting aggregation: per
 * BBV taakveld, per quarter — distinct case count, total recorded (handling)
 * costs, total leges income, average cost per case. Cases whose case type
 * carries no taakveld are excluded from taakveld buckets and reported
 * separately as `uncategorized`. Pure aggregation over OpenRegister `case`
 * and `caseType` objects read via {@see SearchesObjects}; no raw SQL.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/specs/iv3-case-cost-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Quarterly IV3 cost-reporting aggregation service.
 *
 * @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/tasks.md#3.1
 */
class Iv3ReportService
{
    use SearchesObjects;

    /**
     * Cost-entry type: income from leges (fees) recorded against a case.
     */
    private const TYPE_LEGES_INCOME = 'leges_income';

    /**
     * Cost-entry type: internal handling/processing cost recorded against a case.
     */
    private const TYPE_HANDLING_COST = 'handling_cost';

    /**
     * Bucket key used for cases whose case type carries no taakveld.
     */
    private const UNCATEGORIZED_KEY = 'uncategorized';

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Shared OR register/schema resolver.
     * @param Iv3TaakveldList $taakveldList    Taakveld reference list.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly Iv3TaakveldList $taakveldList,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Generate the quarterly IV3 cost report.
     *
     * @param int $year    Calendar year (e.g. 2026).
     * @param int $quarter Quarter number 1-4.
     *
     * @return array{
     *     year: int,
     *     quarter: int,
     *     from: string,
     *     until: string,
     *     perTaakveld: array<string, array{
     *         taakveldLabel: string, caseCount: int, totalCosts: float,
     *         totalLegesIncome: float, avgCostPerCase: float}>,
     *     uncategorized: array{
     *         caseCount: int, totalCosts: float, totalLegesIncome: float,
     *         avgCostPerCase: float}|null,
     *     metadata: array{generatedAt: string, taakveldListVersion: string, casesScanned: int}
     * }
     *
     * @throws RuntimeException When the quarter number is not 1-4.
     *
     * @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/specs/iv3-case-cost-reporting/spec.md
     */
    public function generateQuarterlyReport(int $year, int $quarter): array
    {
        if ($quarter < 1 || $quarter > 4) {
            throw new RuntimeException('quarter must be 1-4, got: '.$quarter);
        }

        $bounds = $this->resolveQuarter(year: $year, quarter: $quarter);

        $taakveldByCaseType = $this->loadCaseTypeTaakveldMap();
        $cases   = $this->loadCases();
        $buckets = $this->accumulateBuckets(cases: $cases, taakveldByCaseType: $taakveldByCaseType, bounds: $bounds);

        [$perTaakveld, $uncategorized] = $this->splitBuckets(buckets: $buckets);

        return [
            'year'          => $year,
            'quarter'       => $quarter,
            'from'          => $bounds['from'],
            'until'         => $bounds['until'],
            'perTaakveld'   => $perTaakveld,
            'uncategorized' => $uncategorized,
            'metadata'      => [
                'generatedAt'         => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
                'taakveldListVersion' => $this->taakveldList->version(),
                'casesScanned'        => count($cases),
            ],
        ];
    }//end generateQuarterlyReport()

    /**
     * Accumulate raw per-taakveld/uncategorized totals across every case
     * with qualifying cost activity in the quarter.
     *
     * @param array<int, array<string, mixed>>   $cases              Case objects.
     * @param array<string, string|null>         $taakveldByCaseType Map of caseType id to taakveld code.
     * @param array{from: string, until: string} $bounds             Quarter date bounds.
     *
     * @return array<string, array{count: int, totalCosts: float, totalLegesIncome: float}>
     */
    private function accumulateBuckets(array $cases, array $taakveldByCaseType, array $bounds): array
    {
        $buckets = [];

        foreach ($cases as $case) {
            $entries = $this->qualifyingEntries(case: $case, from: $bounds['from'], until: $bounds['until']);
            if (count($entries) === 0) {
                continue;
            }

            $caseTypeId = (string) ($case['caseType'] ?? '');
            $taakveld   = ($taakveldByCaseType[$caseTypeId] ?? null);
            $bucketKey  = self::UNCATEGORIZED_KEY;
            if ($taakveld !== null && $taakveld !== '') {
                $bucketKey = $taakveld;
            }

            $buckets[$bucketKey] ??= [
                'count'            => 0,
                'totalCosts'       => 0.0,
                'totalLegesIncome' => 0.0,
            ];
            $buckets[$bucketKey]['count']++;
            $this->applyEntries(entries: $entries, bucket: $buckets[$bucketKey]);
        }//end foreach

        return $buckets;
    }//end accumulateBuckets()

    /**
     * Sum a case's qualifying cost entries into its bucket accumulator, by type.
     *
     * @param array<int, array<string, mixed>>                              $entries Qualifying cost entries.
     * @param array{count: int, totalCosts: float, totalLegesIncome: float} $bucket  Bucket accumulator (by reference).
     *
     * @return void
     */
    private function applyEntries(array $entries, array &$bucket): void
    {
        foreach ($entries as $entry) {
            $bedrag = (float) ($entry['bedrag'] ?? 0);
            $type   = (string) ($entry['type'] ?? '');
            if ($type === self::TYPE_LEGES_INCOME) {
                $bucket['totalLegesIncome'] += $bedrag;
            } else if ($type === self::TYPE_HANDLING_COST) {
                $bucket['totalCosts'] += $bedrag;
            }
        }
    }//end applyEntries()

    /**
     * Reduce and split raw buckets into the public `perTaakveld` (sorted,
     * labelled) and `uncategorized` shapes.
     *
     * @param array<string, array{count: int, totalCosts: float, totalLegesIncome: float}> $buckets Raw buckets.
     *
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, mixed>|null}
     */
    private function splitBuckets(array $buckets): array
    {
        $perTaakveld   = [];
        $uncategorized = null;
        foreach ($buckets as $key => $bucket) {
            $reduced = $this->reduceBucket(bucket: $bucket);
            if ($key === self::UNCATEGORIZED_KEY) {
                $uncategorized = $reduced;
                continue;
            }

            $reduced['taakveldLabel'] = ($this->taakveldList->labelFor($key) ?? $key);
            $perTaakveld[$key]        = $reduced;
        }

        ksort($perTaakveld);

        return [$perTaakveld, $uncategorized];
    }//end splitBuckets()

    /**
     * Serialise a quarterly report as CSV (header + one row per taakveld,
     * plus an uncategorized row when present).
     *
     * @param array<string, mixed> $report Report as returned by {@see self::generateQuarterlyReport()}.
     *
     * @return string CSV content.
     *
     * @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/specs/iv3-case-cost-reporting/spec.md
     */
    public function asCsv(array $report): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, ['taakveld', 'label', 'caseCount', 'totalCosts', 'totalLegesIncome', 'avgCostPerCase']);

        foreach ((array) ($report['perTaakveld'] ?? []) as $code => $row) {
            fputcsv(
                $handle,
                [
                    (string) $code,
                    (string) ($row['taakveldLabel'] ?? ''),
                    (string) ($row['caseCount'] ?? 0),
                    (string) ($row['totalCosts'] ?? 0),
                    (string) ($row['totalLegesIncome'] ?? 0),
                    (string) ($row['avgCostPerCase'] ?? 0),
                ]
            );
        }

        $uncategorized = ($report['uncategorized'] ?? null);
        if (is_array($uncategorized) === true) {
            fputcsv(
                $handle,
                [
                    '',
                    'Uncategorized',
                    (string) ($uncategorized['caseCount'] ?? 0),
                    (string) ($uncategorized['totalCosts'] ?? 0),
                    (string) ($uncategorized['totalLegesIncome'] ?? 0),
                    (string) ($uncategorized['avgCostPerCase'] ?? 0),
                ]
            );
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }//end asCsv()

    /**
     * Reduce one accumulator bucket into its public shape (adds avgCostPerCase).
     *
     * @param array{count: int, totalCosts: float, totalLegesIncome: float} $bucket Accumulator.
     *
     * @return array{caseCount: int, totalCosts: float, totalLegesIncome: float, avgCostPerCase: float}
     */
    private function reduceBucket(array $bucket): array
    {
        $count = $bucket['count'];
        $avg   = 0.0;
        if ($count > 0) {
            $avg = round($bucket['totalCosts'] / $count, 2);
        }

        return [
            'caseCount'        => $count,
            'totalCosts'       => round($bucket['totalCosts'], 2),
            'totalLegesIncome' => round($bucket['totalLegesIncome'], 2),
            'avgCostPerCase'   => $avg,
        ];
    }//end reduceBucket()

    /**
     * Decode a case's `kosten` field and keep only entries dated within
     * `[from, until]` (inclusive, `YYYY-MM-DD` string comparison).
     *
     * @param array<string, mixed> $case  The case object as an array.
     * @param string               $from  Quarter start (YYYY-MM-DD).
     * @param string               $until Quarter end (YYYY-MM-DD).
     *
     * @return array<int, array<string, mixed>>
     */
    private function qualifyingEntries(array $case, string $from, string $until): array
    {
        $out = [];
        foreach ($this->decodeKosten(case: $case) as $entry) {
            if (is_array($entry) === false) {
                continue;
            }

            $datum = substr((string) ($entry['datum'] ?? ''), 0, 10);
            if ($datum === '' || $datum < $from || $datum > $until) {
                continue;
            }

            $out[] = $entry;
        }

        return $out;
    }//end qualifyingEntries()

    /**
     * Decode a case's raw `kosten` field (array or JSON-encoded string) into
     * a plain list, defaulting to an empty list for any other shape.
     *
     * @param array<string, mixed> $case The case object as an array.
     *
     * @return array<int, mixed>
     */
    private function decodeKosten(array $case): array
    {
        $raw = ($case['kosten'] ?? null);
        if (is_array($raw) === true) {
            return $raw;
        }

        if (is_string($raw) === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end decodeKosten()

    /**
     * Build a `caseTypeId => iv3Taakveld|null` map from every caseType object.
     *
     * @return array<string, string|null>
     */
    private function loadCaseTypeTaakveldMap(): array
    {
        $objectService  = $this->settingsService->getObjectService();
        $register       = (string) $this->settingsService->getConfigValue('register');
        $caseTypeSchema = (string) $this->settingsService->getConfigValue('case_type_schema');
        if ($objectService === null || $register === '' || $caseTypeSchema === '') {
            return [];
        }

        try {
            $rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $caseTypeSchema, filters: []);
        } catch (\Throwable $e) {
            $this->logger->warning('[Iv3ReportService] Failed to load case types', ['error' => $e->getMessage()]);
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $id       = (string) ($row['id'] ?? $row['uuid'] ?? '');
            $taakveld = $row['iv3Taakveld'] ?? null;
            if ($id === '') {
                continue;
            }

            $map[$id] = null;
            if (is_string($taakveld) === true && $taakveld !== '') {
                $map[$id] = $taakveld;
            }
        }

        return $map;
    }//end loadCaseTypeTaakveldMap()

    /**
     * Load every case object.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadCases(): array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $caseSchema    = (string) $this->settingsService->getConfigValue('case_schema');
        if ($objectService === null || $register === '' || $caseSchema === '') {
            return [];
        }

        try {
            return $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $caseSchema, filters: []);
        } catch (\Throwable $e) {
            $this->logger->warning('[Iv3ReportService] Failed to load cases', ['error' => $e->getMessage()]);
            return [];
        }
    }//end loadCases()

    /**
     * Resolve a year+quarter to its `[from, until]` date bounds.
     *
     * @param int $year    Calendar year.
     * @param int $quarter Quarter number 1-4.
     *
     * @return array{from: string, until: string}
     */
    private function resolveQuarter(int $year, int $quarter): array
    {
        $startMonth = (($quarter - 1) * 3) + 1;
        $endMonth   = $startMonth + 2;
        $from       = sprintf('%04d-%02d-01', $year, $startMonth);
        $lastDay    = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $endMonth)))->format('t');
        $until      = sprintf('%04d-%02d-%02d', $year, $endMonth, $lastDay);
        return ['from' => $from, 'until' => $until];
    }//end resolveQuarter()
}//end class
